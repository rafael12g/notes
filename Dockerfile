FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

COPY take.php /var/www/html/index.php
