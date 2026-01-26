FROM php:8.2-cli

WORKDIR /app
COPY . .

# Instala drivers MySQL para PDO e mysqli
RUN docker-php-ext-install pdo_mysql mysqli

EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "-t", "/app"]
