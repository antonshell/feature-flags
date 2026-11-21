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

RUN install-php-extensions pdo_mysql intl zip apcu opcache

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
