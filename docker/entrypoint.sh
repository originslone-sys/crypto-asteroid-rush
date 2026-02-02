#!/bin/sh
set -eu

echo "🚀 Iniciando Crypto Asteroid Rush..."
echo "✅ Cloud Run + Cloud SQL attachment ativo (sem proxy local)."
echo "🚀 Iniciando Supervisor (Nginx + PHP-FPM)..."

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
