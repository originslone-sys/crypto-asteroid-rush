<?php
// ============================================
// UNOBIX - Criar Depósito PIX
// api/deposit.php v1.0
// Gera cobrança PIX via ZettPay
// IMPORTANTE: Saldo só é creditado via webhook
// ============================================

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/zettpay-client.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? '');
$amount = (float)($input['amount'] ?? $input['amount_brl'] ?? 0);

// Validar google_uid
if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido. Faça login novamente.']);
    exit;
}

// Validar valor
if ($amount < MIN_DEPOSIT_BRL) {
    echo json_encode(['success' => false, 'error' => 'Valor mínimo: R$ ' . number_format(MIN_DEPOSIT_BRL, 2, ',', '.')]);
    exit;
}

if ($amount > MAX_DEPOSIT_BRL) {
    echo json_encode(['success' => false, 'error' => 'Valor máximo: R$ ' . number_format(MAX_DEPOSIT_BRL, 2, ',', '.')]);
    exit;
}

// Arredondar para 2 casas (padrão ZettPay)
$amount = round($amount, 2);

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    ensureZettpayTable($pdo);

    // Buscar usuário
    $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name, is_banned FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    if ($user['is_banned']) {
        echo json_encode(['success' => false, 'error' => 'Conta suspensa. Entre em contato com o suporte.']);
        exit;
    }

    // Verificar se já tem depósito pendente
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM zettpay_transactions
        WHERE user_id = ? AND type = 'deposit' AND status = 'pending'
        AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stmt->execute([$user['id']]);
    $pendingCount = (int)$stmt->fetchColumn();

    if ($pendingCount >= 3) {
        echo json_encode(['success' => false, 'error' => 'Você já tem depósitos pendentes. Aguarde a expiração ou pague os existentes.']);
        exit;
    }

    // Gerar external_id
    $externalId = zettpayDepositExternalId($user['id']);

    // Chamar API ZettPay
    $result = zettpayCreateDeposit(
        $amount,
        $externalId,
        "Depósito UNOBIX - Usuário #{$user['id']}",
        [
            'name' => $user['display_name'] ?? '',
            'email' => $user['email'] ?? ''
        ],
        ['user_id' => (string)$user['id']]
    );

    if (!$result['success']) {
        secureLog("DEPOSIT_CREATE_FAILED | uid: {$googleUid} | amount: R\${$amount} | error: {$result['error']}");
        echo json_encode(['success' => false, 'error' => 'Erro ao gerar PIX: ' . $result['error']]);
        exit;
    }

    $apiData = $result['data'];

    // ZettPay retorna qr_code como o código PIX copia-e-cola (EMV)
    $pixCode = $apiData['qr_code'] ?? null;

    // Salvar no banco
    $stmt = $pdo->prepare("
        INSERT INTO zettpay_transactions (
            user_id, external_id, zettpay_id, type, amount_brl, fee_brl,
            status, qr_code, pix_copy_paste, expires_at, created_at
        ) VALUES (?, ?, ?, 'deposit', ?, ?, 'pending', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $user['id'],
        $externalId,
        $apiData['id'] ?? null,
        $amount,
        (float)($apiData['fee_amount'] ?? 0),
        $pixCode,
        $pixCode,
        $apiData['expires_at'] ?? null
    ]);

    // Registrar transação pendente no histórico
    $stmt = $pdo->prepare("
        INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
        VALUES (?, 'deposit', ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $googleUid,
        $amount,
        "Depósito PIX R$ " . number_format($amount, 2, ',', '.') . " [{$externalId}]"
    ]);

    secureLog("DEPOSIT_CREATED | uid: {$googleUid} | user_id: {$user['id']} | amount: R\${$amount} | external_id: {$externalId}");

    // Retornar dados do QR Code
    echo json_encode([
        'success' => true,
        'external_id' => $externalId,
        'amount_brl' => $amount,
        'qr_code' => $pixCode,
        'pix_copy_paste' => $pixCode,
        'expires_at' => $apiData['expires_at'] ?? null,
        'message' => 'PIX gerado com sucesso! Escaneie o QR Code ou copie o código para pagar.'
    ]);

} catch (Throwable $e) {
    secureLog("DEPOSIT_ERROR | uid: {$googleUid} | " . $e->getMessage());
    error_log("deposit.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
