<?php
// ============================================
// UNOBIX - Finalizar Sessão de Jogo
// api/game-end.php v4.1
// Adaptado para estrutura existente do banco
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$sessionId = (int)($input['session_id'] ?? 0);
$sessionToken = trim($input['session_token'] ?? '');
$googleUid = trim($input['google_uid'] ?? '');
$score = (int)($input['score'] ?? 0);
$clientEarnings = (float)($input['earnings'] ?? 0);
$livesRemaining = (int)($input['lives_remaining'] ?? 0);
$victory = (bool)($input['victory'] ?? false);
$stats = $input['stats'] ?? [];
$captchaToken = $input['captcha_token'] ?? '';

if (!$sessionId || !$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão inválidos']);
    exit;
}

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão com banco']);
        exit;
    }
    
    // ============================================
    // 1. BUSCAR E VALIDAR SESSÃO
    // ============================================
    
    if (strpos($googleUid, '...') !== false) {
        $stmt = $pdo->prepare("
            SELECT gs.*, u.id as user_id, u.balance_brl, u.is_banned
            FROM game_sessions gs
            LEFT JOIN users u ON u.google_uid = gs.google_uid
            WHERE gs.id = ? 
            AND gs.google_uid LIKE ?
            AND gs.session_token = ?
            LIMIT 1
        ");
        $stmt->execute([$sessionId, str_replace('...', '%', $googleUid), $sessionToken]);
    } else {
        $stmt = $pdo->prepare("
            SELECT gs.*, u.id as user_id, u.balance_brl, u.is_banned
            FROM game_sessions gs
            LEFT JOIN users u ON u.google_uid = gs.google_uid
            WHERE gs.id = ? 
            AND gs.google_uid = ?
            AND gs.session_token = ?
            LIMIT 1
        ");
        $stmt->execute([$sessionId, $googleUid, $sessionToken]);
    }
    
    $session = $stmt->fetch();
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou token inválido']);
        exit;
    }
    
    if ($session['status'] !== 'active') {
        echo json_encode([
            'success' => false, 
            'error' => 'Sessão já finalizada',
            'status' => $session['status']
        ]);
        exit;
    }
    
    if (!empty($session['is_banned'])) {
        $pdo->prepare("UPDATE game_sessions SET status = 'cancelled' WHERE id = ?")->execute([$sessionId]);
        echo json_encode(['success' => false, 'error' => 'Conta suspensa', 'banned' => true]);
        exit;
    }
    
    // ============================================
    // 2. CALCULAR GANHOS DO SERVIDOR
    // ============================================
    
    // Usar coluna earnings_brl da tabela game_events
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_events,
            COALESCE(SUM(earnings_brl), 0) as total_earnings
        FROM game_events 
        WHERE session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $eventStats = $stmt->fetch();
    
    $serverEarnings = (float)($eventStats['total_earnings'] ?? 0);
    
    // Também usar earnings_brl acumulado na sessão
    $sessionEarnings = (float)($session['earnings_brl'] ?? 0);
    
    // Usar o maior entre eventos e sessão
    $finalEarnings = max($serverEarnings, $sessionEarnings);
    
    // Stats da sessão
    $serverStats = [
        'common' => (int)($session['common_asteroids'] ?? 0),
        'rare' => (int)($session['rare_asteroids'] ?? 0),
        'epic' => (int)($session['epic_asteroids'] ?? 0),
        'legendary' => (int)($session['legendary_asteroids'] ?? 0)
    ];
    
    // ============================================
    // 3. VERIFICAR VITÓRIA E APLICAR REGRAS
    // ============================================
    
    $isVictory = $victory && $livesRemaining > 0;
    $credited = false;
    $newBalance = (float)($session['balance_brl'] ?? 0);
    $warning = null;
    
    if (!$isVictory) {
        $finalEarnings = 0;
        $warning = 'Missão falhou - ganhos perdidos';
    }
    
    // Verificar limites de segurança
    if ($finalEarnings > EARNINGS_BLOCK_BRL) {
        secureLog("⚠️ SUSPICIOUS | Session: $sessionId | Earnings: $finalEarnings | BLOCKED");
        $pdo->prepare("UPDATE game_sessions SET status = 'suspicious', ended_at = NOW() WHERE id = ?")
            ->execute([$sessionId]);
        echo json_encode([
            'success' => false,
            'error' => 'Ganhos suspeitos detectados. Sessão em análise.',
            'earnings_blocked' => true
        ]);
        exit;
    }
    
    if ($finalEarnings > EARNINGS_ALERT_BRL) {
        $warning = 'Ganhos altos - em revisão';
        secureLog("⚠️ HIGH_EARNINGS | Session: $sessionId | Earnings: $finalEarnings");
    }
    
    // ============================================
    // 4. VERIFICAR CAPTCHA (se vitória)
    // ============================================
    
    if ($isVictory && $finalEarnings > 0 && CAPTCHA_REQUIRED_ON_VICTORY) {
        $captchaResult = verifyCaptcha($captchaToken);
        
        if (!$captchaResult['success']) {
            echo json_encode([
                'success' => true,
                'captcha_required' => true,
                'message' => 'Complete a verificação para resgatar os ganhos',
                'final_earnings' => $finalEarnings,
                'session_id' => $sessionId,
                'credited' => false
            ]);
            exit;
        }
    }
    
    // ============================================
    // 5. INICIAR TRANSAÇÃO DE BANCO
    // ============================================
    
    $pdo->beginTransaction();
    
    try {
        $realGoogleUid = $session['google_uid'];
        
        // 5a. Finalizar sessão
        $stmt = $pdo->prepare("
            UPDATE game_sessions SET 
                status = ?,
                earnings_brl = ?,
                ended_at = NOW(),
                common_asteroids = ?,
                rare_asteroids = ?,
                epic_asteroids = ?,
                legendary_asteroids = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $isVictory ? 'completed' : 'failed',
            $finalEarnings,
            $serverStats['common'],
            $serverStats['rare'],
            $serverStats['epic'],
            $serverStats['legendary'],
            $sessionId
        ]);
        
        // 5b. Creditar saldo (apenas se vitória e ganhos > 0)
        if ($isVictory && $finalEarnings > 0) {
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    balance_brl = balance_brl + ?,
                    total_earned_brl = total_earned_brl + ?
                WHERE google_uid = ?
            ");
            $stmt->execute([$finalEarnings, $finalEarnings, $realGoogleUid]);
            
            $credited = true;
            
            // 5c. Registrar transação
            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    google_uid, wallet_address, type, 
                    amount, amount_brl, 
                    description, status, created_at
                ) VALUES (?, NULL, 'game_reward', ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $realGoogleUid,
                $finalEarnings,
                $finalEarnings,
                "Missão #{$session['mission_number']} - " . ($session['is_hard_mode'] ? 'Hard Mode' : 'Normal')
            ]);
            
            // 5d. Buscar novo saldo
            $stmt = $pdo->prepare("SELECT balance_brl FROM users WHERE google_uid = ?");
            $stmt->execute([$realGoogleUid]);
            $newBalance = (float)$stmt->fetchColumn();
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
    // ============================================
    // 6. LOG E RESPOSTA
    // ============================================
    
    secureLog(sprintf(
        "GAME_END | Session: %d | User: %s | Victory: %s | Earnings: %.4f | Credited: %s",
        $sessionId,
        substr($realGoogleUid, 0, 15) . '...',
        $isVictory ? 'YES' : 'NO',
        $finalEarnings,
        $credited ? 'YES' : 'NO'
    ));
    
    $response = [
        'success' => true,
        'session_id' => $sessionId,
        'victory' => $isVictory,
        'final_earnings' => round($finalEarnings, 4),
        'credited' => $credited,
        'new_balance' => round($newBalance, 4),
        'stats' => $serverStats,
        'mission_number' => (int)$session['mission_number'],
        'is_hard_mode' => (bool)$session['is_hard_mode']
    ];
    
    if ($warning) {
        $response['warning'] = $warning;
    }
    
    echo json_encode($response);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    secureLog("GAME_END_ERROR | " . $e->getMessage());
    error_log("game-end.php error: " . $e->getMessage() . " | Line: " . $e->getLine());
    
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao finalizar sessão']);
}
