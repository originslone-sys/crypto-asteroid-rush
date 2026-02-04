<?php
// ============================================
// UNOBIX - Finalizar Sessão de Jogo
// api/game-end.php v4.0 - REESCRITO
// Credita saldo, registra transação, finaliza sessão
// ============================================

require_once __DIR__ . "/config.php";

// Headers
setCorsHeaders();

// Ler input
$input = getRequestInput();

$sessionId = (int)($input['session_id'] ?? 0);
$sessionToken = trim($input['session_token'] ?? '');
$googleUid = trim($input['google_uid'] ?? '');
$score = (int)($input['score'] ?? 0);
$clientEarnings = (float)($input['earnings'] ?? 0);
$livesRemaining = (int)($input['lives_remaining'] ?? 0);
$victory = (bool)($input['victory'] ?? false);
$stats = $input['stats'] ?? [];
$destroyedAsteroids = $input['destroyed_asteroids'] ?? [];
$captchaToken = $input['captcha_token'] ?? '';

// Validar dados básicos
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
    
    // Permitir UID truncado
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
    
    // Verificar se já foi finalizada
    if ($session['status'] !== 'active') {
        echo json_encode([
            'success' => false, 
            'error' => 'Sessão já finalizada',
            'status' => $session['status']
        ]);
        exit;
    }
    
    // Verificar se usuário está banido
    if (!empty($session['is_banned'])) {
        $pdo->prepare("UPDATE game_sessions SET status = 'cancelled' WHERE id = ?")->execute([$sessionId]);
        echo json_encode(['success' => false, 'error' => 'Conta suspensa', 'banned' => true]);
        exit;
    }
    
    // ============================================
    // 2. CALCULAR GANHOS DO SERVIDOR (fonte de verdade)
    // ============================================
    
    $stmt = $pdo->prepare("
        SELECT 
            reward_type,
            COUNT(*) as count,
            SUM(reward_amount_brl) as total
        FROM game_events 
        WHERE session_id = ?
        GROUP BY reward_type
    ");
    $stmt->execute([$sessionId]);
    $eventStats = $stmt->fetchAll();
    
    $serverEarnings = 0;
    $serverStats = [
        'common' => 0,
        'rare' => 0,
        'epic' => 0,
        'legendary' => 0
    ];
    
    foreach ($eventStats as $stat) {
        $type = strtolower($stat['reward_type']);
        if (isset($serverStats[$type])) {
            $serverStats[$type] = (int)$stat['count'];
        }
        $serverEarnings += (float)$stat['total'];
    }
    
    // Também pegar o earnings_brl acumulado na sessão
    $sessionEarnings = (float)($session['earnings_brl'] ?? 0);
    
    // Usar o maior entre eventos calculados e sessão (proteção)
    $finalEarnings = max($serverEarnings, $sessionEarnings);
    
    // ============================================
    // 3. VERIFICAR VITÓRIA E APLICAR REGRAS
    // ============================================
    
    $isVictory = $victory && $livesRemaining > 0;
    $credited = false;
    $newBalance = (float)($session['balance_brl'] ?? 0);
    $warning = null;
    
    // Se perdeu todas as vidas, não ganha nada
    if (!$isVictory) {
        $finalEarnings = 0;
        $warning = 'Missão falhou - ganhos perdidos';
    }
    
    // Verificar limites de segurança
    if ($finalEarnings > EARNINGS_BLOCK_BRL) {
        // Ganhos muito altos - possível fraude
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
            // Não creditar sem CAPTCHA, mas não falhar a requisição
            // O frontend pode pedir o CAPTCHA e enviar novamente
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
        $realGoogleUid = $session['google_uid']; // UID completo do banco
        
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
        
        // 5e. Registrar no captcha_log se aplicável
        if ($isVictory && !empty($captchaToken)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO captcha_log (session_id, google_uid, is_success, ip_address, created_at)
                    VALUES (?, ?, 1, ?, NOW())
                ");
                $stmt->execute([$sessionId, $realGoogleUid, getClientIP()]);
            } catch (Exception $e) {
                // Ignorar se tabela não existir
            }
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
    error_log("game-end.php error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao finalizar sessão']);
}
