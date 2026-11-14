# Hypervel, for Anthropic's OSS Scanner (https://red.anthropic.com/oss-scanner/)
#
# /src    the hypervel/components checkout, with Composer dev dependencies installed
#
# Network access is only available while this image builds. PHP test dependencies are installed here.
# Run `hypervel-services-start` to start Redis. Testbench uses in-memory SQLite by default.
FROM ghcr.io/hypervel/components-ci:php8.5-swoole6.3.0-rc1

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    REDIS_HOST=127.0.0.1 \
    REDIS_TEST_DB_MIN=0 \
    REDIS_TEST_DB_MAX=15

RUN apt-get update && apt-get install -y --no-install-recommends \
      redis-server \
    && rm -rf /var/lib/apt/lists/*

# The maintained CI image already supplies Composer, Swoole and the required PHP extensions.

RUN { echo 'memory_limit=-1'; \
      echo 'error_reporting=E_ALL'; \
    } > /usr/local/etc/php/conf.d/oss-scanner.ini

# hypervel-services-start starts Redis, which is not managed by the base image's Supervisor configuration.
# You can run it again safely.
RUN printf '%s\n' '#!/bin/sh' \
      'pgrep -x redis-server >/dev/null || redis-server --daemonize yes --dir /tmp' \
      > /usr/local/bin/hypervel-services-start \
    && chmod +x /usr/local/bin/hypervel-services-start

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

# Install dependencies independently, as in a fresh CI checkout; composer.lock and vendor/ are untracked.
RUN composer update --prefer-stable --prefer-dist --no-progress

# Smoke-test the image before accepting it for offline use.
RUN hypervel-services-start \
    && composer test:parallel -- --processes=4 --filter '/Tests.(Encryption|Cookie|Routing)./'
