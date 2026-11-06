FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip oniguruma libpng libjpeg-turbo libwebp freetype \
    && apk add --no-cache --virtual .build icu-dev libzip-dev oniguruma-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip opcache pcntl gd \
    && apk del .build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY backend/ .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
