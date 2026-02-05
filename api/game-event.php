<?php
// ============================================
// UNOBIX - Registrar Evento de Jogo
// api/game-event.php v4.5
// Atualiza contadores de asteroides por tipo
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$sessionId = (int)($input['session_id'] ?? 0);
$sessionToken = trim($input['session_token'] ?? '');
$googleUid = trim($input['google_uid'] ?? '');
$asteroidId = (int)($input['asteroid_id'] ?? 0);
$rewardType = strtolower(trim($input['reward_type'] ?? 'common'));
$timestamp = (int)($input['timestamp'] ?? time());

// Validação básica
if (!$sessionId || !$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão inválidos']);
    exit;
}

if (!$googleUid) {
    echo json_encode(['success' => false, 'error' => 'google_uid ausente']);
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
            WHERE id = ? AND (google_uid = ? OR google_uid LIKE ?) AND status = 'active'
        ");
        $stmt->execute([$sessionId, $googleUid, $googleUid . '%']);
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
    
    // Normalizar tipo de recompensa
    $validTypes = ['none', 'common', 'rare', 'epic', 'legendary'];
    $validType = in_array($rewardType, $validTypes) ? $rewardType : 'common';
    
    // Calcular recompensa
    $rewardBrl = getRewardByType($validType);
    
    // Registrar evento
    $eventData = json_encode([
        'asteroid_id' => $asteroidId,
        'reward_type' => $validType,
        'client_timestamp' => $timestamp
    ]);
    
    $stmt = $pdo->prepare("
        INSERT INTO game_events (
            session_id, google_uid, event_type, event_data, earnings_brl, created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $sessionId, 
        $session['google_uid'], 
        $validType,
        $eventData,
        $rewardBrl
    ]);
    $eventId = $pdo->lastInsertId();
    
    // Atualizar contadores da sessão
    $typeColumn = $validType . '_asteroids';
    $validColumns = ['common_asteroids', 'rare_asteroids', 'epic_asteroids', 'legendary_asteroids'];
    
    if (in_array($typeColumn, $validColumns)) {
        $stmt = $pdo->prepare("
            UPDATE game_sessions SET 
                asteroids_destroyed = asteroids_destroyed + 1,
                earnings_brl = earnings_brl + ?,
                $typeColumn = $typeColumn + 1
            WHERE id = ?
        ");
        $stmt->execute([$rewardBrl, $sessionId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE game_sessions SET 
                asteroids_destroyed = asteroids_destroyed + 1,
                earnings_brl = earnings_brl + ?
            WHERE id = ?
        ");
        $stmt->execute([$rewardBrl, $sessionId]);
    }
    
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
