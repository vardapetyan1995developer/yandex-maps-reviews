# Production image: a single self-contained container holding nginx, PHP-FPM and
# the queue worker.
#
# It differs from the development compose setup in two deliberate ways. The code
# is copied into the image rather than bind-mounted, so the image is immutable
# and reproducible; and the queue worker runs inside this container rather than
# as its own service, because Render's free plan does not include background
# workers. Where a separate worker process is available, splitting them out is
# the better arrangement.

# --- Stage 1: build the frontend bundle -------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# --- Stage 2: resolve PHP dependencies --------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
COPY . .
# --no-dev drops test and tooling packages; the optimised autoloader avoids a
# filesystem scan on every request
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# --- Stage 3: runtime --------------------------------------------------------
FROM php:8.4-fpm-alpine

# pdo_pgsql because Render provides PostgreSQL; pdo_mysql is kept so the same
# image can run against MySQL elsewhere. intl is required for correct collation
# of Cyrillic text.
RUN apk add --no-cache \
        nginx supervisor gettext \
        icu-dev postgresql-dev oniguruma-dev libzip-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql intl zip bcmath opcache \
    && apk del icu-dev postgresql-dev oniguruma-dev libzip-dev \
    && apk add --no-cache icu-libs libpq libzip

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        # Code never changes inside an immutable image, so skip the mtime check
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

RUN { \
        echo 'memory_limit=256M'; \
        # A parse is a dozen HTTP calls with pauses; the default 30s is far too
        # short for the worker, which runs in this same container
        echo 'max_execution_time=600'; \
        echo 'expose_php=off'; \
    } > /usr/local/etc/php/conf.d/app.ini

# The free plan offers little memory, so the process pool is capped explicitly
# rather than left to grow until the container is killed
RUN { \
        echo '[www]'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 8'; \
        echo 'pm.start_servers = 2'; \
        echo 'pm.min_spare_servers = 1'; \
        echo 'pm.max_spare_servers = 3'; \
        echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www

COPY --from=vendor /app /var/www
COPY --from=assets /app/public/build /var/www/public/build

COPY docker/production/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/production/supervisord.conf /etc/supervisord.conf
COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Render assigns the port at runtime; 10000 is its default and only a fallback
ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
