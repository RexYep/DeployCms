FROM php:8.2-apache

# Enable Apache rewrite
RUN a2enmod rewrite

# Install MySQL extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . /var/www/html/

WORKDIR /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy cert folder
COPY certs/ca.pem /var/www/html/certs/ca.pem

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80