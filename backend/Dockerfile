# RIVA-113 (directive §18): production image for the Laravel monolith.
# Explicitly NOT a Kubernetes/microservices setup — a single PHP-FPM
# container behind the nginx container defined in docker-compose.yml, per
# the master directive's constraint to keep the existing monolith.
#
# Multi-stage: the "vendor" stage runs `composer install` in an image that
# has the PHP CLI + build tools, so the final runtime image never carries
# Composer, dev dependencies, or build-time-only packages.

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# --no-scripts: composer.json's post-install scripts (package:discover etc.)
# need the full Laravel app present, which isn't copied in yet at this
# layer — they run for real in the runtime stage below via the entrypoint,
# after the full source is in place. --no-dev: phpunit/mockery/pint etc.
# have no business in a production image.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

FROM php:8.2-fpm-alpine AS runtime

LABEL org.opencontainers.image.title="riva-backend"

# System packages needed by the PHP extensions below, plus a couple of
# small utilities used by the entrypoint script (envsubst-free — we rely on
# artisan for config caching instead).
RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        zip \
        gd \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# COST (audit §19/§21 "OPcache"): this is the one line that actually
# matters for the directive's "OPcache enabled" requirement — without it,
# every request recompiles every PHP file from scratch. validate_timestamps
# is 0 (never re-check the filesystem) because this is a built, immutable
# image — a new deploy means a new image, not an edited file underneath a
# running container. Local dev (docker-compose.yml's bind-mounted backend
# service) overrides this back to 1 so code changes are picked up live.
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-riva.ini

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

# The web server user needs to own storage/ and bootstrap/cache/ — these
# are the only two directories Laravel writes to at runtime (logs, compiled
# views, framework cache, sessions-if-file-driver). Everything else in the
# image is intentionally read-only from the app's perspective.
RUN addgroup -g 1000 riva \
    && adduser -G riva -u 1000 -D riva \
    && chown -R riva:riva /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER riva

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
