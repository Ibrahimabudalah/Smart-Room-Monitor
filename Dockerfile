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

# Run server
CMD php artisan serve --host=0.0.0.0 --port=10000