#!/bin/bash
# ===========================================
# Auto-Scale Script - Crypto Asteroid Rush
# v2.1 - Cloud Run safe sizing (cgroup aware)
# ===========================================

set -euo pipefail

echo "=========================================="
echo "🚀 Crypto Asteroid Rush - Auto Scale v2.1"
echo "=========================================="

# ===========================================
# DETECTA MEMÓRIA DO CONTAINER
# Prioridade: cgroup v2 → cgroup v1 → env → fallback
# ===========================================

get_container_memory_mb() {
    local mem_bytes=0

    # cgroup v2
    if [ -f "/sys/fs/cgroup/memory.max" ]; then
        mem_bytes=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || echo "0")
        if [ "$mem_bytes" = "max" ]; then
            mem_bytes=0
        fi
    fi

    # cgroup v1
    if [ "${mem_bytes}" = "0" ] || [ -z "${mem_bytes}" ]; then
        if [ -f "/sys/fs/cgroup/memory/memory.limit_in_bytes" ]; then
            mem_bytes=$(cat /sys/fs/cgroup/memory/memory.limit_in_bytes 2>/dev/null || echo "0")
            # valor enorme => sem limite
            if [ "${mem_bytes}" -gt 100000000000000 ] 2>/dev/null; then
                mem_bytes=0
            fi
        fi
    fi

    # env Railway (mantido)
    if [ "${mem_bytes}" = "0" ] || [ -z "${mem_bytes}" ]; then
        if [ -n "${RAILWAY_MEMORY_MB:-}" ]; then
            echo "${RAILWAY_MEMORY_MB}"
            return
        fi
    fi

    # fallback conservador (Cloud Run pequeno / free tiers)
    if [ "${mem_bytes}" = "0" ] || [ -z "${mem_bytes}" ]; then
        echo "512"
        return
    fi

    echo $((mem_bytes / 1024 / 1024))
}

TOTAL_RAM_MB="$(get_container_memory_mb)"

# sanity
if [ "${TOTAL_RAM_MB}" -gt 32768 ] 2>/dev/null; then
    echo "⚠️  RAM detectada muito alta (${TOTAL_RAM_MB} MB), assumindo 1024MB"
    TOTAL_RAM_MB=1024
fi

echo "📊 RAM do Container: ${TOTAL_RAM_MB}MB"

# ===========================================
# SIZING (Cloud Run safe defaults)
# ===========================================
# Premissas realistas:
# - cada worker PHP pode consumir 60-120MB (depende do app)
# - reservar memória para nginx + SO + buffers + opcache
# - permitir override por env sem mudar o script
#
# Overrides:
#   PHP_WORKER_MB      (default 80)
#   PHP_RESERVE_MB     (default 220)
#   OPCACHE_PCT        (default 12)  -> % da RAM
#   OPCACHE_MIN_MB     (default 64)
#   OPCACHE_MAX_MB     (default 256)
#   FPM_MAX_CHILDREN_CAP (default 64)
# ===========================================

PHP_WORKER_MB="${PHP_WORKER_MB:-80}"
PHP_RESERVE_MB="${PHP_RESERVE_MB:-220}"

OPCACHE_PCT="${OPCACHE_PCT:-12}"
OPCACHE_MIN_MB="${OPCACHE_MIN_MB:-64}"
OPCACHE_MAX_MB="${OPCACHE_MAX_MB:-256}"

FPM_MAX_CHILDREN_CAP="${FPM_MAX_CHILDREN_CAP:-64}"

# calcula opcache por % da RAM
OPCACHE_MEMORY=$(( TOTAL_RAM_MB * OPCACHE_PCT / 100 ))
if [ "${OPCACHE_MEMORY}" -lt "${OPCACHE_MIN_MB}" ]; then OPCACHE_MEMORY="${OPCACHE_MIN_MB}"; fi
if [ "${OPCACHE_MEMORY}" -gt "${OPCACHE_MAX_MB}" ]; then OPCACHE_MEMORY="${OPCACHE_MAX_MB}"; fi

# memória disponível para workers
AVAILABLE_FOR_WORKERS=$(( TOTAL_RAM_MB - PHP_RESERVE_MB - OPCACHE_MEMORY ))
if [ "${AVAILABLE_FOR_WORKERS}" -lt 128 ]; then
    AVAILABLE_FOR_WORKERS=128
fi

PM_MAX_CHILDREN=$(( AVAILABLE_FOR_WORKERS / PHP_WORKER_MB ))
# clamps
if [ "${PM_MAX_CHILDREN}" -lt 4 ]; then PM_MAX_CHILDREN=4; fi
if [ "${PM_MAX_CHILDREN}" -gt "${FPM_MAX_CHILDREN_CAP}" ]; then PM_MAX_CHILDREN="${FPM_MAX_CHILDREN_CAP}"; fi

