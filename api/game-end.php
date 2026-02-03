<?php
// ============================================
// UNOBIX - Finalizar Sessão de Jogo
// api/game-end.php - VERSÃO CORRIGIDA
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

$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$sessionToken = isset($input['session_token']) ? trim($input['session_token']) : '';
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$clientScore = isset($input['score']) ? (int)$input['score'] : 0;
$clientEarnings = isset($input['earnings']) ? (float)$input['earnings'] : 0;
$livesRemaining = isset($input['lives_remaining']) ? (int)$input['lives_remaining'] : 0;
$captchaToken = isset($input['captcha_token']) ? trim($input['captcha_token']) : '';
$isVictory = isset($input['victory']) ? (bool)$input['victory'] : ($livesRemaining > 0);

// Validações básicas
if (!$sessionId || !$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão ausentes']);
    exit;
}

if (empty($googleUid) || strlen($googleUid) < 10) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

$clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';

try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();
    
    // ============================================
    // 1. BUSCAR SESSÃO (com lock)
    // ============================================
    if (strpos($googleUid, '...') !== false) {
        $likePattern = str_replace('...', '%', $googleUid);
        $stmt = $pdo->prepare("
            SELECT * FROM game_sessions 
            WHERE id = ? AND google_uid LIKE ? AND status = 'active'
            FOR UPDATE
        ");
        $stmt->execute([$sessionId, $likePattern]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM game_sessions 
            WHERE id = ? AND google_uid = ? AND status = 'active'
            FOR UPDATE
        ");
        $stmt->execute([$sessionId, $googleUid]);
    }
    
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou já finalizada']);
        exit;
    }
    
    // Validar token
    if ($session['session_token'] !== $sessionToken) {
        $pdo->rollBack();
        error_log("⚠️ GAME-END | Token mismatch | Session: $sessionId");
        echo json_encode(['success' => false, 'error' => 'Token inválido']);
        exit;
    }
    
    $realGoogleUid = $session['google_uid']; // UID completo do banco
    
    // ============================================
    // 2. CALCULAR GANHOS REAIS (SERVER-SIDE!)
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
    $serverEvents = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $serverEarningsBrl = (float)($serverEvents['total_brl'] ?? 0);
    $eventCount = (int)($serverEvents['event_count'] ?? 0);
    
    // Também considerar ganhos já acumulados na sessão (caso eventos falharam)
    $sessionEarnings = (float)($session['earnings_brl'] ?? 0);
    
    // Usar o maior valor (mais confiável)
    $finalEarningsBrl = max($serverEarningsBrl, $sessionEarnings);
    
    error_log("📊 GAME-END | Session: $sessionId | Server: R$ $serverEarningsBrl | Session: R$ $sessionEarnings | Final: R$ $finalEarningsBrl");
    
    // ============================================
    // 3. VALIDAÇÕES DE SEGURANÇA
    // ============================================
    $validationErrors = [];
    $alertLevel = null;
    
    // Verificar discrepância cliente/servidor
    if ($clientEarnings > 0 && $finalEarningsBrl > 0) {
        $discrepancy = abs($clientEarnings - $finalEarningsBrl);
        $tolerance = $finalEarningsBrl * 0.15; // 15% tolerância
        
        if ($discrepancy > $tolerance && $discrepancy > 0.001) {
            $validationErrors[] = "Discrepância: cliente R$ $clientEarnings vs servidor R$ $finalEarningsBrl";
            error_log("⚠️ GAME-END | Discrepância | Session: $sessionId | Client: $clientEarnings | Server: $finalEarningsBrl");
        }
    }
    
    // Limites de alerta
    $alertBrl = defined('EARNINGS_ALERT_BRL') ? EARNINGS_ALERT_BRL : 0.30;
    $suspectBrl = defined('EARNINGS_SUSPECT_BRL') ? EARNINGS_SUSPECT_BRL : 0.50;
    $blockBrl = defined('EARNINGS_BLOCK_BRL') ? EARNINGS_BLOCK_BRL : 1.00;
    
    if ($finalEarningsBrl > $blockBrl) {
        $alertLevel = 'BLOCK';
        $validationErrors[] = "Ganhos acima do limite: R$ $finalEarningsBrl";
        $finalEarningsBrl = 0; // Zerar ganhos suspeitos
        error_log("🚫 GAME-END | BLOCKED | Session: $sessionId | Amount: $serverEarningsBrl");
    } elseif ($finalEarningsBrl > $suspectBrl) {
        $alertLevel = 'SUSPECT';
        error_log("⚠️ GAME-END | SUSPECT | Session: $sessionId | Amount: $finalEarningsBrl");
    } elseif ($finalEarningsBrl > $alertBrl) {
        $alertLevel = 'ALERT';
    }
    
    // ============================================
    // 4. CAPTCHA (se necessário)
    // ============================================
    $captchaVerified = false;
    $captchaRequired = ($isVictory && $finalEarningsBrl > 0.01 && defined('CAPTCHA_REQUIRED_ON_VICTORY') && CAPTCHA_REQUIRED_ON_VICTORY);
    
    if ($captchaRequired && empty($captchaToken)) {
        // Não bloquear, mas marcar para verificação futura
        $validationErrors[] = "CAPTCHA não verificado";
    } elseif ($captchaRequired && !empty($captchaToken)) {
        // Verificar CAPTCHA (se função existir)
        if (function_exists('verifyCaptcha')) {
            $captchaResult = verifyCaptcha($captchaToken, $clientIP);
            $captchaVerified = !empty($captchaResult['success']);
        } else {
            $captchaVerified = true; // Assumir válido se função não existir
        }
    }
    
    // ============================================
    // 5. ATUALIZAR SESSÃO
    // ============================================
    $sessionStart = $session['started_at'] ?? $session['created_at'] ?? null;
    $sessionDuration = $sessionStart ? (time() - strtotime($sessionStart)) : null;
    
    // Usar apenas colunas que existem na tabela
    $stmt = $pdo->prepare("
        UPDATE game_sessions SET
            status = 'completed',
            earnings_brl = ?,
            earnings_usdt = ?,
            game_duration = ?,
            ended_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([
        $finalEarningsBrl,
        $finalEarningsBrl, // earnings_usdt = earnings_brl para compatibilidade
        $sessionDuration,
        $sessionId
    ]);
    
    // ============================================
    // 6. CREDITAR USUÁRIO
    // ============================================
    $credited = false;
    
    if ($finalEarningsBrl > 0 && $alertLevel !== 'BLOCK') {
        // Atualizar saldo do usuário
        $stmt = $pdo->prepare("
            UPDATE users SET
                balance_brl = balance_brl + ?,
                total_earned_brl = total_earned_brl + ?,
                updated_at = NOW()
            WHERE google_uid = ?
        ");
        $stmt->execute([$finalEarningsBrl, $finalEarningsBrl, $realGoogleUid]);
        $credited = ($stmt->rowCount() > 0);
        
        // Registrar transação
        if ($credited) {
            $pdo->prepare("
                INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
                VALUES (?, 'game_reward', ?, ?, 'completed', NOW())
            ")->execute([
                $realGoogleUid,
                $finalEarningsBrl,
                "Missão #" . $session['mission_number'] . ($session['is_hard_mode'] ? ' (Hard)' : '')
            ]);
        }
        
        error_log("✅ GAME-END | Creditado | Session: $sessionId | Amount: R$ $finalEarningsBrl | User: " . substr($realGoogleUid, 0, 10));
    }
    
    // ============================================
    // 7. ATUALIZAR IP_SESSIONS
    // ============================================
    try {
        $pdo->prepare("UPDATE ip_sessions SET status = 'completed', ended_at = NOW() WHERE session_id = ?")
            ->execute([$sessionId]);
    } catch (Exception $e) {
        // Tabela pode não existir
    }
    
    $pdo->commit();
    
    // ============================================
    // 8. BUSCAR SALDO ATUALIZADO
    // ============================================
    $stmt = $pdo->prepare("SELECT balance_brl, total_played, total_earned_brl FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$realGoogleUid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ============================================
    // 9. RESPOSTA
    // ============================================
    error_log("✅ GAME-END | Completo | Session: $sessionId | Credited: " . ($credited ? 'YES' : 'NO') . " | Final: R$ $finalEarningsBrl");
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'mission_number' => (int)$session['mission_number'],
        'is_hard_mode' => (bool)($session['is_hard_mode'] ?? 0),
        'victory' => $isVictory,
        'earnings_brl' => $finalEarningsBrl,
        'final_earnings' => $finalEarningsBrl,
        'new_balance' => (float)($user['balance_brl'] ?? 0),
        'events_recorded' => $eventCount,
        'stats' => [
            'legendary' => (int)($serverEvents['legendary_count'] ?? 0),
            'epic' => (int)($serverEvents['epic_count'] ?? 0),
            'rare' => (int)($serverEvents['rare_count'] ?? 0),
            'common' => (int)($serverEvents['common_count'] ?? 0),
            'total' => $eventCount
        ],
        'user' => [
            'balance_brl' => (float)($user['balance_brl'] ?? 0),
            'total_played' => (int)($user['total_played'] ?? 0),
            'total_earned_brl' => (float)($user['total_earned_brl'] ?? 0)
        ],
        'credited' => $credited,
        'captcha_verified' => $captchaVerified,
        'session_duration' => $sessionDuration,
        'alert_level' => $alertLevel
    ]);
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("❌ GAME-END ERROR | Session: $sessionId | " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
