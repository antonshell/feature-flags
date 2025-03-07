# Feature flags service

Self hosted feature flags service

Full code coverage report: [http://files.antonshell.me/github-actions/feature-flags/master/coverage/coverage/](http://files.antonshell.me/github-actions/feature-flags/master/coverage/coverage/)

# Install in docker

1 . Clone repository

```
git clone git@github.com:antonshell/feature-flags.git
```

2 . Run containers
```
docker compose up
```

3 . Install dependencies

```
docker compose exec php-fpm composer install
```

4 . Setup env variables

```
cp .env .env.local
nano .env.local
```

Set ```APP_SECRET```, ```ROOT_TOKEN``` variables

5 . Apply database migrations

```
docker compose exec php-fpm php bin/console doctrine:migrations:migrate
```

6 . Open in browser

[http://127.0.0.1:16580/](http://127.0.0.1:16580/) - Healthcheck

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

# Tests

1 . Init testing environment

```
docker compose exec php-fpm composer init-testing-environment
```

2 . Run tests

Local environment:
```
composer test
```

Docker environment:
```
docker compose exec php-fpm composer test
```

# Codestyle

1 . Fix codestyle

Local environment:
```
composer cs-fixer src
```

Docker environment:
```
docker compose exec php-fpm composer cs-fixer src
```

# API documentation - swagger

Docker environment:
[http://127.0.0.1:16582/](http://127.0.0.1:16582/)

Github pages:
[https://antonshell.github.io/feature-flags/swagger-ui/](https://antonshell.github.io/feature-flags/swagger-ui/)

# Setup xdebug (Docker)

[https://blog.denisbondar.com/post/phpstorm_docker_xdebug](https://blog.denisbondar.com/post/phpstorm_docker_xdebug)

# Macos docker environment(Mutagen)

1 . Install mutagen

```
brew install mutagen-io/mutagen/mutagen
mutagen daemon start
```

2 . Run containers

```
docker compose down --remove-orphans || true
mutagen project start || mutagen project terminate
```

3 . Troubleshooting:

Fix permissions:
```
docker compose exec php-fpm chmod -R 777 /var/www
```

Disable permission tracking:
```
git config core.fileMode false
```