<?php
// ============================================
// UNOBIX - Verificação reCAPTCHA v3
// Arquivo: api/verify-captcha.php
// v7.0 - Google reCAPTCHA v3
// ============================================

require_once __DIR__ . "/config.php";

setCORSHeaders();
header('Content-Type: application/json; charset=utf-8');

$input = getRequestInput();

$captchaResponse = $input['captcha_response'] ?? $input['captcha_token'] ?? $input['recaptcha_token'] ?? $input['g-recaptcha-response'] ?? null;
$sessionId = $input['session_id'] ?? $input['sessionId'] ?? null;
$googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;
$action = $input['action_name'] ?? $input['captcha_action'] ?? 'verify';

if (!$captchaResponse) {
    jsonResponse(false, ['error' => 'Token reCAPTCHA é obrigatório'], 400);
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    // Verificar com reCAPTCHA v3
    $captchaResult = verifyCaptcha($captchaResponse, getClientIP(), $action);
    
    $isSuccess = !empty($captchaResult['success']);
    $score = $captchaResult['score'] ?? 0;
    $isSuspicious = !empty($captchaResult['suspicious']);

    // Registrar tentativa no log
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS captcha_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT DEFAULT NULL,
                google_uid VARCHAR(128) DEFAULT NULL,
                wallet_address VARCHAR(64) DEFAULT NULL,
                captcha_type VARCHAR(20) DEFAULT 'recaptcha_v3',
                is_success TINYINT(1) DEFAULT 0,
                score DECIMAL(3,2) DEFAULT NULL,
                response_token VARCHAR(100) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_uid (google_uid),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO captcha_log 
            (session_id, google_uid, captcha_type, is_success, score, response_token, ip_address, user_agent)
            VALUES (?, ?, 'recaptcha_v3', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sessionId,
            $googleUid,
            $isSuccess ? 1 : 0,
            $score,
            substr($captchaResponse, 0, 100),
            getClientIP(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
    } catch (Exception $e) {
        // Log table creation/insert failure is non-critical
        error_log("captcha_log error: " . $e->getMessage());
    }

    if ($isSuccess) {
        // Se temos session_id, marcar a sessão como verificada
        if ($sessionId) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE game_sessions 
                    SET captcha_verified = 1, captcha_verified_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$sessionId]);
            } catch (Exception $e) {}
        }

        $responseData = [
            'verified' => true,
            'score' => $score,
            'message' => 'Verificação concluída'
        ];
        
        if ($isSuspicious) {
            $responseData['warning'] = 'Score baixo detectado';
        }
        
        jsonResponse(true, $responseData);
    } else {
        // Log de falha
        $errorMsg = $captchaResult['message'] ?? 'Falha na verificação';
        secureLog("RECAPTCHA_FAIL | IP: " . getClientIP() . " | Score: {$score} | Error: {$errorMsg}", 'captcha_failures.log');

        jsonResponse(false, [
            'verified' => false,
            'score' => $score,
            'error' => 'Falha na verificação de segurança'
        ], 400);
    }

} catch (Exception $e) {
    error_log("verify-captcha.php error: " . $e->getMessage());
    jsonResponse(false, ['error' => 'Erro no servidor'], 500);
}
