<?php
// ============================================
// TESTE COM GOOGLE UID REAL
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

error_log('🧪 TESTE COM UID REAL: Iniciando...');

// Google UID real do seu usuário (do banco)
$realGoogleUid = 'DqnexVtvrtdG3fe8fSGzHk8NA713';
// Google UID que frontend envia (truncado no console)
$frontendGoogleUid = 'DqnexVtvrt...';

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Conexão com banco falhou']);
        exit;
    }
    
    echo "🔍 TESTANDO COM DOIS UIDs DIFERENTES:\n";
    echo "====================================\n\n";
    
    // 1. Buscar com UID REAL (completo)
    echo "1. BUSCANDO COM UID REAL (completo):\n";
    echo "   UID: '$realGoogleUid'\n";
    echo "   Comprimento: " . strlen($realGoogleUid) . " caracteres\n";
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$realGoogleUid]);
    $userReal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "   Resultado: " . ($userReal ? '✅ ENCONTRADO' : '❌ NÃO ENCONTRADO') . "\n";
    if ($userReal) {
        echo "   ID: {$userReal['id']}, Email: {$userReal['email']}\n";
    }
    
    // 2. Buscar com UID FRONTEND (truncado)
    echo "\n2. BUSCANDO COM UID FRONTEND (truncado):\n";
    echo "   UID: '$frontendGoogleUid'\n";
    echo "   Comprimento: " . strlen($frontendGoogleUid) . " caracteres\n";
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$frontendGoogleUid]);
    $userFrontend = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "   Resultado: " . ($userFrontend ? '✅ ENCONTRADO' : '❌ NÃO ENCONTRADO') . "\n";
    
    // 3. Buscar com LIKE (match parcial)
    echo "\n3. BUSCANDO COM LIKE (match parcial):\n";
    echo "   LIKE: '$frontendGoogleUid%'\n";
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid LIKE ? LIMIT 1");
    $stmt->execute([$frontendGoogleUid . '%']);
    $userLike = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "   Resultado: " . ($userLike ? '✅ ENCONTRADO' : '❌ NÃO ENCONTRADO') . "\n";
    if ($userLike) {
        echo "   UID encontrado: '{$userLike['google_uid']}'\n";
        echo "   Match? " . (strpos($userLike['google_uid'], $frontendGoogleUid) === 0 ? '✅ SIM' : '❌ NÃO') . "\n";
    }
    
    // 4. Verificar players table
    echo "\n4. VERIFICANDO TABELA PLAYERS:\n";
    
    // Com UID real
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$realGoogleUid]);
    $playerReal = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   Players com UID real: " . ($playerReal ? '✅ EXISTE' : '❌ NÃO EXISTE') . "\n";
    
    // 5. Testar o que game-start.php faria
    echo "\n5. SIMULANDO GAME-START.PHP:\n";
    
    if (!$playerReal && $userReal) {
        echo "   ❌ Player não existe mas User existe\n";
        echo "   👉 game-start.php DEVERIA criar player\n";
        
        // Tentar criar player (como game-start.php faz)
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, balance_brl, total_played, created_at)
            VALUES (?, ?, 0, NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
        $result = $stmt->execute([$realGoogleUid, $userReal['balance_brl'] ?? 0.00]);
        echo "   INSERT player: " . ($result ? '✅ SUCESSO' : '❌ FALHA') . "\n";
    } elseif ($playerReal) {
        echo "   ✅ Player já existe\n";
    } else {
        echo "   ❌ Nem user nem player existem\n";
    }
    
    echo "\n🎯 CONCLUSÃO:\n";
    if ($userReal && !$playerReal) {
        echo "PROBLEMA: User existe mas Player não foi criado!\n";
        echo "SOLUÇÃO: game-start.php precisa sincronizar users→players\n";
    } elseif (!$userReal) {
        echo "PROBLEMA: User não encontrado com nenhum UID!\n";
        echo "SOLUÇÃO: auth-google.php não criou user corretamente\n";
    } elseif (strpos($realGoogleUid, $frontendGoogleUid) !== 0) {
        echo "PROBLEMA: UIDs não batem! Frontend envia UID diferente\n";
        echo "SOLUÇÃO: Verificar como frontend obtém Google UID\n";
    } else {
        echo "✅ Tudo parece OK! game-start.php deveria funcionar\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
}