# Dockerfile
FROM php:7.4-apache

# Instala extensões comuns (ajuste se precisar de outras)
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev zlib1g-dev libzip-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql zip mbstring

# Habilita mod_rewrite (para URLs amigáveis)
RUN a2enmod rewrite

# Copia todo o projeto
COPY . /var/www/html/

# Corrige permissões (opcional, mas evita erros em Railway)
RUN chown -R www-data:www-data /var/www/html/ \
    && chmod -R 755 /var/www/html/

EXPOSE 80
