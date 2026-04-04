#!/bin/sh
set -eu

echo "🚀 Iniciando Crypto Asteroid Rush..."
echo "✅ Cloud Run + Cloud SQL attachment ativo (sem proxy local)."

# Iniciar PHP-FPM em background
echo "🚀 Iniciando PHP-FPM..."
/usr/local/sbin/php-fpm -D

# Iniciar Nginx em foreground (Cloud Run requer processo em foreground)
echo "🚀 Iniciando Nginx na porta 8080..."
exec /usr/sbin/nginx -g 'daemon off;'
