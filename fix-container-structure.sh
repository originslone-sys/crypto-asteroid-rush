#!/bin/bash
# Script para corrigir estrutura de arquivos no container Cloud Run
# Problema: Arquivos PHP estão em api/ mas container espera na raiz ou paths diferentes

echo "🔧 Corrigindo estrutura para Cloud Run container..."

# Opção 1: Criar symlinks na raiz para arquivos críticos
echo "1. Criando symlinks para arquivos críticos na raiz..."
mkdir -p /app 2>/dev/null || true
cd /app

# Se api/ existe, criar symlinks
if [ -d "api" ]; then
    echo "✅ api/ directory found in container"
    
    # Arquivos que devem estar acessíveis na raiz
    CRITICAL_FILES=("config.php" "config-cloudrun.php" "auth-firebase.php" "auth-google.php" "game-start.php")
    
    for file in "${CRITICAL_FILES[@]}"; do
        if [ -f "api/$file" ] && [ ! -f "$file" ]; then
            ln -s "api/$file" "$file"
            echo "  🔗 Created symlink: $file -> api/$file"
        fi
    done
    
    # Verificar estrutura
    echo ""
    echo "📁 Current structure after fix:"
    ls -la *.php 2>/dev/null | head -10 || echo "No PHP files in root"
    
else
    echo "❌ api/ directory NOT found in container"
    echo "📁 Listing root directory:"
    ls -la /app 2>/dev/null || echo "Cannot list /app"
fi

# Opção 2: Verificar nginx configuration
echo ""
echo "2. Verificando nginx configuration..."
if [ -f "/etc/nginx/nginx.conf" ]; then
    echo "✅ nginx.conf found"
    # Verificar root directory setting
    grep -i "root" /etc/nginx/nginx.conf | head -5
else
    echo "❌ nginx.conf not found"
fi

# Opção 3: Testar se PHP files são acessíveis
echo ""
echo "3. Testando acesso a arquivos PHP..."
TEST_FILES=("config.php" "api/config.php" "./config.php" "/app/config.php")

for file in "${TEST_FILES[@]}"; do
    echo -n "  $file: "
    if [ -f "$file" ]; then
        echo "✅ EXISTS"
    else
        echo "❌ MISSING"
    fi
done

echo ""
echo "🎯 SOLUÇÕES POSSÍVEIS:"
echo "A) Manter estrutura atual e ajustar todos os requires para usar __DIR__"
echo "B) Mover arquivos críticos para raiz no Dockerfile"
echo "C) Usar symlinks (implementado acima)"
echo "D) Ajustar nginx para servir de /app/api em vez de /app"

echo ""
echo "📋 Recomendação: Implementar symlinks no entrypoint.sh"