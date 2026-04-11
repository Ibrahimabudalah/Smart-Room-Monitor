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

# Create SQLite DB
RUN mkdir -p database && touch database/database.sqlite

# Permissions
RUN chmod -R 777 storage bootstrap/cache database

# ⚠️ DO NOT run migrate here (build stage)

# Cache configs
RUN php artisan config:clear
RUN php artisan cache:clear

ENV APP_KEY=base64:0FYFqVlS7NVBC2ygcwyp7ExfWgZGHYrFHE7BcM1oNy8=

# 🚀 Run server + migrate + seed at runtime
CMD php artisan migrate --force && \
    php artisan db:seed --force && \
    php -S 0.0.0.0:$PORT -t public