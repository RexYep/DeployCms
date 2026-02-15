FROM php:8.2-apache

RUN a2enmod rewrite

RUN docker-php-ext-install mysqli pdo pdo_mysql

COPY . /var/www/html/

COPY certs/ca.pem /var/www/html/certs/ca.pem

RUN chown -R www-data:www-data /var/www/html/