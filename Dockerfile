FROM php:7.4-cli

WORKDIR /app

COPY . /app

CMD ["php", "-S", "0.0.0.0:80", "-t", "/app"]
