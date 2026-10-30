FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

COPY .devcontainer/apache-seguridad.conf /etc/apache2/conf-enabled/zz-seguridad.conf

WORKDIR /var/www/html