# derivar start/min/max spare de forma proporcional
# start ~ 20% (min 2)
PM_START_SERVERS=$(( PM_MAX_CHILDREN / 5 ))
if [ "${PM_START_SERVERS}" -lt 2 ]; then PM_START_SERVERS=2; fi
if [ "${PM_START_SERVERS}" -gt "${PM_MAX_CHILDREN}" ]; then PM_START_SERVERS="${PM_MAX_CHILDREN}"; fi

# min spare ~ 10% (min 1)
PM_MIN_SPARE=$(( PM_MAX_CHILDREN / 10 ))
if [ "${PM_MIN_SPARE}" -lt 1 ]; then PM_MIN_SPARE=1; fi

# max spare ~ 35% (min 3)
PM_MAX_SPARE=$(( PM_MAX_CHILDREN * 35 / 100 ))
if [ "${PM_MAX_SPARE}" -lt 3 ]; then PM_MAX_SPARE=3; fi
if [ "${PM_MAX_SPARE}" -gt "${PM_MAX_CHILDREN}" ]; then PM_MAX_SPARE="${PM_MAX_CHILDREN}"; fi

# heurística de "capacidade" (não é garantia)
EXPECTED_USERS="depende de DB/CPU"

echo "⚙️  Configuração calculada:"
echo "   - Worker MB estimado: ${PHP_WORKER_MB}MB"
echo "   - Reserva: ${PHP_RESERVE_MB}MB"
echo "   - OPcache: ${OPCACHE_MEMORY}MB (${OPCACHE_PCT}%)"
echo "   - Disponível p/ workers: ${AVAILABLE_FOR_WORKERS}MB"
echo "   - pm.max_children: ${PM_MAX_CHILDREN}"
echo "   - pm.start_servers: ${PM_START_SERVERS}"
echo "   - pm.min_spare_servers: ${PM_MIN_SPARE}"
echo "   - pm.max_spare_servers: ${PM_MAX_SPARE}"
echo "=========================================="

# ===========================================
# GERA CONFIGURAÇÃO PHP-FPM DINÂMICA
# ===========================================

cat > /usr/local/etc/php-fpm.d/zz-dynamic.conf << EOF
[www]
; ===========================================
; Configuração gerada automaticamente
; RAM Container: ${TOTAL_RAM_MB}MB
; Reserva: ${PHP_RESERVE_MB}MB
; WorkerMB: ${PHP_WORKER_MB}MB
; OPcache: ${OPCACHE_MEMORY}MB
; ===========================================

pm = dynamic
pm.max_children = ${PM_MAX_CHILDREN}
pm.start_servers = ${PM_START_SERVERS}
pm.min_spare_servers = ${PM_MIN_SPARE}
pm.max_spare_servers = ${PM_MAX_SPARE}

; reciclagem para estabilidade
pm.max_requests = 300

; timeouts coerentes (nginx tem endpoints até 60s)
request_terminate_timeout = 90s
request_slowlog_timeout = 5s

; Status page / ping (se o nginx expor, proteja com token!)
pm.status_path = /fpm-status
ping.path = /fpm-ping
ping.response = pong
EOF

echo "✅ Configuração PHP-FPM gerada: /usr/local/etc/php-fpm.d/zz-dynamic.conf"

# ===========================================
# AJUSTA OPCACHE BASEADO NA RAM
# ===========================================

cat > /usr/local/etc/php/conf.d/zz-opcache-dynamic.ini << EOF
; OPcache dinâmico - RAM: ${TOTAL_RAM_MB}MB
opcache.memory_consumption=${OPCACHE_MEMORY}
EOF

echo "✅ OPcache ajustado para ${OPCACHE_MEMORY}MB"

# ===========================================
# NGINX WORKERS
# ===========================================
# [CLOUDRUN] Evitar sed em runtime por fragilidade.
# Mantenha worker_processes auto no nginx.conf.
# Se quiser forçar, habilite via env NGINX_WORKERS e substitua de forma segura.
if [ -n "${NGINX_WORKERS:-}" ]; then
    # substitui somente se existir exatamente "worker_processes auto;"
    if grep -q "^worker_processes auto;" /etc/nginx/nginx.conf; then
        sed -i "s/^worker_processes auto;/worker_processes ${NGINX_WORKERS};/" /etc/nginx/nginx.conf
        echo "✅ Nginx workers forçado: ${NGINX_WORKERS}"
    else
        echo "⚠️  nginx.conf não contém 'worker_processes auto;'. Pulando ajuste."
    fi
else
    echo "✅ Nginx workers: auto (recomendado no Cloud Run)"
fi

echo "=========================================="
echo "🎮 Iniciando servidores..."
echo "=========================================="

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
