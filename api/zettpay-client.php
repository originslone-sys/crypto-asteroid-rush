<?php
// ============================================
// UNOBIX - ZettPay API Client
// api/zettpay-client.php v1.0
// Gateway PIX para depósitos e saques
// ============================================

require_once __DIR__ . "/config.php";

// ============================================
// AUTENTICAÇÃO - OBTER TOKEN
// ============================================

/**
 * Obtém token de acesso da ZettPay (cache em memória)
 * @return string|null Bearer token
 */
function zettpayGetToken() {
    static $cachedToken = null;
    static $tokenExpiry = 0;

    if ($cachedToken && time() < $tokenExpiry) {
        return $cachedToken;
    }

    $payload = json_encode([
        'client_id' => ZETTPAY_CLIENT_ID,
        'client_secret' => ZETTPAY_CLIENT_SECRET
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true
        ]
    ]);

    $response = @file_get_contents(ZETTPAY_AUTH_URL, false, $context);

    if ($response === false) {
        secureLog("ZETTPAY_AUTH_ERROR | Falha na conexão com API de autenticação");
        return null;
    }

    $data = json_decode($response, true);

    if (empty($data['access_token'])) {
        secureLog("ZETTPAY_AUTH_ERROR | Resposta inválida: " . substr($response, 0, 200));
        return null;
    }

    $cachedToken = $data['access_token'];
    // Expirar 5 minutos antes para segurança
    $tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 300;

    return $cachedToken;
}

// ============================================
// REQUISIÇÃO HTTP GENÉRICA
// ============================================

/**
 * Faz requisição HTTP para a API ZettPay
 * @param string $method GET ou POST
 * @param string $endpoint Ex: /transactions
 * @param array|null $body Body para POST
 * @param string|null $idempotencyKey UUID para idempotência
 * @return array ['success' => bool, 'data' => array, 'http_code' => int, 'error' => string]
 */
function zettpayRequest($method, $endpoint, $body = null, $idempotencyKey = null) {
    $token = zettpayGetToken();
    if (!$token) {
        return ['success' => false, 'data' => null, 'http_code' => 0, 'error' => 'Falha na autenticação ZettPay'];
    }

    $url = ZETTPAY_BASE_URL . $endpoint;

    $headers = "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\nAccept: application/json\r\n";

    if ($idempotencyKey) {
        $headers .= "Idempotency-Key: {$idempotencyKey}\r\n";
    }

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => $headers,
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ];

    if ($body !== null && strtoupper($method) === 'POST') {
        $options['http']['content'] = json_encode($body);
    }

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    // Extrair código HTTP da resposta
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                $httpCode = (int)$matches[1];
            }
        }
    }

    if ($response === false) {
        secureLog("ZETTPAY_REQUEST_ERROR | {$method} {$endpoint} | Conexão falhou");
        return ['success' => false, 'data' => null, 'http_code' => 0, 'error' => 'Falha na conexão com ZettPay'];
    }

    $data = json_decode($response, true);

    $isSuccess = $httpCode >= 200 && $httpCode < 300;

    if (!$isSuccess) {
        // Capturar erro de múltiplos formatos de resposta
        $errorMsg = $data['error']['message']
            ?? $data['message']
            ?? $data['error']
            ?? (is_string($data) ? $data : null)
            ?? 'Erro desconhecido';
        if (is_array($errorMsg)) $errorMsg = json_encode($errorMsg);
        $errorCode = $data['error']['code'] ?? $data['code'] ?? 'UNKNOWN';
        $fullResponse = substr($response, 0, 500);
        secureLog("ZETTPAY_REQUEST_ERROR | {$method} {$endpoint} | HTTP {$httpCode} | {$errorCode}: {$errorMsg} | response: {$fullResponse}");
        return ['success' => false, 'data' => $data, 'http_code' => $httpCode, 'error' => $errorMsg];
    }

    return ['success' => true, 'data' => $data, 'http_code' => $httpCode, 'error' => null];
}

// ============================================
// DEPÓSITO (CASH-IN)
// ============================================

/**
 * Cria cobrança PIX para depósito
 * @param float $amount Valor em BRL (2 casas)
 * @param string $externalId ID externo único
 * @param string $description Descrição
 * @param array $payer Dados do pagador [name, email, document]
 * @param array $metadata Metadados extras
 * @return array Resposta da API
 */
function zettpayCreateDeposit($amount, $externalId, $description, $payer = [], $additionalFields = []) {
    $body = [
        'amount' => round($amount, 2),
        'description' => $description,
        'external_id' => $externalId,
        'payer_name' => $payer['name'] ?? 'Cliente UNOBIX',
        'payer_email' => $payer['email'] ?? 'cliente@unobix.com',
        'payer_document' => $payer['document'] ?? '00000000000'
    ];

    if (!empty($additionalFields)) {
        $body['additional_fields'] = $additionalFields;
    }

    $idempotencyKey = $externalId; // Usar external_id como chave de idempotência

    $result = zettpayRequest('POST', '/transactions', $body, $idempotencyKey);

    secureLog("ZETTPAY_DEPOSIT_CREATE | external_id: {$externalId} | amount: R\${$amount} | success: " . ($result['success'] ? 'true' : 'false'));

    return $result;
}

/**
 * Consulta status de depósito por external_id
 * @param string $externalId
 * @return array
 */
function zettpayLookupDeposit($externalId) {
    return zettpayRequest('GET', '/transactions/lookup?external_id=' . urlencode($externalId));
}

