FROM php:7.4-apache

# Remove qualquer configuração de MPM existente
RUN rm -rf /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf

# Habilita explicitamente apenas o prefork
RUN a2enmod mpm_prefork

# Verifica e define o MPM explicitamente
RUN echo "LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so" > /etc/apache2/mods-available/mpm_prefork.load
RUN echo "<IfModule mpm_prefork_module>\n    StartServers            5\n    MinSpareServers         5\n    MaxSpareServers        10\n    MaxRequestWorkers      150\n    MaxConnectionsPerChild   0\n</IfModule>" > /etc/apache2/mods-available/mpm_prefork.conf

# Cria os links simbólicos
RUN ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
RUN ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Instala extensões PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copia seu projeto
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
