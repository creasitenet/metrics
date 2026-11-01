# syntax=docker/dockerfile:1.7
###############################################################################
# Composer stage: copy only the official binary so PHP and Composer do not
# need to be installed separately in the final image.
###############################################################################
FROM composer:2.8 AS composer

###############################################################################
# Runtime stage: official FrankenPHP image on PHP 8.5 (Caddy and PHP in a
# single static binary). The base image runs as root, but the entrypoint
# switches to the provided non-root www-data user, compatible with bind mounts.
###############################################################################
FROM dunglas/frankenphp:1-php8.5-alpine AS runtime

# FrankenPHP already provides OPcache and PDO SQLite. intl is required by Symfony;
# pcntl lets the collector loop handle SIGTERM/SIGINT and stop on a clean cycle.
RUN set -eux; \
    apk add --no-cache \
        icu-libs \
        tini \
        su-exec; \
    apk add --no-cache --virtual .build-deps \
        icu-dev \
        $PHPIZE_DEPS; \
    docker-php-ext-install -j"$(nproc)" intl pcntl; \
    apk del .build-deps; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    echo "memory_limit = 256M" > "$PHP_INI_DIR/conf.d/zz-memory.ini"

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/opcache.ini $PHP_INI_DIR/conf.d/opcache.ini
COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /var/www/html

# Install dependencies in two steps to take advantage of the Docker cache.
COPY composer.json composer.lock* symfony.lock* ./
RUN set -eux; \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts --no-autoloader || \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

# Copy the remaining source code and generate the optimized autoloader.
COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    mkdir -p var/cache var/log var/data; \
    chown -R www-data:www-data var; \
    chmod +x /usr/local/bin/app-entrypoint

# Default image environment variables.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    CADDY_GLOBAL_OPTIONS="" \
    CADDY_TRUSTED_PROXIES="private_ranges"

EXPOSE 80
VOLUME ["/var/www/html/var/data"]

# The entrypoint runs as root to adjust bind-mount permissions, then switches
# to www-data through su-exec before starting FrankenPHP.
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
