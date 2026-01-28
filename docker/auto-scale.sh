#!/bin/bash
# ===========================================
# Auto-Scale Script - Crypto Asteroid Rush
# v2.0 - Detecta RAM do CONTAINER (não do host)
# ===========================================

set -e

echo "=========================================="
echo "🚀 Crypto Asteroid Rush - Auto Scale v2.0"
echo "=========================================="

# ===========================================
# DETECTA MEMÓRIA DO CONTAINER
# Prioridade: cgroup v2 → cgroup v1 → /proc/meminfo
# ===========================================

get_container_memory_mb() {
    local mem_bytes=0
    
    # Tenta cgroup v2 (Railway, Docker moderno)
    if [ -f "/sys/fs/cgroup/memory.max" ]; then
        mem_bytes=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || echo "0")
        # "max" significa sem limite, usa /proc/meminfo
        if [ "$mem_bytes" = "max" ]; then
            mem_bytes=0
        fi
    fi
    
    # Tenta cgroup v1 (Docker antigo)
    if [ "$mem_bytes" = "0" ] || [ -z "$mem_bytes" ]; then
        if [ -f "/sys/fs/cgroup/memory/memory.limit_in_bytes" ]; then
            mem_bytes=$(cat /sys/fs/cgroup/memory/memory.limit_in_bytes 2>/dev/null || echo "0")
            # Valor muito alto significa sem limite
            if [ "$mem_bytes" -gt 100000000000000 ] 2>/dev/null; then
                mem_bytes=0
            fi
        fi
    fi
    
    # Fallback: variável de ambiente do Railway
    if [ "$mem_bytes" = "0" ] || [ -z "$mem_bytes" ]; then
        if [ -n "$RAILWAY_MEMORY_MB" ]; then
            echo "$RAILWAY_MEMORY_MB"
            return
        fi
    fi
    
    # Fallback: assume 512MB (Railway free tier)
    if [ "$mem_bytes" = "0" ] || [ -z "$mem_bytes" ]; then
        echo "512"
        return
    fi
    
    # Converte bytes para MB
    echo $((mem_bytes / 1024 / 1024))
}

TOTAL_RAM_MB=$(get_container_memory_mb)

# Validação: se ainda for um valor absurdo, assume 512MB
if [ "$TOTAL_RAM_MB" -gt 32768 ] 2>/dev/null; then
    echo "⚠️  RAM detectada muito alta ($TOTAL_RAM_MB MB), assumindo 512MB (free tier)"
    TOTAL_RAM_MB=512
fi

echo "📊 RAM do Container: ${TOTAL_RAM_MB}MB"

# ===========================================
# TABELA DE ESCALA
# Cada worker PHP-FPM usa ~30-40MB
# Reservamos 30% para Nginx, Sistema, Buffer
# ===========================================

if [ "$TOTAL_RAM_MB" -le 512 ]; then
    # Railway Free: 512MB
    PM_MAX_CHILDREN=15
    PM_START_SERVERS=2
    PM_MIN_SPARE=1
    PM_MAX_SPARE=5
    EXPECTED_USERS="200-500"
    OPCACHE_MEMORY=128
    NGINX_WORKERS="auto"
    
elif [ "$TOTAL_RAM_MB" -le 1024 ]; then
    # 1GB RAM
    PM_MAX_CHILDREN=30
    PM_START_SERVERS=5
    PM_MIN_SPARE=3
    PM_MAX_SPARE=10
    EXPECTED_USERS="500-1000"
    OPCACHE_MEMORY=128
    NGINX_WORKERS="auto"
    
elif [ "$TOTAL_RAM_MB" -le 2048 ]; then
    # 2GB RAM
    PM_MAX_CHILDREN=50
    PM_START_SERVERS=10
    PM_MIN_SPARE=5
    PM_MAX_SPARE=20
    EXPECTED_USERS="1000-1500"
    OPCACHE_MEMORY=256
    NGINX_WORKERS=2
    
elif [ "$TOTAL_RAM_MB" -le 4096 ]; then
    # 4GB RAM
    PM_MAX_CHILDREN=100
    PM_START_SERVERS=20
    PM_MIN_SPARE=10
    PM_MAX_SPARE=40
    EXPECTED_USERS="1500-2500"
    OPCACHE_MEMORY=256
    NGINX_WORKERS=4
    
elif [ "$TOTAL_RAM_MB" -le 8192 ]; then
    # 8GB RAM
    PM_MAX_CHILDREN=200
    PM_START_SERVERS=40
    PM_MIN_SPARE=20
    PM_MAX_SPARE=80
    EXPECTED_USERS="2500-5000"
    OPCACHE_MEMORY=512
    NGINX_WORKERS=4
    
else
    # 8GB+ RAM
    PM_MAX_CHILDREN=300
    PM_START_SERVERS=60
    PM_MIN_SPARE=30
    PM_MAX_SPARE=120
    EXPECTED_USERS="5000+"
    OPCACHE_MEMORY=512
    NGINX_WORKERS=8
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
; RAM Container: ${TOTAL_RAM_MB}MB
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

cat > /usr/local/etc/php/conf.d/zz-opcache-dynamic.ini << EOF
; OPcache dinâmico - RAM: ${TOTAL_RAM_MB}MB
opcache.memory_consumption=${OPCACHE_MEMORY}
EOF

echo "✅ OPcache ajustado para ${OPCACHE_MEMORY}MB"

# ===========================================
# AJUSTA NGINX WORKERS
# ===========================================

sed -i "s/worker_processes auto;/worker_processes ${NGINX_WORKERS};/" /etc/nginx/nginx.conf

echo "✅ Nginx workers: ${NGINX_WORKERS}"
echo "=========================================="
echo "🎮 Iniciando servidores..."
echo "=========================================="

# Inicia Supervisor (que gerencia Nginx + PHP-FPM)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
