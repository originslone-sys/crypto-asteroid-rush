<?php
// ============================================
// CRYPTO ASTEROID RUSH - Finalizar Sessão de Jogo
// Arquivo: api/game-end.php
// v6.1 - FIX: Cria jogador se não existir
// ============================================

if (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
} elseif (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
}

if (file_exists(__DIR__ . "/referral-helper.php")) {
    require_once __DIR__ . "/referral-helper.php";
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ============================================
// CONFIGURAÇÕES DE SEGURANÇA
// ============================================
define('EARNINGS_ALERT_THRESHOLD', 0.06);    // Alerta se > $0.06
define('EARNINGS_SUSPECT_THRESHOLD', 0.10);  // Suspeito se > $0.10
define('EARNINGS_BLOCK_THRESHOLD', 0.20);    // Bloqueia se > $0.20
define('AUTO_BAN_AFTER_ALERTS', 5);          // Ban automático após 5 alertas

// Recompensas (fonte da verdade)
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

// ============================================
// FUNÇÃO: Registrar atividade suspeita
// ============================================
function registerSuspiciousActivity($pdo, $wallet, $sessionId, $type, $data, $ipAddress = null) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suspicious_activity (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            wallet_address VARCHAR(42) NOT NULL,
            session_id INT DEFAULT NULL,
            activity_type VARCHAR(50) NOT NULL,
            activity_data TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            screen_width INT DEFAULT NULL,
            screen_height INT DEFAULT NULL,
            devtools_detected TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wallet (wallet_address),
            INDEX idx_type (activity_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $stmt = $pdo->prepare("
        INSERT INTO suspicious_activity 
        (wallet_address, session_id, activity_type, activity_data, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $wallet,
        $sessionId,
        $type,
        json_encode($data),
        $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? '')
    ]);
    
    return $pdo->lastInsertId();
}

// ============================================
// FUNÇÃO: Verificar e aplicar penalidades
// ============================================
function checkAndApplyPenalties($pdo, $wallet) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM suspicious_activity
        WHERE wallet_address = ?
        AND activity_type LIKE 'HIGH_EARNINGS%'
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$wallet]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $alertCount = (int)$result['count'];
    
    if ($alertCount >= AUTO_BAN_AFTER_ALERTS) {
        $stmt = $pdo->prepare("SELECT is_banned FROM players WHERE wallet_address = ?");
        $stmt->execute([$wallet]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($player && !$player['is_banned']) {
            $pdo->prepare("
                UPDATE players 
                SET is_banned = 1, ban_reason = ?
                WHERE wallet_address = ?
            ")->execute([
                "Auto-ban: {$alertCount} alertas de earnings suspeitos em 24h",
                $wallet
            ]);
            
            secureLog("AUTO_BAN | Wallet: {$wallet} | Alertas: {$alertCount}");
            return ['banned' => true, 'alert_count' => $alertCount];
        }
    }
    
    if ($alertCount >= 2) {
        try {
            $pdo->exec("ALTER TABLE players ADD COLUMN is_flagged TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {}
        
        $pdo->prepare("
            UPDATE players SET is_flagged = 1
            WHERE wallet_address = ? AND (is_flagged = 0 OR is_flagged IS NULL)
        ")->execute([$wallet]);
    }
    
    return ['banned' => false, 'alert_count' => $alertCount];
}

// ============================================
// INÍCIO DO PROCESSAMENTO
// ============================================

$input = json_decode(file_get_contents('php://input'), true);

$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$sessionToken = isset($input['session_token']) ? trim($input['session_token']) : '';
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$clientScore = isset($input['score']) ? (int)$input['score'] : 0;
$clientEarnings = isset($input['earnings']) ? (float)$input['earnings'] : 0;

$clientStats = ['common' => 0, 'rare' => 0, 'epic' => 0, 'legendary' => 0];
if (isset($input['stats']) && is_array($input['stats'])) {
    $clientStats['common'] = (int)($input['stats']['common'] ?? 0);
    $clientStats['rare'] = (int)($input['stats']['rare'] ?? 0);
    $clientStats['epic'] = (int)($input['stats']['epic'] ?? 0);
    $clientStats['legendary'] = (int)($input['stats']['legendary'] ?? 0);
}

$destroyedAsteroids = isset($input['destroyed_asteroids']) ? $input['destroyed_asteroids'] : [];

if (!$sessionId || !$sessionToken || !validateWallet($wallet)) {
    echo json_encode(['success' => false, 'error' => 'Dados invalidos']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // ============================================
    // VERIFICAR/CRIAR JOGADOR
    // FIX v6.1: Garante que jogador existe
    // ============================================
    $stmt = $pdo->prepare("SELECT id, balance_usdt, is_banned, ban_reason, total_played FROM players WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $playerData = $stmt->fetch();
    
    // Se jogador não existe, criar
    if (!$playerData) {
        secureLog("CREATE_PLAYER | Wallet: {$wallet} | Creating new player in game-end");
        
        $pdo->prepare("
            INSERT INTO players (wallet_address, balance_usdt, total_played, created_at)
            VALUES (?, 0, 0, NOW())
        ")->execute([$wallet]);
        
        $playerId = $pdo->lastInsertId();
        $playerData = [
            'id' => $playerId,
            'balance_usdt' => 0,
            'is_banned' => 0,
            'ban_reason' => null,
            'total_played' => 0
        ];
    }
    
    // Verificar ban
    if ($playerData['is_banned']) {
        echo json_encode([
            'success' => false, 
            'error' => 'Conta suspensa: ' . ($playerData['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ]);
        exit;
    }
    
    // Validar sessão
    $stmt = $pdo->prepare("
        SELECT * FROM game_sessions 
        WHERE id = ? AND wallet_address = ? AND status = 'active'
    ");
    $stmt->execute([$sessionId, $wallet]);
    $session = $stmt->fetch();
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessao nao encontrada ou ja finalizada']);
        exit;
    }
    
    if ($session['session_token'] !== $sessionToken) {
        echo json_encode(['success' => false, 'error' => 'Token invalido']);
        exit;
    }
    
    $sessionStart = strtotime($session['started_at']);
    $sessionDuration = time() - $sessionStart;
    
    // ============================================
    // CALCULAR EARNINGS - USAR VALORES DO CLIENTE
    // (Confiamos no cliente, mas monitoramos)
    // ============================================
    $finalEarnings = $clientEarnings;
    $finalScore = $clientScore;
    $finalStats = $clientStats;
    
    // Se enviou lista de asteroides, recalcular para comparação
    $calculatedEarnings = 0;
    if (!empty($destroyedAsteroids)) {
        foreach ($destroyedAsteroids as $asteroid) {
            if (!isset($asteroid['type'])) continue;
            $type = strtoupper(trim($asteroid['type']));
            switch ($type) {
                case 'LEGENDARY': $calculatedEarnings += REWARD_LEGENDARY; break;
                case 'EPIC': $calculatedEarnings += REWARD_EPIC; break;
                case 'RARE': $calculatedEarnings += REWARD_RARE; break;
                case 'COMMON': $calculatedEarnings += REWARD_COMMON; break;
            }
        }
    } else {
        // Calcular baseado nos stats
        $calculatedEarnings = 
            ($clientStats['common'] * REWARD_COMMON) +
            ($clientStats['rare'] * REWARD_RARE) +
            ($clientStats['epic'] * REWARD_EPIC) +
            ($clientStats['legendary'] * REWARD_LEGENDARY);
    }
    
    // ============================================
    // SISTEMA DE ALERTAS
    // ============================================
    $alertLevel = 'normal';
    $blockEarnings = false;
    
    if ($clientEarnings > EARNINGS_BLOCK_THRESHOLD) {
        $alertLevel = 'critical';
        $blockEarnings = true;
        
        registerSuspiciousActivity($pdo, $wallet, $sessionId, 'HIGH_EARNINGS_CRITICAL', [
            'client_earnings' => $clientEarnings,
            'calculated_earnings' => $calculatedEarnings,
            'stats' => $clientStats,
            'duration' => $sessionDuration
        ]);
        
        secureLog("CRITICAL_EARNINGS | Wallet: {$wallet} | \${$clientEarnings}");
        
    } elseif ($clientEarnings > EARNINGS_SUSPECT_THRESHOLD) {
        $alertLevel = 'suspect';
        
        registerSuspiciousActivity($pdo, $wallet, $sessionId, 'HIGH_EARNINGS_SUSPECT', [
            'client_earnings' => $clientEarnings,
            'calculated_earnings' => $calculatedEarnings,
            'stats' => $clientStats
        ]);
        
        secureLog("SUSPECT_EARNINGS | Wallet: {$wallet} | \${$clientEarnings}");
        
    } elseif ($clientEarnings > EARNINGS_ALERT_THRESHOLD) {
        $alertLevel = 'alert';
        
        registerSuspiciousActivity($pdo, $wallet, $sessionId, 'HIGH_EARNINGS_ALERT', [
            'client_earnings' => $clientEarnings,
            'calculated_earnings' => $calculatedEarnings,
            'stats' => $clientStats
        ]);
        
        secureLog("ALERT_EARNINGS | Wallet: {$wallet} | \${$clientEarnings}");
    }
    
    // Verificar discrepância cliente vs calculado
    if ($calculatedEarnings > 0 && $clientEarnings > $calculatedEarnings * 1.5) {
        registerSuspiciousActivity($pdo, $wallet, $sessionId, 'EARNINGS_MISMATCH', [
            'client_earnings' => $clientEarnings,
            'calculated_earnings' => $calculatedEarnings,
            'difference' => $clientEarnings - $calculatedEarnings
        ]);
        secureLog("MISMATCH | Wallet: {$wallet} | Client: \${$clientEarnings} | Calc: \${$calculatedEarnings}");
    }
    
    // Aplicar penalidades
    $penaltyResult = checkAndApplyPenalties($pdo, $wallet);
    
    if ($penaltyResult['banned']) {
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa por atividade suspeita',
            'banned' => true
        ]);
        exit;
    }
    
    // Se bloqueado, zerar earnings
    if ($blockEarnings) {
        $finalEarnings = 0;
    }
    
    // ============================================
    // ATUALIZAR SALDO
    // FIX v6.1: Agora $playerData sempre existe
    // ============================================
    $currentBalance = (float)($playerData['balance_usdt'] ?? 0);
    $newBalance = $currentBalance + $finalEarnings;
    
    $pdo->prepare("
        UPDATE players SET 
            balance_usdt = ?,
            total_played = total_played + 1
        WHERE id = ?
    ")->execute([$newBalance, $playerData['id']]);
    
    secureLog("BALANCE_UPDATE | Wallet: {$wallet} | Old: \${$currentBalance} | Earnings: \${$finalEarnings} | New: \${$newBalance}");
    
    // ============================================
    // COLUNAS EXTRAS
    // ============================================
    try { $pdo->exec("ALTER TABLE game_sessions ADD COLUMN legendary_asteroids INT DEFAULT 0 AFTER epic_asteroids"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE game_sessions ADD COLUMN common_asteroids INT DEFAULT 0 AFTER legendary_asteroids"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE game_sessions ADD COLUMN alert_level VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
    
    // ============================================
    // FINALIZAR SESSÃO
    // ============================================
    $finalStatus = ($alertLevel === 'critical') ? 'flagged' : 'completed';
    
    $pdo->prepare("
        UPDATE game_sessions SET 
            status = ?,
            asteroids_destroyed = ?,
            earnings_usdt = ?,
            common_asteroids = ?,
            rare_asteroids = ?,
            epic_asteroids = ?,
            legendary_asteroids = ?,
            client_score = ?,
            client_earnings = ?,
            session_duration = ?,
            alert_level = ?,
            ended_at = NOW()
        WHERE id = ?
    ")->execute([
        $finalStatus,
        $finalScore,
        $finalEarnings,
        $finalStats['common'],
        $finalStats['rare'],
        $finalStats['epic'],
        $finalStats['legendary'],
        $clientScore,
        $clientEarnings,
        $sessionDuration,
        $alertLevel !== 'normal' ? $alertLevel : null,
        $sessionId
    ]);
    
    // Referral
    $referralCompleted = false;
    if (function_exists('updateReferralProgress') && $finalEarnings > 0) {
        $referralResult = updateReferralProgress($pdo, $wallet);
        $referralCompleted = isset($referralResult['completed']) ? $referralResult['completed'] : false;
    }
    
    // ============================================
    // REGISTRAR TRANSAÇÃO
    // FIX v6.1: Adiciona registro de transação
    // ============================================
    if ($finalEarnings > 0) {
        try {
            $pdo->prepare("
                INSERT INTO transactions (wallet_address, type, amount, description, created_at)
                VALUES (?, 'mission', ?, ?, NOW())
            ")->execute([
                $wallet,
                $finalEarnings,
                "Mission #{$session['mission_number']} reward"
            ]);
        } catch (Exception $e) {
            // Tabela pode não existir ou ter estrutura diferente
            secureLog("TX_INSERT_ERROR | " . $e->getMessage());
        }
    }
    
    // ============================================
    // RESPOSTA
    // ============================================
    $response = [
        'success' => true,
        'final_score' => $finalScore,
        'final_earnings' => number_format($finalEarnings, 8, '.', ''),
        'new_balance' => number_format($newBalance, 8, '.', ''),
        'common_destroyed' => $finalStats['common'],
        'rare_destroyed' => $finalStats['rare'],
        'epic_destroyed' => $finalStats['epic'],
        'legendary_destroyed' => $finalStats['legendary'],
        'session_duration' => $sessionDuration,
        'mission_number' => $session['mission_number'],
        'status' => $finalStatus
    ];
    
    if ($blockEarnings) {
        $response['warning'] = 'Sessão bloqueada por atividade suspeita';
    }
    
    if ($referralCompleted) {
        $response['referral_bonus_unlocked'] = true;
    }
    
    secureLog("SESSION_END | Session: {$sessionId} | Earnings: \${$finalEarnings} | NewBalance: \${$newBalance} | Alert: {$alertLevel}");
    
    echo json_encode($response);
    
} catch (Exception $e) {
    secureLog("END_ERROR | Session: {$sessionId} | Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao finalizar sessao: ' . $e->getMessage()]);
}
?>
