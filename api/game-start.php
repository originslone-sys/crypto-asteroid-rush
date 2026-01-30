<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// Arquivo: api/game-start.php
// v4.1 - Melhor error handling + auto-criar tabelas
// ============================================

// Desabilitar exibição de erros HTML
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

// ============================================
// LER INPUT
// ============================================
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = [];

// Suporte híbrido: google_uid (novo) ou wallet (compatibilidade)
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';

// Determinar identificador principal
$identifier = '';
$identifierType = '';

// Validação simples
if (!empty($googleUid) && strlen($googleUid) >= 10 && strlen($googleUid) <= 128) {
    $identifier = $googleUid;
    $identifierType = 'google_uid';
} elseif (!empty($wallet) && preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
    $identifier = $wallet;
    $identifierType = 'wallet';
} else {
    echo json_encode(['success' => false, 'error' => 'Identificação inválida. Faça login novamente.']);
    exit;
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    // ============================================
    // CRIAR TABELAS SE NÃO EXISTIREM
    // ============================================
    
    // Tabela de controle de IP
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
    $activeSession = $stmt->fetch();

    if ($activeSession) {
        if ($activeSession['game_status'] === 'active') {
            echo json_encode([
                'success' => false,
                'error' => 'Você já tem uma missão em andamento. Complete-a primeiro.',
                'error_code' => 'CONCURRENT_MISSION'
            ]);
            exit;
        } else {
            $pdo->prepare("UPDATE ip_sessions SET status = 'completed', ended_at = NOW() WHERE id = ?")
                ->execute([$activeSession['id']]);
        }
    }

    // ============================================
    // VERIFICAÇÃO 2: Limite de 5 missões por hora por IP
    // ============================================
    $maxMissionsPerHour = defined('MAX_MISSIONS_PER_HOUR') ? MAX_MISSIONS_PER_HOUR : 5;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as mission_count
        FROM ip_sessions
        WHERE ip_address = ?
        AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$clientIP]);
    $hourlyCount = $stmt->fetch();
    
    $missionsThisHour = (int)($hourlyCount['mission_count'] ?? 0);

    if ($missionsThisHour >= $maxMissionsPerHour) {
        $stmt = $pdo->prepare("
            SELECT started_at FROM ip_sessions
            WHERE ip_address = ?
            AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY started_at ASC LIMIT 1
        ");
        $stmt->execute([$clientIP]);
        $oldest = $stmt->fetch();
        
        $waitSeconds = 0;
        if ($oldest) {
            $waitSeconds = max(0, (strtotime($oldest['started_at']) + 3600) - time());
        }
        
        echo json_encode([
            'success' => false,
            'error' => "Limite de {$maxMissionsPerHour} missões por hora atingido. Aguarde " . ceil($waitSeconds / 60) . " minutos.",
            'error_code' => 'HOURLY_LIMIT',
            'wait_seconds' => $waitSeconds
        ]);
        exit;
    }

    // ============================================
    // BUSCAR/CRIAR JOGADOR
    // ============================================
    $player = null;
    
    if ($identifierType === 'google_uid') {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $player = $stmt->fetch();
        
        if (!$player) {
            // Criar jogador
            $tempWallet = '0x' . substr(hash('sha256', $googleUid . time()), 0, 40);
            $stmt = $pdo->prepare("
                INSERT INTO players (google_uid, wallet_address, balance_brl, total_played, created_at)
                VALUES (?, ?, 0, 0, NOW())
            ");
            $stmt->execute([$googleUid, $tempWallet]);
            
            $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $player = $stmt->fetch();
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE wallet_address = ? LIMIT 1");
        $stmt->execute([$wallet]);
        $player = $stmt->fetch();
        
        if (!$player) {
            $stmt = $pdo->prepare("
                INSERT INTO players (wallet_address, balance_brl, total_played, created_at)
                VALUES (?, 0, 0, NOW())
            ");
            $stmt->execute([$wallet]);
            
            $stmt = $pdo->prepare("SELECT * FROM players WHERE wallet_address = ? LIMIT 1");
            $stmt->execute([$wallet]);
            $player = $stmt->fetch();
        }
    }

    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Não foi possível identificar o jogador']);
        exit;
    }

    if (!empty($player['is_banned']) && $player['is_banned']) {
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($player['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ]);
        exit;
    }

    $playerId = (int)$player['id'];
    $playerGoogleUid = $player['google_uid'] ?? null;
    $playerWallet = $player['wallet_address'] ?? '';
    $totalPlayed = (int)($player['total_played'] ?? 0);
    $missionNumber = $totalPlayed + 1;

    // ============================================
    // DETERMINAR HARD MODE (40%)
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

    // Épico: chance baseada em missões jogadas
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
    $sessionToken = hash('sha256', $identifier . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $gameDuration = defined('GAME_DURATION') ? GAME_DURATION : 180;

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
        ) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
    ");

    $stmt->execute([
        $playerGoogleUid,
        $playerWallet,
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
    // REGISTRAR NA TABELA DE CONTROLE DE IP
    // ============================================
    $pdo->prepare("
        INSERT INTO ip_sessions (ip_address, session_id, google_uid, wallet_address, status, started_at)
        VALUES (?, ?, ?, ?, 'active', NOW())
    ")->execute([$clientIP, $sessionId, $playerGoogleUid, $playerWallet]);

    // Log
    if (function_exists('secureLog')) {
        secureLog("GAME_START | IP: {$clientIP} | UID: {$playerGoogleUid} | Session: {$sessionId} | Mission: {$missionNumber} | HardMode: " . ($isHardMode ? 'YES' : 'NO'));
    }

    // ============================================
    // RESPOSTA
    // ============================================
    $response = [
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
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Erro em game-start.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
