# ===========================================
# Crypto Asteroid Rush - Dockerfile Dinâmico
# Auto-ajusta workers baseado na RAM disponível
# ===========================================

FROM php:8.2-fpm-alpine

# Instala Nginx, Supervisor e dependências
RUN apk add --no-cache \
    nginx \
    supervisor \
    bash \
    curl \
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

# Cria diretórios necessários
RUN mkdir -p /run/nginx /var/log/supervisor

# Copia arquivos de configuração
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/auto-scale.sh /usr/local/bin/auto-scale.sh
COPY docker/php-fpm-dynamic.conf /usr/local/etc/php-fpm.d/www.conf

# Permissão de execução para o script
RUN chmod +x /usr/local/bin/auto-scale.sh

# ===========================================
# Cloud SQL Auth Proxy (corrigido)
# Baixa via GitHub Releases (evita curl 22 / 404)
# ===========================================
ARG CLOUD_SQL_PROXY_VERSION=2.21.0
RUN curl -fsSL -o /cloud_sql_proxy \
      https://github.com/GoogleCloudPlatform/cloud-sql-proxy/releases/download/v${CLOUD_SQL_PROXY_VERSION}/cloud-sql-proxy.linux.amd64 \
    && chmod +x /cloud_sql_proxy

# Copia a aplicação
WORKDIR /app
COPY . .

# Remove pasta docker da aplicação (não precisa servir)
RUN rm -rf /app/docker

# Permissões corretas
RUN chown -R www-data:www-data /app \
    && chmod -R 755 /app

# Porta exposta
EXPOSE 8080

# Script de inicialização com Cloud SQL Proxy
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
