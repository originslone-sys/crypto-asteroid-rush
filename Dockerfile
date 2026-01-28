# ===========================================
# Crypto Asteroid Rush - Dockerfile Otimizado
# Nginx + PHP-FPM para Alta Escala
# ===========================================

FROM php:8.2-fpm-alpine

# Instala Nginx e dependências necessárias
RUN apk add --no-cache \
    nginx \
    supervisor \
    && docker-php-ext-install pdo_mysql mysqli \
    && rm -rf /var/cache/apk/*

# Habilita OPcache para performance
RUN docker-php-ext-enable opcache

# Configuração do OPcache otimizada para produção
RUN echo 'opcache.enable=1' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.memory_consumption=128' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.interned_strings_buffer=8' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.max_accelerated_files=4000' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.revalidate_freq=60' >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo 'opcache.fast_shutdown=1' >> /usr/local/etc/php/conf.d/opcache.ini

# Configuração do PHP-FPM otimizada para Railway Free (512MB RAM)
RUN echo '[www]' > /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo 'pm = dynamic' >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo 'pm.max_children = 15' >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo 'pm.start_servers = 2' >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo 'pm.min_spare_servers = 1' >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo 'pm.max_spare_servers = 5' >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo 'pm.max_requests = 500' >> /usr/local/etc/php-fpm.d/zz-docker.conf

# Configuração do Nginx
RUN mkdir -p /run/nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Configuração do Supervisor (gerencia Nginx + PHP-FPM)
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copia a aplicação
WORKDIR /app
COPY . .

# Permissões corretas
RUN chown -R www-data:www-data /app \
    && chmod -R 755 /app

# Porta exposta
EXPOSE 80

# Inicia Supervisor (que gerencia Nginx e PHP-FPM)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
