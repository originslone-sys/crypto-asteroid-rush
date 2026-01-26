FROM php:7.4-apache

# 1. Corrige MPM de uma vez por todas
RUN a2dismod mpm_event mpm_worker --force
RUN a2enmod mpm_prefork rewrite
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# 2. Extensões PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 3. Copia TUDO para o Apache
COPY . /var/www/html/

# 4. Permissões básicas
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# 5. Comando SIMPLES que funciona
CMD ["apache2-foreground"]
