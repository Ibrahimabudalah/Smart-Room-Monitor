FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    curl \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy files
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Create sqlite DB
RUN touch database/database.sqlite

RUN chmod -R 777 storage bootstrap/cache database

RUN php artisan migrate --force
RUN php artisan config:cache
RUN php artisan route:cache

ENV APP_KEY=base64:0FYFqVlS7NVBC2ygcwyp7ExfWgZGHYrFHE7BcM1oNy8=

# Run server
CMD mkdir -p database && \
    touch database/database.sqlite && \
    php artisan migrate --force --seed && \
    php -S 0.0.0.0:$PORT -t public