#!/bin/bash
# ===========================================
# Auto-Scale Script - Crypto Asteroid Rush
# Detecta RAM disponível e ajusta PHP-FPM
# ===========================================

set -e

echo "=========================================="
echo "🚀 Crypto Asteroid Rush - Auto Scale"
echo "=========================================="

# Detecta memória total em MB
TOTAL_RAM_KB=$(grep MemTotal /proc/meminfo | awk '{print $2}')
TOTAL_RAM_MB=$((TOTAL_RAM_KB / 1024))

echo "📊 RAM Detectada: ${TOTAL_RAM_MB}MB"

# ===========================================
# TABELA DE ESCALA
# Cada worker PHP-FPM usa ~30-40MB
# Reservamos 30% para Nginx, MySQL, Sistema
# ===========================================

if [ $TOTAL_RAM_MB -le 512 ]; then
    # Railway Free: 512MB
    PM_MAX_CHILDREN=15
    PM_START_SERVERS=2
    PM_MIN_SPARE=1
    PM_MAX_SPARE=5
    EXPECTED_USERS="200-500"
    
elif [ $TOTAL_RAM_MB -le 1024 ]; then
    # 1GB RAM
    PM_MAX_CHILDREN=30
    PM_START_SERVERS=5
    PM_MIN_SPARE=3
    PM_MAX_SPARE=10
    EXPECTED_USERS="500-1000"
    
elif [ $TOTAL_RAM_MB -le 2048 ]; then
    # 2GB RAM
    PM_MAX_CHILDREN=50
    PM_START_SERVERS=10
    PM_MIN_SPARE=5
    PM_MAX_SPARE=20
    EXPECTED_USERS="1000-1500"
    
elif [ $TOTAL_RAM_MB -le 4096 ]; then
    # 4GB RAM
    PM_MAX_CHILDREN=100
    PM_START_SERVERS=20
    PM_MIN_SPARE=10
    PM_MAX_SPARE=40
    EXPECTED_USERS="1500-2500"
    
elif [ $TOTAL_RAM_MB -le 8192 ]; then
    # 8GB RAM
    PM_MAX_CHILDREN=200
    PM_START_SERVERS=40
    PM_MIN_SPARE=20
    PM_MAX_SPARE=80
    EXPECTED_USERS="2500-5000"
    
else
    # 8GB+ RAM
    PM_MAX_CHILDREN=300
    PM_START_SERVERS=60
    PM_MIN_SPARE=30
    PM_MAX_SPARE=120
    EXPECTED_USERS="5000+"
fi

echo "⚙️  Configuração PHP-FPM:"
echo "   - Max Workers: $PM_MAX_CHILDREN"
echo "   - Start Servers: $PM_START_SERVERS"
echo "   - Min Spare: $PM_MIN_SPARE"
echo "   - Max Spare: $PM_MAX_SPARE"
echo "👥 Capacidade estimada: $EXPECTED_USERS jogadores simultâneos"
echo "=========================================="

# ===========================================
# GERA CONFIGURAÇÃO PHP-FPM DINÂMICA
# ===========================================

cat > /usr/local/etc/php-fpm.d/zz-dynamic.conf << EOF
[www]
; ===========================================
; Configuração gerada automaticamente
; RAM: ${TOTAL_RAM_MB}MB
; Capacidade: ${EXPECTED_USERS} jogadores
; ===========================================

pm = dynamic
pm.max_children = ${PM_MAX_CHILDREN}
pm.start_servers = ${PM_START_SERVERS}
pm.min_spare_servers = ${PM_MIN_SPARE}
pm.max_spare_servers = ${PM_MAX_SPARE}
pm.max_requests = 500

; Timeout e limites
request_terminate_timeout = 60s
request_slowlog_timeout = 10s

; Status page (para monitoramento)
pm.status_path = /fpm-status
ping.path = /fpm-ping
ping.response = pong
EOF

echo "✅ Configuração PHP-FPM gerada!"

# ===========================================
# AJUSTA OPCACHE BASEADO NA RAM
# ===========================================

if [ $TOTAL_RAM_MB -ge 2048 ]; then
    # Mais RAM = mais cache
    OPCACHE_MEMORY=256
else
    OPCACHE_MEMORY=128
fi

cat > /usr/local/etc/php/conf.d/zz-opcache-dynamic.ini << EOF
; OPcache dinâmico - RAM: ${TOTAL_RAM_MB}MB
opcache.memory_consumption=${OPCACHE_MEMORY}
EOF

echo "✅ OPcache ajustado para ${OPCACHE_MEMORY}MB"

# ===========================================
# AJUSTA NGINX WORKERS BASEADO NA RAM
# ===========================================

if [ $TOTAL_RAM_MB -ge 4096 ]; then
    NGINX_WORKERS=4
elif [ $TOTAL_RAM_MB -ge 2048 ]; then
    NGINX_WORKERS=2
else
    NGINX_WORKERS="auto"
fi

# Atualiza nginx.conf com workers corretos
sed -i "s/worker_processes auto;/worker_processes ${NGINX_WORKERS};/" /etc/nginx/nginx.conf

echo "✅ Nginx workers: ${NGINX_WORKERS}"
echo "=========================================="
echo "🎮 Iniciando servidores..."
echo "=========================================="

# Inicia Supervisor (que gerencia Nginx + PHP-FPM)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
