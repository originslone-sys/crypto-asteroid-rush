<?php
// ============================================
// UNOBIX - Consultar Status de Depósito
// api/deposit-status.php v2.0
// Polling do frontend para verificar pagamento
// Consulta API ZettPay quando status local é pending
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
    $stmt = $pdo->prepare("SELECT id, google_uid FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    // Buscar transação local (somente do próprio usuário)
    $stmt = $pdo->prepare("
        SELECT id, external_id, status, amount_brl, created_at, confirmed_at, expires_at, user_id
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

    // Se ainda está pending, consultar API ZettPay para verificar se já foi pago
    if ($tx['status'] === 'pending') {
        $apiResult = zettpayLookupDeposit($externalId);

        if ($apiResult['success'] && !empty($apiResult['data'])) {
            $apiData = $apiResult['data'];
            // Normalizar: resposta pode vir em $apiData diretamente ou em $apiData['data']
            if (isset($apiData['data']) && is_array($apiData['data'])) {
                $apiData = $apiData['data'];
            }

            $apiStatus = $apiData['status'] ?? '';
            $zettpayId = $apiData['id'] ?? '';

            // Status de sucesso na ZettPay: paid, completed, approved
            if (in_array($apiStatus, ['paid', 'completed', 'approved'])) {
                // Processar confirmação
                confirmDeposit($pdo, $tx, $user, $zettpayId, json_encode($apiData));
                $tx['status'] = 'confirmed';
                $tx['confirmed_at'] = date('Y-m-d H:i:s');
            }
            // Status de falha na ZettPay
            elseif (in_array($apiStatus, ['expired', 'failed', 'cancelled', 'canceled'])) {
                $stmt = $pdo->prepare("
                    UPDATE zettpay_transactions
                    SET status = ?, zettpay_id = ?, error_message = ?
                    WHERE id = ?
                ");
                $stmt->execute([$apiStatus === 'cancelled' || $apiStatus === 'canceled' ? 'expired' : $apiStatus, $zettpayId, "Status ZettPay: {$apiStatus}", $tx['id']]);
                $tx['status'] = $apiStatus;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'external_id' => $tx['external_id'],
        'status' => $tx['status'],
        'amount_brl' => (float)$tx['amount_brl'],
        'created_at' => $tx['created_at'],
        'confirmed_at' => $tx['confirmed_at'] ?? null,
        'expires_at' => $tx['expires_at'],
        'is_confirmed' => $tx['status'] === 'confirmed',
        'is_expired' => $tx['status'] === 'expired',
        'is_failed' => $tx['status'] === 'failed'
    ]);

} catch (Throwable $e) {
    error_log("deposit-status.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao consultar status']);
}

/**
 * Confirma depósito: credita créditos ou saldo, atualiza banco
 */
function confirmDeposit($pdo, $tx, $user, $zettpayId, $rawPayload) {
    $externalId = $tx['external_id'];
    $depositAmount = (float)$tx['amount_brl'];
    $isCreditPurchase = strpos($externalId, 'CRD-') === 0;

    $pdo->beginTransaction();

    try {
        // Lock na transação zettpay
        $stmt = $pdo->prepare("SELECT * FROM zettpay_transactions WHERE id = ? FOR UPDATE");
        $stmt->execute([$tx['id']]);
        $lockedTx = $stmt->fetch();

        // Idempotência
        if ($lockedTx['status'] === 'confirmed') {
            $pdo->rollBack();
            return;
        }

        // Lock no usuário
        $stmt = $pdo->prepare("SELECT id, google_uid, balance_brl FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$tx['user_id']]);
        $lockedUser = $stmt->fetch();

        if (!$lockedUser) {
            $pdo->rollBack();
            secureLog("DEPOSIT_STATUS_CONFIRM_USER_NOT_FOUND | user_id: {$tx['user_id']} | external_id: {$externalId}");
            return;
        }

        if ($isCreditPurchase) {
            // Buscar credit_purchase
            $stmt = $pdo->prepare("SELECT * FROM credit_purchases WHERE external_id = ? LIMIT 1");
            $stmt->execute([$externalId]);
            $purchase = $stmt->fetch();

            if (!$purchase) {
                $pdo->rollBack();
                secureLog("DEPOSIT_STATUS_CREDIT_PURCHASE_NOT_FOUND | external_id: {$externalId}");
                return;
            }

            if ($purchase['status'] !== 'confirmed') {
                $creditsToAdd = (int)$purchase['credits_amount'];

                // Creditar créditos
                $stmt = $pdo->prepare("UPDATE users SET credits = credits + ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$creditsToAdd, $lockedUser['id']]);

                // Marcar compra como confirmada
                $stmt = $pdo->prepare("UPDATE credit_purchases SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
                $stmt->execute([$purchase['id']]);

                secureLog("DEPOSIT_STATUS_CREDIT_CONFIRMED | external_id: {$externalId} | user_id: {$lockedUser['id']} | credits: {$creditsToAdd} | amount: R\${$depositAmount}");
            }
        } else {
            // Depósito normal: creditar saldo BRL
            $stmt = $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?, total_earned_brl = total_earned_brl + ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$depositAmount, $depositAmount, $lockedUser['id']]);

            secureLog("DEPOSIT_STATUS_DEPOSIT_CONFIRMED | external_id: {$externalId} | user_id: {$lockedUser['id']} | amount: R\${$depositAmount}");
        }

        // Atualizar zettpay_transactions
        $stmt = $pdo->prepare("
            UPDATE zettpay_transactions
            SET status = 'confirmed', zettpay_id = ?, webhook_payload = ?, confirmed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$zettpayId, $rawPayload, $tx['id']]);

        // Atualizar transactions (histórico)
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET status = 'completed'
            WHERE google_uid = ? AND type IN ('deposit', 'credit_purchase') AND description LIKE ? AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$lockedUser['google_uid'], '%' . $externalId . '%']);

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        secureLog("DEPOSIT_STATUS_CONFIRM_ERROR | external_id: {$externalId} | " . $e->getMessage());
        throw $e;
    }
}
