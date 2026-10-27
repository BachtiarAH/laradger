# syntax=docker/dockerfile:1

# Laradger is an API-only Laravel application: no Node/Vite build step is required,
# the single Blade page falls back to its inline stylesheet when no manifest exists.
ARG PHP_VERSION=8.5

# ---------------------------------------------------------------------------
# base: runtime used by every other stage
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS base

ENV APP_HOME=/var/www/html \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        sqlite3 \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR ${APP_HOME}

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm", "-F"]

# ---------------------------------------------------------------------------
# development: source is bind-mounted, dependencies live in a volume
# ---------------------------------------------------------------------------
FROM base AS development

ARG WITH_XDEBUG=false

RUN if [ "${WITH_XDEBUG}" = "true" ]; then \
        apt-get update \
        && apt-get install -y --no-install-recommends ${PHPIZE_DEPS} \
        && pecl install xdebug \
        && docker-php-ext-enable xdebug \
        && rm -rf /var/lib/apt/lists/* /tmp/pear; \
    fi

COPY docker/php/php-dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

# ---------------------------------------------------------------------------
# production: dependencies baked in, runs as www-data
# ---------------------------------------------------------------------------
FROM base AS production

ENV APP_ENV=production

COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

# The image already runs as www-data, so the pool-level user/group directives
# would only produce "directive is ignored" notices.
RUN sed -i '/^user = /d; /^group = /d' /usr/local/etc/php-fpm.d/www.conf

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY . .

RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan package:discover --ansi \
    && mkdir -p /var/lib/laradger \
    && chown -R www-data:www-data storage bootstrap/cache /var/lib/laradger \
    && chmod -R ug+rwX storage bootstrap/cache

USER www-data

# ---------------------------------------------------------------------------
# webserver: nginx front door, static assets baked from the build context
# ---------------------------------------------------------------------------
FROM nginx:1.29-alpine AS webserver

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY public/ /var/www/html/public/

RUN mkdir -p /var/www/html/storage/app/public \
    && chown -R 33:33 /var/www/html/storage
