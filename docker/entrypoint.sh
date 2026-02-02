#!/bin/sh

echo "🚀 Iniciando Crypto Asteroid Rush..."
echo "📡 Conectando ao Cloud SQL..."

# Iniciar Cloud SQL Proxy em background
# Conecta ao Cloud SQL instance: project-7be1cae5-5f08-45fb-aca:us-west1:unobix
/cloud_sql_proxy \
  -instances=project-7be1cae5-5f08-45fb-aca:us-west1:unobix=tcp:3306 \
  -verbose \
  &

# Aguardar proxy conectar
echo "⏳ Aguardando conexão com Cloud SQL..."
sleep 8

# Verificar se proxy está rodando
if ps aux | grep -q "[c]loud_sql_proxy"; then
    echo "✅ Cloud SQL Proxy conectado"
else
    echo "❌ Cloud SQL Proxy falhou"
fi

# Iniciar aplicação principal
echo "🚀 Iniciando Nginx + PHP-FPM..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf