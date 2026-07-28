FROM composer:2.8.10 AS vendor
WORKDIR /build
COPY backend/composer.json backend/composer.lock ./
COPY backend/src ./src
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --classmap-authoritative

FROM php:8.3-fpm-alpine3.23
RUN docker-php-ext-install pdo_mysql \
    && addgroup -S -g 10001 app \
    && adduser -S -D -H -u 10001 -G app app
WORKDIR /app
COPY --from=vendor --chown=app:app /build/vendor ./vendor
COPY --chown=app:app backend/ ./
RUN chmod +x yii && mkdir -p runtime && chown app:app runtime
USER app
EXPOSE 9000
CMD ["php-fpm", "-F"]
