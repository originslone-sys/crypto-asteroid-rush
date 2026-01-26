FROM php:7.4-apache

# Remove configurações padrão do Apache que podem causar conflito
RUN rm -f /etc/apache2/apache2.conf

# Copia nossa configuração limpa do Apache
COPY apache-config.conf /etc/apache2/apache2.conf

# Remove qualquer configuração de MPM existente
RUN rm -rf /etc/apache2/mods-enabled/mpm_*.conf /etc/apache2/mods-enabled/mpm_*.load 2>/dev/null || true

# Instala extensões PHP necessárias
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Habilita mod_rewrite (se necessário)
RUN a2enmod rewrite

# Copia o projeto para o diretório do Apache
COPY . /var/www/html/

# Ajusta permissões
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Expõe a porta 80
EXPOSE 80

# Comando para iniciar o Apache
CMD ["apache2-foreground"]
