<?php
// ============================================
// UNOBIX - Finalizar Sessão de Jogo
// Arquivo: api/game-end.php
// v3.0 - Unobix-only (google_uid), BRL-only, captcha, compat DB legado
// ============================================

require_once __DIR__ . "/config.php";

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    jsonOut(['success' => false, 'error' => 'Dados inválidos'], 400);
}

$sessionId     = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$sessionToken  = isset($input['session_token']) ? trim($input['session_token']) : '';
$googleUid     = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$clientScore   = isset($input['score']) ? (int)$input['score'] : 0;
$clientEarnings= isset($input['earnings']) ? (float)$input['earnings'] : 0;
$livesRemaining= isset($input['lives_remaining']) ? (int)$input['lives_remaining'] : 0;
$captchaToken  = isset($input['captcha_token']) ? trim($input['captcha_token']) : '';
$isVictory     = isset($input['victory']) ? (bool)$input['victory'] : ($livesRemaining > 0);

if (!$sessionId || $sessionToken === '' || $googleUid === '') {
    jsonOut(['success' => false, 'error' => 'Dados de sessão ausentes'], 400);
}

if (!validateGoogleUid($googleUid)) {
    jsonOut(['success' => false, 'error' => 'google_uid inválido'], 400);
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        jsonOut(['success' => false, 'error' => 'Erro ao conectar ao banco'], 500);
    }

    $pdo->beginTransaction();

    // 1) Buscar sessão (lock) - Unobix-only: google_uid
    $stmt = $pdo->prepare("
        SELECT * FROM game_sessions
        WHERE id = ?
          AND google_uid = ?
          AND status = 'active'
        FOR UPDATE
    ");
    $stmt->execute([$sessionId, $googleUid]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        $pdo->rollBack();
        jsonOut(['success' => false, 'error' => 'Sessão não encontrada ou já finalizada'], 404);
    }

    if (!hash_equals((string)$session['session_token'], (string)$sessionToken)) {
        $pdo->rollBack();
        secureLog("GAME_END_TOKEN_MISMATCH | session_id: {$sessionId}");
        jsonOut(['success' => false, 'error' => 'Token inválido'], 401);
    }

    // 2) Calcular ganhos reais do servidor
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(reward_amount_brl), 0) as total_brl,
            COUNT(*) as event_count,
            SUM(CASE WHEN reward_type = 'legendary' THEN 1 ELSE 0 END) as legendary_count,
            SUM(CASE WHEN reward_type = 'epic' THEN 1 ELSE 0 END) as epic_count,
            SUM(CASE WHEN reward_type = 'rare' THEN 1 ELSE 0 END) as rare_count,
            SUM(CASE WHEN reward_type = 'common' THEN 1 ELSE 0 END) as common_count
        FROM game_events
        WHERE session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $serverEvents = $stmt->fetch(PDO::FETCH_ASSOC);

    $serverEarningsBrl = (float)($serverEvents['total_brl'] ?? 0);
    $eventCount = (int)($serverEvents['event_count'] ?? 0);

    // 3) CAPTCHA (apenas vitória)
    $captchaVerified = false;
    $captchaRequired = (!empty($session['captcha_required']) && $isVictory && defined('CAPTCHA_REQUIRED_ON_VICTORY') && CAPTCHA_REQUIRED_ON_VICTORY);

    if ($captchaRequired) {
        if ($captchaToken === '') {
            $pdo->rollBack();
            jsonOut([
                'success' => false,
                'error' => 'Verificação CAPTCHA necessária',
                'captcha_required' => true,
                'session_id' => $sessionId
            ], 400);
        }

        $captchaResult = verifyCaptcha($captchaToken, $clientIP);

        if (empty($captchaResult['success'])) {
            // log falha (sem wallet)
            $pdo->prepare("
                INSERT INTO captcha_log (session_id, google_uid, is_success, ip_address, created_at)
                VALUES (?, ?, 0, ?, NOW())
            ")->execute([$sessionId, $googleUid, $clientIP]);

            $pdo->rollBack();
            secureLog("CAPTCHA_FAILED | session_id: {$sessionId} | msg: " . ($captchaResult['message'] ?? 'unknown'));
            jsonOut([
                'success' => false,
                'error' => 'Falha na verificação CAPTCHA. Tente novamente.',
                'captcha_required' => true
            ], 400);
        }

        $captchaVerified = true;

        $pdo->prepare("
            INSERT INTO captcha_log (session_id, google_uid, is_success, ip_address, created_at)
            VALUES (?, ?, 1, ?, NOW())
        ")->execute([$sessionId, $googleUid, $clientIP]);
    }

    // 4) Validação ganhos (10%)
    $validationErrors = [];
    $alertLevel = null;
    $finalEarningsBrl = $serverEarningsBrl;

    if ($clientEarnings > 0 && $serverEarningsBrl > 0) {
        $discrepancy = abs($clientEarnings - $serverEarningsBrl);
        $tolerance = $serverEarningsBrl * 0.10;
        if ($discrepancy > $tolerance && $discrepancy > 0.001) {
            $validationErrors[] = "Discrepância cliente/servidor: R$ {$clientEarnings} vs R$ {$serverEarningsBrl}";
            secureLog("EARNINGS_DISCREPANCY | session: {$sessionId} | client: {$clientEarnings} | server: {$serverEarningsBrl}");
        }
    }

    if (defined('EARNINGS_BLOCK_BRL') && $finalEarningsBrl > EARNINGS_BLOCK_BRL) {
        $alertLevel = 'BLOCK';
        $validationErrors[] = "Ganhos acima do limite: R$ {$finalEarningsBrl}";
        $finalEarningsBrl = 0.0;
        secureLog("EARNINGS_BLOCKED | session: {$sessionId} | amount: {$serverEarningsBrl}");
    } elseif (defined('EARNINGS_SUSPECT_BRL') && $finalEarningsBrl > EARNINGS_SUSPECT_BRL) {
        $alertLevel = 'SUSPECT';
        secureLog("EARNINGS_SUSPECT | session: {$sessionId} | amount: {$finalEarningsBrl}");
    } elseif (defined('EARNINGS_ALERT_BRL') && $finalEarningsBrl > EARNINGS_ALERT_BRL) {
        $alertLevel = 'ALERT';
        secureLog("EARNINGS_ALERT | session: {$sessionId} | amount: {$finalEarningsBrl}");
    }

    // 5) Atualizar sessão (BRL-only)
    $sessionDuration = 0;
    if (!empty($session['started_at'])) {
        $sessionDuration = max(0, time() - strtotime($session['started_at']));
    }

    $stmt = $pdo->prepare("
        UPDATE game_sessions SET
            status = 'completed',
            earnings_brl = ?,
            client_score = ?,
            client_earnings = ?,
            captcha_verified = ?,
            captcha_verified_at = ?,
            session_duration = ?,
            validation_errors = ?,
            alert_level = ?,
            ended_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $finalEarningsBrl,
        $clientScore,
        $clientEarnings,
        $captchaVerified ? 1 : 0,
        $captchaVerified ? date('Y-m-d H:i:s') : null,
        $sessionDuration,
        !empty($validationErrors) ? json_encode($validationErrors) : null,
        $alertLevel,
        $sessionId
    ]);

    // 6) Creditar jogador
    $credited = false;

    // compat DB legado: alguns schemas ainda exigem wallet_address NOT NULL em transactions
    $dbWalletCompat = $session['wallet_address'] ?? null;
    if (!$dbWalletCompat || !validateWallet($dbWalletCompat)) {
        // não depende do front: gera compat determinística por usuário
        $dbWalletCompat = '0x' . substr(hash('sha256', 'unobix|' . $googleUid), 0, 40);
    }

    if ($finalEarningsBrl > 0 && $alertLevel !== 'BLOCK') {
        $stmt = $pdo->prepare("
            UPDATE players SET
                balance_brl = balance_brl + ?,
                total_earned_brl = total_earned_brl + ?,
                total_played = total_played + 1
            WHERE google_uid = ?
        ");
        $stmt->execute([$finalEarningsBrl, $finalEarningsBrl, $googleUid]);

        $credited = true;

        // Registrar transação (BRL-only); compat preenchendo campos legado
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                wallet_address, google_uid, type, amount, amount_brl, description, status, tx_hash, fee_bnb, fee_type, created_at
            ) VALUES (?, ?, 'game_reward', ?, ?, ?, 'completed', NULL, 0.00000000, 'unobix', NOW())
        ");
        $stmt->execute([
            $dbWalletCompat,                 // compat legado
            $googleUid,
            $finalEarningsBrl,               // amount legado (preenchido)
            $finalEarningsBrl,               // amount_brl correto
            "Missão #{$session['mission_number']}" . (!empty($session['is_hard_mode']) ? ' (Hard)' : '')
        ]);
    } else {
        $pdo->prepare("UPDATE players SET total_played = total_played + 1 WHERE google_uid = ?")
            ->execute([$googleUid]);
    }

    // 7) ip_sessions (mantém)
    $pdo->prepare("UPDATE ip_sessions SET status = 'completed', ended_at = NOW() WHERE session_id = ?")
        ->execute([$sessionId]);

    // 8) referrals (ainda legado por wallet) - Unobix-only: só por google_uid
    $stmt = $pdo->prepare("
        UPDATE referrals SET missions_completed = missions_completed + 1
        WHERE referred_google_uid = ?
          AND status = 'pending'
    ");
    $stmt->execute([$googleUid]);

    $pdo->exec("
        UPDATE referrals
        SET status = 'completed', completed_at = NOW()
        WHERE missions_completed >= missions_required
          AND status = 'pending'
    ");

    $pdo->commit();

    // 9) Saldo atualizado
    $stmt = $pdo->prepare("SELECT balance_brl, total_earned_brl, total_played FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    secureLog("GAME_END | session: {$sessionId} | uid: {$googleUid} | earnings: R$ {$finalEarningsBrl} | credited: " . ($credited ? 'YES' : 'NO') . " | victory: " . ($isVictory ? 'YES' : 'NO'));

    jsonOut([
        'success' => true,
        'session_id' => $sessionId,
        'mission_number' => (int)$session['mission_number'],
        'is_hard_mode' => (bool)($session['is_hard_mode'] ?? 0),
        'victory' => $isVictory,
        'earnings_brl' => $finalEarningsBrl,
        'final_earnings' => $finalEarningsBrl,
        'new_balance' => (float)($player['balance_brl'] ?? 0),
        'events_recorded' => $eventCount,
        'stats' => [
            'asteroids_destroyed' => (int)($session['asteroids_destroyed'] ?? 0) + $eventCount,
            'legendary' => (int)($serverEvents['legendary_count'] ?? 0),
            'epic' => (int)($serverEvents['epic_count'] ?? 0),
            'rare' => (int)($serverEvents['rare_count'] ?? 0),
            'common' => (int)($serverEvents['common_count'] ?? 0),
        ],
        'player' => [
            'balance_brl' => (float)($player['balance_brl'] ?? 0),
            'total_earned_brl' => (float)($player['total_earned_brl'] ?? 0),
            'total_played' => (int)($player['total_played'] ?? 0),
        ],
        'credited' => $credited,
        'captcha_verified' => $captchaVerified,
        'session_duration' => $sessionDuration,
        'validation_warnings' => count($validationErrors) > 0 ? count($validationErrors) : null
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    secureLog("GAME_END_ERROR | session: {$sessionId} | Error: " . $e->getMessage());
    error_log("Erro em game-end.php: " . $e->getMessage());
    jsonOut(['success' => false, 'error' => 'Erro interno'], 500);
}
