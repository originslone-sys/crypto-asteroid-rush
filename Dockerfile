FROM php:7.4-apache

# Remove outros MPMs e garante apenas prefork
RUN a2dismod mpm_event || true \
    && a2dismod mpm_worker || true \
    && a2enmod mpm_prefork

# Instala extensões do PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
