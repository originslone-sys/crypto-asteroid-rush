#!/bin/bash
# Script para análise rápida de todos arquivos do projeto

echo "🔍 ANÁLISE RÁPIDA DE TODOS ARQUIVOS"
echo "==================================="
echo ""

ANALYSIS_FILE="ANALISE_RAPIDA_COMPLETA.md"

# Cabeçalho do arquivo de análise
cat > "$ANALYSIS_FILE" << EOF
# 🔍 ANÁLISE RÁPIDA COMPLETA
## Crypto Asteroid Rush / Unobix

**Data**: $(date +"%Y-%m-%d")  
**Total arquivos**: $(find . -type f \( -name "*.php" -o -name "*.js" -o -name "*.html" -o -name "*.css" -o -name "*.sql" -o -name "*.json" -o -name "*.md" -o -name "*.txt" -o -name "*.sh" \) | grep -v node_modules | grep -v ".git" | wc -l)  
**Método**: Análise rápida (checagem básica)

---

## 📊 LEGENDA

### 🎯 PRIORIDADE:
- **CRÍTICA**: Sistema core, dinheiro real, segurança máxima
- **ALTA**: Funcionalidade importante, segurança média
- **MÉDIA**: Funcionalidade auxiliar, segurança baixa
- **BAIXA**: Documentação, assets, configurações

### ⚠️ RISCO:
- **ALTO**: Pode causar perda financeira, comprometer sistema
- **MÉDIO**: Pode causar problemas funcionais, vazamento dados
- **BAIXO**: Impacto mínimo, problemas cosméticos

---

## 📁 ANÁLISE POR CATEGORIA

EOF

# Função para analisar um arquivo
analyze_file() {
    local file="$1"
    local category="$2"
    
    echo "### 📄 \`$file\`" >> "$ANALYSIS_FILE"
    echo "" >> "$ANALYSIS_FILE"
    
    # Tamanho
    if [ -f "$file" ]; then
        local lines=$(wc -l "$file" 2>/dev/null | awk '{print $1}')
        local size=$(ls -lh "$file" 2>/dev/null | awk '{print $5}')
        echo "📏 **Tamanho**: $lines linhas ($size)" >> "$ANALYSIS_FILE"
    else
        echo "📏 **Tamanho**: Arquivo não encontrado" >> "$ANALYSIS_FILE"
        return
    fi
    
    # Propósito (tenta adivinhar pelo nome)
    local purpose=""
    case "$file" in
        *game-start*|*game-end*) purpose="Sistema core do jogo" ;;
        *withdraw*) purpose="Sistema de saques" ;;
        *stake*) purpose="Sistema de staking" ;;
        *balance*) purpose="Consulta de saldo" ;;
        *transaction*) purpose="Gestão de transações" ;;
        *auth*|*login*) purpose="Autenticação" ;;
        *config*) purpose="Configuração" ;;
        *admin*) purpose="Painel administrativo" ;;
        *dashboard*) purpose="Dashboard/painel" ;;
        *wallet*) purpose="Carteira" ;;
        *.html) purpose="Página frontend" ;;
        *.js) purpose="JavaScript client-side" ;;
        *.css) purpose="Estilos" ;;
        *.php) purpose="API/backend" ;;
        *) purpose="Arquivo geral" ;;
    esac
    
    echo "🎯 **Propósito**: $purpose" >> "$ANALYSIS_FILE"
    echo "" >> "$ANALYSIS_FILE"
    
    # Problemas comuns (checagem rápida)
    echo "🔍 **Problemas potenciais**: " >> "$ANALYSIS_FILE"
    
    # Checagens básicas
    if [[ "$file" == *.php ]]; then
        # Checar por senhas hardcoded
        if grep -q "password.*=.*['\"]" "$file" 2>/dev/null; then
            echo "1. ❌ Possível senha hardcoded" >> "$ANALYSIS_FILE"
        fi
        
        # Checar por SQL injection risks
        if grep -q "\$_GET\|\$_POST\|\$_REQUEST" "$file" 2>/dev/null | grep -v "//" | head -1; then
            echo "2. ❌ Possível SQL injection risk" >> "$ANALYSIS_FILE"
        fi
        
        # Checar por error reporting ativado
        if grep -q "display_errors.*1\|error_reporting.*E_ALL" "$file" 2>/dev/null | grep -v "//" | head -1; then
            echo "3. ❌ Error reporting pode estar ativado" >> "$ANALYSIS_FILE"
        fi
    fi
    
    if [[ "$file" == *.js ]]; then
        # Checar por API keys hardcoded
        if grep -qi "api.*key\|secret.*key\|token" "$file" 2>/dev/null | head -1; then
            echo "1. ❌ Possível chave API hardcoded" >> "$ANALYSIS_FILE"
        fi
    fi
    
    # Se não encontrou problemas óbvios
    if ! tail -1 "$ANALYSIS_FILE" | grep -q "❌"; then
        echo "1. ✅ Nenhum problema óbvio identificado" >> "$ANALYSIS_FILE"
    fi
    
    echo "" >> "$ANALYSIS_FILE"
    
    # Determinar prioridade e risco baseado no tipo/nome
    local priority="MÉDIA"
    local risk="MÉDIO"
    
    case "$file" in
        *game-start*|*game-end*|*withdraw*|*stake*)
            priority="CRÍTICA"
            risk="ALTO"
            ;;
        *balance*|*transaction*|*auth*|*admin*)
            priority="ALTA"
            risk="ALTO"
            ;;
        *config*|*dashboard*|*wallet*)
            priority="ALTA"
            risk="MÉDIO"
            ;;
        *.html)
            priority="MÉDIA"
            risk="BAIXO"
            ;;
        *.js|*.css)
            priority="BAIXA"
            risk="BAIXO"
            ;;
    esac
    
    echo "⚠️ **Risco**: $risk" >> "$ANALYSIS_FILE"
    echo "🎯 **Prioridade**: $priority" >> "$ANALYSIS_FILE"
    echo "" >> "$ANALYSIS_FILE"
    echo "---" >> "$ANALYSIS_FILE"
    echo "" >> "$ANALYSIS_FILE"
    
    echo "✅ Analisado: $file"
}

