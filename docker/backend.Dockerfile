FROM docker.io/library/composer:2.8.10 AS vendor-dependencies
WORKDIR /build
COPY backend/composer.json backend/composer.lock ./
# ext-ldap и ext-gd не собраны в этот образ (он только для резолва зависимостей,
# не рантайм) — реальная проверка расширений идёт ниже, platform_check.php уже
# внутри целевого php:8.3-fpm-alpine с установленными расширениями.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --no-autoloader --ignore-platform-req=ext-ldap --ignore-platform-req=ext-gd

FROM vendor-dependencies AS vendor
COPY backend/src ./src
RUN composer dump-autoload --no-dev --no-interaction --classmap-authoritative

FROM vendor-dependencies AS vendor-test-dependencies
RUN composer install --no-interaction --no-progress --prefer-dist \
    --no-autoloader --ignore-platform-req=ext-ldap --ignore-platform-req=ext-gd

FROM vendor-test-dependencies AS vendor-test
COPY backend/src ./src
COPY backend/tests ./tests
RUN composer dump-autoload --no-interaction --classmap-authoritative

FROM docker.io/library/php:8.3-fpm-alpine3.23 AS runtime
# Build dependency follows the pinned Alpine base repository.
# hadolint ignore=DL3018
RUN apk add --no-cache freetype-dev libjpeg-turbo-dev libpng-dev libxml2-dev libzip-dev oniguruma-dev openldap-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install dom gd ldap mbstring pcntl pdo_mysql zip \
    && addgroup -S -g 10001 app \
    && adduser -S -D -H -u 10001 -G app app
WORKDIR /app
COPY --chown=app:app backend/ ./
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/www-logging.conf /usr/local/etc/php-fpm.d/zz-logging.conf
RUN chmod +x yii \
    && mkdir -p runtime storage/documents storage/test-documents \
    && chown -R app:app runtime storage
USER app
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM runtime AS production
COPY --from=vendor --chown=app:app /build/vendor ./vendor
RUN php vendor/composer/platform_check.php

FROM runtime AS test
COPY --from=vendor-test --chown=app:app /build/vendor ./vendor
RUN php vendor/composer/platform_check.php
