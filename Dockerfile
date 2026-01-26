FROM php:7.4-apache

# Remove qualquer MPM extra diretamente dos arquivos
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load \
    && rm -f /etc/apache2/mods-enabled/mpm_worker.load \
    && rm -f /etc/apache2/mods-enabled/mpm_event.conf \
    && rm -f /etc/apache2/mods-enabled/mpm_worker.conf \
    && ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Instala extensões PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copia seu projeto
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
