<?php
// ============================================
// UNOBIX - Finalizar Sessão de Jogo
// Arquivo: api/game-end.php
// Unobix-only: google_uid + session_token + session_id
// ============================================

require_once __DIR__ . "/config.php";

ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function readJsonInput(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$input = readJsonInput();

$sessionId    = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$sessionToken = isset($input['session_token']) ? trim($input['session_token']) : '';
$googleUid    = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$clientScore  = isset($input['score']) ? (int)$input['score'] : 0;
$clientEarnings = isset($input['earnings']) ? (float)$input['earnings'] : 0;
$livesRemaining = isset($input['lives_remaining']) ? (int)$input['lives_remaining'] : 0;
$captchaToken = isset($input['captcha_token']) ? trim($input['captcha_token']) : '';
$isVictory = isset($input['victory']) ? (bool)$input['victory'] : ($livesRemaining > 0);

if (!$sessionId || !$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão ausentes']);
    exit;
}

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    $pdo->beginTransaction();

    // 1) buscar sessão com lock (apenas google_uid)
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
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já finalizada']);
        exit;
    }

    if (($session['session_token'] ?? '') !== $sessionToken) {
        $pdo->rollBack();
        if (function_exists('secureLog')) secureLog("GAME_END_TOKEN_MISMATCH | session_id: {$sessionId}");
        echo json_encode(['success' => false, 'error' => 'Token inválido']);
        exit;
    }

    // 2) ganhos reais via events
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

    // 3) captcha (se vitória)
    $captchaVerified = false;
    $captchaRequired = (!empty($session['captcha_required']) && $isVictory && defined('CAPTCHA_REQUIRED_ON_VICTORY') && CAPTCHA_REQUIRED_ON_VICTORY);

    if ($captchaRequired) {
        if (empty($captchaToken)) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Verificação CAPTCHA necessária',
                'captcha_required' => true,
                'session_id' => $sessionId
            ]);
            exit;
        }

        $captchaResult = verifyCaptcha($captchaToken, $clientIP);
        if (empty($captchaResult['success'])) {
            $pdo->prepare("
                INSERT INTO captcha_log (session_id, google_uid, wallet_address, is_success, ip_address, created_at)
                VALUES (?, ?, NULL, 0, ?, NOW())
            ")->execute([$sessionId, $googleUid, $clientIP]);

            $pdo->rollBack();
            if (function_exists('secureLog')) secureLog("CAPTCHA_FAILED | session_id: {$sessionId} | msg: " . ($captchaResult['message'] ?? ''));
            echo json_encode(['success' => false, 'error' => 'Falha na verificação CAPTCHA. Tente novamente.', 'captcha_required' => true]);
            exit;
        }

        $captchaVerified = true;
        $pdo->prepare("
            INSERT INTO captcha_log (session_id, google_uid, wallet_address, is_success, ip_address, created_at)
            VALUES (?, ?, NULL, 1, ?, NOW())
        ")->execute([$sessionId, $googleUid, $clientIP]);
    }

    // 4) validações (mantém sua lógica)
    $validationErrors = [];
    $alertLevel = null;
    $finalEarningsBrl = $serverEarningsBrl;

    if ($clientEarnings > 0 && $serverEarningsBrl > 0) {
        $discrepancy = abs($clientEarnings - $serverEarningsBrl);
        $tolerance = $serverEarningsBrl * 0.10;
        if ($discrepancy > $tolerance && $discrepancy > 0.001) {
            $validationErrors[] = "Discrepância cliente/servidor: R$ {$clientEarnings} vs R$ {$serverEarningsBrl}";
            if (function_exists('secureLog')) secureLog("EARNINGS_DISCREPANCY | session: {$sessionId} | client: {$clientEarnings} | server: {$serverEarningsBrl}");
        }
    }

    if (defined('EARNINGS_BLOCK_BRL') && $finalEarningsBrl > EARNINGS_BLOCK_BRL) {
        $alertLevel = 'BLOCK';
        $validationErrors[] = "Ganhos acima do limite: R$ {$finalEarningsBrl}";
        $finalEarningsBrl = 0;
        if (function_exists('secureLog')) secureLog("EARNINGS_BLOCKED | session: {$sessionId} | amount: {$serverEarningsBrl}");
    } elseif (defined('EARNINGS_SUSPECT_BRL') && $finalEarningsBrl > EARNINGS_SUSPECT_BRL) {
        $alertLevel = 'SUSPECT';
    } elseif (defined('EARNINGS_ALERT_BRL') && $finalEarningsBrl > EARNINGS_ALERT_BRL) {
        $alertLevel = 'ALERT';
    }

    // 5) atualizar sessão
    $startedAt = $session['started_at'] ?? null;
    $sessionDuration = $startedAt ? (time() - strtotime($startedAt)) : null;

    $stmt = $pdo->prepare("
        UPDATE game_sessions SET
            status = 'completed',
            earnings_brl = ?,
            earnings_usdt = ?, -- compat
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

    // 6) creditar player (google_uid only)
    $credited = false;
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

        $pdo->prepare("
            INSERT INTO transactions (google_uid, wallet_address, type, amount, amount_brl, description, status, created_at)
            VALUES (?, NULL, 'game_reward', ?, ?, ?, 'completed', NOW())
        ")->execute([
            $googleUid,
            $finalEarningsBrl,
            $finalEarningsBrl,
            "Missão #{$session['mission_number']}" . (!empty($session['is_hard_mode']) ? ' (Hard)' : '')
        ]);
    } else {
        $pdo->prepare("UPDATE players SET total_played = total_played + 1 WHERE google_uid = ?")
            ->execute([$googleUid]);
    }

    // 7) ip_sessions
    $pdo->prepare("UPDATE ip_sessions SET status = 'completed', ended_at = NOW() WHERE session_id = ?")
        ->execute([$sessionId]);

    $pdo->commit();

    // 8) saldo atualizado
    $stmt = $pdo->prepare("SELECT balance_brl, total_earned_brl, total_played FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (function_exists('secureLog')) {
        secureLog("GAME_END | session: {$sessionId} | earnings: R$ {$finalEarningsBrl} | credited: " . ($credited ? 'YES' : 'NO'));
    }

    echo json_encode([
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
            'legendary' => (int)($serverEvents['legendary_count'] ?? 0),
            'epic' => (int)($serverEvents['epic_count'] ?? 0),
            'rare' => (int)($serverEvents['rare_count'] ?? 0),
            'common' => (int)($serverEvents['common_count'] ?? 0)
        ],
        'player' => [
            'balance_brl' => (float)($player['balance_brl'] ?? 0),
            'total_earned_brl' => (float)($player['total_earned_brl'] ?? 0),
            'total_played' => (int)($player['total_played'] ?? 0)
        ],
        'credited' => $credited,
        'captcha_verified' => $captchaVerified,
        'session_duration' => $sessionDuration
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('secureLog')) secureLog("GAME_END_ERROR | session: {$sessionId} | " . $e->getMessage());
    error_log("Erro em game-end.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