# Analisar APIs PHP primeiro
echo "📁 ANALISANDO APIs PHP..." 
echo "" >> "$ANALYSIS_FILE"
echo "## 🔧 APIs PHP" >> "$ANALYSIS_FILE"
echo "" >> "$ANALYSIS_FILE"

for php_file in $(find . -name "*.php" | grep -v node_modules | grep -v ".git" | sort); do
    analyze_file "$php_file" "PHP"
done

# Analisar JavaScript
echo ""
echo "📁 ANALISANDO JavaScript..."
echo "" >> "$ANALYSIS_FILE"
echo "## ⚡ JavaScript" >> "$ANALYSIS_FILE"
echo "" >> "$ANALYSIS_FILE"

for js_file in $(find . -name "*.js" | grep -v node_modules | grep -v ".git" | sort); do
    analyze_file "$js_file" "JavaScript"
done

# Analisar CSS
echo ""
echo "📁 ANALISANDO CSS..."
echo "" >> "$ANALYSIS_FILE"
echo "## 🎨 CSS" >> "$ANALYSIS_FILE"
echo "" >> "$ANALYSIS_FILE"

for css_file in $(find . -name "*.css" | grep -v node_modules | grep -v ".git" | sort); do
    analyze_file "$css_file" "CSS"
done

# Analisar outros
echo ""
echo "📁 ANALISANDO OUTROS ARQUIVOS..."
echo "" >> "$ANALYSIS_FILE"
echo "## 📄 Outros Arquivos" >> "$ANALYSIS_FILE"
echo "" >> "$ANALYSIS_FILE"

for other_file in $(find . -type f \( -name "*.json" -o -name "*.sql" -o -name "*.md" -o -name "*.txt" -o -name "*.sh" \) | grep -v node_modules | grep -v ".git" | sort); do
    analyze_file "$other_file" "Outros"
done

# Resumo final
echo "" >> "$ANALYSIS_FILE"
echo "## 📊 RESUMO FINAL" >> "$ANALYSIS_FILE"
echo "" >> "$ANALYSIS_FILE"

echo "✅ Análise rápida completa!" 
echo "📄 Relatório salvo em: $ANALYSIS_FILE"
echo ""
echo "🎯 PRÓXIMOS PASSOS:"
echo "1. Revisar arquivos com prioridade CRÍTICA"
echo "2. Corrigir problemas identificados"
echo "3. Análise detalhada dos sistemas core"