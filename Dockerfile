FROM php:7.4-apache

# VERSÃO NUCLEAR - Remove completamente e recria
RUN rm -f /usr/sbin/apache2 && \
    apt-get update && \
    apt-get install --reinstall -y apache2 apache2-bin libapache2-mod-php7.4 && \
    a2dismod mpm_event mpm_worker && \
    a2enmod mpm_prefork

RUN docker-php-ext-install mysqli pdo pdo_mysql
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
