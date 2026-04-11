FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    unzip \
    git \
    curl \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN mkdir -p database && touch database/database.sqlite

RUN php artisan config:cache

RUN php artisan route:cache

RUN chmod -R 777 storage bootstrap/cache database

ENV APP_KEY=base64:0FYFqVlS7NVBC2ygcwyp7ExfWgZGHYrFHE7BcM1oNy8=

CMD php artisan migrate --force && \
    php artisan db:seed --force && \
    php -S 0.0.0.0:$PORT -t public