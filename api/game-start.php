<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// api/game-start.php v5.0 - Arquitetura Segura
// Gera seed para verificação de integridade
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

if (empty($input['google_uid'])) {
    echo json_encode(['success' => false, 'error' => 'google_uid não fornecido']);
    exit;
}

$googleUid = trim($input['google_uid']);

// Permitir UID truncado com ...
if (strpos($googleUid, '...') === false && !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }
    
    // Buscar usuário
    $user = findPlayer($pdo, $googleUid);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado. Faça login primeiro.']);
        exit;
    }
    
    // Verificar ban
    if (!empty($user['is_banned'])) {
        echo json_encode(['success' => false, 'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violação dos termos')]);
        exit;
    }
    
    $userId = (int)$user['id'];
    $realGoogleUid = $user['google_uid'];

    // Verificar créditos do usuário
    $userCredits = (int)($user['credits'] ?? 0);
    if ($userCredits < CREDITS_PER_GAME) {
        echo json_encode([
            'success' => false,
            'error' => 'Créditos insuficientes! Você precisa de ' . CREDITS_PER_GAME . ' crédito(s) para jogar. Compre créditos na Carteira.',
            'error_code' => 'NO_CREDITS',
            'credits' => $userCredits,
            'credits_required' => CREDITS_PER_GAME
        ]);
        exit;
    }

    // Verificar se já tem sessão ativa (impedir partidas simultâneas)
    $stmt = $pdo->prepare("
        SELECT id, started_at, TIMESTAMPDIFF(SECOND, started_at, NOW()) as elapsed
        FROM game_sessions
        WHERE google_uid = ? AND status = 'active'
        ORDER BY started_at DESC
        LIMIT 1
    ");
    $stmt->execute([$realGoogleUid]);
    $activeSession = $stmt->fetch();

    $forceStart = !empty($input['force_start']);

    if ($activeSession) {
        $elapsed = (int)$activeSession['elapsed'];
        $maxSessionTime = GAME_DURATION + GAME_TOLERANCE + (defined('CAPTCHA_RESEND_TOLERANCE') ? CAPTCHA_RESEND_TOLERANCE : 60);

        // Auto-expirar se: sessão ultrapassou tempo máximo OU jogo já acabou (>180s) e usuário pediu force_start
        $shouldExpire = ($elapsed >= $maxSessionTime) || ($forceStart && $elapsed > GAME_DURATION);

        if ($shouldExpire) {
            // Sessão expirada/travada — auto-expirar
            $pdo->prepare("
                UPDATE game_sessions
                SET status = 'abandoned', ended_at = NOW()
                WHERE id = ?
            ")->execute([$activeSession['id']]);
            secureLog("AUTO_EXPIRE_SESSION | Session: {$activeSession['id']} | Elapsed: {$elapsed}s | User: $userId | Force: " . ($forceStart ? 'YES' : 'NO'));
        } else {
            // Sessão ainda é válida — bloquear nova partida
            echo json_encode([
                'success' => false,
                'error' => 'Você já tem uma partida em andamento! Finalize a partida atual antes de iniciar outra.',
                'error_code' => 'ACTIVE_SESSION_EXISTS',
                'active_session_id' => (int)$activeSession['id'],
                'elapsed_seconds' => $elapsed,
                'can_force' => $elapsed > GAME_DURATION
            ]);
            exit;
        }
    }
    
    // Calcular número da missão
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM game_sessions WHERE google_uid = ?");
    $stmt->execute([$realGoogleUid]);
    $result = $stmt->fetch();
    $missionNumber = (int)($result['total'] ?? 0) + 1;
    
    // Determinar hard mode (servidor decide, não cliente)
    $isHardMode = isHardModeMission();
    
    // Gerar tokens de segurança
    $sessionToken = hash('sha256', $realGoogleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $sessionSeed = generateSessionSeed(); // Novo: seed para verificação
    
    // Criar sessão
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            google_uid, 
            session_token,
            session_uuid,
            mission_number, 
            is_hard_mode,
            ip_address, 
            user_agent, 
            earnings_brl, 
            asteroids_destroyed,
            common_asteroids,
            rare_asteroids,
            epic_asteroids,
            legendary_asteroids,
            started_at, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, NOW(), NOW())
    ");
    
    $stmt->execute([
        $realGoogleUid, 
        $sessionToken,
        $sessionSeed, // Armazena seed no campo session_uuid
        $missionNumber, 
        $isHardMode ? 1 : 0,
        $clientIP, 
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);
    
    $sessionId = (int)$pdo->lastInsertId();

    // Proteção contra loop de sessões: se última sessão foi abandonada há menos de 30s,
    // reembolsar o crédito dela (era bug de redirect/auth, não uso legítimo)
    $recentAbandoned = $pdo->prepare("
        SELECT id FROM game_sessions
        WHERE google_uid = ? AND status = 'abandoned'
        AND earnings_brl = 0 AND asteroids_destroyed = 0
        AND ended_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
        ORDER BY ended_at DESC LIMIT 1
    ");
    $recentAbandoned->execute([$realGoogleUid]);
    if ($recentAbandoned->fetch()) {
        // Sessão fantasma (loop de auth) — devolver crédito
        $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?")->execute([CREDITS_PER_GAME, $userId]);
        secureLog("CREDIT_REFUND_LOOP | User: $userId | Reason: abandoned session with 0 score within 30s");
    }

    // Debitar crédito do usuário
    $stmt = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?");
    $stmt->execute([CREDITS_PER_GAME, $userId, CREDITS_PER_GAME]);

    if ($stmt->rowCount() === 0) {
        // Race condition: crédito foi usado por outra sessão simultânea
        $pdo->prepare("UPDATE game_sessions SET status = 'abandoned' WHERE id = ?")->execute([$sessionId]);
        echo json_encode([
            'success' => false,
            'error' => 'Créditos insuficientes! Compre créditos na Carteira.',
            'error_code' => 'NO_CREDITS'
        ]);
        exit;
    }

    $remainingCredits = $userCredits - CREDITS_PER_GAME;

    secureLog("GAME_START | Session: $sessionId | User: $userId | Mission: $missionNumber | HardMode: " . ($isHardMode ? 'YES' : 'NO') . " | Credits: $remainingCredits");
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'session_seed' => $sessionSeed, // Enviado ao cliente para gerar hash
        'google_uid' => $realGoogleUid,
        'user_id' => $userId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'game_duration' => GAME_DURATION,
        'initial_lives' => INITIAL_LIVES,
        'credits' => $remainingCredits,
        'credits_per_game' => CREDITS_PER_GAME,
        // Limites para o cliente saber (baseado no modo)
        'limits' => [
            'max_asteroids' => MAX_ASTEROIDS_PER_GAME,
            'max_legendary' => $isHardMode ? MAX_LEGENDARY_HARD : MAX_LEGENDARY_NORMAL,
            'max_epic' => $isHardMode ? MAX_EPIC_HARD : MAX_EPIC_NORMAL,
            'max_rare' => $isHardMode ? MAX_RARE_HARD : MAX_RARE_NORMAL
        ]
    ]);
    
} catch (Throwable $e) {
    error_log("❌ GAME-START ERROR: " . $e->getMessage() . " | Line: " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Erro interno'
    ]);
}
