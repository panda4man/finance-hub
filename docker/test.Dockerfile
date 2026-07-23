# syntax=docker/dockerfile:1
FROM php:8.4-cli-alpine

RUN apk add --no-cache \
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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/test-entrypoint.sh /usr/local/bin/test-entrypoint.sh
RUN chmod +x /usr/local/bin/test-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/test-entrypoint.sh"]
CMD ["php", "artisan", "test", "--compact"]
