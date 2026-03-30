<?php
// ============================================
// UNOBIX - Verificação de CAPTCHA
// Arquivo: api/verify-captcha.php
// v5.0 - Google reCAPTCHA v2
// ============================================

require_once __DIR__ . "/config.php";

setCORSHeaders();
header('Content-Type: application/json; charset=utf-8');

$input = getRequestInput();

$captchaResponse = $input['captcha_response'] ?? $input['captcha_token'] ?? $input['g-recaptcha-response'] ?? null;
$sessionId = $input['session_id'] ?? $input['sessionId'] ?? null;
$googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

if (!$captchaResponse) {
    jsonResponse(false, ['error' => 'Token reCAPTCHA é obrigatório'], 400);
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    // Verificar com reCAPTCHA v2
    $captchaResult = verifyCaptcha($captchaResponse, getClientIP());

    $isSuccess = !empty($captchaResult['success']);

    // Registrar tentativa no log (tabela criada via migrate.php)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO captcha_log
            (session_id, google_uid, captcha_type, is_success, response_token, ip_address, user_agent)
            VALUES (?, ?, 'recaptcha_v2', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $sessionId,
            $googleUid,
            $isSuccess ? 1 : 0,
            substr($captchaResponse, 0, 100),
            getClientIP(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
    } catch (Exception $e) {
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

        jsonResponse(true, [
            'verified' => true,
            'message' => 'Verificação reCAPTCHA concluída'
        ]);
    } else {
        $errorMsg = $captchaResult['message'] ?? 'Falha na verificação';
        secureLog("RECAPTCHA_FAIL | IP: " . getClientIP() . " | Error: {$errorMsg}");

        jsonResponse(false, [
            'verified' => false,
            'error' => 'Falha na verificação de segurança'
        ], 400);
    }

} catch (Exception $e) {
    error_log("verify-captcha.php error: " . $e->getMessage());
    jsonResponse(false, ['error' => 'Erro no servidor'], 500);
}
