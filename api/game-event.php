<?php
// ============================================
// UNOBIX - Registrar Evento de Jogo
// api/game-event.php v4.0
// Usa config.php
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$sessionId = (int)($input['session_id'] ?? 0);
$sessionToken = trim($input['session_token'] ?? '');
$googleUid = trim($input['google_uid'] ?? '');
$asteroidId = (int)($input['asteroid_id'] ?? 0);
$rewardType = strtolower(trim($input['reward_type'] ?? 'none'));
$timestamp = (int)($input['timestamp'] ?? time());

if (!$sessionId || !$sessionToken || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão inválidos']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }
    
    // Validar sessão (permitir UID truncado)
    if (strpos($googleUid, '...') !== false) {
        $stmt = $pdo->prepare("
            SELECT * FROM game_sessions 
            WHERE id = ? AND google_uid LIKE ? AND status = 'active'
        ");
        $stmt->execute([$sessionId, str_replace('...', '%', $googleUid)]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM game_sessions 
            WHERE id = ? AND google_uid = ? AND status = 'active'
        ");
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
    
    // Rate limit
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as c 
        FROM game_events 
        WHERE session_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)
    ");
    $stmt->execute([$sessionId]);
    if ($stmt->fetch()['c'] >= MAX_EVENTS_PER_SECOND) {
        echo json_encode(['success' => true, 'throttled' => true, 'reward_brl' => 0]);
        exit;
    }
    
    // Calcular recompensa (SERVIDOR!)
    $validTypes = ['none', 'common', 'rare', 'epic', 'legendary'];
    $validType = in_array($rewardType, $validTypes) ? $rewardType : 'none';
    $rewardBrl = getRewardByType($validType);
    
    // Registrar evento
    $stmt = $pdo->prepare("
        INSERT INTO game_events (
            session_id, google_uid, asteroid_id, 
            reward_type, reward_amount, reward_amount_brl, 
            client_timestamp, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), NOW())
    ");
    $stmt->execute([
        $sessionId, 
        $session['google_uid'], 
        $asteroidId, 
        $validType, 
        $rewardBrl, 
        $rewardBrl, 
        $timestamp
    ]);
    $eventId = $pdo->lastInsertId();
    
    // Atualizar sessão (usando prepared statements para segurança)
    $updateFields = ["asteroids_destroyed = asteroids_destroyed + 1", "earnings_brl = earnings_brl + ?"];
    $updateParams = [$rewardBrl];
    
    if (in_array($validType, ['common', 'rare', 'epic', 'legendary'])) {
        $typeColumn = $validType . '_asteroids';
        $updateFields[] = "$typeColumn = COALESCE($typeColumn, 0) + 1";
    }
    
    $updateParams[] = $sessionId;
    
    $stmt = $pdo->prepare("UPDATE game_sessions SET " . implode(', ', $updateFields) . " WHERE id = ?");
    $stmt->execute($updateParams);
    
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
