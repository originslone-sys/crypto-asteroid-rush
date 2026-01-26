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

// ===== DEBUG / DIAGNÓSTICO (remover depois) =====
$__REQ_ID = null;
try { $__REQ_ID = bin2hex(random_bytes(4)); } catch (Exception $e) { $__REQ_ID = (string)mt_rand(1000,9999); }
function gs_log($msg) {
    global $__REQ_ID;
    error_log("[GS][$__REQ_ID] " . $msg);
}
function gs_exec(PDOStatement $stmt, array $params, string $label) {
    $t0 = microtime(true);
    gs_log("EXEC {$label} | sql=" . $stmt->queryString . " | params=" . json_encode($params));
    try {
        $ok = $stmt->execute($params);
        gs_log("DONE {$label} | ms=" . round((microtime(true)-$t0)*1000));
        return $ok;
    } catch (PDOException $e) {
        gs_log("FAIL {$label} | ms=" . round((microtime(true)-$t0)*1000) . " | msg=" . $e->getMessage() . " | info=" . json_encode($e->errorInfo) . " | sql=" . $stmt->queryString);
        throw $e;
    }
}
// ===============================================
gs_log('MARKER_GAME_START_V2 file=' . __FILE__);
gs_log('MARKER_GAME_START_V2 sha1=' . sha1_file(__FILE__));

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
    // Conexão com banco (Railway/Docker-safe) + logs
    if (function_exists('getDatabaseConnection')) {
        $pdo = getDatabaseConnection();
        if (!$pdo) {
            throw new Exception('DB connection failed (getDatabaseConnection returned null)');
        }
    } else {
        $host = (defined('DB_HOST') && DB_HOST === 'localhost') ? '127.0.0.1' : (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
        $port = defined('DB_PORT') ? DB_PORT : 3306;
        $dsn  = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    try {
        $dbNameRuntime = $pdo->query('SELECT DATABASE()')->fetchColumn();
        gs_log('DB runtime database=' . $dbNameRuntime);
        $cols = $pdo->query('SHOW COLUMNS FROM game_sessions')->fetchAll(PDO::FETCH_COLUMN);
        gs_log('game_sessions columns=' . json_encode($cols));
    } catch (Exception $e) {
        gs_log('DB introspection failed: ' . $e->getMessage());
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
    // 1. VERIFICAR SE JOGADOR EXISTE
    // ============================================
    $stmt = $pdo->prepare("SELECT id, total_played FROM players WHERE wallet_address = ?");
    gs_exec($stmt, [$wallet], 'select_player');
    $player = $stmt->fetch();

    if (!$player) {
        $stmtIns = $pdo->prepare("INSERT INTO players (wallet_address, balance_usdt, total_played) VALUES (?, 0, 0)");
        gs_exec($stmtIns, [$wallet], 'insert_player');
        $playerId = $pdo->lastInsertId();
        $totalPlayed = 0;
    } else {
        $playerId = $player['id'];
        $totalPlayed = $player['total_played'];
    }

    // ============================================
    // 2. CALCULAR NÚMERO DA MISSÃO
    // ============================================
    $missionNumber = $totalPlayed + 1;

    // ============================================
    // 3. VERIFICAR RATE LIMIT (1 jogo a cada 3 min)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, created_at FROM game_sessions 
        WHERE wallet_address = ? 
        AND status IN ('active', 'completed')
        AND created_at > DATE_SUB(NOW(), INTERVAL 3 MINUTE)
        ORDER BY created_at DESC LIMIT 1
    ");
    gs_exec($stmt, [$wallet], 'recent_session_check');
    $recentSession = $stmt->fetch();

    if ($recentSession) {
        $waitTime = 180 - (time() - strtotime($recentSession['created_at']));
        echo json_encode([
            'success' => false,
            'error' => 'Aguarde antes de jogar novamente',
            'wait_seconds' => max(0, $waitTime)
        ]);
        exit;
    }

    // ============================================
    // 4. DEFINIR ASTEROIDES ESPECIAIS
    // ============================================
    // Raros: 1-2 por missão (70% chance de 1, 30% chance de 2)
    $rareCount = (mt_rand(1, 100) <= 70) ? 1 : 2;

    // Épico: a cada 15+ missões com 30% de chance
    $stmt = $pdo->prepare("
        SELECT MAX(mission_number) as last_epic 
        FROM game_sessions 
        WHERE wallet_address = ? AND epic_asteroids > 0
    ");
    gs_exec($stmt, [$wallet], 'last_epic_check');
    $lastEpic = $stmt->fetch();
    $lastEpicMission = $lastEpic['last_epic'] ?? 0;

    $missionsSinceEpic = $missionNumber - (int)$lastEpicMission;
    $hasEpic = ($missionsSinceEpic >= EPIC_MIN_MISSIONS_INTERVAL && mt_rand(1, 100) <= 30);

    // Gerar IDs dos asteroides especiais
    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }

    $epicId = $hasEpic ? mt_rand(201, 250) : 0;

    // ============================================
    // 5. CRIAR SESSÃO DE JOGO
    // ============================================
    $sessionToken = ''; // será atualizado após o insert

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

    gs_log('about_to_insert_game_sessions');

    gs_exec($stmt, [
        $wallet,
        $sessionToken,
        $missionNumber,
        $rareCount,
        $hasEpic ? 1 : 0,
        json_encode($rareIds),
        $epicId,
        $txHash
    ], 'insert_game_session');

    $sessionId = $pdo->lastInsertId();

    // ============================================
    // 6. GERAR TOKEN DE SESSÃO
    // ============================================
    $sessionToken = generateSessionToken($wallet, $sessionId);

    $stmtUpd = $pdo->prepare("UPDATE game_sessions SET session_token = ? WHERE id = ?");
    gs_exec($stmtUpd, [$sessionToken, $sessionId], 'update_session_token');

    // ============================================
    // 7. RETORNAR DADOS DA SESSÃO
    // ============================================
    echo json_encode([
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
    ]);

} catch (Exception $e) {
    secureLog("GAME START ERROR | wallet={$wallet} | msg=" . $e->getMessage());
    error_log("Erro em game-start.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
