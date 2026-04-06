<?php
// ============================================
// UNOBIX - Admin Deposit Status Check
// api/deposit-status-admin.php
// Verifica status de depósitos do caixa admin
// ============================================

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/zettpay-client.php";

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
$externalId = trim($input['external_id'] ?? '');

if (empty($externalId) || strpos($externalId, 'CAIXA-') !== 0) {
    echo json_encode(['success' => false, 'error' => 'external_id inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro de conexão");

    // Buscar transação local
    $stmt = $pdo->prepare("
        SELECT id, status, amount_brl FROM zettpay_transactions
        WHERE external_id = ? AND type = 'deposit' LIMIT 1
    ");
    $stmt->execute([$externalId]);
    $tx = $stmt->fetch();

    if (!$tx) {
        echo json_encode(['success' => false, 'error' => 'Não encontrado']);
        exit;
    }

    if ($tx['status'] === 'confirmed') {
        echo json_encode(['success' => true, 'is_confirmed' => true, 'is_expired' => false]);
        exit;
    }

    if (in_array($tx['status'], ['expired', 'failed'])) {
        echo json_encode(['success' => true, 'is_confirmed' => false, 'is_expired' => true]);
        exit;
    }

    // Consultar ZettPay
    $apiResult = zettpayLookupDeposit($externalId);

    if (!$apiResult['success'] || empty($apiResult['data'])) {
        echo json_encode(['success' => true, 'is_confirmed' => false, 'is_expired' => false]);
        exit;
    }

    $apiData = $apiResult['data'];
    if (isset($apiData['data']) && is_array($apiData['data'])) {
        $apiData = $apiData['data'];
    }

    $apiStatus = strtoupper($apiData['status'] ?? '');

    if (in_array($apiStatus, ['PAID', 'COMPLETED', 'APPROVED'])) {
        $zettpayId = $apiData['provider_transaction_id'] ?? $apiData['id'] ?? '';
        $pdo->prepare("UPDATE zettpay_transactions SET status = 'confirmed', zettpay_id = ?, confirmed_at = NOW() WHERE id = ? AND status = 'pending'")
            ->execute([$zettpayId, $tx['id']]);

        secureLog("ADMIN_CASHIER_CONFIRMED | external_id: {$externalId} | amount: R\${$tx['amount_brl']}");
        echo json_encode(['success' => true, 'is_confirmed' => true, 'is_expired' => false]);
        exit;
    }

    if (in_array($apiStatus, ['EXPIRED', 'FAILED', 'CANCELLED', 'CANCELED'])) {
        $pdo->prepare("UPDATE zettpay_transactions SET status = 'expired' WHERE id = ? AND status = 'pending'")
            ->execute([$tx['id']]);
        echo json_encode(['success' => true, 'is_confirmed' => false, 'is_expired' => true]);
        exit;
    }

    echo json_encode(['success' => true, 'is_confirmed' => false, 'is_expired' => false]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
