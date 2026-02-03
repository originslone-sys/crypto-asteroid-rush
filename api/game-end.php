<?php
// ============================================
// UNOBIX - Registrar Evento de Jogo
// api/game-event.php v3.0
// ============================================

require_once __DIR__ . "/config.php";
setCorsHeaders();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$sessionId = (int)($input['session_id'] ?? 0);
$sessionToken = trim($input['session_token'] ?? '');
$googleUid = trim($input['google_uid'] ?? '');
$asteroidId = (int)($input['asteroid_id'] ?? 0);
$rewardType = strtolower(trim($input['reward_type'] ?? 'none'));
$timestamp = (int)($input['timestamp'] ?? time());

if (!$sessionId || !$sessionToken || strlen($googleUid) < 10) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão inválidos']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }
    
    // 1. VALIDAR SESSÃO
    if (strpos($googleUid, '...') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM game_sessions WHERE id = ? AND google_uid LIKE ? AND status = 'active'");
        $stmt->execute([$sessionId, str_replace('...', '%', $googleUid)]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM game_sessions WHERE id = ? AND google_uid = ? AND status = 'active'");
        $stmt->execute([$sessionId, $googleUid]);
    }
    
    $session = $stmt->fetch();
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
        exit;
    }
    
    if ($session['session_token'] !== $sessionToken) {
        echo json_encode(['success' => false, 'error' => 'Token inválido']);
        exit;
    }
    
    // Verificar tempo
    $elapsed = time() - strtotime($session['started_at']);
    if ($elapsed > GAME_DURATION + GAME_TOLERANCE) {
        echo json_encode(['success' => false, 'error' => 'Sessão expirada']);
        exit;
    }
    
    // 2. RATE LIMIT
    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM game_events WHERE session_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)");
    $stmt->execute([$sessionId]);
    if ($stmt->fetch()['c'] >= MAX_EVENTS_PER_SECOND) {
        echo json_encode(['success' => true, 'throttled' => true, 'reward_brl' => 0]);
        exit;
    }
    
    // 3. CALCULAR RECOMPENSA (SERVIDOR!)
    $validTypes = ['none', 'common', 'rare', 'epic', 'legendary'];
    $validType = in_array($rewardType, $validTypes) ? $rewardType : 'none';
    $rewardBrl = getRewardByType($validType);
    
    // 4. REGISTRAR EVENTO
    $stmt = $pdo->prepare("
        INSERT INTO game_events (session_id, google_uid, asteroid_id, reward_type, reward_amount, reward_amount_brl, client_timestamp, created_at)
        VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), NOW())
    ");
    $stmt->execute([$sessionId, $session['google_uid'], $asteroidId, $validType, $rewardBrl, $rewardBrl, $timestamp]);
    $eventId = $pdo->lastInsertId();
    
    // 5. ATUALIZAR SESSÃO
    $typeColumn = $validType . '_asteroids';
    $pdo->exec("UPDATE game_sessions SET asteroids_destroyed = asteroids_destroyed + 1, earnings_brl = earnings_brl + $rewardBrl" . 
        (in_array($validType, ['common','rare','epic','legendary']) ? ", $typeColumn = COALESCE($typeColumn, 0) + 1" : "") . 
        " WHERE id = $sessionId");
    
    echo json_encode([
        'success' => true,
        'event_id' => (int)$eventId,
        'reward_type' => $validType,
        'reward_brl' => $rewardBrl
    ]);
    
} catch (Throwable $e) {
    error_log("❌ GAME-EVENT ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao registrar']);
}
