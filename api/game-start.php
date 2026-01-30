<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// Arquivo: api/game-start.php
// Unobix-only: google_uid obrigatório, wallet não é identidade
// ============================================

ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function readJsonInput(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$input = readJsonInput();
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$sessionTokenFromClient = isset($input['session_token']) ? trim($input['session_token']) : '';

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido. Faça login novamente.']);
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

    // garantir ip_sessions
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

    // 1) missão simultânea por IP
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
    $activeSession = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeSession && ($activeSession['game_status'] ?? null) === 'active') {
        echo json_encode([
            'success' => false,
            'error' => 'Você já tem uma missão em andamento. Complete-a primeiro.',
            'error_code' => 'CONCURRENT_MISSION'
        ]);
        exit;
    } elseif ($activeSession) {
        $pdo->prepare("UPDATE ip_sessions SET status = 'completed', ended_at = NOW() WHERE id = ?")
            ->execute([$activeSession['id']]);
    }

    // 2) limite por hora
    $maxMissionsPerHour = defined('MAX_MISSIONS_PER_HOUR') ? MAX_MISSIONS_PER_HOUR : 5;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as mission_count
        FROM ip_sessions
        WHERE ip_address = ?
          AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$clientIP]);
    $missionsThisHour = (int)$stmt->fetchColumn();

    if ($missionsThisHour >= $maxMissionsPerHour) {
        echo json_encode([
            'success' => false,
            'error' => "Limite de {$maxMissionsPerHour} missões por hora atingido.",
            'error_code' => 'HOURLY_LIMIT'
        ]);
        exit;
    }

    // 3) buscar player (cria se não existir)
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, balance_brl, total_played, created_at, updated_at)
            VALUES (?, 0.00, 0, NOW(), NOW())
        ");
        $stmt->execute([$googleUid]);

        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Não foi possível identificar o jogador']);
        exit;
    }

    if (!empty($player['is_banned'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($player['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ]);
        exit;
    }

    $playerId = (int)$player['id'];
    $totalPlayed = (int)($player['total_played'] ?? 0);
    $missionNumber = $totalPlayed + 1;

    // hard mode
    $hardModePercentage = defined('HARD_MODE_PERCENTAGE') ? HARD_MODE_PERCENTAGE : 40;
    $isHardMode = (mt_rand(1, 100) <= $hardModePercentage);

    // especiais
    $rareCount = $isHardMode ? ((mt_rand(1, 100) <= 50) ? 1 : 0) : ((mt_rand(1, 100) <= 70) ? 1 : 2);
    $epicChance = $isHardMode ? 15 : 30;
    $hasEpic = ($missionNumber >= 5 && mt_rand(1, 100) <= $epicChance);

    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) $rareIds[] = mt_rand(50, 200);
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;

    // sessão token (server)
    $sessionToken = hash('sha256', $googleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $gameDuration = defined('GAME_DURATION') ? GAME_DURATION : 180;

    // criar session (wallet NULL)
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            google_uid,
            wallet_address,
            session_token,
            mission_number,
            status,
            is_hard_mode,
            rare_asteroids_target,
            epic_asteroid_target,
            rare_ids,
            epic_id,
            ip_address,
            earnings_brl,
            started_at,
            created_at
        ) VALUES (?, NULL, ?, ?, 'active', ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
    ");
    $stmt->execute([
        $googleUid,
        $sessionToken,
        $missionNumber,
        $isHardMode ? 1 : 0,
        $rareCount,
        $hasEpic ? 1 : 0,
        json_encode($rareIds),
        $epicId,
        $clientIP
        // ✅ CORRETO: 9 valores para 9 placeholders (NULL e 'active' são literais)
    ]);

    $sessionId = (int)$pdo->lastInsertId();

    // ip_sessions
    $pdo->prepare("
        INSERT INTO ip_sessions (ip_address, session_id, google_uid, wallet_address, status, started_at)
        VALUES (?, ?, ?, NULL, 'active', NOW())
    ")->execute([$clientIP, $sessionId, $googleUid]);

    if (function_exists('secureLog')) {
        secureLog("GAME_START | IP: {$clientIP} | UID: {$googleUid} | Session: {$sessionId} | Mission: {$missionNumber} | HardMode: " . ($isHardMode ? 'YES' : 'NO'));
    }

    echo json_encode([
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
        'game_duration' => $gameDuration,
        'initial_lives' => defined('INITIAL_LIVES') ? INITIAL_LIVES : 6,
        'missions_remaining' => $maxMissionsPerHour - $missionsThisHour - 1
    ]);

} catch (Throwable $e) {
    error_log("Erro em game-start.php: " . $e->getMessage());
    if (function_exists('secureLog')) secureLog("GAME_START_ERROR | " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
