<?php
// ============================================
// UNOBIX - Registrar Evento de Jogo
// api/game-event.php - VERSÃO CORRIGIDA
// ============================================

require_once __DIR__ . "/config.php";

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

if (!$input || !is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$sessionToken = isset($input['session_token']) ? trim($input['session_token']) : '';
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$asteroidId = isset($input['asteroid_id']) ? (int)$input['asteroid_id'] : 0;
$rewardType = isset($input['reward_type']) ? trim(strtolower($input['reward_type'])) : 'none';
$timestamp = isset($input['timestamp']) ? (int)$input['timestamp'] : time();

// Validações básicas
if (!$sessionId) {
    echo json_encode(['success' => false, 'error' => 'session_id ausente']);
    exit;
}

if (!$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'session_token ausente']);
    exit;
}

if (empty($googleUid) || strlen($googleUid) < 10) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // ============================================
    // 1. VALIDAR SESSÃO
    // ============================================
    // Busca flexível (aceita UID truncado)
    if (strpos($googleUid, '...') !== false) {
        $likePattern = str_replace('...', '%', $googleUid);
        $stmt = $pdo->prepare("
            SELECT * FROM game_sessions 
            WHERE id = ? AND google_uid LIKE ? AND status = 'active'
        ");
        $stmt->execute([$sessionId, $likePattern]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM game_sessions 
            WHERE id = ? AND google_uid = ? AND status = 'active'
        ");
        $stmt->execute([$sessionId, $googleUid]);
    }
    
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou expirada']);
        exit;
    }
    
    // Validar token
    if ($session['session_token'] !== $sessionToken) {
        error_log("⚠️ GAME-EVENT | Token mismatch | Session: $sessionId");
        echo json_encode(['success' => false, 'error' => 'Token inválido']);
        exit;
    }
    
    // Verificar tempo da sessão
    $sessionStart = strtotime($session['started_at']);
    $gameDuration = defined('GAME_DURATION') ? GAME_DURATION : 180;
    $tolerance = defined('GAME_TOLERANCE') ? GAME_TOLERANCE : 30;
    $elapsed = time() - $sessionStart;
    
    if ($elapsed > $gameDuration + $tolerance) {
        echo json_encode(['success' => false, 'error' => 'Sessão expirada']);
        exit;
    }
    
    // ============================================
    // 2. DETERMINAR RECOMPENSA (SERVER-SIDE!)
    // ============================================
    // NUNCA confiar no valor do cliente!
    $validTypes = ['none', 'common', 'rare', 'epic', 'legendary'];
    $validRewardType = in_array($rewardType, $validTypes) ? $rewardType : 'none';
    
    // Valores definidos no servidor
    $rewards = [
        'none' => 0,
        'common' => defined('REWARD_COMMON') ? REWARD_COMMON : 0,
        'rare' => defined('REWARD_RARE') ? REWARD_RARE : 0.001,
        'epic' => defined('REWARD_EPIC') ? REWARD_EPIC : 0.005,
        'legendary' => defined('REWARD_LEGENDARY') ? REWARD_LEGENDARY : 0.02
    ];
    
    $rewardBrl = $rewards[$validRewardType] ?? 0;
    
    // ============================================
    // 3. RATE LIMITING (máx 5 eventos/segundo)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM game_events 
        WHERE session_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)
    ");
    $stmt->execute([$sessionId]);
    $recentEvents = $stmt->fetch();
    
    $maxEventsPerSecond = defined('MAX_EVENTS_PER_SECOND') ? MAX_EVENTS_PER_SECOND : 5;
    
    if ($recentEvents['count'] >= $maxEventsPerSecond) {
        // Apenas logar, não bloquear (pode ser lag)
        error_log("⚠️ GAME-EVENT | Rate limit | Session: $sessionId | Count: " . $recentEvents['count']);
        echo json_encode(['success' => true, 'throttled' => true, 'reward_brl' => 0]);
        exit;
    }
    
    // ============================================
    // 4. REGISTRAR EVENTO
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO game_events (
            session_id, 
            google_uid,
            asteroid_id, 
            reward_type,
            reward_amount_brl,
            client_timestamp,
            created_at
        ) VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), NOW())
    ");
    
    $stmt->execute([
        $sessionId,
        $session['google_uid'], // Usar UID da sessão (completo)
        $asteroidId,
        $validRewardType,
        $rewardBrl,
        $timestamp
    ]);
    
    $eventId = $pdo->lastInsertId();
    
    // ============================================
    // 5. ATUALIZAR CONTADORES DA SESSÃO
    // ============================================
    $updateFields = [
        'asteroids_destroyed = asteroids_destroyed + 1',
        "earnings_brl = earnings_brl + $rewardBrl"
    ];
    
    // Contador específico por tipo
    switch ($validRewardType) {
        case 'legendary':
            $updateFields[] = 'legendary_asteroids = COALESCE(legendary_asteroids, 0) + 1';
            break;
        case 'epic':
            $updateFields[] = 'epic_asteroids = COALESCE(epic_asteroids, 0) + 1';
            break;
        case 'rare':
            $updateFields[] = 'rare_asteroids = COALESCE(rare_asteroids, 0) + 1';
            break;
        case 'common':
            $updateFields[] = 'common_asteroids = COALESCE(common_asteroids, 0) + 1';
            break;
    }
    
    $pdo->prepare("UPDATE game_sessions SET " . implode(', ', $updateFields) . " WHERE id = ?")
        ->execute([$sessionId]);
    
    // ============================================
    // 6. RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'event_id' => (int)$eventId,
        'reward_type' => $validRewardType,
        'reward_brl' => $rewardBrl
    ]);
    
} catch (Throwable $e) {
    error_log("❌ GAME-EVENT ERROR | Session: $sessionId | " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao registrar evento']);
}
