# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-libs \
    icu-libs \
    libzip \
    libpng \
    freetype \
    libjpeg-turbo \
  && apk add --no-cache --virtual .build-deps \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    libpng-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    $PHPIZE_DEPS \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) pdo_pgsql pcntl bcmath intl zip opcache gd \
  && apk del .build-deps

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY . .

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker-entrypoint.sh ./
RUN chmod +x docker-entrypoint.sh

RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["./docker-entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
