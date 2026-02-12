<?php
// ============================================
// UNOBIX - Verificação de CAPTCHA
// Arquivo: api/verify-captcha.php
// v4.0 - Suporte a hCaptcha
// ============================================

require_once __DIR__ . "/config.php";

setCORSHeaders();
header('Content-Type: application/json; charset=utf-8');

$input = getRequestInput();

$captchaResponse = $input['captcha_response'] ?? $input['h-captcha-response'] ?? $input['captchaResponse'] ?? null;
$sessionId = $input['session_id'] ?? $input['sessionId'] ?? null;
$googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;
$wallet = $input['wallet'] ?? $input['wallet_address'] ?? null;

if (!$captchaResponse) {
    jsonResponse(false, ['error' => 'Resposta do CAPTCHA é obrigatória'], 400);
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    // Verificar com hCaptcha
    $verifyUrl = 'https://hcaptcha.com/siteverify';
    $data = [
        'secret' => HCAPTCHA_SECRET_KEY,
        'response' => $captchaResponse,
        'remoteip' => getClientIP()
    ];

    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($verifyUrl, false, $context);

    $isSuccess = false;
    $verifyResult = null;

    if ($result !== false) {
        $verifyResult = json_decode($result, true);
        $isSuccess = isset($verifyResult['success']) && $verifyResult['success'] === true;
    }

    // Registrar tentativa no log
    $stmt = $pdo->prepare("
        INSERT INTO captcha_log 
        (session_id, google_uid, wallet_address, captcha_type, is_success, response_token, ip_address, user_agent)
        VALUES (?, ?, ?, 'hcaptcha', ?, ?, ?, ?)
    ");
    $stmt->execute([
        $sessionId,
        $googleUid,
        $wallet ? strtolower($wallet) : null,
        $isSuccess ? 1 : 0,
        substr($captchaResponse, 0, 100), // Guardar apenas parte do token
        getClientIP(),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);

    if ($isSuccess) {
        // Se temos session_id, marcar a sessão como verificada
        if ($sessionId) {
            $stmt = $pdo->prepare("
                UPDATE game_sessions 
                SET captcha_verified = 1, captcha_verified_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$sessionId]);
        }

        jsonResponse(true, [
            'verified' => true,
            'message' => 'CAPTCHA verificado com sucesso'
        ]);
    } else {
        // Log de falha
        $errorCodes = $verifyResult['error-codes'] ?? [];
        secureLog("CAPTCHA falhou | IP: " . getClientIP() . " | Errors: " . implode(', ', $errorCodes), 'captcha_failures.log');

        jsonResponse(false, [
            'verified' => false,
            'error' => 'Falha na verificação do CAPTCHA',
            'details' => $errorCodes
        ], 400);
    }

} catch (Exception $e) {
    error_log("verify-captcha.php error: " . $e->getMessage());
    jsonResponse(false, ['error' => 'Erro no servidor'], 500);
}
?>
