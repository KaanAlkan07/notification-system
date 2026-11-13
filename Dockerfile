FROM composer:2 AS composer_binary
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer_binary /usr/bin/composer /usr/local/bin/composer
WORKDIR /app

COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY . ./
RUN mkdir -p .phpunit.cache bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-interaction \
    && chown -R www-data:www-data .phpunit.cache bootstrap/cache storage

USER www-data
ENV COMPOSER_HOME=/tmp/composer
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
