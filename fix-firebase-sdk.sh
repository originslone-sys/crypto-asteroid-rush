#!/bin/bash
# ===========================================
# ADICIONA SDK DO FIREBASE NOS HTMLs
# Firebase SDK deve carregar ANTES da configuração
# ===========================================

echo "🔥 ADICIONANDO SDK DO FIREBASE"
echo "==============================="
echo ""

# Lista de arquivos HTML principais
FILES="index.html dashboard.html game.html wallet.html staking.html play.html"

for file in $FILES; do
    if [ ! -f "$file" ]; then
        echo "⚠️  $file não existe, pulando..."
        continue
    fi
    
    echo "📄 Processando: $file"
    
    # Verificar se já tem Firebase SDK
    if grep -q "firebasejs" "$file"; then
        echo "   ✅ Já tem Firebase SDK"
    else
        # Adicionar Firebase SDK ANTES de firebase-config.js
        if grep -q "firebase-config.js" "$file"; then
            # Encontrar linha do firebase-config.js
            line=$(grep -n "firebase-config.js" "$file" | head -1 | cut -d: -f1)
            
            # Inserir SDK do Firebase antes dessa linha
            sed -i "${line}i\\
<!-- Firebase SDK -->\\
<script src=\"https://www.gstatic.com/firebasejs/9.0.0/firebase-app.js\"></script>\\
<script src=\"https://www.gstatic.com/firebasejs/9.0.0/firebase-auth.js\"></script>" "$file"
            
            echo "   🔧 Adicionado Firebase SDK (v9.0.0)"
        else
            echo "   ⚠️  Não encontrou firebase-config.js"
        fi
    fi
done

echo ""
echo "✅ SDK DO FIREBASE ADICIONADO!"
echo ""
echo "🎯 NOVA ORDEM DE CARREGAMENTO:"
echo "1. Firebase SDK (CDN)"
echo "2. firebase-config.js"
echo "3. auth-manager.js"
echo "4. main.js"
echo ""
echo "🔧 PARA TESTAR:"
echo "1. Recarregar página"
echo "2. Console: typeof firebase // deve ser 'object'"
echo "3. Console: window.authManager // deve existir"
echo "4. Testar login"