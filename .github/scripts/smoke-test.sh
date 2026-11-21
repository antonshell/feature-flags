#!/usr/bin/env bash
# Smoke test of the production image together with a real MariaDB (same image as in deploy/compose.yml):
# checks what PHPUnit can't see — the prod container boots, pdo_mysql talks to MariaDB, migrations apply
# from a clean database, the Authorization header reaches PHP through FrankenPHP, prod mode hides debug info.
#
# With a second image, also checks the backup cron container (Dockerfile target "backup"): the crontab is valid,
# a dump of the same database is created and uploaded with rclone, a failed upload gives exit code 3.
#
# Usage: .github/scripts/smoke-test.sh <image> [<backup image>]
set -euo pipefail

IMAGE="${1:?usage: $0 <image> [<backup image>]}"
BACKUP_IMAGE="${2:-}"
DB_IMAGE="${DB_IMAGE:-mariadb:11.4}"
ID="feature-flags-smoke-$$"
PORT="${SMOKE_PORT:-18099}"
BASE="http://127.0.0.1:$PORT"
ROOT_TOKEN="smoke-root-token"
DATABASE_URL="mysql://feature_flags:smoke@$ID-db:3306/feature_flags?charset=utf8mb4"
failures=0
TMP="$(mktemp -d)"

