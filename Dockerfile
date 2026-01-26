FROM php:7.4-apache
# Para debug
RUN echo "=== MPMs HABILITADOS ===" > /tmp/debug.log && \
    ls -la /etc/apache2/mods-enabled/mpm* 2>/dev/null >> /tmp/debug.log || echo "Nenhum" >> /tmp/debug.log && \
    echo "=== CONFIG TEST ===" >> /tmp/debug.log && \
    apache2ctl configtest 2>&1 >> /tmp/debug.log
# SOLUÇÃO: Remove TODOS os arquivos de MPM e cria APENAS UM
RUN rm -f /etc/apache2/mods-enabled/mpm_*.conf /etc/apache2/mods-enabled/mpm_*.load 2>/dev/null || true
RUN echo "LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so" > /etc/apache2/mods-enabled/mpm_prefork.load
RUN echo "Mutex posixsem" >> /etc/apache2/apache2.conf
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Extensões PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Seu projeto
COPY . /var/www/html/

# Permissões
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Comando de inicialização VERIFICADO
CMD ["apache2-foreground"]