// ============================================
// SAQUE (CASH-OUT)
// ============================================

/**
 * Cria saque PIX (cash-out)
 * @param float $amount Valor em BRL (2 casas)
 * @param string $pixKey Chave PIX do destinatário
 * @param string $pixKeyType Tipo da chave (cpf, cnpj, email, phone, evp)
 * @param string $externalId ID externo único
 * @param array $metadata Metadados extras
 * @return array Resposta da API
 */
function zettpayCreateCashout($amount, $pixKey, $pixKeyType, $externalId, $metadata = []) {
    $formattedAmount = number_format(round($amount, 2), 2, '.', '');

    $body = [
        'amount' => $formattedAmount,
        'pix_key' => $pixKey,
        'pix_key_type' => $pixKeyType,
        'external_id' => $externalId
    ];

    if (!empty($metadata)) {
        $body['metadata'] = $metadata;
    }

    $idempotencyKey = $externalId;

    secureLog("ZETTPAY_CASHOUT_REQUEST | external_id: {$externalId} | amount: {$formattedAmount} | key_type: {$pixKeyType} | pix_key: " . substr($pixKey, 0, 6) . "*** | body: " . json_encode($body));

    // Endpoint correto: /pix/cashouts (consistente com lookup em /pix/cashouts/lookup)
    $result = zettpayRequest('POST', '/pix/cashouts', $body, $idempotencyKey);

    secureLog("ZETTPAY_CASHOUT_CREATE | external_id: {$externalId} | amount: R\${$amount} | key_type: {$pixKeyType} | success: " . ($result['success'] ? 'true' : 'false') . " | response: " . json_encode($result['data'] ?? null));

    return $result;
}

/**
 * Consulta status de saque por external_id
 * @param string $externalId
 * @return array
 */
function zettpayLookupCashout($externalId) {
    return zettpayRequest('GET', '/pix/cashouts/lookup?external_id=' . urlencode($externalId));
}

// ============================================
// WEBHOOK - VALIDAÇÃO DE ASSINATURA
// ============================================

/**
 * Valida assinatura HMAC SHA256 do webhook ZettPay
 * @param string $rawBody Corpo bruto da requisição
 * @param string $signature Header X-Signature
 * @param string $timestamp Header X-Timestamp
 * @return bool
 */
function zettpayVerifyWebhookSignature($rawBody, $signature, $timestamp) {
    if (empty($signature) || empty($timestamp) || empty(ZETTPAY_WEBHOOK_SECRET)) {
        return false;
    }

    $expectedSignature = hash_hmac('sha256', $rawBody . $timestamp, ZETTPAY_WEBHOOK_SECRET);

    return hash_equals($expectedSignature, $signature);
}

// ============================================
// TABELA - AUTO-CRIAÇÃO
// ============================================

/**
 * Cria tabela zettpay_transactions se não existir
 * @param PDO $pdo
 */
function ensureZettpayTable($pdo) {
    static $checked = false;
    if ($checked) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS zettpay_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                external_id VARCHAR(100) NOT NULL UNIQUE,
                zettpay_id VARCHAR(100) DEFAULT NULL,
                type ENUM('deposit', 'cashout') NOT NULL,
                amount_brl DECIMAL(15,2) NOT NULL,
                fee_brl DECIMAL(15,2) DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                pix_key VARCHAR(255) DEFAULT NULL,
                pix_key_type VARCHAR(20) DEFAULT NULL,
                qr_code TEXT DEFAULT NULL,
                pix_copy_paste TEXT DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                webhook_payload JSON DEFAULT NULL,
                withdrawal_id INT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_type_status (type, status),
                INDEX idx_withdrawal_id (withdrawal_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Garantir que tabela transactions aceite os tipos necessários (converter ENUM para VARCHAR se necessário)
        try {
            $colInfo = $pdo->query("SHOW COLUMNS FROM transactions WHERE Field = 'type'")->fetch();
            if ($colInfo && stripos($colInfo['Type'], 'enum') !== false) {
                $pdo->exec("ALTER TABLE transactions MODIFY COLUMN type VARCHAR(30) NOT NULL");
            }
        } catch (Exception $e) {
            // tabela transactions pode não existir ainda
        }

        // Adicionar colunas na tabela withdrawals para integração ZettPay
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'zettpay_external_id'")->fetch();
            if (!$cols) {
                $pdo->exec("ALTER TABLE withdrawals ADD COLUMN zettpay_external_id VARCHAR(100) DEFAULT NULL");
                $pdo->exec("ALTER TABLE withdrawals ADD COLUMN zettpay_status VARCHAR(30) DEFAULT NULL");
                $pdo->exec("ALTER TABLE withdrawals ADD INDEX idx_zettpay_ext_id (zettpay_external_id)");
            }
        } catch (Exception $e) {
            error_log("ZettPay: Erro ao alterar tabela withdrawals: " . $e->getMessage());
        }

        $checked = true;
    } catch (Exception $e) {
        error_log("ZettPay: Erro ao criar tabela: " . $e->getMessage());
    }
}

/**
 * Gera external_id para depósito
 * @param int $userId
 * @return string
 */
function zettpayDepositExternalId($userId) {
    return 'DEP-' . $userId . '-' . time() . '-' . bin2hex(random_bytes(4));
}

/**
 * Gera external_id para saque
 * @param int $withdrawalId
 * @return string
 */
function zettpayWithdrawExternalId($withdrawalId) {
    return 'WDR-' . $withdrawalId . '-' . time() . '-' . bin2hex(random_bytes(4));
}
