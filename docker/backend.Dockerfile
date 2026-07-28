FROM composer:2.8.10 AS vendor
WORKDIR /build
COPY backend/composer.json backend/composer.lock ./
COPY backend/src ./src
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --classmap-authoritative

FROM php:8.3-fpm-alpine3.23
# Build dependency follows the pinned Alpine base repository.
# hadolint ignore=DL3018
RUN apk add --no-cache libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && addgroup -S -g 10001 app \
    && adduser -S -D -H -u 10001 -G app app
WORKDIR /app
COPY --from=vendor --chown=app:app /build/vendor ./vendor
COPY --chown=app:app backend/ ./
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
RUN chmod +x yii && mkdir -p runtime storage/documents && chown -R app:app runtime storage
USER app
EXPOSE 9000
CMD ["php-fpm", "-F"]
