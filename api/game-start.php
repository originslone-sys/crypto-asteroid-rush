<?php
// ============================================
// CRYPTO ASTEROID RUSH - Iniciar Sessão de Jogo
// Arquivo: api/game-start.php
// v4.0 - Controle de IP: 5 missões/hora, 1 simultânea
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
if (!defined('MAX_MISSIONS_PER_HOUR')) define('MAX_MISSIONS_PER_HOUR', 5);
if (!defined('MAX_CONCURRENT_MISSIONS')) define('MAX_CONCURRENT_MISSIONS', 1);

if (!defined('REWARD_NONE')) define('REWARD_NONE', 0);
if (!defined('REWARD_COMMON')) define('REWARD_COMMON', 0);
if (!defined('REWARD_RARE')) define('REWARD_RARE', 0.0003);
if (!defined('REWARD_EPIC')) define('REWARD_EPIC', 0.0008);
if (!defined('REWARD_LEGENDARY')) define('REWARD_LEGENDARY', 0.002);

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

if (!function_exists('getClientIP')) {
    function getClientIP() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

$input = json_decode(file_get_contents('php://input'), true);
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$txHash = isset($input['txHash']) ? trim($input['txHash']) : '';

if (!validateWallet($wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

// Obter IP do cliente
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
            wallet_address VARCHAR(42) NOT NULL,
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
    // Apenas 1 missão ativa por IP
    // ============================================
    $stmt = $pdo->prepare("
        SELECT ips.id, ips.session_id, ips.wallet_address, gs.status as game_status
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
        // Verificar se a sessão do jogo ainda está ativa
        if ($activeSession['game_status'] === 'active') {
            secureLog("CONCURRENT_BLOCK | IP: {$clientIP} | Wallet: {$wallet} | Active session: {$activeSession['session_id']}");
            
            echo json_encode([
                'success' => false,
                'error' => 'Você já tem uma missão em andamento. Complete-a primeiro.',
                'error_code' => 'CONCURRENT_MISSION',
                'active_session_id' => $activeSession['session_id']
            ]);
            exit;
        } else {
            // Sessão do jogo foi finalizada, atualizar ip_sessions
            $pdo->prepare("
                UPDATE ip_sessions 
                SET status = 'completed', ended_at = NOW()
                WHERE id = ?
            ")->execute([$activeSession['id']]);
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
    $hourlyCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $missionsThisHour = (int)($hourlyCount['mission_count'] ?? 0);

    if ($missionsThisHour >= MAX_MISSIONS_PER_HOUR) {
        // Calcular tempo de espera
        $stmt = $pdo->prepare("
            SELECT started_at
            FROM ip_sessions
            WHERE ip_address = ?
            AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY started_at ASC
            LIMIT 1
        ");
        $stmt->execute([$clientIP]);
        $oldestSession = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $waitSeconds = 0;
        if ($oldestSession) {
            $oldestTime = strtotime($oldestSession['started_at']);
            $waitSeconds = max(0, ($oldestTime + 3600) - time());
        }
        
        $waitMinutes = ceil($waitSeconds / 60);
        
        secureLog("HOURLY_LIMIT | IP: {$clientIP} | Wallet: {$wallet} | Count: {$missionsThisHour}");
        
        echo json_encode([
            'success' => false,
            'error' => "Limite de " . MAX_MISSIONS_PER_HOUR . " missões por hora atingido. Aguarde {$waitMinutes} minutos.",
            'error_code' => 'HOURLY_LIMIT',
            'missions_played' => $missionsThisHour,
            'max_missions' => MAX_MISSIONS_PER_HOUR,
            'wait_seconds' => $waitSeconds,
            'wait_minutes' => $waitMinutes
        ]);
        exit;
    }

    // ============================================
    // 0) RATE LIMIT ADICIONAL (se existir)
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
    $stmt = $pdo->prepare("SELECT id, total_played, is_banned, ban_reason FROM players WHERE wallet_address = ? LIMIT 1");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($player && $player['is_banned']) {
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($player['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ]);
        exit;
    }

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
    // 2) ANTI-SPAM POR WALLET: 3 minutos entre missões
    // (Isso é além do limite de IP)
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
            'error_code' => 'WALLET_COOLDOWN',
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
            ip_address,
            started_at,
            created_at
        ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $wallet,
        $sessionToken,
        $missionNumber,
        $rareCount,
        $hasEpic ? 1 : 0,
        json_encode($rareIds),
        $epicId,
        $txHash,
        $clientIP
    ]);

    $sessionId = (int)$pdo->lastInsertId();

    // Token final
    $sessionToken = generateSessionToken($wallet, $sessionId);

    $stmt = $pdo->prepare("UPDATE game_sessions SET session_token = ? WHERE id = ?");
    $stmt->execute([$sessionToken, $sessionId]);

    // ============================================
    // 5) REGISTRAR NA TABELA DE CONTROLE DE IP
    // ============================================
    $pdo->prepare("
        INSERT INTO ip_sessions (ip_address, session_id, wallet_address, status, started_at)
        VALUES (?, ?, ?, 'active', NOW())
    ")->execute([$clientIP, $sessionId, $wallet]);

    secureLog("GAME_START | IP: {$clientIP} | Wallet: {$wallet} | Session: {$sessionId} | Mission: {$missionNumber} | Missions this hour: " . ($missionsThisHour + 1));

    // ============================================
    // 6) RETORNAR
    // ============================================
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'player_id' => $playerId,
        'mission_number' => $missionNumber,
        'rare_count' => $rareCount,
        'has_epic' => (bool)$hasEpic,
        'rare_ids' => $rareIds,
        'epic_id' => $epicId,
        'game_duration' => GAME_DURATION,
        'missions_remaining' => MAX_MISSIONS_PER_HOUR - $missionsThisHour - 1,
        'rewards' => [
            'none' => REWARD_NONE,
            'common' => REWARD_COMMON,
            'rare' => REWARD_RARE,
            'epic' => REWARD_EPIC,
            'legendary' => REWARD_LEGENDARY
        ]
    ]);

} catch (Exception $e) {
    secureLog("GAME START ERROR | wallet={$wallet} | IP={$clientIP} | msg=" . $e->getMessage());
    error_log("Erro em game-start.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
