FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

COPY . /var/www/html/
RUN mkdir -p /var/www/html/uploads \
	&& chown -R www-data:www-data /var/www/html/uploads
