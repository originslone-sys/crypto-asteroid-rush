FROM php:7.4-apache

# SOLUÇÃO DEFINITIVA
RUN echo "" > /etc/apache2/mods-enabled/mpm_prefork.load && \
    echo "LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so" > /etc/apache2/mods-enabled/mpm_prefork.load

RUN docker-php-ext-install mysqli pdo pdo_mysql
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
