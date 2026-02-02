<?php
// ============================================
// DIAGNÓSTICO COMPLETO DO UID
// Verifica EXATAMENTE o que está no banco
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: text/plain');
echo "🔍 DIAGNÓSTICO COMPLETO DO UID\n";
echo "===============================\n\n";

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo "❌ Conexão com banco falhou\n";
        exit;
    }
    
    echo "1. LISTANDO TODOS OS USERS NO BANCO:\n";
    echo "-----------------------------------\n";
    
    $stmt = $pdo->query("SELECT id, google_uid, email, created_at FROM users ORDER BY id");
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($allUsers)) {
        echo "   ❌ NENHUM usuário na tabela users!\n";
    } else {
        echo "   Total users: " . count($allUsers) . "\n\n";
        foreach ($allUsers as $user) {
            echo "   - ID: {$user['id']}\n";
            echo "     UID: '{$user['google_uid']}'\n";
            echo "     Email: {$user['email']}\n";
            echo "     Criado: {$user['created_at']}\n";
            
            // Verificar se contém 'DqnexVtvrt'
            if (strpos($user['google_uid'], 'DqnexVtvrt') !== false) {
                echo "     ✅ CONTÉM 'DqnexVtvrt'!\n";
                echo "     Comprimento: " . strlen($user['google_uid']) . " chars\n";
                
                // Verificar match com frontend
                $frontendUid = 'DqnexVtvrt...';
                $cleanUid = str_replace('...', '', $frontendUid);
                echo "     LIKE '{$cleanUid}%' match? " . 
                     (strpos($user['google_uid'], $cleanUid) === 0 ? '✅ SIM' : '❌ NÃO') . "\n";
            }
            echo "\n";
        }
    }
    
    echo "\n2. LISTANDO TODOS OS PLAYERS NO BANCO:\n";
    echo "--------------------------------------\n";
    
    $stmt = $pdo->query("SELECT id, google_uid, balance_brl, total_played, created_at FROM players ORDER BY id");
    $allPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($allPlayers)) {
        echo "   ❌ NENHUM player na tabela players!\n";
    } else {
        echo "   Total players: " . count($allPlayers) . "\n\n";
        foreach ($allPlayers as $player) {
            echo "   - ID: {$player['id']}\n";
            echo "     UID: '{$player['google_uid']}'\n";
            echo "     Balance: {$player['balance_brl']} BRL\n";
            echo "     Total played: {$player['total_played']}\n";
            
            if (strpos($player['google_uid'], 'DqnexVtvrt') !== false) {
                echo "     ✅ CONTÉM 'DqnexVtvrt'!\n";
            }
            echo "\n";
        }
    }
    
    echo "\n3. VERIFICANDO SE FRONTEND ESTÁ ENVIANDO UID CORRETO:\n";
    echo "----------------------------------------------------\n";
    
    echo "   Frontend mostra no console: 'DqnexVtvrt...'\n";
    echo "   Mas isso pode ser TRUNCAMENTO DO CONSOLE, não do valor real!\n\n";
    
    echo "   TESTE: Verificar Network tab no navegador:\n";
    echo "   1. Abra DevTools → Network tab\n";
    echo "   2. Clique 'Iniciar Missão'\n";
    echo "   3. Clique no request para game-start.php\n";
    echo "   4. Veja 'Payload' ou 'Request' tab\n";
    echo "   5. Procure EXATAMENTE o google_uid enviado\n\n";
    
    echo "4. HIPÓTESES:\n";
    echo "------------\n";
    
    echo "   A) ❌ Usuário não existe em users (auth-google.php falhou)\n";
    echo "   B) ❌ UID no banco é COMPLETAMENTE DIFERENTE\n";
    echo "   C) ❌ Frontend envia UID DIFERENTE do que mostra no console\n";
    echo "   D) ✅ Tabelas estão vazias (precisa fazer login primeiro)\n\n";
    
    echo "5. SOLUÇÕES:\n";
    echo "-----------\n";
    
    echo "   1. ✅ FAZER LOGIN NOVAMENTE (auth-google.php cria user)\n";
    echo "   2. ✅ Verificar EXATO payload do frontend (Network tab)\n";
    echo "   3. ✅ Testar auth-google.php diretamente\n";
    
    echo "\n🎯 AÇÃO IMEDIATA:\n";
    echo "1. Recarregue a página do jogo\n";
    echo "2. Faça login novamente (se necessário)\n";
    echo "3. Verifique Network tab para UID EXATO\n";
    echo "4. Teste auth-google.php diretamente\n";
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
}