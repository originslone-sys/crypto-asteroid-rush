<?php
// ============================================
// TESTE DA CORREÇÃO DO GAME-START
// Simula exatamente o que frontend faz
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: text/plain');
echo "🧪 TESTE DA CORREÇÃO DO GAME-START\n";
echo "==================================\n\n";

// Simular exatamente o que frontend envia
$frontendGoogleUid = 'DqnexVtvrt...'; // Truncado
$realGoogleUid = 'DqnexVtvrtdG3fe8fSGzHk8NA713'; // Completo

echo "🎯 SIMULANDO FRONTEND:\n";
echo "   Frontend envia: '$frontendGoogleUid'\n";
echo "   Banco tem:      '$realGoogleUid'\n\n";

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo "❌ Conexão com banco falhou\n";
        exit;
    }
    
    echo "1. TESTANDO BUSCA EM USERS (LIKE):\n";
    echo "----------------------------------\n";
    
    $stmt = $pdo->prepare("SELECT id, google_uid FROM users WHERE google_uid LIKE ? LIMIT 1");
    $stmt->execute([$frontendGoogleUid . '%']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "   ✅ ENCONTRADO com LIKE!\n";
        echo "   ID: {$user['id']}, UID: '{$user['google_uid']}'\n";
        echo "   Match? " . (strpos($user['google_uid'], $frontendGoogleUid) === 0 ? '✅ SIM' : '❌ NÃO') . "\n";
    } else {
        echo "   ❌ NÃO ENCONTRADO com LIKE\n";
        
        // Tentar exato
        $stmt = $pdo->prepare("SELECT id, google_uid FROM users WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$frontendGoogleUid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo "   ✅ ENCONTRADO com busca exata\n";
        } else {
            echo "   ❌ NÃO ENCONTRADO de jeito nenhum\n";
        }
    }
    
    echo "\n2. TESTANDO BUSCA EM PLAYERS (LIKE):\n";
    echo "-----------------------------------\n";
    
    $stmt = $pdo->prepare("SELECT id, google_uid FROM players WHERE google_uid LIKE ? LIMIT 1");
    $stmt->execute([$frontendGoogleUid . '%']);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($player) {
        echo "   ✅ ENCONTRADO com LIKE!\n";
        echo "   ID: {$player['id']}, UID: '{$player['google_uid']}'\n";
    } else {
        echo "   ❌ NÃO ENCONTRADO com LIKE\n";
        
        // Tentar exato
        $stmt = $pdo->prepare("SELECT id, google_uid FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$frontendGoogleUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($player) {
            echo "   ✅ ENCONTRADO com busca exata\n";
        } else {
            echo "   ❌ NÃO ENCONTRADO - game-start.php DEVERIA CRIAR\n";
        }
    }
    
    echo "\n3. SIMULANDO O QUE GAME-START.PHP FARIA:\n";
    echo "---------------------------------------\n";
    
    if ($user && !$player) {
        echo "   ✅ User existe mas Player não\n";
        echo "   👉 game-start.php criaria player com UID real\n";
        
        $realUid = $user['google_uid'];
        $stmt = $pdo->prepare("INSERT INTO players (google_uid, balance_brl, total_played, created_at) VALUES (?, 0.00, 0, NOW())");
        $result = $stmt->execute([$realUid]);
        
        echo "   INSERT player: " . ($result ? '✅ SUCESSO' : '❌ FALHA') . "\n";
    } elseif ($player) {
        echo "   ✅ Player já existe\n";
        echo "   👉 game-start.php usaria este player\n";
    } else {
        echo "   ❌ Nem user nem player existem\n";
        echo "   👉 game-start.php falharia\n";
    }
    
    echo "\n🎯 CONCLUSÃO:\n";
    if ($user) {
        echo "✅ game-start.php DEVE FUNCIONAR agora!\n";
        echo "   - Encontra user com LIKE search\n";
        echo "   - Encontra/cria player com UID real\n";
        echo "   - Retorna success: true\n";
    } else {
        echo "❌ PROBLEMA: User não encontrado mesmo com LIKE\n";
        echo "   Verificar se auth-google.php criou user corretamente\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
}