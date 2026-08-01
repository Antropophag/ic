FROM composer:2.8.10 AS vendor
WORKDIR /build
COPY backend/composer.json backend/composer.lock ./
COPY backend/src ./src
# ext-ldap не собран в этот образ (он только для резолва зависимостей, не
# рантайм) — реальная проверка расширений идёт ниже, platform_check.php уже
# внутри целевого php:8.3-fpm-alpine с установленным ldap.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --classmap-authoritative --ignore-platform-req=ext-ldap

FROM php:8.3-fpm-alpine3.23
# Build dependency follows the pinned Alpine base repository.
# hadolint ignore=DL3018
RUN apk add --no-cache libxml2-dev libzip-dev oniguruma-dev openldap-dev \
    && docker-php-ext-install dom ldap mbstring pcntl pdo_mysql zip \
    && addgroup -S -g 10001 app \
    && adduser -S -D -H -u 10001 -G app app
WORKDIR /app
COPY --from=vendor --chown=app:app /build/vendor ./vendor
COPY --chown=app:app backend/ ./
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/www-logging.conf /usr/local/etc/php-fpm.d/zz-logging.conf
RUN php vendor/composer/platform_check.php \
    && chmod +x yii \
    && mkdir -p runtime storage/documents storage/test-documents \
    && chown -R app:app runtime storage
USER app
EXPOSE 9000
CMD ["php-fpm", "-F"]
