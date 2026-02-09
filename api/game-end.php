<?php
// ============================================
// UNOBIX - Finalizar Sessão de Jogo
// api/game-end.php v5.0 - Arquitetura Segura
// Validação server-side, anti-cheat, crédito
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$sessionId = (int)($input['session_id'] ?? 0);
$sessionToken = trim($input['session_token'] ?? '');
$googleUid = trim($input['google_uid'] ?? '');
$clientEarnings = (float)($input['earnings'] ?? 0);
$livesRemaining = (int)($input['lives_remaining'] ?? 0);
$victory = (bool)($input['victory'] ?? false);
$clientStats = $input['stats'] ?? [];
$captchaToken = $input['captcha_token'] ?? '';
$gameHash = $input['game_hash'] ?? ''; // Hash de verificação (opcional)

// Validação básica
if (!$sessionId || !$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão inválidos']);
    exit;
}

if (!$googleUid) {
    echo json_encode(['success' => false, 'error' => 'google_uid ausente']);
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
    
    // Buscar sessão e usuário
    $stmt = $pdo->prepare("
        SELECT gs.*, u.id as user_id, u.balance_brl as user_balance, u.is_banned
        FROM game_sessions gs
        LEFT JOIN users u ON u.google_uid = gs.google_uid
        WHERE gs.id = ? 
        AND gs.session_token = ?
        LIMIT 1
    ");
    $stmt->execute([$sessionId, $sessionToken]);
    $session = $stmt->fetch();
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou token inválido']);
        exit;
    }
    
    // Verificar UID (permitir truncado)
    $sessionUid = $session['google_uid'];
    if (strpos($googleUid, '...') === false && $sessionUid !== $googleUid) {
        echo json_encode(['success' => false, 'error' => 'UID não corresponde à sessão']);
        exit;
    }
    
    if ($session['status'] !== 'active') {
        // Sessão já finalizada - retornar dados anteriores
        echo json_encode([
            'success' => true,
            'already_completed' => true,
            'final_earnings' => (float)$session['earnings_brl'],
            'message' => 'Sessão já foi finalizada anteriormente'
        ]);
        exit;
    }
    
    if (!empty($session['is_banned'])) {
        $pdo->prepare("UPDATE game_sessions SET status = 'abandoned', ended_at = NOW() WHERE id = ?")
            ->execute([$sessionId]);
        echo json_encode(['success' => false, 'error' => 'Conta suspensa', 'banned' => true]);
        exit;
    }
    
    // ============================================
    // 2. CALCULAR DURAÇÃO E VALIDAR TEMPO
    // ============================================
    
    $startedAt = strtotime($session['started_at']);
    $gameDuration = time() - $startedAt;
    
    // Verificar se não passou muito tempo
    if ($gameDuration > GAME_DURATION + GAME_TOLERANCE) {
        $pdo->prepare("UPDATE game_sessions SET status = 'abandoned', ended_at = NOW() WHERE id = ?")
            ->execute([$sessionId]);
        echo json_encode([
            'success' => false, 
            'error' => 'Sessão expirada',
            'elapsed' => $gameDuration,
            'max_allowed' => GAME_DURATION + GAME_TOLERANCE
        ]);
        exit;
    }
    
    // ============================================
    // 3. DETERMINAR ESTATÍSTICAS FINAIS
    // ============================================
    
    // Usar stats do cliente se fornecidos, senão usar contadores da sessão
    $finalStats = [
        'common' => (int)($clientStats['common'] ?? $session['common_asteroids'] ?? 0),
        'rare' => (int)($clientStats['rare'] ?? $session['rare_asteroids'] ?? 0),
        'epic' => (int)($clientStats['epic'] ?? $session['epic_asteroids'] ?? 0),
        'legendary' => (int)($clientStats['legendary'] ?? $session['legendary_asteroids'] ?? 0)
    ];
    
    $totalAsteroids = array_sum($finalStats);
    
    // ============================================
    // 4. VALIDAÇÃO ANTI-CHEAT
    // ============================================
    
    $validation = validateGameStats($finalStats, $gameDuration, (bool)$session['is_hard_mode']);
    
    $isFlagged = false;
    $flagReason = null;
    
    if (!$validation['valid']) {
        // Estatísticas inválidas - possível trapaça
        $isFlagged = true;
        $flagReason = implode('; ', $validation['errors']);
        secureLog("🚨 CHEAT_DETECTED | Session: $sessionId | Errors: $flagReason");
    } elseif (!empty($validation['warnings'])) {
        // Estatísticas suspeitas - marcar para revisão mas não bloquear
        secureLog("⚠️ SUSPICIOUS | Session: $sessionId | Warnings: " . implode('; ', $validation['warnings']));
    }
    
    // ============================================
    // 5. CALCULAR GANHOS NO SERVIDOR
    // ============================================
    
    $isVictory = $victory && $livesRemaining > 0;
    
    // Se foi flagged como trapaça, não credita
    if ($isFlagged) {
        $finalEarnings = 0;
        $isVictory = false;
    } elseif (!$isVictory) {
        // Perdeu - não ganha nada
        $finalEarnings = 0;
    } else {
        // Calcular ganhos baseado nas estatísticas validadas
        $finalEarnings = calculateServerEarnings($finalStats);
    }
    
    // Verificar limites de ganhos
    if ($finalEarnings > EARNINGS_BLOCK_BRL) {
        secureLog("🚨 EARNINGS_BLOCKED | Session: $sessionId | Earnings: $finalEarnings");
        $isFlagged = true;
        $flagReason = "Ganhos excedem limite: R$$finalEarnings > R$" . EARNINGS_BLOCK_BRL;
        $finalEarnings = 0;
    } elseif ($finalEarnings > EARNINGS_ALERT_BRL) {
        secureLog("⚠️ HIGH_EARNINGS | Session: $sessionId | Earnings: $finalEarnings");
    }
    
    // ============================================
    // 6. VERIFICAR reCAPTCHA v3 (score-based)
    // ============================================
    
    $captchaScore = null;
    $captchaSuspicious = false;
    
    if ($isVictory && $finalEarnings > 0 && CAPTCHA_REQUIRED_ON_VICTORY) {
        $captchaResult = verifyCaptcha($captchaToken, getClientIP(), 'game_end');
        $captchaScore = $captchaResult['score'] ?? null;
        
        if (!$captchaResult['success']) {
            // Token ausente ou bloqueado
            if (!empty($captchaResult['blocked'])) {
                // Score muito baixo → provável bot → não credita
                secureLog("RECAPTCHA_BLOCKED | Session: {$sessionId} | Score: {$captchaScore}");
                $isFlagged = true;
                $flagReason = ($flagReason ? $flagReason . '; ' : '') . "reCAPTCHA score bloqueado ({$captchaScore})";
                $finalEarnings = 0;
            } else {
                // Sem token → pedir verificação ao frontend
                echo json_encode([
                    'success' => true,
                    'captcha_required' => true,
                    'message' => 'Verificação de segurança necessária',
                    'pending_earnings' => $finalEarnings,
                    'session_id' => $sessionId,
                    'credited' => false
                ]);
                exit;
            }
        } elseif (!empty($captchaResult['suspicious'])) {
            // Score baixo mas não bloqueante → flaggar sessão para revisão
            $captchaSuspicious = true;
            secureLog("RECAPTCHA_SUSPICIOUS | Session: {$sessionId} | Score: {$captchaScore}");
            
            // Registrar atividade suspeita
            try {
                $stmtSusp = $pdo->prepare("
                    INSERT INTO suspicious_activity 
                    (user_id, ip_address, activity_type, details, severity, created_at)
                    VALUES (?, ?, 'LOW_RECAPTCHA_SCORE', ?, 'medium', NOW())
                ");
                $stmtSusp->execute([
                    $session['user_id'] ?? null,
                    getClientIP(),
                    json_encode(['session_id' => $sessionId, 'score' => $captchaScore, 'earnings' => $finalEarnings])
                ]);
            } catch (Exception $e) {}
        }
    }
    
    // ============================================
    // 7. TRANSAÇÃO: ATUALIZAR BANCO
    // ============================================
    
    $pdo->beginTransaction();
    
    try {
        $realGoogleUid = $session['google_uid'];
        $status = $isFlagged ? 'flagged' : ($isVictory ? 'completed' : 'abandoned');
        
        // 7a. Atualizar sessão
        $stmt = $pdo->prepare("
            UPDATE game_sessions SET 
                status = ?,
                earnings_brl = ?,
                asteroids_destroyed = ?,
                common_asteroids = ?,
                rare_asteroids = ?,
                epic_asteroids = ?,
                legendary_asteroids = ?,
                game_duration = ?,
                ended_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $finalEarnings,
            $totalAsteroids,
            $finalStats['common'],
            $finalStats['rare'],
            $finalStats['epic'],
            $finalStats['legendary'],
            min($gameDuration, GAME_DURATION), // Cap duration at game time
            $sessionId
        ]);
        
        $credited = false;
        $newBalance = (float)($session['user_balance'] ?? 0);
        
        // 7b. Creditar saldo (apenas se vitória, não flagged, e ganhos > 0)
        if ($isVictory && !$isFlagged && $finalEarnings > 0 && $session['user_id']) {
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    balance_brl = balance_brl + ?,
                    total_earned_brl = total_earned_brl + ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$finalEarnings, $finalEarnings, $session['user_id']]);
            
            $credited = true;
            
            // 7c. Registrar transação
            $description = sprintf(
                "Missão #%d%s: %d raros, %d épicos, %d lendários",
                $session['mission_number'],
                $session['is_hard_mode'] ? ' (Hard)' : '',
                $finalStats['rare'],
                $finalStats['epic'],
                $finalStats['legendary']
            );
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    google_uid, type, amount, amount_brl, 
                    description, status, created_at
                ) VALUES (?, 'game_earning', ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $realGoogleUid,
                $finalEarnings,
                $finalEarnings,
                $description
            ]);
            
            // 7d. Buscar novo saldo
            $stmt = $pdo->prepare("SELECT balance_brl FROM users WHERE id = ?");
            $stmt->execute([$session['user_id']]);
            $newBalance = (float)$stmt->fetchColumn();
        }
        
        // 7e. Registrar atividade suspeita se flagged
        if ($isFlagged) {
            $stmt = $pdo->prepare("
                INSERT INTO suspicious_activity (
                    user_id, ip_address, activity_type, details, 
                    severity, created_at
                ) VALUES (?, ?, 'game_cheat', ?, 'high', NOW())
            ");
            $stmt->execute([
                $session['user_id'] ?? 0,
                getClientIP(),
                json_encode([
                    'session_id' => $sessionId,
                    'reason' => $flagReason,
                    'stats' => $finalStats,
                    'client_earnings' => $clientEarnings,
                    'flags' => $validation['flags'] ?? []
                ])
            ]);
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
    // ============================================
    // 7f. ATUALIZAR PROGRESSO DE REFERRAL
    // ============================================
    if ($credited && !empty($realGoogleUid)) {
        try {
            require_once __DIR__ . '/referral-helper.php';
            $refResult = updateReferralProgress($pdo, $realGoogleUid);
            if ($refResult['completed']) {
                secureLog("REFERRAL_QUALIFIED_VIA_GAME | Referred: {$realGoogleUid} | Referrer: {$refResult['referrer_google_uid']}");
            }
        } catch (Exception $e) {
            // Não falhar o game-end por erro de referral
            error_log("Referral progress error: " . $e->getMessage());
        }
    }
    
    // ============================================
    // 8. LOG E RESPOSTA
    // ============================================
    
    secureLog(sprintf(
        "GAME_END | Session: %d | Status: %s | Earnings: %.4f | Credited: %s | Balance: %.4f",
        $sessionId,
        $status,
        $finalEarnings,
        $credited ? 'YES' : 'NO',
        $newBalance
    ));
    
    $response = [
        'success' => true,
        'session_id' => $sessionId,
        'victory' => $isVictory,
        'final_earnings' => round($finalEarnings, 6),
        'credited' => $credited,
        'new_balance' => round($newBalance, 6),
        'stats' => $finalStats,
        'mission_number' => (int)$session['mission_number'],
        'is_hard_mode' => (bool)$session['is_hard_mode'],
        'game_duration' => min($gameDuration, GAME_DURATION)
    ];
    
    if ($isFlagged) {
        $response['flagged'] = true;
        $response['flag_reason'] = 'Atividade suspeita detectada';
    }
    
    if ($captchaSuspicious) {
        $response['captcha_suspicious'] = true;
        $response['captcha_score'] = $captchaScore;
    }
    
    if (!empty($validation['warnings']) && !$isFlagged) {
        $response['warnings'] = $validation['warnings'];
    }
    
    echo json_encode($response);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    secureLog("GAME_END_ERROR | Session: $sessionId | " . $e->getMessage());
    error_log("game-end.php error: " . $e->getMessage() . " | Line: " . $e->getLine());
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Erro ao finalizar sessão'
    ]);
}
