#!/bin/sh
set -eu

echo "🚀 Iniciando Crypto Asteroid Rush..."
echo "📡 Inicializando Cloud SQL Auth Proxy (v2)..."

# Instance connection name (preferir via ENV)
# Exemplo: project-7be1cae5-5f08-45fb-aca:us-central1:unobix
CLOUDSQL_INSTANCE="${CLOUDSQL_INSTANCE:-project-7be1cae5-5f08-45fb-aca:us-central1:unobix}"

# Porta local onde o proxy vai escutar (MySQL padrão)
CLOUDSQL_PORT="${CLOUDSQL_PORT:-3306}"

echo "🔧 Instance: ${CLOUDSQL_INSTANCE}"
echo "🔧 Local TCP: 127.0.0.1:${CLOUDSQL_PORT}"

# Inicia o proxy em background (v2 syntax)
# OBS: v2 aceita o instance name como argumento posicional.
# Flags: --address / --port
/cloud_sql_proxy \
  --address 127.0.0.1 \
  --port "${CLOUDSQL_PORT}" \
  --verbose \
  "${CLOUDSQL_INSTANCE}" \
  &

PROXY_PID="$!"

# Aguarda o proxy "subir" (checando se o processo continua vivo)
echo "⏳ Aguardando Cloud SQL Proxy iniciar..."
i=0
while [ $i -lt 15 ]; do
  if kill -0 "$PROXY_PID" 2>/dev/null; then
    # Proxy ainda está rodando
    sleep 1
    i=$((i+1))
  else
    echo "❌ Cloud SQL Proxy morreu ao iniciar (PID ${PROXY_PID})."
    exit 1
  fi
done

echo "✅ Cloud SQL Proxy em execução (PID ${PROXY_PID})."

# Iniciar aplicação principal
echo "🚀 Iniciando Supervisor (Nginx + PHP-FPM)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
