FROM composer:2.8.10 AS vendor
WORKDIR /build
COPY backend/composer.json backend/composer.lock ./
COPY backend/src ./src
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --classmap-authoritative

FROM php:8.3-fpm-alpine3.23
# Build dependency follows the pinned Alpine base repository.
# hadolint ignore=DL3018
RUN apk add --no-cache libxml2-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install dom mbstring pdo_mysql zip \
    && addgroup -S -g 10001 app \
    && adduser -S -D -H -u 10001 -G app app
WORKDIR /app
COPY --from=vendor --chown=app:app /build/vendor ./vendor
COPY --chown=app:app backend/ ./
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
RUN php vendor/composer/platform_check.php \
    && chmod +x yii \
    && mkdir -p runtime storage/documents \
    && chown -R app:app runtime storage
USER app
EXPOSE 9000
CMD ["php-fpm", "-F"]
