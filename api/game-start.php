<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// api/game-start.php v4.3
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

if (empty($input['google_uid'])) {
    echo json_encode(['success' => false, 'error' => 'google_uid não fornecido']);
    exit;
}

$googleUid = trim($input['google_uid']);

// Permitir UID truncado com ...
if (strpos($googleUid, '...') === false && !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }
    
    // Buscar usuário
    $user = findPlayer($pdo, $googleUid);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado. Faça login primeiro.']);
        exit;
    }
    
    // Verificar ban
    if (!empty($user['is_banned'])) {
        echo json_encode(['success' => false, 'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violação dos termos')]);
        exit;
    }
    
    $userId = (int)$user['id'];
    $realGoogleUid = $user['google_uid'];
    
    // Verificar limite por IP
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM game_sessions 
        WHERE ip_address = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$clientIP]);
    $ipCheck = $stmt->fetch();
    
    if ($ipCheck['count'] >= MAX_MISSIONS_PER_HOUR) {
        echo json_encode([
            'success' => false,
            'error' => 'Limite de ' . MAX_MISSIONS_PER_HOUR . ' missões por hora atingido',
            'wait_seconds' => 3600,
            'missions_remaining' => 0
        ]);
        exit;
    }
    
    $missionsRemaining = MAX_MISSIONS_PER_HOUR - $ipCheck['count'] - 1;
    
    // Expirar sessões ativas do usuário
    $pdo->prepare("
        UPDATE game_sessions 
        SET status = 'abandoned', ended_at = NOW() 
        WHERE google_uid = ? AND status = 'active'
    ")->execute([$realGoogleUid]);
    
    // Calcular número da missão
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM game_sessions WHERE google_uid = ?");
    $stmt->execute([$realGoogleUid]);
    $result = $stmt->fetch();
    $missionNumber = (int)($result['total'] ?? 0) + 1;
    
    // Determinar hard mode
    $isHardMode = isHardModeMission();
    
    // Configurar recompensas especiais
    $rareCount = $isHardMode ? 1 : 2;
    $hasEpic = ($missionNumber >= 5 && mt_rand(1, 100) <= 30);
    $hasLegendary = ($missionNumber >= 10 && mt_rand(1, 100) <= 10);
    
    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;
    $legendaryId = $hasLegendary ? mt_rand(251, 280) : 0;
    
    // Criar token de sessão
    $sessionToken = hash('sha256', $realGoogleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    
    // Criar sessão - deixar status usar DEFAULT
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            google_uid, 
            session_token, 
            mission_number, 
            is_hard_mode,
            rare_asteroids_target, 
            epic_asteroid_target, 
            rare_ids, 
            epic_id,
            ip_address, 
            user_agent, 
            earnings_brl, 
            asteroids_destroyed,
            common_asteroids,
            rare_asteroids,
            epic_asteroids,
            legendary_asteroids,
            started_at, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, NOW(), NOW())
    ");
    
    $stmt->execute([
        $realGoogleUid, 
        $sessionToken, 
        $missionNumber, 
        $isHardMode ? 1 : 0,
        $rareCount, 
        $hasEpic ? 1 : 0, 
        json_encode($rareIds), 
        $epicId,
        $clientIP, 
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);
    
    $sessionId = (int)$pdo->lastInsertId();
    
    // Atualizar total_played do usuário
    $pdo->prepare("UPDATE users SET total_played = total_played + 1, last_login = NOW() WHERE id = ?")
        ->execute([$userId]);
    
    secureLog("GAME_START | Session: $sessionId | User: $userId | Mission: $missionNumber | HardMode: " . ($isHardMode ? 'YES' : 'NO'));
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'google_uid' => $realGoogleUid,
        'user_id' => $userId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'rare_count' => $rareCount,
        'has_epic' => $hasEpic,
        'has_legendary' => $hasLegendary,
        'rare_ids' => $rareIds,
        'epic_id' => $epicId,
        'legendary_id' => $legendaryId,
        'game_duration' => GAME_DURATION,
        'initial_lives' => INITIAL_LIVES,
        'missions_remaining' => $missionsRemaining
    ]);
    
} catch (Throwable $e) {
    error_log("❌ GAME-START ERROR: " . $e->getMessage() . " | Line: " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Erro interno',
        'debug_error' => $e->getMessage(),
        'debug_line' => $e->getLine()
    ]);
}
