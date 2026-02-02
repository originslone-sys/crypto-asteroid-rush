<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// Arquivo: api/game-start.php
// Unobix-only: google_uid obrigatório, wallet não é identidade
// ============================================

ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . "/config-cloudrun.php";

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
        if (function_exists('secureLog')) secureLog("GAME_START_DB_FAIL | google_uid: {$googleUid}");
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

    // 3) buscar user (usando tabela users que já existe)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado. Faça login novamente.']);
        exit;
    }

    if (!empty($user['is_banned'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ]);
        exit;
    }

    $userId = (int)$user['id'];
    // Como users não tem total_played, usamos um valor padrão ou contamos game_sessions
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM game_sessions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $sessionCount = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalPlayed = (int)($sessionCount['total'] ?? 0);
    $missionNumber = $totalPlayed + 1;

    // hard mode (simplificado para schema atual)
    $hardModePercentage = defined('HARD_MODE_PERCENTAGE') ? HARD_MODE_PERCENTAGE : 40;
    $isHardMode = (mt_rand(1, 100) <= $hardModePercentage);

    // game duration
    $gameDuration = defined('GAME_DURATION') ? GAME_DURATION : 180;

    // criar session usando schema real da tabela game_sessions
    $sessionUuid = bin2hex(random_bytes(18)); // 36 caracteres hex
    
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            user_id,
            session_uuid,
            google_uid,
            is_hard_mode,
            status,
            earnings_brl,
            ip_address,
            created_at
        ) VALUES (?, ?, ?, ?, 'active', 0, ?, NOW())
    ");
    $stmt->execute([
        $userId,                 // 1. user_id (relacionamento correto)
        $sessionUuid,            // 2. session_uuid (36 chars)
        $googleUid,              // 3. google_uid (para compatibilidade)
        $isHardMode ? 1 : 0,     // 4. is_hard_mode
        $clientIP                // 5. ip_address
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
        'session_uuid' => $sessionUuid,
        'user_id' => $userId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
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