cleanup() {
    if (( failures > 0 )); then
        echo "--- app logs ---";      docker logs "$ID" 2>&1 | tail -50 || true
        echo "--- database logs ---"; docker logs "$ID-db" 2>&1 | tail -20 || true
    fi
    docker rm -f "$ID" "$ID-db" >/dev/null 2>&1 || true
    docker network rm "$ID" >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT

check() { # check <description> <command...>
    local description="$1"; shift
    if "$@"; then
        echo "✅ $description"
    else
        echo "❌ $description"
        failures=$((failures + 1))
    fi
}

wait_healthy() { # wait_healthy <container>
    local health=starting
    for _ in $(seq 1 60); do
        health="$(docker inspect -f '{{.State.Health.Status}}' "$1")"
        [[ "$health" == healthy ]] && return 0
        sleep 2
    done
    echo "   $1: $health"
    return 1
}

request() { # request <method> <path> [curl args...] → "<status> <body>"
    local method="$1" path="$2" out; shift 2
    out="$(curl -s -X "$method" -w '\n%{http_code}' "$@" "$BASE$path")"
    printf '%s %s' "${out##*$'\n'}" "${out%$'\n'*}"
}

expect() { # expect <status> <body regex> <method> <path> [curl args...]
    local status="$1" pattern="$2" response; shift 2
    response="$(request "$@")"
    [[ "${response%% *}" == "$status" && "${response#* }" =~ $pattern ]] \
        || { echo "   got: ${response:0:300}"; echo "   expected: $status, body ~ /$pattern/"; return 1; }
}

docker network create "$ID" >/dev/null
docker run -d --name "$ID-db" --network "$ID" \
    -e MARIADB_DATABASE=feature_flags -e MARIADB_USER=feature_flags -e MARIADB_PASSWORD=smoke \
    -e MARIADB_RANDOM_ROOT_PASSWORD=yes \
    --health-cmd 'healthcheck.sh --connect --innodb_initialized' --health-interval 2s \
    "$DB_IMAGE" >/dev/null

APP_ENV_ARGS=(-e APP_SECRET=smoke-test -e ROOT_TOKEN="$ROOT_TOKEN" -e DATABASE_URL="$DATABASE_URL")

check "database is healthy" wait_healthy "$ID-db"

# exactly what the deploy job runs before switching to the new image
check "migrations apply to an empty database" \
    docker run --rm --network "$ID" "${APP_ENV_ARGS[@]}" "$IMAGE" \
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

docker run -d --name "$ID" --network "$ID" "${APP_ENV_ARGS[@]}" -p "127.0.0.1:$PORT:8080" "$IMAGE" >/dev/null
check "app container is healthy" wait_healthy "$ID"

check "GET / → 200 status ok" expect 200 '"status":"ok"' GET /
check "GET / is not in debug mode" bash -c "! curl -s -D - -o /dev/null '$BASE/' | grep -qi '^x-debug-token'"
check "unknown route → 404" expect 404 '' GET /this/route/does/not/exist

# auth: the token is read from the Authorization header
check "root API without a token → 403" expect 403 'needs a valid token' GET /manage/projects
check "root API with a wrong token → 403" expect 403 'Invalid access token' GET /manage/projects -H 'Authorization: bearer wrong'

# write + read through Doctrine
AUTH=(-H "Authorization: bearer $ROOT_TOKEN")
check "create a project → 200 with keys" expect 200 '"read_key":"' POST /manage/project "${AUTH[@]}" \
    -H 'Content-Type: application/json' -d '{"name":"smoke","description":"smoke test","owner":"ci"}'
check "the project is stored" expect 200 '"name":"smoke"' GET /manage/projects "${AUTH[@]}"
check "duplicate project → 400" expect 400 'Duplicated' POST /manage/project "${AUTH[@]}" \
    -H 'Content-Type: application/json' -d '{"name":"smoke","description":"smoke test","owner":"ci"}'
check "errors are JSON without a stack trace" bash -c "! curl -s '$BASE/manage/project/999999' -H 'Authorization: bearer $ROOT_TOKEN' | grep -qi 'trace'"

# —— backup container ——————————————————————————————————————————————————————————
if [[ -n "$BACKUP_IMAGE" ]]; then
    mkdir -p "$TMP/backups" "$TMP/remote"
    # as in deploy/compose.yml: runs as the (deploy) user, dumps to a bind mount; "spaces" is a local rclone remote here
    BACKUP_ARGS=(--rm --network "$ID" --user "$(id -u):$(id -g)" -v "$TMP/backups:/backups"
        -e DB_HOST="$ID-db" -e DB_NAME=feature_flags -e DB_USER=feature_flags -e DB_PASSWORD=smoke)

    check "backup: crontab is valid" docker run --rm "$BACKUP_IMAGE" supercronic -test /etc/backup/crontab
    check "backup: dump + upload → exit 0" docker run "${BACKUP_ARGS[@]}" -v "$TMP/remote:/remote" \
        -e RCLONE_CONFIG_SPACES_TYPE=local -e BACKUP_REMOTE=spaces:/remote/feature-flags "$BACKUP_IMAGE" backup
    check "backup: the dump is a valid gzip with the project table and the created project" bash -c \
        "f=\$(ls '$TMP'/backups/feature_flags-*.sql.gz) && gzip -t \"\$f\" && zcat \"\$f\" | grep -q 'CREATE TABLE \`project\`' && zcat \"\$f\" | grep -q \"'smoke'\""
    check "backup: the dump is uploaded" bash -c "cmp '$TMP'/backups/feature_flags-*.sql.gz '$TMP'/remote/feature-flags/feature_flags-*.sql.gz"

    sleep 1   # dump names have a one-second resolution
    rc=0
    docker run "${BACKUP_ARGS[@]}" -e RCLONE_CONFIG_SPACES_TYPE=s3 -e RCLONE_CONFIG_SPACES_PROVIDER=Other \
        -e RCLONE_CONFIG_SPACES_ENDPOINT=http://127.0.0.1:1 -e BACKUP_REMOTE=spaces:bucket/feature-flags \
        "$BACKUP_IMAGE" backup >/dev/null 2>&1 || rc=$?
    check "backup: failed upload → exit 3, the dump stays local" bash -c \
        "[ $rc -eq 3 ] && [ \$(ls '$TMP'/backups/feature_flags-*.sql.gz | wc -l) -eq 2 ]"
fi

if (( failures > 0 )); then
    echo "Smoke test failed: $failures check(s)"
    exit 1
fi
echo "Smoke test passed"
