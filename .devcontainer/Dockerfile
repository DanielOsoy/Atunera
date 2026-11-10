FROM php:8.2-apache

# gd + fuente DejaVu: dibujan el captcha del inicio de sesión
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libfreetype6-dev fonts-dejavu-core \
    && docker-php-ext-configure gd --with-freetype \
    && docker-php-ext-install pdo pdo_mysql mysqli gd \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

COPY .devcontainer/apache-seguridad.conf /etc/apache2/conf-enabled/zz-seguridad.conf

WORKDIR /var/www/html
