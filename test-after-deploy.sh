#!/bin/bash
# Script para testar aplicação após deploy no Cloud Run

echo "🧪 TESTE PÓS-DEPLOY - CLOUD RUN + CLOUD SQL"
echo "=========================================="

SERVICE_URL="https://crypto-asteroid-234282032979.us-central1.run.app"

echo ""
echo "1. 📡 Testando URL do serviço..."
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$SERVICE_URL")
echo "   HTTP Status: $HTTP_STATUS"

if [ "$HTTP_STATUS" = "200" ]; then
    echo "   ✅ Serviço respondendo"
    
    echo ""
    echo "2. 🔍 Testando conteúdo da página..."
    curl -s "$SERVICE_URL" | grep -i -E "unobix|crypto|asteroid|game" | head -5
    
    echo ""
    echo "3. 🗄️ Testando API endpoints..."
    # Testar endpoint de health (se existir)
    curl -s "$SERVICE_URL/api/config.php" | head -2
    
else
    echo "   ❌ Serviço não respondendo (HTTP $HTTP_STATUS)"
fi

echo ""
echo "4. 📊 Verificando logs do Cloud Run..."
echo "   Execute manualmente:"
echo "   gcloud logging read \\"
echo "     \"resource.type=cloud_run_revision AND resource.labels.service_name=crypto-asteroid\" \\"
echo "     --limit=20 --format=\"table(timestamp,severity,textPayload)\""

echo ""
echo "5. 🔧 Possíveis problemas e soluções:"
echo ""
echo "   ❌ ERRO: 'Database unobix_db doesn't exist'"
echo "   ✅ SOLUÇÃO: Criar database no Cloud SQL:"
echo "      1. Acesse: https://console.cloud.google.com/sql"
echo "      2. Clique na instância 'unobix'"
echo "      3. Vá para 'Databases' → 'Create database'"
echo "      4. Nome: 'unobix_db'"
echo "      5. Collation: 'utf8mb4_unicode_ci'"
echo ""
echo "   ❌ ERRO: 'Access denied for user unobix_user'"
echo "   ✅ SOLUÇÃO: Verificar credenciais no .env"
echo "      - User: unobix_user"
echo "      - Password: }Q9?@EeP<uMiGQ:/"
echo ""
echo "   ❌ ERRO: 'Can't connect to MySQL server'"
echo "   ✅ SOLUÇÃO: Cloud SQL Proxy falhou"
echo "      - Verificar permissões do Cloud Run"
echo "      - Verificar se instância 'unobix' existe"
echo ""
echo "6. 🚀 Para recriar deploy:"
echo "   gcloud run deploy crypto-asteroid \\"
echo "     --source https://github.com/originslone-sys/crypto-asteroid-rush \\"
echo "     --region=us-central1 \\"
echo "     --platform=managed \\"
echo "     --allow-unauthenticated"
echo ""
echo "📌 LEMBRETE: Configure variáveis de ambiente no Cloud Run:"
echo "   MYSQLHOST=127.0.0.1"
echo "   MYSQLPORT=3306"
echo "   MYSQLDATABASE=unobix_db"
echo "   MYSQLUSER=unobix_user"
echo "   MYSQLPASSWORD=}Q9?@EeP<uMiGQ:/"
echo "   FIREBASE_API_KEY=AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U"
echo "   ... (todas do .env)"