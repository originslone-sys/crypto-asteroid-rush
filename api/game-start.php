<?php
// ============================================
// GAME-START SIMPLIFICADO (KISS Principle)
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Log inicial
error_log('🎮 GAME-START SIMPLE: Iniciando...');

// 1. Obter UID
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST ?: $_GET;
$googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

error_log("🎮 UID recebido: " . ($googleUid ? "'$googleUid'" : 'NULL'));

if (!$googleUid) {
    echo json_encode(['success' => false, 'error' => 'google_uid não fornecido']);
    exit;
}

try {
    // 2. Conectar ao banco
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception('Falha na conexão com banco');
    }
    
    // 3. BUSCAR USER COM MÚLTIPLAS TENTATIVAS
    error_log("🎯 BUSCA USER: UID recebido = '$googleUid'");
    
    $user = null;
    $searchMethods = [];
    
    // Método 1: Busca exata com UID recebido
    $searchMethods[] = ['method' => 'exato', 'uid' => $googleUid];
    
    // Método 2: Se UID tem '...', tentar sem eles
    if (strpos($googleUid, '...') !== false) {
        $cleanUid = str_replace('...', '', $googleUid);
        $searchMethods[] = ['method' => 'sem ...', 'uid' => $cleanUid];
    }
    
    // Método 3: LIKE com UID limpo
    if (isset($cleanUid)) {
        $searchMethods[] = ['method' => 'LIKE inicio', 'uid' => $cleanUid . '%'];
    }
    
    // Método 4: LIKE com qualquer parte
    $searchMethods[] = ['method' => 'LIKE qualquer', 'uid' => '%' . $googleUid . '%'];
    
    foreach ($searchMethods as $search) {
        if ($search['method'] === 'LIKE inicio' || $search['method'] === 'LIKE qualquer') {
            $stmt = $pdo->prepare("SELECT id, google_uid, email FROM users WHERE google_uid LIKE ? LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT id, google_uid, email FROM users WHERE google_uid = ? LIMIT 1");
        }
        
        $stmt->execute([$search['uid']]);
        $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($foundUser) {
            error_log("✅ ENCONTRADO com método: {$search['method']}, UID: '{$search['uid']}'");
            error_log("   User ID: {$foundUser['id']}, UID banco: '{$foundUser['google_uid']}'");
            $user = $foundUser;
            $googleUid = $foundUser['google_uid']; // Usar UID REAL do banco
            break;
        } else {
            error_log("❌ NÃO encontrado com método: {$search['method']}, UID: '{$search['uid']}'");
        }
    }
    
    // Se ainda não encontrou, verificar se tabela tem users
    if (!$user) {
        error_log("🔍 Verificando se tabela users tem dados...");
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("   Total users no banco: " . $count['total']);
        
        if ($count['total'] > 0) {
            // Listar primeiros 3 users para debug
            $stmt = $pdo->query("SELECT id, google_uid, email FROM users ORDER BY id LIMIT 3");
            $someUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($someUsers as $u) {
                error_log("   - ID: {$u['id']}, UID: '{$u['google_uid']}'");
            }
            
            // Usar primeiro user disponível
            $stmt = $pdo->query("SELECT id, google_uid, email FROM users LIMIT 1");
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                error_log("⚠️ Usando primeiro user disponível: ID {$user['id']}, UID: '{$user['google_uid']}'");
                $googleUid = $user['google_uid'];
            }
        } else {
            error_log("❌ TABELA USERS VAZIA! auth-google.php não criou users.");
            // Não vamos criar user de teste - melhor falhar e mostrar erro claro
            echo json_encode([
                'success' => false, 
                'error' => 'Usuário não encontrado. Faça login primeiro.',
                'debug' => 'Tabela users está vazia. Execute auth-google.php primeiro.'
            ]);
            exit;
        }
    }
    
    if (!$user) {
        error_log("❌ CRÍTICO: Nenhum user encontrado após todas tentativas");
        echo json_encode([
            'success' => false,
            'error' => 'Erro crítico: Nenhum usuário no sistema',
            'debug' => 'Tabela users vazia ou problema de conexão'
        ]);
        exit;
    }
    
    $userId = $user['id'];
    error_log("✅ User ID: $userId, UID real: '{$user['google_uid']}'");
    
    // 4. BUSCAR/CRIAR PLAYER
    $stmt = $pdo->prepare("SELECT id FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$player) {
        error_log("📝 Criando player para UID: '$googleUid'");
        $stmt = $pdo->prepare("INSERT INTO players (google_uid, balance_brl, total_played, created_at) VALUES (?, 0.00, 0, NOW())");
        $stmt->execute([$googleUid]);
        $playerId = $pdo->lastInsertId();
    } else {
        $playerId = $player['id'];
    }
    
    error_log("✅ Player ID: $playerId");
    
    // 5. CRIAR SESSÃO (simplificado)
    $sessionUuid = bin2hex(random_bytes(18));
    $sessionToken = hash('sha256', $googleUid . time());
    
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            user_id, session_uuid, session_token, google_uid, 
            mission_number, is_hard_mode, status, game_duration,
            ip_address, created_at
        ) VALUES (?, ?, ?, ?, 1, 0, 'active', 180, ?, NOW())
    ");
    
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt->execute([$userId, $sessionUuid, $sessionToken, $googleUid, $clientIP]);
    $sessionId = $pdo->lastInsertId();
    
    // 6. ATUALIZAR TOTAL_PLAYED
    $pdo->prepare("UPDATE players SET total_played = total_played + 1 WHERE id = ?")
        ->execute([$playerId]);
    
    // 7. RESPOSTA SIMPLES
    $response = [
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'session_uuid' => $sessionUuid,
        'player_id' => $playerId,
        'user_id' => $userId,
        'mission_number' => 1,
        'is_hard_mode' => false,
        'game_duration' => 180,
        'initial_lives' => 6,
        'debug' => [
            'uid_received' => $input['google_uid'] ?? $input['googleUid'] ?? null,
            'uid_used' => $googleUid,
            'user_found' => !empty($user),
            'player_found' => !empty($player)
        ]
    ];
    
    error_log("🎉 Sessão criada: ID $sessionId");
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('❌ GAME-START ERROR: ' . $e->getMessage());
    error_log('❌ TRACE: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno',
        'debug_error' => $e->getMessage(),
        'debug_file' => $e->getFile(),
        'debug_line' => $e->getLine()
    ]);
}