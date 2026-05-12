FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl unzip libicu-dev libzip-dev libpng-dev libjpeg-dev \
    libfreetype6-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo pdo_mysql intl zip gd opcache mbstring xml ctype iconv \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for layer caching
COPY composer.json composer.lock ./

# Install PHP dependencies (no dev, no scripts yet)
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

# Copy the rest of the app
COPY . .

# Run post-install scripts
RUN composer dump-autoload --optimize --no-dev

# Set permissions for Symfony
RUN mkdir -p var/cache var/log var/uploads \
    && chown -R www-data:www-data var/ public/uploads/ \
    && chmod -R 775 var/ public/uploads/

EXPOSE 9000
CMD ["php-fpm"]
