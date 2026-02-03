<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// api/game-start.php - VERSÃO CORRIGIDA
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
if (!$input || empty($input['google_uid'])) {
    echo json_encode(['success' => false, 'error' => 'google_uid não fornecido']);
    exit;
}

$googleUid = trim($input['google_uid']);

// Validar google_uid (aceita UID completo ou truncado para compatibilidade)
if (strlen($googleUid) < 10) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido (muito curto)']);
    exit;
}

$clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

try {
    $pdo = getDBConnection();
    
    error_log("🎮 GAME-START | UID: " . substr($googleUid, 0, 15) . "...");
    
    // ============================================
    // 1. BUSCAR USUÁRIO
    // ============================================
    // Primeiro tentar busca exata
    $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name, balance_brl, is_banned, ban_reason FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Se não encontrar e UID parece truncado, tentar LIKE
    if (!$user && strpos($googleUid, '...') !== false) {
        $likePattern = str_replace('...', '%', $googleUid);
        $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name, balance_brl, is_banned, ban_reason FROM users WHERE google_uid LIKE ? LIMIT 1");
        $stmt->execute([$likePattern]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$user) {
        error_log("❌ GAME-START | Usuário não encontrado: $googleUid");
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado. Faça login primeiro.']);
        exit;
    }
    
    // Verificar ban
    if (!empty($user['is_banned'])) {
        echo json_encode([
            'success' => false, 
            'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Entre em contato com suporte')
        ]);
        exit;
    }
    
    $userId = (int)$user['id'];
    $realGoogleUid = $user['google_uid']; // UID completo do banco
    
    error_log("✅ GAME-START | User encontrado: ID $userId");
    
    // ============================================
    // 2. VERIFICAR LIMITE DE MISSÕES POR IP (5/hora)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM game_sessions 
        WHERE ip_address = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$clientIP]);
    $ipCheck = $stmt->fetch();
    
    $maxMissionsPerHour = defined('MAX_MISSIONS_PER_HOUR') ? MAX_MISSIONS_PER_HOUR : 5;
    
    if ($ipCheck['count'] >= $maxMissionsPerHour) {
        $waitMinutes = 60 - (int)((time() % 3600) / 60);
        echo json_encode([
            'success' => false,
            'error' => "Limite de $maxMissionsPerHour missões por hora atingido",
            'wait_seconds' => $waitMinutes * 60,
            'missions_remaining' => 0
        ]);
        exit;
    }
    
    $missionsRemaining = $maxMissionsPerHour - $ipCheck['count'] - 1;
    
    // ============================================
    // 3. VERIFICAR SESSÃO ATIVA
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id FROM game_sessions 
        WHERE google_uid = ? AND status = 'active' 
        LIMIT 1
    ");
    $stmt->execute([$realGoogleUid]);
    
    if ($stmt->fetch()) {
        // Expirar sessão antiga
        $pdo->prepare("
            UPDATE game_sessions SET status = 'expired', ended_at = NOW() 
            WHERE google_uid = ? AND status = 'active'
        ")->execute([$realGoogleUid]);
    }
    
    // ============================================
    // 4. CALCULAR NÚMERO DA MISSÃO
    // ============================================
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM game_sessions WHERE google_uid = ?");
    $stmt->execute([$realGoogleUid]);
    $result = $stmt->fetch();
    $missionNumber = (int)($result['total'] ?? 0) + 1;
    
    // ============================================
    // 5. DETERMINAR HARD MODE E RECOMPENSAS
    // ============================================
    $hardModeChance = defined('HARD_MODE_PERCENTAGE') ? HARD_MODE_PERCENTAGE : 40;
    $isHardMode = (mt_rand(1, 100) <= $hardModeChance);
    
    // Quantidade de raros e épicos na missão
    $rareCount = $isHardMode ? 1 : 2;
    $hasEpic = ($missionNumber >= 5 && mt_rand(1, 100) <= 30);
    $hasLegendary = ($missionNumber >= 10 && mt_rand(1, 100) <= 10);
    
    // IDs dos asteroides especiais (para validação)
    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;
    $legendaryId = $hasLegendary ? mt_rand(251, 280) : 0;
    
    // ============================================
    // 6. GERAR TOKEN DE SESSÃO
    // ============================================
    $sessionToken = hash('sha256', $realGoogleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $gameDuration = defined('GAME_DURATION') ? GAME_DURATION : 180;
    
    // ============================================
    // 7. CRIAR SESSÃO NO BANCO
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            google_uid,
            session_token,
            mission_number,
            status,
            is_hard_mode,
            rare_asteroids_target,
            epic_asteroid_target,
            legendary_asteroid_target,
            rare_ids,
            epic_id,
            legendary_id,
            ip_address,
            user_agent,
            earnings_brl,
            asteroids_destroyed,
            started_at,
            created_at
        ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW())
    ");
    
    $stmt->execute([
        $realGoogleUid,
        $sessionToken,
        $missionNumber,
        $isHardMode ? 1 : 0,
        $rareCount,
        $hasEpic ? 1 : 0,
        $hasLegendary ? 1 : 0,
        json_encode($rareIds),
        $epicId,
        $legendaryId,
        $clientIP,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);
    
    $sessionId = (int)$pdo->lastInsertId();
    
    // ============================================
    // 8. ATUALIZAR CONTADOR DO USUÁRIO
    // ============================================
    $pdo->prepare("UPDATE users SET total_played = total_played + 1, last_login = NOW(), updated_at = NOW() WHERE id = ?")
        ->execute([$userId]);
    
    // ============================================
    // 9. REGISTRAR IP_SESSION (para tracking)
    // ============================================
    try {
        $pdo->prepare("
            INSERT INTO ip_sessions (ip_address, google_uid, session_id, status, created_at)
            VALUES (?, ?, ?, 'active', NOW())
        ")->execute([$clientIP, $realGoogleUid, $sessionId]);
    } catch (Exception $e) {
        // Tabela pode não existir, ignorar
    }
    
    // ============================================
    // 10. RESPOSTA DE SUCESSO
    // ============================================
    error_log("✅ GAME-START | Sessão criada: ID $sessionId, Missão #$missionNumber" . ($isHardMode ? ' (HARD)' : ''));
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'google_uid' => $realGoogleUid, // Retornar UID completo
        'user_id' => $userId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'rare_count' => $rareCount,
        'has_epic' => $hasEpic,
        'has_legendary' => $hasLegendary,
        'rare_ids' => $rareIds,
        'epic_id' => $epicId,
        'legendary_id' => $legendaryId,
        'game_duration' => $gameDuration,
        'initial_lives' => defined('INITIAL_LIVES') ? INITIAL_LIVES : 6,
        'missions_remaining' => $missionsRemaining
    ]);
    
} catch (Throwable $e) {
    error_log("❌ GAME-START ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Erro interno do servidor',
        'debug' => (getenv('APP_ENV') !== 'production') ? $e->getMessage() : null
    ]);
}
