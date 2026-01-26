<?php
// ============================================
// CRYPTO ASTEROID RUSH - Iniciar Sessão de Jogo
// Arquivo: api/game-start.php
// ============================================

// Tentar incluir config de diferentes locais
if (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
} elseif (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Arquivo de configuração não encontrado']);
    exit;
}

// Incluir Rate Limiter
if (file_exists(__DIR__ . "/rate-limiter.php")) {
    require_once __DIR__ . "/rate-limiter.php";
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Constantes de segurança (caso não existam no config.php antigo)
if (!defined('GAME_SECRET_KEY')) define('GAME_SECRET_KEY', 'CAR_2026_S3CR3T_K3Y_X9Z2M4');
if (!defined('GAME_DURATION')) define('GAME_DURATION', 180);
if (!defined('EPIC_MIN_MISSIONS_INTERVAL')) define('EPIC_MIN_MISSIONS_INTERVAL', 15);
if (!defined('REWARD_NONE')) define('REWARD_NONE', 0);
if (!defined('REWARD_COMMON')) define('REWARD_COMMON', 0.0001);
if (!defined('REWARD_RARE')) define('REWARD_RARE', 0.01);
if (!defined('REWARD_EPIC')) define('REWARD_EPIC', 0.10);

// Função para gerar token (caso não exista)
if (!function_exists('generateSessionToken')) {
    function generateSessionToken($wallet, $sessionId) {
        $data = $wallet . '|' . $sessionId . '|' . time() . '|' . GAME_SECRET_KEY;
        return hash('sha256', $data);
    }
}

// Função para log seguro (caso não exista)
if (!function_exists('secureLog')) {
    function secureLog($message, $file = 'game_security.log') {
        $logEntry = date('Y-m-d H:i:s') . ' | ' . $message . "\n";
        file_put_contents(__DIR__ . '/' . $file, $logEntry, FILE_APPEND);
    }
}

$input = json_decode(file_get_contents('php://input'), true);

// Validar entrada
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$txHash = isset($input['txHash']) ? trim($input['txHash']) : '';

if (!validateWallet($wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

try {
    // Conexão com banco (Railway-safe)
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    // ============================================
    // 0. VERIFICAR RATE LIMIT
    // ============================================
    if (class_exists('RateLimiter')) {
        $limiter = new RateLimiter($pdo, $wallet);
        $rateCheck = $limiter->checkGameStart();

        if (!$rateCheck['allowed']) {
            echo json_encode([
                'success' => false,
                'error' => $rateCheck['error'],
                'wait_seconds' => $rateCheck['wait_seconds'] ?? null,
                'retry_after' => $rateCheck['retry_after'] ?? null,
                'banned' => $rateCheck['banned'] ?? false
            ]);
            exit;
        }
    }

    // ============================================
    // 1. VALIDAR TX HASH (se fornecido)
    // ============================================
    if (!empty($txHash) && !preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
        echo json_encode(['success' => false, 'error' => 'Hash de transação inválido']);
        exit;
    }

    // ============================================
    // 2. BUSCAR/CRIAR JOGADOR
    // ============================================
    $stmt = $pdo->prepare("SELECT id, total_played FROM players WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch();

    if (!$player) {
        $pdo->prepare("INSERT INTO players (wallet_address, balance_usdt, total_played) VALUES (?, 0.0, 0)")
            ->execute([$wallet]);

        $stmt = $pdo->prepare("SELECT id, total_played FROM players WHERE wallet_address = ?");
        $stmt->execute([$wallet]);
        $player = $stmt->fetch();
    }

    $playerId = (int)$player['id'];
    $totalPlayed = (int)$player['total_played'];

    // ============================================
    // 3. CALCULAR NÚMERO DA MISSÃO
    // ============================================
    $missionNumber = $totalPlayed + 1;

    // ============================================
    // 4. SORTEAR RAROS E EPIC DENTRO DOS LIMITES
    // ============================================
    $rareCount = 0;
    $rareIds = [];
    $hasEpic = false;
    $epicId = null;

    // Raros (até MAX_RARE_PER_MISSION)
    $rareCount = rand(0, MAX_RARE_PER_MISSION);
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = rand(1000, 9999);
    }

    // Epic (máximo 1, respeitando intervalo)
    if ($missionNumber % EPIC_MIN_MISSIONS_INTERVAL === 0) {
        $hasEpic = true;
        $epicId = rand(10000, 99999);
    }

    // ============================================
    // 5. CRIAR GAME SESSION
    // ============================================
    $sessionToken = ''; // será atualizado após obter o ID
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions 
            (player_id, wallet_address, session_token, mission_number, rare_count, has_epic, rare_ids, epic_id, tx_hash, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $playerId,
        $wallet,
        $sessionToken,
        $missionNumber,
        $rareCount,
        $hasEpic ? 1 : 0,
        json_encode($rareIds),
        $epicId,
        $txHash
    ]);

    $sessionId = $pdo->lastInsertId();

    // ============================================
    // 6. GERAR TOKEN DE SESSÃO
    // ============================================
    $sessionToken = generateSessionToken($wallet, $sessionId);

    $pdo->prepare("UPDATE game_sessions SET session_token = ? WHERE id = ?")
        ->execute([$sessionToken, $sessionId]);

    // ============================================
    // 7. ATUALIZAR TOTAL PLAYED DO JOGADOR
    // ============================================
    $pdo->prepare("UPDATE players SET total_played = total_played + 1 WHERE id = ?")
        ->execute([$playerId]);

    // ============================================
    // 8. RETORNAR DADOS DA SESSÃO
    // ============================================
    $response = [
        'success' => true,
        'session_id' => (int)$sessionId,
        'session_token' => $sessionToken,
        'mission_number' => $missionNumber,
        'rare_count' => $rareCount,
        'has_epic' => $hasEpic,
        'rare_ids' => $rareIds,
        'epic_id' => $epicId,
        'game_duration' => GAME_DURATION,
        'rewards' => [
            'none' => REWARD_NONE,
            'common' => REWARD_COMMON,
            'rare' => REWARD_RARE,
            'epic' => REWARD_EPIC
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    secureLog("GAME START ERROR | wallet={$wallet} | msg=" . $e->getMessage());
    error_log("Erro em game-start.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
