<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// Arquivo: api/game-start.php
// v5.0 - Unobix-only (google_uid), sem wallet/web3, compat DB legado
// ============================================

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function dbWalletCompat(string $googleUid): string {
    // Compat determinística (não é identidade real; apenas para schema legado NOT NULL)
    return '0x' . substr(hash('sha256', 'unobix|' . $googleUid), 0, 40);
}

// ============================================
// LER INPUT
// ============================================
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = [];

$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
if ($googleUid === '' || !validateGoogleUid($googleUid)) {
    jsonOut(['success' => false, 'error' => 'Identificação inválida. Faça login novamente.'], 400);
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        jsonOut(['success' => false, 'error' => 'Erro ao conectar ao banco'], 500);
    }

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
    $activeSession = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeSession) {
        if (($activeSession['game_status'] ?? null) === 'active') {
            jsonOut([
                'success' => false,
                'error' => 'Você já tem uma missão em andamento. Complete-a primeiro.',
                'error_code' => 'CONCURRENT_MISSION'
            ], 409);
        } else {
            $pdo->prepare("UPDATE ip_sessions SET status = 'completed', ended_at = NOW() WHERE id = ?")
                ->execute([$activeSession['id']]);
        }
    }

    // ============================================
    // VERIFICAÇÃO 2: Limite por IP/hora
    // ============================================
    $maxMissionsPerHour = defined('MAX_MISSIONS_PER_HOUR') ? MAX_MISSIONS_PER_HOUR : 5;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as mission_count
        FROM ip_sessions
        WHERE ip_address = ?
          AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$clientIP]);
    $missionsThisHour = (int)(($stmt->fetch(PDO::FETCH_ASSOC)['mission_count'] ?? 0));

    if ($missionsThisHour >= $maxMissionsPerHour) {
        $stmt = $pdo->prepare("
            SELECT started_at
            FROM ip_sessions
            WHERE ip_address = ?
              AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY started_at ASC
            LIMIT 1
        ");
        $stmt->execute([$clientIP]);
        $oldest = $stmt->fetch(PDO::FETCH_ASSOC);

        $waitSeconds = 0;
        if ($oldest && !empty($oldest['started_at'])) {
            $waitSeconds = max(0, (strtotime($oldest['started_at']) + 3600) - time());
        }

        jsonOut([
            'success' => false,
            'error' => "Limite de {$maxMissionsPerHour} missões por hora atingido. Aguarde " . ceil($waitSeconds / 60) . " minutos.",
            'error_code' => 'HOURLY_LIMIT',
            'wait_seconds' => $waitSeconds
        ], 429);
    }

    // ============================================
    // BUSCAR/CRIAR JOGADOR (Unobix-only)
    // ============================================
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        // Cria sem wallet (requer schema players.wallet_address nullable)
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, balance_brl, total_played, created_at)
            VALUES (?, 0.00, 0, NOW())
        ");
        $stmt->execute([$googleUid]);

        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$player) {
        jsonOut(['success' => false, 'error' => 'Não foi possível identificar o jogador'], 500);
    }

    if (!empty($player['is_banned']) && (int)$player['is_banned'] === 1) {
        jsonOut([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($player['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ], 403);
    }

    $playerId      = (int)$player['id'];
    $totalPlayed   = (int)($player['total_played'] ?? 0);
    $missionNumber = $totalPlayed + 1;

    // ============================================
    // HARD MODE (40%)
    // ============================================
    $hardModePercentage = defined('HARD_MODE_PERCENTAGE') ? HARD_MODE_PERCENTAGE : 40;
    $isHardMode = (mt_rand(1, 100) <= $hardModePercentage);

    // ============================================
    // DEFINIR ESPECIAIS (rare/epic)
    // ============================================
    if ($isHardMode) {
        $rareCount = (mt_rand(1, 100) <= 50) ? 1 : 0;
    } else {
        $rareCount = (mt_rand(1, 100) <= 70) ? 1 : 2;
    }

    $epicChance = $isHardMode ? 15 : 30;
    $hasEpic = ($missionNumber >= 5 && mt_rand(1, 100) <= $epicChance);

    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;

    // ============================================
    // CRIAR SESSÃO
    // ============================================
    $sessionToken = hash('sha256', $googleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $gameDuration = defined('GAME_DURATION') ? GAME_DURATION : 180;

    // Compat DB legado: algumas versões ainda exigem wallet_address NOT NULL em game_sessions
    $walletCompat = $player['wallet_address'] ?? null;
    if (!$walletCompat || !validateWallet($walletCompat)) {
        $walletCompat = dbWalletCompat($googleUid);
    }

    // Insert session (google_uid sempre)
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
        ) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, 0.00, NOW(), NOW())
    ");
    $stmt->execute([
        $googleUid,
        $walletCompat,            // compat (não depende do front)
        $sessionToken,
        $missionNumber,
        $isHardMode ? 1 : 0,
        $rareCount,
        $hasEpic ? 1 : 0,
        json_encode($rareIds),
        $epicId,
        $clientIP
    ]);

    $sessionId = (int)$pdo->lastInsertId();

    // ============================================
    // REGISTRAR NA TABELA IP_SESSIONS
    // ============================================
    $pdo->prepare("
        INSERT INTO ip_sessions (ip_address, session_id, google_uid, wallet_address, status, started_at)
        VALUES (?, ?, ?, ?, 'active', NOW())
    ")->execute([$clientIP, $sessionId, $googleUid, $walletCompat]);

    if (function_exists('secureLog')) {
        secureLog("GAME_START | IP: {$clientIP} | UID: {$googleUid} | Session: {$sessionId} | Mission: {$missionNumber} | HardMode: " . ($isHardMode ? 'YES' : 'NO'));
    }

    jsonOut([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'player_id' => $playerId,
        'google_uid' => $googleUid,
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
    jsonOut(['success' => false, 'error' => 'Erro interno do servidor'], 500);
}
