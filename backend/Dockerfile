FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libcurl4-openssl-dev libicu-dev libonig-dev libpq-dev libxml2-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath curl dom intl mbstring opcache pdo_pgsql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "php artisan migrate --force && exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
