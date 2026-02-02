#!/bin/bash
# ============================================
# ATUALIZAR TODOS OS ENDPOINTS PARA config-cloudrun.php
# ============================================

echo "🚀 ATUALIZANDO ENDPOINTS PARA config-cloudrun.php"
echo "================================================"
echo ""

# Lista de endpoints para atualizar (exceto os já atualizados)
ENDPOINTS=(
    "api/admin-ads.php"
    "api/admin-ajax.php"
    "api/admin-security.php"
    "api/admin-withdrawals.php"
    "api/balance.php"
    "api/db-ping.php"
    "api/debug.php"
    "api/game-end.php"
    "api/game-event.php"
    "api/game-start.php"
    "api/get-stakes.php"
    "api/login.php"
    "api/maintenance.php"
    "api/referral-claim.php"
    "api/referral-info.php"
    "api/referral-register.php"
    "api/report-suspicious.php"
    "api/stake.php"
    "api/transactions.php"
    "api/unstake.php"
    "api/update-stakes.php"
    "api/verify-captcha.php"
    "api/wallet-info.php"
    "api/withdraw.php"
)

# Endpoints já atualizados
UPDATED=(
    "api/auth-google.php"
    "api/auth-firebase.php"
)

echo "📋 Endpoints para atualizar: ${#ENDPOINTS[@]}"
echo "✅ Já atualizados: ${#UPDATED[@]}"
echo ""

for endpoint in "${ENDPOINTS[@]}"; do
    if [ -f "$endpoint" ]; then
        echo "🔧 Atualizando $endpoint..."
        
        # Verificar se já usa config-cloudrun.php
        if grep -q "config-cloudrun.php" "$endpoint"; then
            echo "   ⏭️  Já usa config-cloudrun.php"
            continue
        fi
        
        # Fazer backup
        cp "$endpoint" "$endpoint.backup"
        
        # Substituir require_once config.php por config-cloudrun.php
        sed -i 's|require_once __DIR__ . "/config.php";|require_once __DIR__ . "/config-cloudrun.php";|g' "$endpoint"
        sed -i 's|require_once .config.php.;|require_once __DIR__ . "/config-cloudrun.php";|g' "$endpoint"
        sed -i 's|require_once.*config.php|require_once __DIR__ . "/config-cloudrun.php"|g' "$endpoint"
        
        # Verificar se a substituição funcionou
        if grep -q "config-cloudrun.php" "$endpoint"; then
            echo "   ✅ Atualizado com sucesso"
            
            # Testar sintaxe PHP
            php -l "$endpoint" > /dev/null 2>&1
            if [ $? -eq 0 ]; then
                echo "   ✅ Sintaxe PHP OK"
            else
                echo "   ❌ Erro de sintaxe, restaurando backup"
                cp "$endpoint.backup" "$endpoint"
            fi
        else
            echo "   ❌ Falha na atualização, restaurando backup"
            cp "$endpoint.backup" "$endpoint"
        fi
        
        # Remover backup
        rm -f "$endpoint.backup"
        
    else
        echo "   ❌ Arquivo não encontrado: $endpoint"
    fi
done

echo ""
echo "🎯 ATUALIZAÇÃO CONCLUÍDA"
echo "========================"
echo ""
echo "📊 Resumo:"
echo "- Endpoints atualizados: $(grep -r "config-cloudrun.php" api/*.php 2>/dev/null | wc -l)"
echo "- Endpoints com config.php antigo: $(grep -r "config.php" api/*.php 2>/dev/null | grep -v "config-cloudrun.php" | wc -l)"
echo ""
echo "⚠️ IMPORTANTE: Testar endpoints críticos antes de deploy!"
echo "   - game-start.php (iniciar jogo)"
echo "   - game-end.php (finalizar jogo)"
echo "   - balance.php (saldo)"
echo ""