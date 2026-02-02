<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo (VERSÃO CORRIGIDA)
// SOLUÇÃO COMPLETA mantendo todas as funcionalidades
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

$input = array_merge($_GET, $_POST, readJsonInput());
$googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido ou não fornecido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
        exit;
    }
    
    // ============================================
    // 1. GARANTIR QUE TABELA PLAYERS EXISTE
    // ============================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS players (
            id INT PRIMARY KEY AUTO_INCREMENT,
            google_uid VARCHAR(255) UNIQUE,
            balance_brl DECIMAL(10,2) DEFAULT 0.00,
            total_played INT DEFAULT 0,
            is_banned BOOLEAN DEFAULT FALSE,
            ban_reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_google_uid (google_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // ============================================
    // 2. SINCRONIZAR USUÁRIO (users → players)
    // ============================================
    // Primeiro verificar se usuário existe em users
    $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name, balance_brl FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Sincronizar com players
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, balance_brl, total_played, created_at)
            VALUES (?, ?, 0, NOW())
            ON DUPLICATE KEY UPDATE 
                balance_brl = VALUES(balance_brl),
                updated_at = NOW()
        ");
        $stmt->execute([$googleUid, $user['balance_brl'] ?? 0.00]);
    }
    
    // ============================================
    // 3. BUSCAR/CRIAR PLAYER (código original mantido)
    // ============================================
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, balance_brl, total_played, created_at, updated_at)
            VALUES (?, 0.00, 0, NOW(), NOW())
        ");
        $stmt->execute([$googleUid]);

        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Não foi possível identificar o jogador']);
        exit;
    }

    if (!empty($player['is_banned'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($player['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ]);
        exit;
    }

    $playerId = (int)$player['id'];
    $totalPlayed = (int)($player['total_played'] ?? 0);
    $missionNumber = $totalPlayed + 1;

    // ============================================
    // 4. GARANTIR COLUNAS EM GAME_SESSIONS
    // ============================================
    $pdo->exec("
        ALTER TABLE game_sessions
        ADD COLUMN IF NOT EXISTS session_token VARCHAR(255),
        ADD COLUMN IF NOT EXISTS mission_number INT,
        ADD COLUMN IF NOT EXISTS rare_count INT,
        ADD COLUMN IF NOT EXISTS rare_ids TEXT,
        ADD COLUMN IF NOT EXISTS epic_id INT,
        ADD COLUMN IF NOT EXISTS game_duration INT,
        ADD COLUMN IF NOT EXISTS started_at DATETIME
    ");
    
    // ============================================
    // 5. LÓGICA DO JOGO (mantida do original)
    // ============================================
    // hard mode
    $hardModePercentage = defined('HARD_MODE_PERCENTAGE') ? HARD_MODE_PERCENTAGE : 40;
    $isHardMode = (mt_rand(1, 100) <= $hardModePercentage);

    // especiais
    $rareCount = $isHardMode ? ((mt_rand(1, 100) <= 50) ? 1 : 0) : ((mt_rand(1, 100) <= 70) ? 1 : 2);
    $epicChance = $isHardMode ? 15 : 30;
    $hasEpic = ($missionNumber >= 5 && mt_rand(1, 100) <= $epicChance);

    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) $rareIds[] = mt_rand(50, 200);
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;

    // sessão token (server)
    $sessionToken = hash('sha256', $googleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $sessionUuid = bin2hex(random_bytes(18)); // 36 chars para session_uuid
    $gameDuration = defined('GAME_DURATION') ? GAME_DURATION : 180;
    
    $clientIP = getClientIP();
    
    // ============================================
    // 6. CRIAR SESSÃO (com todas as colunas necessárias)
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            user_id,
            session_uuid,
            session_token,
            google_uid,
            mission_number,
            is_hard_mode,
            rare_count,
            rare_ids,
            epic_id,
            status,
            game_duration,
            earnings_brl,
            ip_address,
            started_at,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, 0, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $playerId,                 // user_id (para relacionamento)
        $sessionUuid,              // session_uuid
        $sessionToken,             // session_token (frontend espera)
        $googleUid,                // google_uid
        $missionNumber,            // mission_number
        $isHardMode ? 1 : 0,       // is_hard_mode
        $rareCount,                // rare_count
        json_encode($rareIds),     // rare_ids
        $epicId,                   // epic_id
        $gameDuration,             // game_duration
        $clientIP                  // ip_address
    ]);

    $sessionId = (int)$pdo->lastInsertId();

    // ============================================
    // 7. ATUALIZAR TOTAL_PLAYED DO PLAYER
    // ============================================
    $pdo->prepare("UPDATE players SET total_played = total_played + 1, updated_at = NOW() WHERE id = ?")
        ->execute([$playerId]);

    // ============================================
    // 8. RESPOSTA COMPLETA (frontend espera tudo isso)
    // ============================================
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,      // Frontend usa
        'session_uuid' => $sessionUuid,        // Para referência
        'player_id' => $playerId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'rare_count' => $rareCount,
        'has_epic' => (bool)$hasEpic,
        'rare_ids' => $rareIds,
        'epic_id' => $epicId,
        'game_duration' => $gameDuration,
        'initial_lives' => defined('INITIAL_LIVES') ? INITIAL_LIVES : 6,
        'missions_remaining' => 99 // Placeholder - precisa calcular
    ]);

} catch (Throwable $e) {
    error_log("Erro em game-start.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    if (function_exists('secureLog')) secureLog("GAME_START_ERROR | " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor', 'debug' => $e->getMessage()]);
}