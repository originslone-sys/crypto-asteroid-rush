<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// Arquivo: api/game-start.php
// v2.0 - Google Auth + Hard Mode 40% + BRL
// ============================================

require_once __DIR__ . "/config.php";

if (file_exists(__DIR__ . "/rate-limiter.php")) {
    require_once __DIR__ . "/rate-limiter.php";
}

setCorsHeaders();

// ============================================
// LER INPUT
// ============================================
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = [];

// Suporte híbrido: google_uid (novo) ou wallet (compatibilidade)
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$txHash = isset($input['txHash']) ? trim($input['txHash']) : '';

// Determinar identificador principal
$identifier = '';
$identifierType = '';

if (!empty($googleUid) && validateGoogleUid($googleUid)) {
    $identifier = $googleUid;
    $identifierType = 'google_uid';
} elseif (!empty($wallet) && validateWallet($wallet)) {
    $identifier = $wallet;
    $identifierType = 'wallet';
} else {
    echo json_encode(['success' => false, 'error' => 'Identificação inválida. Faça login novamente.']);
    exit;
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    // ============================================
    // CRIAR TABELA DE CONTROLE DE IP (se não existir)
    // ============================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ip_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            session_id INT NOT NULL,
            google_uid VARCHAR(128) DEFAULT NULL,
            wallet_address VARCHAR(42) DEFAULT NULL,
            status ENUM('active', 'completed', 'expired') DEFAULT 'active',
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME DEFAULT NULL,
            INDEX idx_ip_status (ip_address, status),
            INDEX idx_ip_time (ip_address, started_at),
            INDEX idx_session (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ============================================
    // VERIFICAÇÃO 1: Missão simultânea por IP
    // ============================================
    $stmt = $pdo->prepare("
        SELECT ips.id, ips.session_id, gs.status as game_status
        FROM ip_sessions ips
        LEFT JOIN game_sessions gs ON gs.id = ips.session_id
        WHERE ips.ip_address = ?
        AND ips.status = 'active'
        AND ips.started_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        LIMIT 1
    ");
    $stmt->execute([$clientIP]);
    $activeSession = $stmt->fetch();

    if ($activeSession) {
        if ($activeSession['game_status'] === 'active') {
            secureLog("CONCURRENT_BLOCK | IP: {$clientIP} | ID: {$identifier} | Active session: {$activeSession['session_id']}");
            
            echo json_encode([
                'success' => false,
                'error' => 'Você já tem uma missão em andamento. Complete-a primeiro.',
                'error_code' => 'CONCURRENT_MISSION',
                'active_session_id' => $activeSession['session_id']
            ]);
            exit;
        } else {
            $pdo->prepare("UPDATE ip_sessions SET status = 'completed', ended_at = NOW() WHERE id = ?")
                ->execute([$activeSession['id']]);
        }
    }

    // ============================================
    // VERIFICAÇÃO 2: Limite de 5 missões por hora por IP
    // ============================================
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as mission_count
        FROM ip_sessions
        WHERE ip_address = ?
        AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$clientIP]);
    $hourlyCount = $stmt->fetch();
    
    $missionsThisHour = (int)($hourlyCount['mission_count'] ?? 0);

    if ($missionsThisHour >= MAX_MISSIONS_PER_HOUR) {
        $stmt = $pdo->prepare("
            SELECT started_at FROM ip_sessions
            WHERE ip_address = ?
            AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY started_at ASC LIMIT 1
        ");
        $stmt->execute([$clientIP]);
        $oldest = $stmt->fetch();
        
        $waitSeconds = 0;
        if ($oldest) {
            $waitSeconds = max(0, (strtotime($oldest['started_at']) + 3600) - time());
        }
        
        secureLog("HOURLY_LIMIT | IP: {$clientIP} | ID: {$identifier} | Count: {$missionsThisHour}");
        
        echo json_encode([
            'success' => false,
            'error' => "Limite de " . MAX_MISSIONS_PER_HOUR . " missões por hora atingido. Aguarde " . ceil($waitSeconds / 60) . " minutos.",
            'error_code' => 'HOURLY_LIMIT',
            'missions_played' => $missionsThisHour,
            'max_missions' => MAX_MISSIONS_PER_HOUR,
            'wait_seconds' => $waitSeconds
        ]);
        exit;
    }

    // ============================================
    // VERIFICAÇÃO 3: Rate limiter adicional
    // ============================================
    if (class_exists('RateLimiter')) {
        $limiter = new RateLimiter($pdo, $wallet ?: null, $googleUid ?: null);
        $rateCheck = $limiter->checkGameStart();

        if (!($rateCheck['allowed'] ?? true)) {
            echo json_encode([
                'success' => false,
                'error' => $rateCheck['error'] ?? 'Rate limit',
                'wait_seconds' => $rateCheck['wait_seconds'] ?? null,
                'banned' => $rateCheck['banned'] ?? false
            ]);
            exit;
        }
    }

    // ============================================
    // BUSCAR/CRIAR JOGADOR
    // ============================================
    $player = getOrCreatePlayer($pdo, $input);
    
    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Não foi possível identificar o jogador']);
        exit;
    }

    if ($player['is_banned']) {
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($player['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ]);
        exit;
    }

    $playerId = (int)$player['id'];
    $playerGoogleUid = $player['google_uid'] ?? null;
    $playerWallet = $player['wallet_address'];
    $totalPlayed = (int)$player['total_played'];
    $missionNumber = $totalPlayed + 1;

    // ============================================
    // VERIFICAÇÃO 4: Cooldown de 3 minutos por usuário
    // ============================================
    $whereClause = $playerGoogleUid 
        ? "(google_uid = ? OR wallet_address = ?)" 
        : "wallet_address = ?";
    $params = $playerGoogleUid 
        ? [$playerGoogleUid, $playerWallet] 
        : [$playerWallet];

    $stmt = $pdo->prepare("
        SELECT id, created_at FROM game_sessions
        WHERE {$whereClause}
        AND status IN ('active', 'completed')
        AND created_at > DATE_SUB(NOW(), INTERVAL 3 MINUTE)
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute($params);
    $recentSession = $stmt->fetch();

    if ($recentSession) {
        $waitTime = 180 - (time() - strtotime($recentSession['created_at']));
        echo json_encode([
            'success' => false,
            'error' => 'Aguarde antes de jogar novamente',
            'error_code' => 'USER_COOLDOWN',
            'wait_seconds' => max(0, (int)$waitTime)
        ]);
        exit;
    }

    // ============================================
    // DETERMINAR HARD MODE (40% via stored procedure)
    // ============================================
    $isHardMode = false;
    try {
        $stmt = $pdo->query("CALL sp_get_mission_difficulty()");
        $difficulty = $stmt->fetch();
        $isHardMode = isset($difficulty['is_hard_mode']) && $difficulty['is_hard_mode'] == 1;
        $stmt->closeCursor();
    } catch (Exception $e) {
        // Fallback: calcular localmente
        $isHardMode = isHardModeMission();
    }

    // ============================================
    // DEFINIR ESPECIAIS (rare/epic)
    // No hard mode, menos especiais
    // ============================================
    if ($isHardMode) {
        $rareCount = (mt_rand(1, 100) <= 50) ? 1 : 0;  // 50% chance de 1 raro no hard mode
    } else {
        $rareCount = (mt_rand(1, 100) <= 70) ? 1 : 2;  // 70% chance 1, 30% chance 2
    }

    // Épico: a cada 15+ missões desde o último épico, com 30% chance
    $stmt = $pdo->prepare("
        SELECT MAX(mission_number) AS last_epic FROM game_sessions
        WHERE (google_uid = ? OR wallet_address = ?) AND epic_asteroids > 0
    ");
    $stmt->execute([$playerGoogleUid, $playerWallet]);
    $lastEpic = $stmt->fetch();

    $lastEpicMission = isset($lastEpic['last_epic']) ? (int)$lastEpic['last_epic'] : 0;
    $missionsSinceEpic = $missionNumber - $lastEpicMission;
    
    // No hard mode, épico é mais raro
    $epicChance = $isHardMode ? 15 : 30;
    $hasEpic = ($missionsSinceEpic >= 15 && mt_rand(1, 100) <= $epicChance);

    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;

    // ============================================
    // CRIAR SESSÃO
    // ============================================
    $sessionToken = '';
    $captchaRequired = CAPTCHA_REQUIRED_ON_VICTORY ? 1 : 0;

    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            google_uid,
            wallet_address,
            session_token,
            mission_number,
            status,
            is_hard_mode,
            captcha_required,
            rare_asteroids_target,
            epic_asteroid_target,
            rare_ids,
            epic_id,
            tx_hash,
            ip_address,
            earnings_brl,
            started_at,
            created_at
        ) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
    ");

    $stmt->execute([
        $playerGoogleUid,
        $playerWallet,
        $sessionToken,
        $missionNumber,
        $isHardMode ? 1 : 0,
        $captchaRequired,
        $rareCount,
        $hasEpic ? 1 : 0,
        json_encode($rareIds),
        $epicId,
        $txHash,
        $clientIP
    ]);

    $sessionId = (int)$pdo->lastInsertId();
    $sessionToken = generateSessionToken($identifier, $sessionId);

    $pdo->prepare("UPDATE game_sessions SET session_token = ? WHERE id = ?")
        ->execute([$sessionToken, $sessionId]);

    // ============================================
    // REGISTRAR NA TABELA DE CONTROLE DE IP
    // ============================================
    $pdo->prepare("
        INSERT INTO ip_sessions (ip_address, session_id, google_uid, wallet_address, status, started_at)
        VALUES (?, ?, ?, ?, 'active', NOW())
    ")->execute([$clientIP, $sessionId, $playerGoogleUid, $playerWallet]);

    secureLog("GAME_START | IP: {$clientIP} | UID: {$playerGoogleUid} | Wallet: {$playerWallet} | Session: {$sessionId} | Mission: {$missionNumber} | HardMode: " . ($isHardMode ? 'YES' : 'NO'));

    // ============================================
    // RESPOSTA
    // ============================================
    $response = [
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'player_id' => $playerId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'rare_count' => $rareCount,
        'has_epic' => (bool)$hasEpic,
        'rare_ids' => $rareIds,
        'epic_id' => $epicId,
        'game_duration' => GAME_DURATION,
        'initial_lives' => INITIAL_LIVES,
        'missions_remaining' => MAX_MISSIONS_PER_HOUR - $missionsThisHour - 1,
        'captcha_required' => (bool)$captchaRequired
    ];

    // Se hard mode, enviar configurações especiais
    if ($isHardMode) {
        $response['hard_mode_config'] = [
            'speed_multiplier' => HARD_MODE_SPEED_MULTIPLIER,
            'spawn_multiplier' => HARD_MODE_SPAWN_MULTIPLIER
        ];
    }

    // NÃO enviar valores de recompensa nem spawn rates (secreto!)
    // O frontend deve mostrar valores genéricos

    echo json_encode($response);

} catch (Exception $e) {
    secureLog("GAME_START_ERROR | ID: {$identifier} | IP: {$clientIP} | Error: " . $e->getMessage());
    error_log("Erro em game-start.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
