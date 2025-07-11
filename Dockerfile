FROM composer:latest AS composer

FROM php:8.4-fpm-alpine

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache \
    postgresql-dev \
    supervisor \
    nginx \
    && docker-php-ext-install pdo_pgsql pcntl opcache

COPY ./docker/supervisord.conf /etc/supervisord.conf

COPY ./docker/php.ini /usr/local/etc/php/php.ini

COPY ./docker/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini

COPY . /app
WORKDIR /app

RUN composer install -o -a --apcu-autoloader --no-dev

EXPOSE 8000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
