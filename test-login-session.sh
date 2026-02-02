#!/bin/bash
# ===========================================
# TESTE DE LOGIN E SESSÃO DE JOGO
# Monitora todo o fluxo do usuário
# ===========================================

set -e

echo "🎮 TESTE DE LOGIN E SESSÃO DE JOGO"
echo "=================================="
echo "Data: $(date)"
echo "URL: https://crypto-asteroid-6dczkl3zma-uc.a.run.app"
echo ""

# Configurações
APP_URL="https://crypto-asteroid-6dczkl3zma-uc.a.run.app"
LOG_FILE="/tmp/game-test-$(date +%Y%m%d-%H%M%S).log"

echo "📝 Logs sendo salvos em: $LOG_FILE"
echo ""

# Função para log
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Função para testar endpoint
test_endpoint() {
    local endpoint=$1
    local expected_status=$2
    local description=$3
    
    log "🔍 Testando: $description"
    log "   Endpoint: $APP_URL$endpoint"
    
    local response=$(curl -s -o /dev/null -w "%{http_code}" "$APP_URL$endpoint" 2>/dev/null || echo "000")
    
    if [ "$response" -eq "$expected_status" ] || [ "$expected_status" -eq "any" ]; then
        log "   ✅ STATUS: $response (OK)"
        return 0
    else
        log "   ❌ STATUS: $response (esperado: $expected_status)"
        return 1
    fi
}

# Função para verificar database
check_database() {
    log "🗄️  Verificando conexão com database..."
    
    # Tentar conectar ao Cloud SQL
    mysql -h 34.168.76.127 -u unobix_user -p"YyZD3H)dndSo*A/N" unobix_db -e "SELECT COUNT(*) as total_users FROM users;" 2>/dev/null | tail -1
    if [ $? -eq 0 ]; then
        log "   ✅ Database acessível"
        return 0
    else
        log "   ⚠️  Database não acessível (pode ser normal para testes)"
        return 1
    fi
}

# ===========================================
# INÍCIO DOS TESTES
# ===========================================

log "🚀 INICIANDO TESTES COMPLETOS"

echo ""
echo "📊 TESTE 1: PÁGINAS ESTÁTICAS"
echo "-----------------------------"

test_endpoint "/" 200 "Página inicial"
test_endpoint "/game.html" 200 "Página do jogo"
test_endpoint "/login.html" 200 "Página de login"
test_endpoint "/dashboard.html" 200 "Dashboard do usuário"
test_endpoint "/staking.html" 200 "Página de staking"

echo ""
echo "🔐 TESTE 2: ENDPOINTS DE API"
echo "---------------------------"

test_endpoint "/api/auth-firebase.php" "any" "API Firebase Auth"
test_endpoint "/api/debug.php" "any" "API Debug (se existir)"
test_endpoint "/api/events.php" "any" "API de Eventos do Jogo"

echo ""
echo "🎮 TESTE 3: FLUXO DO JOGO"
echo "-------------------------"

# Verificar se os arquivos JS necessários existem
log "🔍 Verificando assets do jogo:"
for asset in "js/game.js" "js/auth.js" "js/wallet.js"; do
    if curl -s -I "$APP_URL/$asset" 2>/dev/null | grep -q "200 OK"; then
        log "   ✅ $asset disponível"
    else
        log "   ⚠️  $asset não encontrado"
    fi
done

echo ""
echo "🗄️ TESTE 4: CONEXÃO COM BANCO"
echo "----------------------------"

check_database

echo ""
echo "📈 TESTE 5: PERFORMANCE"
echo "----------------------"

log "⏱️  Testando tempo de resposta:"
for i in {1..3}; do
    start_time=$(date +%s%N)
    curl -s -o /dev/null "$APP_URL/"
    end_time=$(date +%s%N)
    response_time=$(( (end_time - start_time) / 1000000 ))
    log "   Tentativa $i: ${response_time}ms"
done

echo ""
echo "🔧 TESTE 6: CONFIGURAÇÕES"
echo "------------------------"

log "🔍 Verificando configurações:"
# Verificar se .env.example existe
if [ -f ".env.example" ]; then
    log "   ✅ .env.example presente"
else
    log "   ⚠️  .env.example não encontrado"
fi

# Verificar configurações PHP
if curl -s "$APP_URL/config.php" 2>/dev/null | grep -q "mysql"; then
    log "   ✅ config.php parece configurado"
else
    log "   ⚠️  config.php pode não estar configurado"
fi

echo ""
echo "📋 RESUMO FINAL"
echo "--------------"

log "🎯 TESTES CONCLUÍDOS EM: $(date)"
log ""
log "📊 RECOMENDAÇÕES:"
log "1. Testar login manual com conta Google"
log "2. Iniciar uma sessão de jogo completa"
log "3. Verificar se eventos são registrados no banco"
log "4. Testar sistema de saques/staking"
log ""
log "🔧 PRÓXIMOS PASSOS:"
log "- Corrigir quaisquer endpoints com status != 200"
log "- Verificar console do navegador por erros JS"
log "- Monitorar logs do Cloud Run durante teste"
log ""
log "✅ RELATÓRIO SALVO EM: $LOG_FILE"

echo ""
echo "🎉 TESTES CONCLUÍDOS!"
echo "Consulte o arquivo de log para detalhes: $LOG_FILE"
echo ""
echo "📌 PARA TESTAR MANUALMENTE:"
echo "1. Acesse: $APP_URL"
echo "2. Clique em 'Login com Google'"
echo "3. Inicie uma missão no jogo"
echo "4. Verifique console do navegador (F12)"
echo "5. Monitore logs: gcloud logging read --limit=10"