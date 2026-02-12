<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// api/game-start.php v5.0 - Arquitetura Segura
// Gera seed para verificação de integridade
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

    // Verificar VPN/Proxy via proxycheck.io
    ensureProxyCacheTable($pdo);
    $proxyCheck = checkProxyVPN($clientIP, $pdo);
    if ($proxyCheck['is_proxy']) {
        secureLog("VPN_PROXY_BLOCKED_GAME | IP: $clientIP | Type: {$proxyCheck['type']} | User: $userId");
        echo json_encode([
            'success' => false,
            'error' => 'Detectamos que você está usando VPN ou Proxy. Por segurança, desative a VPN/Proxy para jogar.',
            'error_code' => 'VPN_PROXY_DETECTED',
            'proxy_type' => $proxyCheck['type']
        ]);
        exit;
    }

    // Verificar limite diário por IP (50 missões por 24h)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count,
               MIN(created_at) as first_session
        FROM game_sessions
        WHERE ip_address = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$clientIP]);
    $ipCheck = $stmt->fetch();

    if ($ipCheck['count'] >= MAX_MISSIONS_PER_DAY) {
        // Calcular segundos restantes até a sessão mais antiga expirar (24h)
        $firstSession = strtotime($ipCheck['first_session']);
        $resetTime = $firstSession + 86400; // 24h em segundos
        $waitSeconds = max(0, $resetTime - time());

        echo json_encode([
            'success' => false,
            'error' => 'Você atingiu o limite de ' . MAX_MISSIONS_PER_DAY . ' missões por dia. Tente novamente amanhã!',
            'error_code' => 'DAILY_LIMIT_REACHED',
            'wait_seconds' => $waitSeconds,
            'missions_remaining' => 0,
            'daily_limit' => MAX_MISSIONS_PER_DAY
        ]);
        exit;
    }

    $missionsRemaining = MAX_MISSIONS_PER_DAY - $ipCheck['count'] - 1;
    
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
    
    // Determinar hard mode (servidor decide, não cliente)
    $isHardMode = isHardModeMission();
    
    // Gerar tokens de segurança
    $sessionToken = hash('sha256', $realGoogleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $sessionSeed = generateSessionSeed(); // Novo: seed para verificação
    
    // Criar sessão
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            google_uid, 
            session_token,
            session_uuid,
            mission_number, 
            is_hard_mode,
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, NOW(), NOW())
    ");
    
    $stmt->execute([
        $realGoogleUid, 
        $sessionToken,
        $sessionSeed, // Armazena seed no campo session_uuid
        $missionNumber, 
        $isHardMode ? 1 : 0,
        $clientIP, 
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);
    
    $sessionId = (int)$pdo->lastInsertId();
    
    secureLog("GAME_START | Session: $sessionId | User: $userId | Mission: $missionNumber | HardMode: " . ($isHardMode ? 'YES' : 'NO'));
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'session_seed' => $sessionSeed, // Enviado ao cliente para gerar hash
        'google_uid' => $realGoogleUid,
        'user_id' => $userId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'game_duration' => GAME_DURATION,
        'initial_lives' => INITIAL_LIVES,
        'missions_remaining' => $missionsRemaining,
        'daily_limit' => MAX_MISSIONS_PER_DAY,
        // Limites para o cliente saber
        'limits' => [
            'max_asteroids' => MAX_ASTEROIDS_PER_GAME,
            'max_legendary' => MAX_LEGENDARY_PER_GAME,
            'max_epic' => MAX_EPIC_PER_GAME,
            'max_rare' => MAX_RARE_PER_GAME
        ]
    ]);
    
} catch (Throwable $e) {
    error_log("❌ GAME-START ERROR: " . $e->getMessage() . " | Line: " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Erro interno'
    ]);
}
