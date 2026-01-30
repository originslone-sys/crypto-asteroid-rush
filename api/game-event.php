<?php
// ============================================
// UNOBIX - Registrar Evento de Jogo
// Arquivo: api/game-event.php
// v2.0 - Google Auth + BRL + Valores servidor
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// ============================================
// LER INPUT
// ============================================
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || !is_array($input)) {
    secureLog("EVENT_INVALID_INPUT | Raw: " . substr($rawInput, 0, 500));
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$sessionToken = isset($input['session_token']) ? trim($input['session_token']) : '';
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$asteroidId = isset($input['asteroid_id']) ? (int)$input['asteroid_id'] : 0;
$rewardType = isset($input['reward_type']) ? trim(strtolower($input['reward_type'])) : 'none';
$timestamp = isset($input['timestamp']) ? (int)$input['timestamp'] : time();

// Validar dados obrigatórios
if (!$sessionId) {
    echo json_encode(['success' => false, 'error' => 'session_id ausente']);
    exit;
}

if (!$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'session_token ausente']);
    exit;
}

// Determinar identificador
$identifier = '';
if (!empty($googleUid) && validateGoogleUid($googleUid)) {
    $identifier = $googleUid;
} elseif (!empty($wallet) && validateWallet($wallet)) {
    $identifier = $wallet;
} else {
    echo json_encode(['success' => false, 'error' => 'Identificação inválida']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    // ============================================
    // 1. VALIDAR SESSÃO (Google UID tem prioridade)
    // ============================================
    if (!empty($googleUid) && validateGoogleUid($googleUid)) {
        // Buscar por Google UID
        $stmt = $pdo->prepare("
            SELECT * FROM game_sessions 
            WHERE id = ? 
            AND google_uid = ?
            AND status IN ('active', 'completed')
        ");
        $stmt->execute([$sessionId, $googleUid]);
    } elseif (!empty($wallet) && validateWallet($wallet)) {
        // Fallback para wallet (legacy)
        $stmt = $pdo->prepare("
            SELECT * FROM game_sessions 
            WHERE id = ? 
            AND wallet_address = ?
            AND status IN ('active', 'completed')
        ");
        $stmt->execute([$sessionId, $wallet]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Identificação inválida']);
        exit;
    }
    
    $session = $stmt->fetch();
    
    if (!$session) {
        secureLog("EVENT_SESSION_NOT_FOUND | session_id: {$sessionId} | ID: {$identifier}");
        echo json_encode(['success' => false, 'error' => 'Sessão inválida ou expirada']);
        exit;
    }
    
    if ($session['session_token'] !== $sessionToken) {
        secureLog("EVENT_TOKEN_MISMATCH | session_id: {$sessionId}");
        echo json_encode(['success' => false, 'error' => 'Token inválido']);
        exit;
    }
    
    // Verificar tempo da sessão
    $sessionStart = strtotime($session['started_at']);
    $elapsed = time() - $sessionStart;
    
    if ($elapsed > GAME_DURATION + GAME_TOLERANCE) {
        echo json_encode(['success' => false, 'error' => 'Sessão expirada']);
        exit;
    }
    
    // ============================================
    // 2. VERIFICAR RATE LIMIT DE EVENTOS
    // ============================================
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM game_events 
        WHERE session_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)
    ");
    $stmt->execute([$sessionId]);
    $recentEvents = $stmt->fetch();
    
    if ($recentEvents['count'] >= MAX_EVENTS_PER_SECOND) {
        // Apenas logar, não bloquear (game-end validará)
        secureLog("EVENT_RATE_LIMIT | session_id: {$sessionId} | count: {$recentEvents['count']}");
    }
    
    // ============================================
    // 3. DETERMINAR RECOMPENSA - SOMENTE DO SERVIDOR!
    // NUNCA aceitar reward_amount do cliente!
    // ============================================
    $validTypes = ['none', 'common', 'rare', 'epic', 'legendary'];
    $validRewardType = in_array($rewardType, $validTypes) ? $rewardType : 'none';
    
    // Buscar valor do servidor baseado no tipo
    $validRewardBrl = getRewardByType($validRewardType);
    
    // ============================================
    // 4. REGISTRAR EVENTO (wallet_address pode ser NULL)
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO game_events (
            session_id, 
            google_uid,
            wallet_address, 
            asteroid_id, 
            reward_type,
            reward_amount,
            reward_amount_brl,
            client_timestamp,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), NOW())
    ");
    
    $stmt->execute([
        $sessionId,
        $session['google_uid'],
        $session['wallet_address'],  // Pode ser NULL para Google users
        $asteroidId,
        $validRewardType,
        $validRewardBrl,
        $validRewardBrl,
        $timestamp
    ]);
    
    $eventId = $pdo->lastInsertId();
    
    // ============================================
    // 5. ATUALIZAR CONTADORES DA SESSÃO (se ativa)
    // ============================================
    if ($session['status'] === 'active') {
        $updateFields = [
            'asteroids_destroyed = asteroids_destroyed + 1',
            "earnings_brl = earnings_brl + {$validRewardBrl}"
        ];
        
        // Também atualiza earnings_usdt para compatibilidade (mesmo valor)
        $updateFields[] = "earnings_usdt = earnings_usdt + {$validRewardBrl}";
        
        switch ($validRewardType) {
            case 'legendary':
                $updateFields[] = 'legendary_asteroids = COALESCE(legendary_asteroids, 0) + 1';
                break;
            case 'epic':
                $updateFields[] = 'epic_asteroids = COALESCE(epic_asteroids, 0) + 1';
                break;
            case 'rare':
                $updateFields[] = 'rare_asteroids = COALESCE(rare_asteroids, 0) + 1';
                break;
            case 'common':
                $updateFields[] = 'common_asteroids = COALESCE(common_asteroids, 0) + 1';
                break;
        }
        
        $pdo->prepare("UPDATE game_sessions SET " . implode(', ', $updateFields) . " WHERE id = ?")
            ->execute([$sessionId]);
    }
    
    echo json_encode([
        'success' => true,
        'reward_type' => $validRewardType,
        'reward_brl' => $validRewardBrl,
        'event_id' => $eventId
    ]);
    
} catch (Exception $e) {
    secureLog("EVENT_ERROR | Session: {$sessionId} | Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao registrar evento']);
}
