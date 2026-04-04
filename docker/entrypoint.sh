#!/bin/sh
set -eu

echo "🚀 Iniciando Crypto Asteroid Rush..."
echo "✅ Cloud Run + Cloud SQL attachment ativo (sem proxy local)."

# Substituir variáveis de ambiente no nginx.conf
# PVP_GAME_SERVER_HOST: endereço do Game Server PvP (usa IP interno VPC por padrão)
export PVP_GAME_SERVER_HOST="${PVP_GAME_SERVER_HOST:-10.0.0.2:3000}"
echo "🎮 PvP Game Server: ${PVP_GAME_SERVER_HOST}"
envsubst '${PVP_GAME_SERVER_HOST}' < /etc/nginx/nginx.conf > /tmp/nginx.conf
cp /tmp/nginx.conf /etc/nginx/nginx.conf

# Iniciar PHP-FPM em background
echo "🚀 Iniciando PHP-FPM..."
/usr/local/sbin/php-fpm -D

# Iniciar Nginx em foreground (Cloud Run requer processo em foreground)
echo "🚀 Iniciando Nginx na porta 8080..."
exec /usr/sbin/nginx -g 'daemon off;'
