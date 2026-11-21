# Feature flags service

Self hosted feature flags service

# Install in docker

1 . Clone repository

```
git clone git@github.com:antonshell/feature-flags.git
```

2 . Run containers (the app and MariaDB)

```
docker compose up -d
```

Containers run as your user (`HOST_UID`/`HOST_GID`, default `1000`), so `vendor/` and `var/` stay yours.

3 . Install dependencies

```
docker compose exec app composer install
```

4 . Setup env variables

```
cp .env .env.local
nano .env.local
```

Set ```APP_SECRET```, ```ROOT_TOKEN``` variables.
`DATABASE_URL` comes from `docker-compose.yml` (MariaDB in the `database` container), you don't need to set it.

5 . Apply database migrations

```
docker compose exec app php bin/console doctrine:migrations:migrate
```

6 . Open in browser

[http://127.0.0.1:16580/](http://127.0.0.1:16580/) - Healthcheck

MariaDB is published on `127.0.0.1:16506` (user `root`, password `root`) for database clients.

# Usage

There is a Swagger API docs.

1 . Health check

```
curl --request GET \
  --url http://127.0.0.1:16580/
```

2 . Get feature value

```
curl --request GET \
  --url http://127.0.0.1:16580/feature/antonshell/demo/feature1/prod \
  --header 'Authorization: bearer demo_read_key'
```

3 . Manage features & environments

See Swagger API docs.

# Demo

There is a demo instance available: [https://feature-flags.antonshell.me](https://feature-flags.antonshell.me)

1 . Health check

```
curl --request GET \
  --url https://feature-flags.antonshell.me/
```

2 . Get feature value

```
curl --request GET \
  --url https://feature-flags.antonshell.me/feature/antonshell/demo/feature1 \
  --header 'Authorization: bearer demo_read_key'
```

# Deploy (production)

Production runs as three containers behind the existing Caddy container (`/srv/proxy`):
the app ([FrankenPHP](https://frankenphp.dev)), MariaDB and `backup` (daily dumps, see [Backups](#backups)).
The database is attached only to the stack's own network: Caddy and other projects can't reach it.
Its port is published only on the server's localhost (`127.0.0.1:26506`) for access via an SSH tunnel.
Its data lives in the external docker volume `feature-flags_db-data`: it is created once by hand,
`docker compose down -v` doesn't delete it, and if it is missing, deploy fails instead of starting with an empty database.

Every push to `master` runs `.github/workflows/ci.yml`: lint, audit, tests and the image build run in parallel.
The build job builds two images (the app `sha-<commit>` and the backup container `backup-sha-<commit>`),
smoke-tests them together with a real MariaDB and pushes them to `ghcr.io/antonshell/feature-flags` only if it passes;
the deploy job runs only when lint, tests and build are green. On the server it:

1. starts the database (if it isn't running) and waits until it is healthy
2. dumps the database to `backups/` with the new backup image and uploads it to DigitalOcean Spaces, if configured
   (a failed upload is only a warning: the local dump exists)
3. runs `doctrine:migrations:migrate` with the **new** image
4. switches the app and backup containers to the new images, waits until the app is healthy and the backup container runs

If the backup or a migration fails, deploy stops and the old app container keeps running.
The audit job (`composer audit`) is informational. The images of the 10 most recent deploys are kept in GHCR as rollback targets.

## One-time server setup

Server layout: Caddy lives in `/srv/proxy` (compose + Caddyfile) and is attached to the external docker network `web`.
The app stack lives in `/srv/feature-flags`.

1 . Create a deploy user with docker access (if it doesn't exist yet) and a directory for the stack

```
sudo useradd --create-home --shell /bin/bash --groups docker deployment && sudo usermod --password '*' deployment
sudo mkdir -p /srv/feature-flags && sudo chown deployment:deployment /srv/feature-flags
```

Add the public part of the deploy SSH key to `/home/deployment/.ssh/authorized_keys`.

2 . Create the database volume (once; deploy fails with `external volume "feature-flags_db-data" not found` without it)

```
docker volume create feature-flags_db-data
```

The volume is external: compose never creates or removes it, so `docker compose down -v` keeps the data.
It is deleted only explicitly: `docker volume rm feature-flags_db-data`.

3 . Add the site to `/srv/proxy/Caddyfile` (see `deploy/Caddyfile.example`) and reload Caddy

```
feature-flags.antonshell.me {
	encode zstd gzip
	reverse_proxy feature-flags:8080
}
```

```
cd /srv/proxy && docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile
```

## GitHub configuration

Settings → Environments → `production` → secrets:

| Secret           | Example                                         |
|------------------|-------------------------------------------------|
| `DEPLOY_HOST`    | server IP / hostname                            |
| `DEPLOY_PORT`    | `22` (optional)                                 |
| `DEPLOY_USER`    | `deployment`                                    |
| `DEPLOY_SSH_KEY` | private key (ed25519)                           |
| `DEPLOY_PATH`    | `/srv/feature-flags`                            |
| `APP_SECRET`     | output of `openssl rand -hex 32`                |
| `ROOT_TOKEN`     | token for the `/manage/*` API, `openssl rand -hex 32` |
| `DB_PASSWORD`    | output of `openssl rand -hex 24` (URL-safe: it goes into `DATABASE_URL`) |

`DB_PASSWORD` is applied by MariaDB only when it initializes an empty volume (first deploy).
To change it later, change it in the database first, then update the secret:

```
cd /srv/feature-flags
docker compose exec database sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "ALTER USER feature_flags@\"%\" IDENTIFIED BY \"<new password>\""'
```

## Moving the data from the old installation

The first deploy creates an empty database and applies the migrations. After that, import a dump of the old database
(`mysqldump` adds `DROP TABLE` for every table, so the import replaces the tables of the first deploy,
including `doctrine_migration_versions`):

```
# on the old installation
mysqldump --single-transaction --routines --triggers -u <user> -p feature_flags | gzip > feature_flags-old.sql.gz

# on the server, in /srv/feature-flags
zcat feature_flags-old.sql.gz | docker compose exec -T database sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

## Database access

The database port is published only on the server's localhost (`127.0.0.1:26506`), so it is reachable only via SSH.
Credentials: user `feature_flags`, database `feature_flags`, password — `DB_PASSWORD` from `/srv/feature-flags/.env`
(the `DB_PASSWORD` secret). The `feature_flags` user owns the database; root is not needed.

1 . PhpStorm / DataGrip / DBeaver: SSH tunnel

- **General**: host `127.0.0.1`, port `26506`, user `feature_flags`, database `feature_flags`, password from `.env`
- **SSH/SSL**: Use SSH tunnel → `deployment@<server>`, authentication: key pair (the deploy key or your own key
  in `/home/deployment/.ssh/authorized_keys`)

The IDE picks a free local port for the tunnel by itself, so it never conflicts with local databases.

2 . Manual tunnel for any client

```
ssh -N -L 26506:127.0.0.1:26506 deployment@<server>
```

Then connect to `127.0.0.1:26506` locally.

Port convention: `1PPSS` locally (`docker-compose.yml`), `2PPSS` on the server (localhost-only ports).
`PP` is the project (`65` for feature-flags), `SS` the service (`80` http, `06` MySQL/MariaDB, `82` swagger).
So `16506` is always the local database and `26506` the production one: a tunnel can't be mixed up with the local database.

3 . Console on the server

```
cd /srv/feature-flags
docker compose exec database sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

Make a backup (`docker compose run --rm backup backup`) before changing data by hand.

## Backups

The `feature-flags-backup` container (Dockerfile target `backup`: the MariaDB image + [supercronic](https://github.com/aptible/supercronic)
+ [rclone](https://rclone.org)) dumps the database daily by the schedule in `config/backup/crontab`, and the deploy job
runs the same dump before migrations. Nothing is installed on the server and there is no host cron.

- dumps: `/srv/feature-flags/backups/feature_flags-<UTC time>.sql.gz`, owned by the deploy user, the 14 newest are kept
- off-site copy: every new dump is uploaded to DigitalOcean Spaces if `.backup.env` sets `BACKUP_REMOTE`

| Schedule (UTC) | Job                                                                |
|----------------|--------------------------------------------------------------------|
| daily at 03:30 | dump the database, rotate local dumps, upload to Spaces            |

```
cd /srv/feature-flags
docker logs feature-flags-backup               # logs of the scheduled runs
docker compose run --rm backup backup          # backup now
```

| Exit code | Meaning                                                                    |
|-----------|----------------------------------------------------------------------------|
| `0`       | dump created (and uploaded, if `BACKUP_REMOTE` is set)                     |
| `1`       | dump failed                                                                |
| `3`       | dump created locally, upload failed — deploy goes on with a warning        |

To change the schedule: edit `config/backup/crontab` (standard cron syntax, times in UTC) and deploy.
The smoke test checks that the crontab is valid and that a dump is created and uploaded.

### Off-site copy: DigitalOcean Spaces

1 . Spaces: bucket `antonshell`, dumps go to `backups/feature-flags/` (a folder per project under `backups/`).
**Access Keys → Create Access Key → Limited Access**: only this bucket, permission *Read/Write/Delete*.
Don't use a full-access key: it lives on the server.

2 . On the server, create `/srv/feature-flags/.backup.env` (deploy only creates it empty if missing, never changes it):

```
cat > /srv/feature-flags/.backup.env <<'ENV'
BACKUP_REMOTE=spaces:antonshell/backups/feature-flags
RCLONE_CONFIG_SPACES_TYPE=s3
RCLONE_CONFIG_SPACES_PROVIDER=DigitalOcean
RCLONE_CONFIG_SPACES_ENDPOINT=fra1.digitaloceanspaces.com
RCLONE_CONFIG_SPACES_ACCESS_KEY_ID=<key>
RCLONE_CONFIG_SPACES_SECRET_ACCESS_KEY=<secret>
ENV
chmod 600 /srv/feature-flags/.backup.env
```

`fra1` is the bucket's region (see its URL: `<bucket>.<region>.digitaloceanspaces.com`).
The running backup container reads `.backup.env` at start: after creating or changing it, recreate the container:

```
cd /srv/feature-flags && docker compose up -d --force-recreate backup
```

3 . Check: `docker compose run --rm backup backup` must end with
`upload: spaces:antonshell/backups/feature-flags/feature_flags-<time>.sql.gz`.

4 . Retention in the bucket. The container only uploads; old dumps are deleted by a lifecycle rule of the bucket
(the Spaces UI has no editor for it, set it once via the S3 API). `s3cmd expire` replaces all lifecycle rules of the bucket:
check first that there are none (`s3cmd getlifecycle s3://antonshell` with the same options).

```
docker run --rm -it --entrypoint sh python:3-alpine -c '
  pip install -q s3cmd &&
  s3cmd --access_key=<key> --secret_key=<secret> \
    --host=fra1.digitaloceanspaces.com --host-bucket="%(bucket)s.fra1.digitaloceanspaces.com" \
    expire s3://antonshell --expiry-days=90 --expiry-prefix=backups/feature-flags/'
```

List and download dumps from the bucket (rclone is in the backup image, credentials from `.backup.env`):

```
cd /srv/feature-flags
docker compose run --rm --no-deps backup rclone ls spaces:antonshell/backups/feature-flags
docker compose run --rm --no-deps backup rclone copyto \
  spaces:antonshell/backups/feature-flags/feature_flags-<time>.sql.gz /backups/feature_flags-<time>.sql.gz
```

### Restore a dump

```
cd /srv/feature-flags
zcat backups/feature_flags-<time>.sql.gz | docker compose exec -T database sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

## Rollback

Migrations are not rolled back automatically. To return to a previous image, set `APP_IMAGE` in `/srv/feature-flags/.env`
to one of the kept tags (`ghcr.io/antonshell/feature-flags:sha-<commit>`) and run `docker compose up -d`.
If the newer version changed the schema, restore the backup made right before that deploy first.

## Build the production image locally

```
docker build -t feature-flags .
```

The image needs a database (`DATABASE_URL`), `APP_SECRET` and `ROOT_TOKEN`; the simplest way to run it is the smoke test below.

## Smoke test of the production image

`.github/scripts/smoke-test.sh` starts the image together with MariaDB (the same image as in production) and checks
what PHPUnit can't see in the real container:

- the migrations apply to an empty database with the prod image (exactly what deploy runs)
- the container becomes `healthy`, `/` returns `200` with JSON (not in debug mode), an unknown route returns `404`
- the `Authorization` header reaches PHP: no token / a wrong token → `403`
- a project is created through the root API, stored in MariaDB and read back; a duplicate → `400`; errors have no stack trace

It runs in CI before the image is pushed to GHCR; to run it locally:

```
docker build --target prod -t feature-flags:prod .
.github/scripts/smoke-test.sh feature-flags:prod
```

The app is published on `127.0.0.1:18099` (override with `SMOKE_PORT=...`); containers and the network are removed afterwards.
On failure the script prints the failed checks and the container logs and exits with code `1`.

# Tests

1 . Init testing environment (creates `feature_flags_test` in the MariaDB container and applies migrations)

```
docker compose exec app composer init-testing-environment
```

2 . Run tests

```
docker compose exec app composer test
```

In CI tests run in the same dev image and MariaDB; the coverage report is in the job summary
and in the **coverage** artifact of the run.

# Codestyle

1 . Fix codestyle

```
docker compose exec app composer cs-fixer src
```

# API documentation - swagger

Docker environment:

```
docker compose --profile swagger up -d
```

[http://127.0.0.1:16582/](http://127.0.0.1:16582/)

Github pages:
[https://antonshell.github.io/feature-flags/swagger-ui/](https://antonshell.github.io/feature-flags/swagger-ui/)

# Setup xdebug (Docker)

Xdebug is installed in the dev image in `coverage` mode. For step debugging:

```
XDEBUG_MODE=debug,coverage docker compose up -d
```

Debugging starts on request with the `XDEBUG_TRIGGER` cookie/parameter (e.g. a browser extension);
PhpStorm server name: `Docker`, path mapping: project root → `/app`.
