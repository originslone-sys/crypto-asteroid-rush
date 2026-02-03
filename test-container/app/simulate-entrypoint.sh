#!/bin/bash
echo "🚀 Simulando entrypoint.sh do container..."
echo "📁 Diretório atual: $(pwd)"

# Criar symlinks como no entrypoint.sh real
CRITICAL_FILES=("config.php" "config-cloudrun.php" "auth-firebase.php" "auth-google.php" "game-start.php")

for file in "${CRITICAL_FILES[@]}"; do
    if [ -f "api/$file" ] && [ ! -f "$file" ]; then
        ln -s "api/$file" "$file"
        echo "  🔗 Symlink criado: $file -> api/$file"
    elif [ ! -f "api/$file" ]; then
        echo "  ⚠️  Arquivo não encontrado: api/$file"
    fi
done

echo ""
echo "📁 Estrutura após symlinks:"
ls -la *.php 2>/dev/null | head -10 || echo "  (nenhum arquivo PHP na raiz)"
echo ""
echo "📁 Conteúdo de api/:"
ls -la api/*.php 2>/dev/null | head -10 || echo "  (nenhum arquivo PHP em api/)"
