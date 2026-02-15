FROM php:8.2-apache

# Enable Apache rewrite
RUN a2enmod rewrite

# Install required packages & PHP extensions
RUN apt-get update && apt-get install -y \
    git unzip zip libzip-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && docker-php-ext-enable mysqli pdo pdo_mysql

# Install Composer
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy SSL cert folder (redundant if already in project copy)
COPY certs/ca.pem /var/www/html/certs/ca.pem

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80