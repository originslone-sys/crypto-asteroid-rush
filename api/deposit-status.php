<?php
// ============================================
// UNOBIX - Consultar Status de Depósito
// api/deposit-status.php v1.0
// Polling do frontend para verificar pagamento
// ============================================

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/zettpay-client.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? '');
$externalId = trim($input['external_id'] ?? '');

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

if (empty($externalId)) {
    echo json_encode(['success' => false, 'error' => 'external_id é obrigatório']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    ensureZettpayTable($pdo);

    // Buscar usuário
    $stmt = $pdo->prepare("SELECT id FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    // Buscar transação (somente do próprio usuário)
    $stmt = $pdo->prepare("
        SELECT external_id, status, amount_brl, created_at, confirmed_at, expires_at
        FROM zettpay_transactions
        WHERE external_id = ? AND user_id = ? AND type = 'deposit'
        LIMIT 1
    ");
    $stmt->execute([$externalId, $user['id']]);
    $tx = $stmt->fetch();

    if (!$tx) {
        echo json_encode(['success' => false, 'error' => 'Depósito não encontrado']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'external_id' => $tx['external_id'],
        'status' => $tx['status'],
        'amount_brl' => (float)$tx['amount_brl'],
        'created_at' => $tx['created_at'],
        'confirmed_at' => $tx['confirmed_at'],
        'expires_at' => $tx['expires_at'],
        'is_confirmed' => $tx['status'] === 'confirmed',
        'is_expired' => $tx['status'] === 'expired',
        'is_failed' => $tx['status'] === 'failed'
    ]);

} catch (Throwable $e) {
    error_log("deposit-status.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao consultar status']);
}
