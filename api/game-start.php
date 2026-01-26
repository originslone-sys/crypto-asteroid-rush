<?php
// ============================================
// CRYPTO ASTEROID RUSH - Iniciar Sessão de Jogo
// Arquivo: api/game-start.php (FINAL / PRODUÇÃO)
// ============================================

require_once __DIR__ . "/config.php";

// Rate limiter (se existir)
if (file_exists(__DIR__ . "/rate-limiter.php")) {
    require_once __DIR__ . "/rate-limiter.php";
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Fallbacks (caso alguma constante/função não exista no config)
if (!defined('GAME_SECRET_KEY')) define('GAME_SECRET_KEY', 'CAR_2026_S3CR3T_K3Y_X9Z2M4');
if (!defined('GAME_DURATION')) define('GAME_DURATION', 180);
if (!defined('EPIC_MIN_MISSIONS_INTERVAL')) define('EPIC_MIN_MISSIONS_INTERVAL', 15);

if (!defined('REWARD_NONE')) define('REWARD_NONE', 0);
if (!defined('REWARD_COMMON')) define('REWARD_COMMON', 0.0001);
if (!defined('REWARD_RARE')) define('REWARD_RARE', 0.01);
if (!defined('REWARD_EPIC')) define('REWARD_EPIC', 0.10);

if (!function_exists('generateSessionToken')) {
    function generateSessionToken($wallet, $sessionId) {
        $data = $wallet . '|' . $sessionId . '|' . time() . '|' . GAME_SECRET_KEY;
        return hash('sha256', $data);
    }
}

if (!function_exists('secureLog')) {
    function secureLog($message, $file = 'game_security.log') {
        $logEntry = date('Y-m-d H:i:s') . ' | ' . $message . "\n";
        file_put_contents(__DIR__ . '/' . $file, $logEntry, FILE_APPEND);
    }
}

if (!function_exists('validateWallet')) {
    function validateWallet($wallet) {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet);
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$txHash = isset($input['txHash']) ? trim($input['txHash']) : '';

if (!validateWallet($wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
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
    // 0) RATE LIMIT (se existir)
    // ============================================
    if (class_exists('RateLimiter')) {
        $limiter = new RateLimiter($pdo, $wallet);
        $rateCheck = $limiter->checkGameStart();

        if (!($rateCheck['allowed'] ?? true)) {
            echo json_encode([
                'success' => false,
                'error' => $rateCheck['error'] ?? 'Rate limit',
                'wait_seconds' => $rateCheck['wait_seconds'] ?? null,
                'retry_after' => $rateCheck['retry_after'] ?? null,
                'banned' => $rateCheck['banned'] ?? false
            ]);
            exit;
        }
    }

    // ============================================
    // 1) BUSCAR PLAYER
    // ============================================
    $stmt = $pdo->prepare("SELECT id, total_played FROM players WHERE wallet_address = ? LIMIT 1");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        // Cria player (mantém o schema atual)
        $stmtIns = $pdo->prepare("INSERT INTO players (wallet_address, balance_usdt, total_played) VALUES (?, 0, 0)");
        $stmtIns->execute([$wallet]);

        $playerId = (int)$pdo->lastInsertId();
        $totalPlayed = 0;
    } else {
        $playerId = (int)$player['id'];
        $totalPlayed = (int)$player['total_played'];
    }

    $missionNumber = $totalPlayed + 1;

    // ============================================
    // 2) ANTI-SPAM: 1 jogo a cada 3 minutos (além do rate-limiter)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, created_at
        FROM game_sessions
        WHERE wallet_address = ?
          AND status IN ('active', 'completed')
          AND created_at > DATE_SUB(NOW(), INTERVAL 3 MINUTE)
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $recentSession = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($recentSession) {
        $waitTime = 180 - (time() - strtotime($recentSession['created_at']));
        echo json_encode([
            'success' => false,
            'error' => 'Aguarde antes de jogar novamente',
            'wait_seconds' => max(0, (int)$waitTime)
        ]);
        exit;
    }

    // ============================================
    // 3) DEFINIR ESPECIAIS (rare/epic)
    // ============================================
    // Raros: 70% chance 1, 30% chance 2
    $rareCount = (mt_rand(1, 100) <= 70) ? 1 : 2;

    // Épico: a cada 15+ missões (desde o último épico), com 30% de chance
    $stmt = $pdo->prepare("
        SELECT MAX(mission_number) AS last_epic
        FROM game_sessions
        WHERE wallet_address = ?
          AND epic_asteroids > 0
    ");
    $stmt->execute([$wallet]);
    $lastEpic = $stmt->fetch(PDO::FETCH_ASSOC);

    $lastEpicMission = isset($lastEpic['last_epic']) ? (int)$lastEpic['last_epic'] : 0;
    $missionsSinceEpic = $missionNumber - $lastEpicMission;
    $hasEpic = ($missionsSinceEpic >= EPIC_MIN_MISSIONS_INTERVAL && mt_rand(1, 100) <= 30);

    // IDs dos especiais (cliente valida/alinha isso)
    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;

    // ============================================
    // 4) CRIAR SESSÃO
    // (schema atual: game_sessions NÃO possui player_id)
    // ============================================
    $sessionToken = '';

    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            wallet_address,
            session_token,
            mission_number,
            status,
            rare_asteroids_target,
            epic_asteroid_target,
            rare_ids,
            epic_id,
            tx_hash,
            started_at,
            created_at
        ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $wallet,
        $sessionToken,
        $missionNumber,
        $rareCount,
        $hasEpic ? 1 : 0,
        json_encode($rareIds),
        $epicId,
        $txHash
    ]);

    $sessionId = (int)$pdo->lastInsertId();

    // Token final
    $sessionToken = generateSessionToken($wallet, $sessionId);

    $stmt = $pdo->prepare("UPDATE game_sessions SET session_token = ? WHERE id = ?");
    $stmt->execute([$sessionToken, $sessionId]);

    // ============================================
    // 5) RETORNAR
    // ============================================
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'player_id' => $playerId, // útil no frontend, mesmo sem coluna na tabela
        'mission_number' => $missionNumber,
        'rare_count' => $rareCount,
        'has_epic' => (bool)$hasEpic,
        'rare_ids' => $rareIds,
        'epic_id' => $epicId,
        'game_duration' => GAME_DURATION,
        'rewards' => [
            'none' => REWARD_NONE,
            'common' => REWARD_COMMON,
            'rare' => REWARD_RARE,
            'epic' => REWARD_EPIC
        ]
    ]);

} catch (Exception $e) {
    secureLog("GAME START ERROR | wallet={$wallet} | msg=" . $e->getMessage());
    error_log("Erro em game-start.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
