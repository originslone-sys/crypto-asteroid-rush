<?php
// ============================================
// CRYPTO ASTEROID RUSH - Registrar Evento de Jogo
// Arquivo: api/game-event.php
// v2.0 - Rate limiting mais permissivo
// ============================================

if (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
} elseif (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Constantes - RATE LIMIT MAIS PERMISSIVO
if (!defined('GAME_DURATION')) define('GAME_DURATION', 180);
if (!defined('GAME_TOLERANCE')) define('GAME_TOLERANCE', 300); // 5 minutos de tolerância
if (!defined('MAX_EVENTS_PER_SECOND')) define('MAX_EVENTS_PER_SECOND', 10); // Aumentado de 3 para 10
if (!defined('REWARD_NONE')) define('REWARD_NONE', 0);
if (!defined('REWARD_COMMON')) define('REWARD_COMMON', 0.0001);
if (!defined('REWARD_RARE')) define('REWARD_RARE', 0.0003);
if (!defined('REWARD_EPIC')) define('REWARD_EPIC', 0.0008);
if (!defined('REWARD_LEGENDARY')) define('REWARD_LEGENDARY', 0.002);

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

$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$sessionToken = isset($input['session_token']) ? trim($input['session_token']) : '';
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$asteroidId = isset($input['asteroid_id']) ? (int)$input['asteroid_id'] : 0;
$rewardType = isset($input['reward_type']) ? trim($input['reward_type']) : 'none';
$rewardAmount = isset($input['reward_amount']) ? (float)$input['reward_amount'] : 0;
$timestamp = isset($input['timestamp']) ? (int)$input['timestamp'] : time();

if (!$sessionId || !$sessionToken || !validateWallet($wallet)) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // ============================================
    // 1. VALIDAR SESSÃO (permite completed também para eventos atrasados)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT * FROM game_sessions 
        WHERE id = ? AND wallet_address = ? AND status IN ('active', 'completed')
    ");
    $stmt->execute([$sessionId, $wallet]);
    $session = $stmt->fetch();
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessão inválida ou expirada']);
        exit;
    }
    
    if ($session['session_token'] !== $sessionToken) {
        echo json_encode(['success' => false, 'error' => 'Token inválido']);
        exit;
    }
    
    // Verificar tempo da sessão (com tolerância)
    $sessionStart = strtotime($session['started_at']);
    $elapsed = time() - $sessionStart;
    
    if ($elapsed > GAME_DURATION + GAME_TOLERANCE) {
        echo json_encode(['success' => false, 'error' => 'Sessão expirada']);
        exit;
    }
    
    // ============================================
    // 2. VERIFICAR SE TABELA game_events EXISTE
    // ============================================
    $tableExists = $pdo->query("SHOW TABLES LIKE 'game_events'")->fetch();
    
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE game_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NOT NULL,
                wallet_address VARCHAR(42) NOT NULL,
                asteroid_id INT NOT NULL,
                reward_type ENUM('none', 'common', 'rare', 'epic', 'legendary') DEFAULT 'none',
                reward_amount DECIMAL(20,8) DEFAULT 0.00000000,
                client_timestamp DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_wallet (wallet_address),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    
    // ============================================
    // 3. VERIFICAR RATE LIMIT (mais permissivo)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM game_events 
        WHERE session_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)
    ");
    $stmt->execute([$sessionId]);
    $recentEvents = $stmt->fetch();
    
    if ($recentEvents['count'] >= MAX_EVENTS_PER_SECOND) {
        // Em vez de rejeitar, apenas logamos e aceitamos (para não perder eventos)
        // O game-end.php vai validar os totais de qualquer forma
    }
    
    // ============================================
    // 4. DETERMINAR RECOMPENSA
    // ============================================
    $validReward = 0;
    $validRewardType = 'none';
    
    // Aceitar o tipo enviado pelo cliente se for válido
    $validTypes = ['none', 'common', 'rare', 'epic', 'legendary'];
    if (in_array($rewardType, $validTypes)) {
        $validRewardType = $rewardType;
        
        switch ($rewardType) {
            case 'legendary':
                $validReward = REWARD_LEGENDARY;
                break;
            case 'epic':
                $validReward = REWARD_EPIC;
                break;
            case 'rare':
                $validReward = REWARD_RARE;
                break;
            case 'common':
                $validReward = REWARD_COMMON;
                break;
            default:
                $validReward = 0;
        }
    }
    
    // Se cliente enviou reward_amount, usar esse valor (com limite)
    if ($rewardAmount > 0 && $rewardAmount <= 0.01) {
        $validReward = $rewardAmount;
    }
    
    // ============================================
    // 5. REGISTRAR EVENTO
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO game_events (
            session_id, 
            wallet_address, 
            asteroid_id, 
            reward_type,
            reward_amount,
            client_timestamp,
            created_at
        ) VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), NOW())
    ");
    
    $stmt->execute([
        $sessionId,
        $wallet,
        $asteroidId,
        $validRewardType,
        $validReward,
        $timestamp
    ]);
    
    // ============================================
    // 6. ATUALIZAR CONTADORES DA SESSÃO (opcional)
    // ============================================
    if ($session['status'] === 'active') {
        $updateFields = ['asteroids_destroyed = asteroids_destroyed + 1'];
        $updateFields[] = "earnings_usdt = earnings_usdt + {$validReward}";
        
        if ($validRewardType === 'legendary' || $validRewardType === 'epic') {
            $updateFields[] = 'epic_asteroids = epic_asteroids + 1';
        } elseif ($validRewardType === 'rare') {
            $updateFields[] = 'rare_asteroids = rare_asteroids + 1';
        }
        
        $pdo->prepare("UPDATE game_sessions SET " . implode(', ', $updateFields) . " WHERE id = ?")
            ->execute([$sessionId]);
    }
    
    echo json_encode([
        'success' => true,
        'reward_type' => $validRewardType,
        'reward_amount' => $validReward,
        'event_id' => $pdo->lastInsertId()
    ]);
    
} catch (Exception $e) {
    secureLog("EVENT_ERROR | Session: {$sessionId} | Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao registrar evento']);
}
?>