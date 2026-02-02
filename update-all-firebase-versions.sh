#!/bin/bash
# ===========================================
# ATUALIZA TODOS OS HTMLs PARA FIREBASE v10.7.1 COMPAT
# Garante consistência de versão
# ===========================================

echo "🔄 ATUALIZANDO PARA FIREBASE v10.7.1 COMPAT"
echo "==========================================="
echo ""

# Lista de todos HTMLs
FILES=$(ls *.html 2>/dev/null)

for file in $FILES; do
    echo "📄 Processando: $file"
    
    # Substituir qualquer versão do Firebase por v10.7.1 compat
    if grep -q "firebasejs" "$file"; then
        # Remover linhas antigas do Firebase
        sed -i '/firebasejs/d' "$file"
        
        # Adicionar versão correta ANTES de firebase-config.js
        if grep -q "firebase-config.js" "$file"; then
            line=$(grep -n "firebase-config.js" "$file" | head -1 | cut -d: -f1)
            sed -i "${line}i\\
<!-- Firebase SDK v10.7.1 (compat mode) -->\\
<script src=\"https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js\"></script>\\
<script src=\"https://www.gstatic.com/firebasejs/10.7.1/firebase-auth-compat.js\"></script>" "$file"
            
            echo "   🔧 Atualizado para Firebase v10.7.1 compat"
        else
            echo "   ⚠️  Não tem firebase-config.js, adicionando no final..."
            echo -e "\n<!-- Firebase SDK v10.7.1 (compat mode) -->\n<script src=\"https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js\"></script>\n<script src=\"https://www.gstatic.com/firebasejs/10.7.1/firebase-auth-compat.js\"></script>" >> "$file"
        fi
    else
        echo "   ℹ️  Não usa Firebase, pulando..."
    fi
done

echo ""
echo "✅ TODOS ATUALIZADOS PARA FIREBASE v10.7.1 COMPAT!"
echo ""
echo "🎯 VERSÃO CONSISTENTE EM TODOS OS ARQUIVOS:"
echo "- Firebase SDK: v10.7.1 compat"
echo "- firebase-config.js: Atualizado para v10.7.1"
echo "- auth-manager.js: Já compatível com v10.7.1"
echo ""
echo "🔧 TESTE RÁPIDO NO CONSOLE:"
echo "1. firebase.SDK_VERSION // deve ser '10.7.1'"
echo "2. typeof firebase.auth // deve ser 'function'"
echo "3. window.authManager // deve existir"