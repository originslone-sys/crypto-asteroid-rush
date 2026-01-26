FROM php:8.2-cli

WORKDIR /app

COPY . .

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "/app"]
