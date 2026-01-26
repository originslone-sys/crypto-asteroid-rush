FROM php:7.4-apache

# CORREÇÃO DO ERRO: Remove conflitos de MPM
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load && \
    rm -f /etc/apache2/mods-enabled/mpm_*.conf && \
    ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/

# Extensões PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copia projeto
COPY . /var/www/html/

# Permissões
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
