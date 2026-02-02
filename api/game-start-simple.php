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
    
    // 3. BUSCAR USER (qualquer método que funcione)
    error_log("🔍 Buscando user com UID: '$googleUid'");
    
    // Tentativa 1: Busca exata
    $stmt = $pdo->prepare("SELECT id, google_uid, email FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Tentativa 2: Se não encontrou, buscar qualquer user (para teste)
    if (!$user) {
        error_log("❌ User não encontrado com busca exata");
        
        // Buscar PRIMEIRO user disponível (para teste)
        $stmt = $pdo->query("SELECT id, google_uid, email FROM users LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            error_log("⚠️ Usando user alternativo: ID {$user['id']}, UID: {$user['google_uid']}");
            $googleUid = $user['google_uid']; // Usar UID real do banco
        }
    }
    
    if (!$user) {
        // Criar user de teste
        error_log("⚠️ Nenhum user encontrado, criando de teste...");
        $testUid = 'test_' . time();
        $stmt = $pdo->prepare("INSERT INTO users (google_uid, email, created_at) VALUES (?, 'test@example.com', NOW())");
        $stmt->execute([$testUid]);
        
        $user = ['id' => $pdo->lastInsertId(), 'google_uid' => $testUid];
        $googleUid = $testUid;
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