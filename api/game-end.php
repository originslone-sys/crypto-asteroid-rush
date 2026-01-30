<?php
// ============================================
// UNOBIX - Finalizar Sessão de Jogo
// Arquivo: api/game-end.php
// v2.0 - Google Auth + CAPTCHA + BRL
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// ============================================
// LER INPUT
// ============================================
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || !is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$sessionToken = isset($input['session_token']) ? trim($input['session_token']) : '';
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$clientScore = isset($input['score']) ? (int)$input['score'] : 0;
$clientEarnings = isset($input['earnings']) ? (float)$input['earnings'] : 0;
$livesRemaining = isset($input['lives_remaining']) ? (int)$input['lives_remaining'] : 0;
$captchaToken = isset($input['captcha_token']) ? trim($input['captcha_token']) : '';
$isVictory = isset($input['victory']) ? (bool)$input['victory'] : ($livesRemaining > 0);

// Validar dados obrigatórios
if (!$sessionId || !$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão ausentes']);
    exit;
}

// Determinar identificador
$identifier = '';
if (!empty($googleUid) && validateGoogleUid($googleUid)) {
    $identifier = $googleUid;
} elseif (!empty($wallet) && validateWallet($wallet)) {
    $identifier = $wallet;
} else {
    echo json_encode(['success' => false, 'error' => 'Identificação inválida']);
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

    // ============================================
    // 1. BUSCAR SESSÃO (com lock)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT * FROM game_sessions 
        WHERE id = ? 
        AND (google_uid = ? OR wallet_address = ?)
        AND status = 'active'
        FOR UPDATE
    ");
    $stmt->execute([$sessionId, $identifier, $identifier]);
    $session = $stmt->fetch();
    
    if (!$session) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já finalizada']);
        exit;
    }
    
    if ($session['session_token'] !== $sessionToken) {
        $pdo->rollBack();
        secureLog("GAME_END_TOKEN_MISMATCH | session_id: {$sessionId}");
        echo json_encode(['success' => false, 'error' => 'Token inválido']);
        exit;
    }

    // ============================================
    // 2. CALCULAR GANHOS REAIS DO SERVIDOR
    // ============================================
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
    $serverEvents = $stmt->fetch();
    
    $serverEarningsBrl = (float)($serverEvents['total_brl'] ?? 0);
    $eventCount = (int)($serverEvents['event_count'] ?? 0);

    // ============================================
    // 3. VERIFICAR CAPTCHA (apenas se vitória)
    // ============================================
    $captchaVerified = false;
    $captchaRequired = ($session['captcha_required'] && $isVictory && CAPTCHA_REQUIRED_ON_VICTORY);
    
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
        
        if (!$captchaResult['success']) {
            // Logar tentativa falha
            $pdo->prepare("
                INSERT INTO captcha_log (session_id, google_uid, wallet_address, is_success, ip_address, created_at)
                VALUES (?, ?, ?, 0, ?, NOW())
            ")->execute([$sessionId, $session['google_uid'], $session['wallet_address'], $clientIP]);
            
            $pdo->rollBack();
            secureLog("CAPTCHA_FAILED | session_id: {$sessionId} | msg: {$captchaResult['message']}");
            
            echo json_encode([
                'success' => false, 
                'error' => 'Falha na verificação CAPTCHA. Tente novamente.',
                'captcha_required' => true
            ]);
            exit;
        }
        
        $captchaVerified = true;
        
        // Logar sucesso
        $pdo->prepare("
            INSERT INTO captcha_log (session_id, google_uid, wallet_address, is_success, ip_address, created_at)
            VALUES (?, ?, ?, 1, ?, NOW())
        ")->execute([$sessionId, $session['google_uid'], $session['wallet_address'], $clientIP]);
    }

    // ============================================
    // 4. VALIDAR GANHOS (tolerância de 10%)
    // ============================================
    $validationErrors = [];
    $alertLevel = null;
    $finalEarningsBrl = $serverEarningsBrl;
    
    // Verificar discrepância cliente vs servidor
    if ($clientEarnings > 0 && $serverEarningsBrl > 0) {
        $discrepancy = abs($clientEarnings - $serverEarningsBrl);
        $tolerance = $serverEarningsBrl * 0.10; // 10%
        
        if ($discrepancy > $tolerance && $discrepancy > 0.001) {
            $validationErrors[] = "Discrepância cliente/servidor: R$ {$clientEarnings} vs R$ {$serverEarningsBrl}";
            secureLog("EARNINGS_DISCREPANCY | session: {$sessionId} | client: {$clientEarnings} | server: {$serverEarningsBrl}");
        }
    }
    
    // SEMPRE usar valor do servidor
    $finalEarningsBrl = $serverEarningsBrl;
    
    // Verificar limites de alerta
    if ($finalEarningsBrl > EARNINGS_BLOCK_BRL) {
        $alertLevel = 'BLOCK';
        $validationErrors[] = "Ganhos acima do limite: R$ {$finalEarningsBrl}";
        $finalEarningsBrl = 0; // Zerar ganhos suspeitos
        secureLog("EARNINGS_BLOCKED | session: {$sessionId} | amount: {$serverEarningsBrl}");
    } elseif ($finalEarningsBrl > EARNINGS_SUSPECT_BRL) {
        $alertLevel = 'SUSPECT';
        secureLog("EARNINGS_SUSPECT | session: {$sessionId} | amount: {$finalEarningsBrl}");
    } elseif ($finalEarningsBrl > EARNINGS_ALERT_BRL) {
        $alertLevel = 'ALERT';
        secureLog("EARNINGS_ALERT | session: {$sessionId} | amount: {$finalEarningsBrl}");
    }

    // ============================================
    // 5. ATUALIZAR SESSÃO
    // ============================================
    $sessionDuration = time() - strtotime($session['started_at']);
    
    $stmt = $pdo->prepare("
        UPDATE game_sessions SET
            status = 'completed',
            earnings_brl = ?,
            earnings_usdt = ?,
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
        $finalEarningsBrl, // Compatibilidade
        $clientScore,
        $clientEarnings,
        $captchaVerified ? 1 : 0,
        $captchaVerified ? date('Y-m-d H:i:s') : null,
        $sessionDuration,
        !empty($validationErrors) ? json_encode($validationErrors) : null,
        $alertLevel,
        $sessionId
    ]);

    // ============================================
    // 6. CREDITAR JOGADOR (se sem bloqueio)
    // ============================================
    $credited = false;
    
    if ($finalEarningsBrl > 0 && $alertLevel !== 'BLOCK') {
        $stmt = $pdo->prepare("
            UPDATE players SET
                balance_brl = balance_brl + ?,
                total_earned_brl = total_earned_brl + ?,
                total_played = total_played + 1
            WHERE (google_uid = ? OR wallet_address = ?)
        ");
        $stmt->execute([
            $finalEarningsBrl, 
            $finalEarningsBrl,
            $session['google_uid'],
            $session['wallet_address']
        ]);
        
        $credited = true;
        
        // Registrar transação
        $pdo->prepare("
            INSERT INTO transactions (
                google_uid, wallet_address, type, amount, amount_brl, 
                description, status, created_at
            ) VALUES (?, ?, 'game_reward', ?, ?, ?, 'completed', NOW())
        ")->execute([
            $session['google_uid'],
            $session['wallet_address'],
            $finalEarningsBrl,
            $finalEarningsBrl,
            "Missão #{$session['mission_number']}" . ($session['is_hard_mode'] ? ' (Hard)' : '')
        ]);
    } else {
        // Apenas incrementar total_played
        $pdo->prepare("
            UPDATE players SET total_played = total_played + 1
            WHERE (google_uid = ? OR wallet_address = ?)
        ")->execute([$session['google_uid'], $session['wallet_address']]);
    }

    // ============================================
    // 7. ATUALIZAR IP_SESSIONS
    // ============================================
    $pdo->prepare("
        UPDATE ip_sessions SET status = 'completed', ended_at = NOW()
        WHERE session_id = ?
    ")->execute([$sessionId]);

    // ============================================
    // 8. ATUALIZAR REFERRAL (se houver)
    // ============================================
    $stmt = $pdo->prepare("
        UPDATE referrals SET missions_completed = missions_completed + 1
        WHERE (referred_google_uid = ? OR referred_wallet = ?)
        AND status = 'pending'
    ");
    $stmt->execute([$session['google_uid'], $session['wallet_address']]);

    // Verificar se completou 100 missões
    $pdo->exec("
        UPDATE referrals 
        SET status = 'completed', completed_at = NOW()
        WHERE missions_completed >= missions_required
        AND status = 'pending'
    ");

    $pdo->commit();

    // ============================================
    // 9. BUSCAR SALDO ATUALIZADO
    // ============================================
    $stmt = $pdo->prepare("
        SELECT balance_brl, total_earned_brl, total_played 
        FROM players 
        WHERE (google_uid = ? OR wallet_address = ?)
    ");
    $stmt->execute([$session['google_uid'], $session['wallet_address']]);
    $player = $stmt->fetch();

    // Log de sucesso
    secureLog("GAME_END | session: {$sessionId} | earnings: R$ {$finalEarningsBrl} | credited: " . ($credited ? 'YES' : 'NO') . " | victory: " . ($isVictory ? 'YES' : 'NO'));

    // ============================================
    // RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'mission_number' => $session['mission_number'],
        'is_hard_mode' => (bool)$session['is_hard_mode'],
        'victory' => $isVictory,
        'earnings_brl' => $finalEarningsBrl,
        'final_earnings' => $finalEarningsBrl,  // Alias para frontend
        'new_balance' => (float)($player['balance_brl'] ?? 0),  // Alias para frontend
        'events_recorded' => $eventCount,
        'stats' => [
            'asteroids_destroyed' => (int)$session['asteroids_destroyed'] + $eventCount,
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
        'session_duration' => $sessionDuration,
        'validation_warnings' => count($validationErrors) > 0 ? count($validationErrors) : null
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    secureLog("GAME_END_ERROR | session: {$sessionId} | Error: " . $e->getMessage());
    error_log("Erro em game-end.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
