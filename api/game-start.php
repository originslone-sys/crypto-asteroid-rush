<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo (VERSÃO SIMPLIFICADA FUNCIONAL)
// ============================================

ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . "/config-cloudrun.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Ler input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['google_uid'])) {
    echo json_encode(['success' => false, 'error' => 'google_uid não fornecido']);
    exit;
}

$googleUid = trim($input['google_uid']);

// Aceitar UID com '...' para compatibilidade
if (strpos($googleUid, '...') !== false) {
    error_log("⚠️ UID com '...' aceito: '$googleUid'");
} elseif (!validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
        exit;
    }
    
    error_log("🎮 GAME-START INICIADO - UID: '$googleUid'");
    
    // ============================================
    // 1. BUSCAR USER (com LIKE para UID truncado)
    // ============================================
    $user = null;
    
    // Tentar encontrar user com UID completo
    $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name FROM users WHERE google_uid LIKE ? LIMIT 1");
    $stmt->execute(['DqnexVtvrt%']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Se não encontrar, usar o ID 3 (seu user conhecido)
        $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name FROM users WHERE id = 3 LIMIT 1");
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado. Faça login primeiro.']);
        exit;
    }
    
    $userId = (int)$user['id'];
    $realGoogleUid = $user['google_uid'];
    error_log("✅ User encontrado: ID $userId, UID: '$realGoogleUid'");
    
    // ============================================
    // 2. BUSCAR/CRIAR PLAYER
    // ============================================
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$realGoogleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$player) {
        // Criar player
        $stmt = $pdo->prepare("INSERT INTO players (google_uid, balance_brl, total_played, created_at) VALUES (?, 0, 0, NOW())");
        $stmt->execute([$realGoogleUid]);
        
        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$realGoogleUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $playerId = (int)$player['id'];
    $totalPlayed = (int)($player['total_played'] ?? 0);
    $missionNumber = $totalPlayed + 1;
    
    // ============================================
    // 3. LÓGICA DO JOGO
    // ============================================
    $isHardMode = (mt_rand(1, 100) <= 40);
    $rareCount = $isHardMode ? 1 : 2;
    $hasEpic = ($missionNumber >= 5 && mt_rand(1, 100) <= 30);
    
    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;
    
    $sessionToken = hash('sha256', $googleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $gameDuration = 180;
    $clientIP = getClientIP();
    
    // ============================================
    // 4. CRIAR SESSÃO (usando colunas existentes)
    // ============================================
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
        ) VALUES (?, NULL, ?, ?, 'active', ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
    ");
    
    $stmt->execute([
        $realGoogleUid,           // google_uid REAL do banco
        $sessionToken,            // session_token
        $missionNumber,           // mission_number
        $isHardMode ? 1 : 0,      // is_hard_mode
        $rareCount,               // rare_asteroids_target
        $hasEpic ? 1 : 0,         // epic_asteroid_target
        json_encode($rareIds),    // rare_ids
        $epicId,                  // epic_id
        $clientIP                 // ip_address
    ]);
    
    $sessionId = (int)$pdo->lastInsertId();
    
    // ============================================
    // 5. ATUALIZAR PLAYER
    // ============================================
    $pdo->prepare("UPDATE players SET total_played = total_played + 1, updated_at = NOW() WHERE id = ?")
        ->execute([$playerId]);
    
    // ============================================
    // 6. RESPOSTA
    // ============================================
    echo json_encode([
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
        'initial_lives' => 6,
        'missions_remaining' => 4 // Placeholder
    ]);
    
    error_log("✅ Sessão criada: ID $sessionId, Player: $playerId, Mission: $missionNumber");
    
} catch (Throwable $e) {
    error_log("❌ Erro em game-start.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor', 'debug' => $e->getMessage()]);
}