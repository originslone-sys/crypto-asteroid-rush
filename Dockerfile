FROM php:7.4-apache

# Remove todos os MPMs primeiro
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load \
    /etc/apache2/mods-enabled/mpm_event.conf \
    /etc/apache2/mods-enabled/mpm_worker.load \
    /etc/apache2/mods-enabled/mpm_worker.conf \
    /etc/apache2/mods-enabled/mpm_prefork.load \
    /etc/apache2/mods-enabled/mpm_prefork.conf

# Ativa somente o mpm_prefork
RUN ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
 && ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Instala extensões PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copia seu projeto
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
