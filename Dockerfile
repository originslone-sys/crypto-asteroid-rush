FROM php:8.2-fpm

# Instala nginx
RUN apt-get update && apt-get install -y nginx

# Copia nginx config
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Instala extensões PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copia projeto
COPY . /var/www/html

WORKDIR /var/www/html

# Permissões
RUN chown -R www-data:www-data /var/www/html

# Expõe porta
EXPOSE 80

# Inicia PHP-FPM + Nginx
CMD service nginx start && php-fpm
