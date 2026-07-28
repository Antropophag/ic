FROM php:8.3-cli-alpine3.23

RUN apk add --no-cache --virtual .coverage-build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .coverage-build-deps

COPY --from=composer:2.8.10 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist
COPY backend/ ./

ENV XDEBUG_MODE=coverage
CMD ["php", "vendor/bin/phpunit", "--coverage-clover", "build/coverage/clover.xml"]
