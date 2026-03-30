<?php
// ============================================
// UNOBIX - Iniciar Sessao de Jogo v2
// api/game-start-v2.php - Modo de jogo escolhido pelo usuario
// Suporta: normal, hard, extreme, training
// ============================================

require_once __DIR__ . "/config.php";

// v2: Duração do jogo (override do config global)
if (!defined('GAME_DURATION_V2')) {
    define('GAME_DURATION_V2', 60); // 60 segundos
}

setCorsHeaders();

$input = getRequestInput();

if (empty($input['google_uid'])) {
    echo json_encode(['success' => false, 'error' => 'google_uid nao fornecido']);
    exit;
}

$googleUid = trim($input['google_uid']);

// Permitir UID truncado com ...
if (strpos($googleUid, '...') === false && !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid invalido']);
    exit;
}

// Validar game_mode
$gameMode = trim($input['game_mode'] ?? 'normal');
if (!isValidGameMode($gameMode)) {
    echo json_encode([
        'success' => false,
        'error' => 'Modo de jogo invalido: ' . $gameMode,
        'error_code' => 'INVALID_GAME_MODE'
    ]);
    exit;
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexao']);
        exit;
    }

    // Buscar usuario
    $user = findPlayer($pdo, $googleUid);

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuario nao encontrado. Faca login primeiro.']);
        exit;
    }

    // Verificar ban
    if (!empty($user['is_banned'])) {
        echo json_encode(['success' => false, 'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violacao dos termos')]);
        exit;
    }

    $userId = (int)$user['id'];
    $realGoogleUid = $user['google_uid'];

    // Premium status
    $isPremium = false;
    $stmtPremium = $pdo->prepare("SELECT is_premium, premium_expires_at FROM users WHERE id = ?");
    $stmtPremium->execute([$userId]);
    $premiumData = $stmtPremium->fetch();
    if ($premiumData) {
        $isPremium = !empty($premiumData['is_premium']);
        if ($isPremium && !empty($premiumData['premium_expires_at'])) {
            // Check if premium has expired
            if (strtotime($premiumData['premium_expires_at']) < time()) {
                $isPremium = false;
            }
        }
    }

    // Training mode: skip credits, skip DB save, return fake session immediately
    if ($gameMode === 'training') {
        $fakeToken = hash('sha256', $realGoogleUid . '|training|' . time() . '|' . bin2hex(random_bytes(16)));
        $fakeSeed = generateSessionSeed();

        secureLog("GAME_START_TRAINING | User: $userId | Mode: training");

        echo json_encode([
            'success' => true,
            'session_id' => 0,
            'session_token' => $fakeToken,
            'session_seed' => $fakeSeed,
            'google_uid' => $realGoogleUid,
            'user_id' => $userId,
            'mission_number' => 0,
            'is_hard_mode' => false,
            'game_mode' => 'training',
            'credits_cost' => 0,
            'game_duration' => GAME_DURATION_V2,
            'initial_lives' => INITIAL_LIVES,
            'credits' => (int)($user['credits'] ?? 0),
            'credits_per_game' => 0,
            'is_premium' => $isPremium,
            'limits' => [
                'max_asteroids' => MAX_ASTEROIDS_PER_GAME,
                'max_legendary' => MAX_LEGENDARY_PER_GAME,
                'max_epic' => MAX_EPIC_PER_GAME,
                'max_rare' => MAX_RARE_PER_GAME
            ]
        ]);
        exit;
    }

    // Credit cost based on game mode (ler do banco se disponível, senão fallback hardcoded)
    $creditsCost = getGameModeCredits($gameMode);
    $dbCredits = $pdo->prepare("SELECT setting_value FROM game_settings WHERE setting_key = ?");
    $dbCredits->execute(["mode_{$gameMode}_credits"]);
    $dbCreditsRow = $dbCredits->fetch();
    if ($dbCreditsRow && is_numeric($dbCreditsRow['setting_value'])) {
        $creditsCost = max(1, (int)$dbCreditsRow['setting_value']);
    }

    // Verificar se o modo está habilitado no admin
    $dbEnabled = $pdo->prepare("SELECT setting_value FROM game_settings WHERE setting_key = ?");
    $dbEnabled->execute(["mode_{$gameMode}_enabled"]);
    $dbEnabledRow = $dbEnabled->fetch();
    if ($dbEnabledRow && $dbEnabledRow['setting_value'] === 'false') {
        echo json_encode([
            'success' => false,
            'error' => 'O modo ' . $gameMode . ' está desativado no momento.',
            'error_code' => 'MODE_DISABLED'
        ]);
        exit;
    }

    // Verificar creditos do usuario
    $userCredits = (int)($user['credits'] ?? 0);
    if ($userCredits < $creditsCost) {
        echo json_encode([
            'success' => false,
            'error' => 'Creditos insuficientes! Voce precisa de ' . $creditsCost . ' credito(s) para jogar no modo ' . $gameMode . '. Compre creditos na Carteira.',
            'error_code' => 'NO_CREDITS',
            'credits' => $userCredits,
            'credits_required' => $creditsCost
        ]);
        exit;
    }

    // Verificar se ja tem sessao ativa (impedir partidas simultaneas)
    $stmt = $pdo->prepare("
        SELECT id, started_at, game_mode, TIMESTAMPDIFF(SECOND, started_at, NOW()) as elapsed
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
        $maxSessionTime = GAME_DURATION_V2 + GAME_TOLERANCE + (defined('CAPTCHA_RESEND_TOLERANCE') ? CAPTCHA_RESEND_TOLERANCE : 60);

        // Auto-expirar se: sessao ultrapassou tempo maximo OU jogo ja acabou (>180s) e usuario pediu force_start
        $shouldExpire = ($elapsed >= $maxSessionTime) || ($forceStart && $elapsed > GAME_DURATION_V2);

        if ($shouldExpire) {
            // Sessao expirada/travada - auto-expirar
            $pdo->prepare("
                UPDATE game_sessions
                SET status = 'abandoned', ended_at = NOW()
                WHERE id = ?
            ")->execute([$activeSession['id']]);
            secureLog("AUTO_EXPIRE_SESSION | Session: {$activeSession['id']} | Elapsed: {$elapsed}s | User: $userId | Force: " . ($forceStart ? 'YES' : 'NO'));
        } else {
            // Sessao ainda e valida - bloquear nova partida
            echo json_encode([
                'success' => false,
                'error' => 'Voce ja tem uma partida em andamento! Finalize a partida atual antes de iniciar outra.',
                'error_code' => 'ACTIVE_SESSION_EXISTS',
                'active_session_id' => (int)$activeSession['id'],
                'elapsed_seconds' => $elapsed,
                'can_force' => $elapsed > GAME_DURATION_V2
            ]);
            exit;
        }
    }

    // Calcular numero da missao
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM game_sessions WHERE google_uid = ?");
    $stmt->execute([$realGoogleUid]);
    $result = $stmt->fetch();
    $missionNumber = (int)($result['total'] ?? 0) + 1;

    // Hard mode baseado no modo escolhido pelo usuario
    $isHardMode = in_array($gameMode, ['hard', 'extreme']);

    // Gerar tokens de seguranca
    $sessionToken = hash('sha256', $realGoogleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $sessionSeed = generateSessionSeed();

    // Criar sessao com game_mode
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            google_uid,
            session_token,
            session_uuid,
            mission_number,
            is_hard_mode,
            game_mode,
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, NOW(), NOW())
    ");

    $stmt->execute([
        $realGoogleUid,
        $sessionToken,
        $sessionSeed,
        $missionNumber,
        $isHardMode ? 1 : 0,
        $gameMode,
        $clientIP,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);

    $sessionId = (int)$pdo->lastInsertId();

    // Protecao contra loop de sessoes: se ultima sessao foi abandonada ha menos de 30s,
    // reembolsar o credito dela (era bug de redirect/auth, nao uso legitimo)
    $recentAbandoned = $pdo->prepare("
        SELECT id, game_mode FROM game_sessions
        WHERE google_uid = ? AND status = 'abandoned'
        AND earnings_brl = 0 AND asteroids_destroyed = 0
        AND ended_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
        ORDER BY ended_at DESC LIMIT 1
    ");
    $recentAbandoned->execute([$realGoogleUid]);
    $abandonedRow = $recentAbandoned->fetch();
    if ($abandonedRow) {
        // Sessao fantasma (loop de auth) - devolver credito baseado no modo da sessao abandonada
        $abandonedModeCost = getGameModeCredits($abandonedRow['game_mode'] ?? 'normal');
        $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?")->execute([$abandonedModeCost, $userId]);
        secureLog("CREDIT_REFUND_LOOP | User: $userId | RefundAmount: $abandonedModeCost | AbandonedMode: " . ($abandonedRow['game_mode'] ?? 'normal') . " | Reason: abandoned session with 0 score within 30s");
    }

    // Debitar credito do usuario
    $stmt = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?");
    $stmt->execute([$creditsCost, $userId, $creditsCost]);

    if ($stmt->rowCount() === 0) {
        // Race condition: credito foi usado por outra sessao simultanea
        $pdo->prepare("UPDATE game_sessions SET status = 'abandoned' WHERE id = ?")->execute([$sessionId]);
        echo json_encode([
            'success' => false,
            'error' => 'Creditos insuficientes! Compre creditos na Carteira.',
            'error_code' => 'NO_CREDITS'
        ]);
        exit;
    }

    $remainingCredits = $userCredits - $creditsCost;

    secureLog("GAME_START_V2 | Session: $sessionId | User: $userId | Mission: $missionNumber | Mode: $gameMode | HardMode: " . ($isHardMode ? 'YES' : 'NO') . " | CreditsCost: $creditsCost | Credits: $remainingCredits");

    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'session_seed' => $sessionSeed,
        'google_uid' => $realGoogleUid,
        'user_id' => $userId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'game_mode' => $gameMode,
        'credits_cost' => $creditsCost,
        'game_duration' => GAME_DURATION_V2,
        'initial_lives' => INITIAL_LIVES,
        'credits' => $remainingCredits,
        'credits_per_game' => $creditsCost,
        'is_premium' => $isPremium,
        'limits' => [
            'max_asteroids' => MAX_ASTEROIDS_PER_GAME,
            'max_legendary' => MAX_LEGENDARY_PER_GAME,
            'max_epic' => MAX_EPIC_PER_GAME,
            'max_rare' => MAX_RARE_PER_GAME
        ]
    ]);

} catch (Throwable $e) {
    error_log("GAME-START-V2 ERROR: " . $e->getMessage() . " | Line: " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno'
    ]);
}
