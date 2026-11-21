# syntax=docker/dockerfile:1
#
# Targets:
#   dev   — local development and CI tests (docker compose), code is bind-mounted, xdebug included
#   prod  — production image (default target), code baked in
#
# The database (MariaDB) is a separate container: see docker-compose.yml (dev) and deploy/compose.yml (prod).

# ---------- Base: FrankenPHP (PHP + Caddy in one binary) ----------
# PHP 8.2: the locked laminas/laminas-code 4.10 (via symfony/proxy-manager-bridge) requires php ~8.1 || ~8.2
FROM dunglas/frankenphp:1-php8.2-bookworm AS base

RUN install-php-extensions pdo_mysql intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Plain HTTP on 8080 — TLS is terminated by the external Caddy (prod) or not needed (dev)
ENV SERVER_NAME=:8080

WORKDIR /app

EXPOSE 8080

# ---------- Dev ----------
FROM base AS dev

RUN install-php-extensions xdebug \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    # allow running as the host user (see `user:` in docker-compose.yml)
    && chmod -R a+rwX /data /config

COPY <<EOF $PHP_INI_DIR/conf.d/zz-dev.ini
memory_limit = 1G
max_execution_time = 300
xdebug.client_host = host.docker.internal
xdebug.start_with_request = trigger
xdebug.log_level = 0
EOF

# Writable home for the composer cache when running as an arbitrary UID.
# No APP_ENV here: it would override APP_ENV=test of phpunit (dev comes from .env).
ENV HOME=/tmp \
    XDEBUG_MODE=coverage

# ---------- supercronic: cron for containers (env vars passed to jobs, logs to stdout, runs as any user) ----------
# Checksums from https://github.com/aptible/supercronic/releases/tag/v0.2.51
FROM alpine:3.20 AS supercronic
ARG TARGETARCH
ARG SUPERCRONIC_VERSION=v0.2.51
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
        amd64) arch=amd64; sha1=1692066e350a88ff4d7d6474fe3c7a6c41ec5e89 ;; \
        arm64) arch=arm64; sha1=9d4c2000493215a1898502b2fc2dada3e8ea8c7a ;; \
        *) echo "unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    wget -qO /supercronic "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${arch}"; \
    echo "${sha1}  /supercronic" | sha1sum -c -; \
    chmod +x /supercronic

# ---------- Backup: the "backup" cron container of deploy/compose.yml ----------
# Daily mariadb-dump of the database (+ upload to S3 / DigitalOcean Spaces with rclone), schedule in config/backup/crontab.
# Based on the same MariaDB image as the database, so mariadb-dump always matches the server version.
FROM mariadb:11.4 AS backup

COPY --from=supercronic /supercronic /usr/local/bin/supercronic
COPY --from=rclone/rclone:1.68.2 /usr/local/bin/rclone /usr/local/bin/rclone
COPY --chmod=755 config/backup/backup.sh /usr/local/bin/backup
COPY config/backup/crontab /etc/backup/crontab

# runs as the deploy user (see `user:` in deploy/compose.yml): rclone needs a writable home
ENV HOME=/tmp \
    BACKUP_DIR=/backups

# not the database entrypoint: this container only runs the schedule (or `backup` by hand)
ENTRYPOINT []
CMD ["supercronic", "-passthrough-logs", "/etc/backup/crontab"]

# ---------- Prod (default target — keep it last) ----------
FROM base AS prod

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY <<EOF $PHP_INI_DIR/conf.d/zz-prod.ini
expose_php = Off
memory_limit = 256M
max_execution_time = 30

opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.preload = /app/config/preload.php
opcache.preload_user = www-data
realpath_cache_size = 4096K
realpath_cache_ttl = 600
EOF

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first for better layer caching
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .

# The cache is warmed up without a database: server_version is set in config/packages/doctrine.yaml
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && php bin/console cache:clear --no-warmup \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var /data/caddy /config/caddy

USER www-data

# / answers without touching the database: the container is "healthy" as soon as PHP serves requests
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/") === false ? 1 : 0);'
