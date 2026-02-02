#!/bin/bash
# ===========================================
# CORRIGE CARREGAMENTO DO FIREBASE
# Garante ordem: firebase-config.js → auth-manager.js → main.js
# ===========================================

echo "🔥 CORRIGINDO CARREGAMENTO DO FIREBASE"
echo "======================================"
echo ""

# Lista de arquivos HTML que usam main.js (páginas que precisam de auth)
FILES="index.html dashboard.html game.html wallet.html staking.html play.html"

for file in $FILES; do
    if [ ! -f "$file" ]; then
        echo "⚠️  $file não existe, pulando..."
        continue
    fi
    
    echo "📄 Processando: $file"
    
    # Verificar se já tem firebase-config.js
    if grep -q "firebase-config.js" "$file"; then
        echo "   ✅ Já tem firebase-config.js"
    else
        # Adicionar firebase-config.js antes de main.js
        if grep -q "main.js" "$file"; then
            sed -i 's|<script src="js/main.js"></script>|<script src="js/firebase-config.js"></script>\n<script src="js/auth-manager.js"></script>\n<script src="js/main.js"></script>|g' "$file"
            echo "   🔧 Adicionado firebase-config.js e auth-manager.js antes de main.js"
        else
            echo "   ⚠️  Não encontrou main.js (formato diferente)"
        fi
    fi
    
    # Verificar se auth-manager.js está depois de firebase-config.js
    if grep -q "auth-manager.js" "$file" && grep -q "firebase-config.js" "$file"; then
        # Verificar ordem
        line_firebase=$(grep -n "firebase-config.js" "$file" | head -1 | cut -d: -f1)
        line_auth=$(grep -n "auth-manager.js" "$file" | head -1 | cut -d: -f1)
        
        if [ "$line_firebase" -lt "$line_auth" ]; then
            echo "   ✅ Ordem correta: firebase-config.js → auth-manager.js"
        else
            echo "   🔧 Ordem incorreta, corrigindo..."
            # Remover ambos e adicionar na ordem correta
            sed -i '/firebase-config.js/d' "$file"
            sed -i '/auth-manager.js/d' "$file"
            sed -i 's|<script src="js/main.js"></script>|<script src="js/firebase-config.js"></script>\n<script src="js/auth-manager.js"></script>\n<script src="js/main.js"></script>|g' "$file"
        fi
    fi
done

echo ""
echo "✅ CORREÇÕES APLICADAS!"
echo ""
echo "🎯 PRÓXIMOS PASSOS:"
echo "1. Testar login novamente"
echo "2. Verificar se Firebase carrega"
echo "3. Verificar se window.authManager existe"
echo ""
echo "🔧 PARA TESTAR NO CONSOLE DO NAVEGADOR:"
echo "1. typeof firebase  // deve ser 'object'"
echo "2. window.authManager  // deve existir"
echo "3. window.authManager.signIn()  // deve funcionar"