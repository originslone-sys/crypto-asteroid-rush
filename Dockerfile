FROM php:7.4-apache

# 1. Primeiro, desabilite TODOS os MPMs usando os comandos oficiais do Apache
RUN a2dismod mpm_event mpm_worker

# 2. Garanta que apenas o mpm_prefork está habilitado
RUN a2enmod mpm_prefork

# 3. Opcional: Verifique quais MPMs estão ativos
RUN ls -la /etc/apache2/mods-enabled/ | grep mpm

# 4. Instala extensões PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 5. Copia seu projeto
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
