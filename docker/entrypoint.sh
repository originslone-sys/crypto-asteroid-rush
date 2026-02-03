#!/bin/sh
set -eu

echo "🚀 Iniciando Crypto Asteroid Rush..."
echo "✅ Cloud Run + Cloud SQL attachment ativo (sem proxy local)."

# ============================================
# FIX: Criar symlinks para arquivos críticos
# Problema: Arquivos PHP estão em /app/api/ mas nginx espera na raiz
# ============================================
echo "🔧 Configurando estrutura de arquivos..."

cd /app

# Verificar se api/ directory existe
if [ -d "api" ]; then
    echo "✅ api/ directory encontrada"
    
    # Criar symlinks para arquivos críticos na raiz
    CRITICAL_FILES=("config.php" "config-cloudrun.php" "auth-firebase.php" "auth-google.php" "game-start.php" "game-end.php" "game-event.php")
    
    for file in "${CRITICAL_FILES[@]}"; do
        if [ -f "api/$file" ] && [ ! -f "$file" ]; then
            ln -sf "api/$file" "$file"
            echo "  🔗 Symlink criado: $file -> api/$file"
        elif [ ! -f "api/$file" ]; then
            echo "  ⚠️  Arquivo não encontrado: api/$file"
        fi
    done
    
    # Verificar estrutura
    echo ""
    echo "📁 Estrutura após correção:"
    echo "Raiz (/app):"
    ls *.php 2>/dev/null | head -10 || echo "  (nenhum arquivo PHP na raiz)"
    
else
    echo "❌ ATENÇÃO: api/ directory NÃO encontrada em /app/"
    echo "📁 Conteúdo de /app/:"
    ls -la 2>/dev/null | head -20 || echo "  (não foi possível listar)"
fi

# ============================================
# Verificar configuração do banco
# ============================================
echo ""
echo "🔍 Verificando configuração do banco..."

if [ -n "${CLOUDSQL_INSTANCE:-}" ]; then
    echo "✅ Cloud SQL Instance: $CLOUDSQL_INSTANCE"
fi

if [ -n "${MYSQLHOST:-}" ]; then
    echo "✅ MySQL Host: $MYSQLHOST"
elif [ -n "${CLOUDSQL_INSTANCE:-}" ]; then
    echo "ℹ️  MYSQLHOST não definido, usando Cloud SQL socket"
else
    echo "⚠️  MYSQLHOST não definido e sem CLOUDSQL_INSTANCE"
fi

# ============================================
# Iniciar serviços
# ============================================
echo ""
echo "🚀 Iniciando Supervisor (Nginx + PHP-FPM)..."
echo "📊 Endpoint de diagnóstico: /api/cloudrun-diagnostic.php"

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
