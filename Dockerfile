FROM php:8.2-fpm

# Install system deps for PHP extensions
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libzip-dev libicu-dev libpq-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_pgsql bcmath intl zip opcache gd \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy Laravel files & install dependencies
COPY . .

# Copy the Docker-specific environment file as .env
COPY .env.docker .env

RUN composer install --no-interaction --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www && chmod -R 755 storage bootstrap/cache

USER www-data

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
