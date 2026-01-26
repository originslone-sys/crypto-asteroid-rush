FROM php:7.4-apache-bullseye

# SOLUÇÃO: Força apenas um MPM via linha de comando
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf && \
    a2dismod mpm_event mpm_worker --force && \
    a2enmod mpm_prefork rewrite && \
    echo "Mutex posixsem" >> /etc/apache2/apache2.conf

# PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Seu projeto
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# COMANDO CRÍTICO: Força o MPM na inicialização
CMD ["sh", "-c", "a2dismod mpm_event mpm_worker --force; a2enmod mpm_prefork; apache2-foreground"]
