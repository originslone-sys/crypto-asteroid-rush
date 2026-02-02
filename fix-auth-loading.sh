#!/bin/bash
# ===========================================
# CORRIGE CARREGAMENTO DO AUTH-SIMPLE.JS
# Adiciona auth-simple.js antes de main.js
# ===========================================

echo "🔧 CORRIGINDO CARREGAMENTO DO AUTH-SIMPLE.JS"
echo "============================================"
echo ""

# Lista de arquivos HTML que usam main.js
FILES=$(grep -l "main.js" *.html 2>/dev/null)

for file in $FILES; do
    echo "📄 Processando: $file"
    
    # Verificar se já tem auth-simple.js
    if grep -q "auth-simple.js" "$file"; then
        echo "   ✅ Já tem auth-simple.js"
        continue
    fi
    
    # Verificar se tem main.js
    if grep -q "main.js" "$file"; then
        # Adicionar auth-simple.js antes de main.js
        sed -i 's|<script src="js/main.js"></script>|<script src="js/auth-simple.js"></script>\n<script src="js/main.js"></script>|g' "$file"
        echo "   🔧 Adicionado auth-simple.js antes de main.js"
    else
        echo "   ⚠️  Tem main.js mas não encontrei a tag (pode ser formato diferente)"
    fi
done

echo ""
echo "✅ CORREÇÕES APLICADAS!"
echo ""
echo "📋 ARQUIVOS MODIFICADOS:"
for file in $FILES; do
    if grep -q "auth-simple.js" "$file"; then
        echo "   ✅ $file"
    else
        echo "   ⚠️  $file (não modificado - verificar manualmente)"
    fi
done

echo ""
echo "🎯 PRÓXIMOS PASSOS:"
echo "1. Testar login novamente"
echo "2. Verificar se authManager está disponível"
echo "3. Testar todas páginas que usam autenticação"