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

// Função para validar carteira (caso não exista)
if (!function_exists('validateWallet')) {
    function validateWallet($wallet) {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet);
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
    // Conexão com banco
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
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
        
        // Log se wallet está flagged
        if (!empty($rateCheck['flagged'])) {
            secureLog("FLAGGED_PLAYER_START | Wallet: {$wallet}");
        }
    }
    
    // ============================================
    // 1. VERIFICAR SE JOGADOR EXISTE
    // ============================================
    $stmt = $pdo->prepare("SELECT id, total_played FROM players WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch();
    
    if (!$player) {
        $pdo->prepare("INSERT INTO players (wallet_address, balance_usdt, total_played) VALUES (?, 0, 0)")
            ->execute([$wallet]);
        $playerId = $pdo->lastInsertId();
        $totalPlayed = 0;
    } else {
        $playerId = $player['id'];
        $totalPlayed = (int)$player['total_played'];
    }
    
    // ============================================
    // 2. VERIFICAR SE TABELA game_sessions EXISTE
    // ============================================
    $tableExists = $pdo->query("SHOW TABLES LIKE 'game_sessions'")->fetch();
    
    if (!$tableExists) {
        // Criar tabela se não existir
        $pdo->exec("
            CREATE TABLE game_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                wallet_address VARCHAR(42) NOT NULL,
                session_token VARCHAR(64) DEFAULT '',
                mission_number INT NOT NULL,
                status ENUM('active', 'completed', 'expired', 'flagged', 'cancelled') DEFAULT 'active',
                rare_asteroids_target INT DEFAULT 0,
                epic_asteroid_target INT DEFAULT 0,
                rare_ids TEXT DEFAULT NULL,
                epic_id INT DEFAULT -1,
                asteroids_destroyed INT DEFAULT 0,
                rare_asteroids INT DEFAULT 0,
                epic_asteroids INT DEFAULT 0,
                earnings_usdt DECIMAL(20,8) DEFAULT 0.00000000,
                client_score INT DEFAULT NULL,
                client_earnings DECIMAL(20,8) DEFAULT NULL,
                validation_errors TEXT DEFAULT NULL,
                tx_hash VARCHAR(66) DEFAULT NULL,
                entry_fee_bnb DECIMAL(20,8) DEFAULT 0.00001,
                session_duration INT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                ended_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_wallet (wallet_address),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        secureLog("TABLE_CREATED | game_sessions");
    }
    
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
    $stmt->execute([$wallet]);
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
    // 4. DETERMINAR RECOMPENSAS DA MISSÃO
    // ============================================
    $missionNumber = $totalPlayed + 1;
    
    // Raros: 1-2 por missão (70% chance de 1, 30% chance de 2)
    $rareCount = (mt_rand(1, 100) <= 70) ? 1 : 2;
    
    // Épico: a cada 15+ missões com 30% de chance
    $stmt = $pdo->prepare("
        SELECT MAX(mission_number) as last_epic 
        FROM game_sessions 
        WHERE wallet_address = ? AND epic_asteroids > 0
    ");
    $stmt->execute([$wallet]);
    $lastEpic = $stmt->fetch();
    $lastEpicMission = $lastEpic['last_epic'] ?? 0;
    
    $missionsSinceEpic = $missionNumber - (int)$lastEpicMission;
    $hasEpic = ($missionsSinceEpic >= EPIC_MIN_MISSIONS_INTERVAL && mt_rand(1, 100) <= 30);
    
    // Gerar IDs dos asteroides especiais
    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }
    $epicId = $hasEpic ? mt_rand(100, 200) : -1;
    
    // ============================================
    // 5. CRIAR SESSÃO DE JOGO
    // ============================================
    $sessionToken = ''; // Será atualizado depois
    
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
    
    $sessionId = $pdo->lastInsertId();
    
    // ============================================
    // 6. GERAR TOKEN DE SESSÃO
    // ============================================
    $sessionToken = generateSessionToken($wallet, $sessionId);
    
    $pdo->prepare("UPDATE game_sessions SET session_token = ? WHERE id = ?")
        ->execute([$sessionToken, $sessionId]);
    
    // ============================================
    // 7. RETORNAR DADOS DA SESSÃO
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
    
    secureLog("SESSION_START | Wallet: {$wallet} | Session: {$sessionId} | Mission: {$missionNumber}");
    
    echo json_encode($response);
    
} catch (Exception $e) {
    secureLog("SESSION_ERROR | Wallet: {$wallet} | Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao iniciar sessão: ' . $e->getMessage()]);
}
?>
