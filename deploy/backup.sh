#!/usr/bin/env bash
# Dumps the feature_flags database to backups/feature_flags-<UTC timestamp>.sql.gz and keeps the newest $KEEP dumps.
# Runs on the server from $DEPLOY_PATH: automatically before every deploy (before migrations), or by hand / cron:
#   cd /srv/feature-flags && ./backup.sh
set -euo pipefail
cd "$(dirname "$0")"

KEEP="${KEEP:-14}"
mkdir -p backups
file="backups/feature_flags-$(date -u +%Y%m%d-%H%M%S).sql.gz"

# credentials are taken from the database container's own environment
docker compose exec -T database sh -c \
    'exec mariadb-dump --single-transaction --routines --triggers -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
    | gzip > "$file.tmp"
mv "$file.tmp" "$file"
echo "backup: $file ($(du -h "$file" | cut -f1))"

# rotation: newest first, delete everything after the first $KEEP
ls -1t backups/feature_flags-*.sql.gz | tail -n +"$((KEEP + 1))" | xargs -r rm -f
