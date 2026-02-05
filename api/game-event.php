<?php
// ============================================
// UNOBIX - Registrar Evento de Jogo
// api/game-event.php v4.1
// Adaptado para estrutura existente do banco
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
    
    // Calcular recompensa (SERVIDOR!)
    $validTypes = ['none', 'common', 'rare', 'epic', 'legendary'];
    $validType = in_array($rewardType, $validTypes) ? $rewardType : 'none';
    $rewardBrl = getRewardByType($validType);
    
    // Registrar evento - USANDO COLUNAS EXISTENTES
    // event_type = tipo do asteroide (rare, epic, etc)
    // event_data = JSON com detalhes
    // earnings_brl = valor da recompensa
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
    
    // Atualizar sessão
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
    } else {
        $stmt = $pdo->prepare("
            UPDATE game_sessions SET 
                asteroids_destroyed = asteroids_destroyed + 1,
                earnings_brl = earnings_brl + ?
            WHERE id = ?
        ");
    }
    $stmt->execute([$rewardBrl, $sessionId]);
    
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
