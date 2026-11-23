#syntax=docker/dockerfile:1

# Adapted from https://github.com/dunglas/symfony-docker
# Base images are built daily in https://github.com/FetchDock/fetchdock-base-images

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/develop/develop-images/multistage-build/#stop-at-a-specific-build-stage
# https://docs.docker.com/compose/compose-file/#target

# Base FrankenPHP image
FROM ghcr.io/fetchdock/fetchdock-base-images/frankenphp-server:1.12-php8.5 AS frankenphp_base
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}

WORKDIR /app

COPY --link --chmod=755 frankenphp/cron/cron.hourly/ /etc/cron.hourly/
COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link --chmod=755 frankenphp/worker-entrypoint.sh /usr/local/bin/worker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# Dev FrankenPHP image
FROM ghcr.io/fetchdock/fetchdock-base-images/frankenphp-server-dev:1.12-php8.5 AS frankenphp_dev
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}

COPY --link --chmod=755 frankenphp/cron/cron.hourly/ /etc/cron.hourly/
COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link --chmod=755 frankenphp/worker-entrypoint.sh /usr/local/bin/worker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch"]

# Prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod
ARG APP_VERSION=undefined
ENV APP_VERSION=${APP_VERSION}

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link . ./
RUN rm -Rf frankenphp/

RUN set -eux; \
	mkdir -p var/cache var/log; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer dump-env prod; \
	composer run-script --no-dev post-install-cmd; \
	chmod +x bin/console; sync;

# Integrity manifest (ADR 0013): sha256 of the application files, checked at runtime by app:integrity:check. Must stay
# the last step that writes below /app. CI publishes the printed digest as <APP_VERSION>.sha256.
ARG GIT_SHA=unknown
RUN php bin/console app:integrity:manifest --git-sha="${GIT_SHA}" --no-debug
