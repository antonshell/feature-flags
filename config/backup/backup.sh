#!/usr/bin/env bash
# Dumps the database to $BACKUP_DIR/feature_flags-<UTC timestamp>.sql.gz, keeps the newest $KEEP dumps locally
# and, if BACKUP_REMOTE is set, uploads the new dump to DigitalOcean Spaces (any S3 storage) with rclone.
#
# Runs in the "backup" container (Dockerfile target "backup"): daily by supercronic (config/backup/crontab),
# before every deploy and by hand:  docker compose run --rm backup backup
#
# Environment (deploy/compose.yml + .backup.env on the server):
#   DB_HOST, DB_NAME, DB_USER, DB_PASSWORD   database connection
#   KEEP                                     local dumps to keep (default 14)
#   BACKUP_REMOTE                            rclone destination, e.g. spaces:antonshell/backups/feature-flags
#   RCLONE_CONFIG_SPACES_*                   rclone remote "spaces" (see README → Backups)
#
# Exit codes: 0 — dump created (and uploaded, if configured); 1 — dump failed;
#             3 — dump created locally, upload failed (deploy tolerates it).
set -euo pipefail
umask 077   # dumps contain project key hashes: readable only by the deploy user

: "${DB_HOST:=database}" "${DB_NAME:?DB_NAME is not set}" "${DB_USER:?DB_USER is not set}" "${DB_PASSWORD:?DB_PASSWORD is not set}"
BACKUP_DIR="${BACKUP_DIR:-/backups}"
KEEP="${KEEP:-14}"
name="feature_flags-$(date -u +%Y%m%d-%H%M%S).sql.gz"
file="$BACKUP_DIR/$name"

# MYSQL_PWD: the password is not visible in the process list
MYSQL_PWD="$DB_PASSWORD" mariadb-dump -h "$DB_HOST" -u "$DB_USER" \
    --single-transaction --routines --triggers "$DB_NAME" \
    | gzip > "$file.tmp"
mv "$file.tmp" "$file"
echo "backup: $file ($(du -h "$file" | cut -f1))"

# local rotation: newest first, delete everything after the first $KEEP
ls -1t "$BACKUP_DIR"/feature_flags-*.sql.gz | tail -n +"$((KEEP + 1))" | xargs -r rm -f

# —— off-site copy ————————————————————————————————————————————————————————————
if [[ -z "${BACKUP_REMOTE:-}" ]]; then
    echo "upload: skipped (BACKUP_REMOTE is not set, the dump is stored only on this server)"
    exit 0
fi

# Only the new dump is copied: remote retention is a lifecycle rule of the bucket, not this script.
# --s3-no-check-bucket: a bucket-scoped (limited access) key may not create or list buckets.
# Timeouts and retries are bounded: with the defaults an unreachable endpoint blocks deploy for tens of minutes.
if rclone copyto "$file" "$BACKUP_REMOTE/$name" \
    --s3-no-check-bucket --contimeout 15s --timeout 2m --retries 3 --low-level-retries 3 -q; then
    echo "upload: $BACKUP_REMOTE/$name"
else
    echo "upload: FAILED, the dump is stored only locally: $file" >&2
    exit 3
fi
