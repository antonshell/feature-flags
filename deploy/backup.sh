#!/usr/bin/env bash
# Dumps the feature_flags database to backups/feature_flags-<UTC timestamp>.sql.gz, keeps the newest $KEEP dumps
# locally and, if .backup.env exists, uploads the new dump to DigitalOcean Spaces (any S3 storage) with rclone.
# Runs on the server from $DEPLOY_PATH: automatically before every deploy (before migrations), or by hand / cron:
#   cd /srv/feature-flags && ./backup.sh
#
# .backup.env (next to this script, chmod 600; never written by deploy) — see README → Backups:
#   BACKUP_REMOTE=spaces:antonshell/backups/feature-flags
#   RCLONE_CONFIG_SPACES_TYPE=s3
#   RCLONE_CONFIG_SPACES_PROVIDER=DigitalOcean
#   RCLONE_CONFIG_SPACES_ENDPOINT=<region>.digitaloceanspaces.com
#   RCLONE_CONFIG_SPACES_ACCESS_KEY_ID=...
#   RCLONE_CONFIG_SPACES_SECRET_ACCESS_KEY=...
#
# Exit codes: 0 — dump created (and uploaded, if configured); 1 — dump failed;
#             3 — dump created locally, upload failed (deploy tolerates it, cron should alert).
set -euo pipefail
cd "$(dirname "$0")"

KEEP="${KEEP:-14}"
RCLONE_IMAGE="${RCLONE_IMAGE:-rclone/rclone:1.68.2}"
mkdir -p backups
file="backups/feature_flags-$(date -u +%Y%m%d-%H%M%S).sql.gz"

# credentials are taken from the database container's own environment
docker compose exec -T database sh -c \
    'exec mariadb-dump --single-transaction --routines --triggers -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
    | gzip > "$file.tmp"
mv "$file.tmp" "$file"
echo "backup: $file ($(du -h "$file" | cut -f1))"

# local rotation: newest first, delete everything after the first $KEEP
ls -1t backups/feature_flags-*.sql.gz | tail -n +"$((KEEP + 1))" | xargs -r rm -f

# —— off-site copy ————————————————————————————————————————————————————————————
if [[ ! -f .backup.env ]]; then
    echo "upload: skipped (no .backup.env, the dump is stored only on this server)"
    exit 0
fi

remote="$(sed -n 's/^BACKUP_REMOTE=//p' .backup.env)"
if [[ -z "$remote" ]]; then
    echo "upload: FAILED, BACKUP_REMOTE is not set in .backup.env" >&2
    exit 3
fi

# Only the new dump is copied: remote retention is a lifecycle rule of the bucket, not this script.
# --s3-no-check-bucket: a bucket-scoped (limited access) key may not create or list buckets.
# Timeouts and retries are bounded: with the defaults an unreachable endpoint blocks deploy for tens of minutes.
if docker run --rm --env-file .backup.env -v "$PWD/backups:/backups:ro" "$RCLONE_IMAGE" \
    copyto "/backups/${file#backups/}" "$remote/${file#backups/}" \
    --s3-no-check-bucket --contimeout 15s --timeout 2m --retries 3 --low-level-retries 3 -q; then
    echo "upload: $remote/${file#backups/}"
else
    echo "upload: FAILED, the dump is stored only locally: $file" >&2
    exit 3
fi
