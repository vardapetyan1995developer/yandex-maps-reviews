FROM php:8.3-fpm-alpine

# intl is needed for correct comparison and collation of Cyrillic strings,
# pdo_mysql for the main database, bcmath is a dependency of some Laravel packages
RUN apk add --no-cache \
        git curl unzip icu-dev oniguruma-dev libzip-dev nodejs npm \
    && docker-php-ext-install pdo_mysql intl zip bcmath opcache \
    && apk del --no-network .build-deps || true

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# OPcache markedly speeds up both the framework itself and the queue worker,
# which has to boot the application on every restart
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Parsing 600 reviews means a dozen requests with pauses; the default 30-second
# limit is far too small for the queue worker
RUN { \
        echo 'memory_limit=512M'; \
        echo 'max_execution_time=600'; \
    } > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www

EXPOSE 9000
CMD ["php-fpm"]
