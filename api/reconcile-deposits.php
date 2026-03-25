<?php
// ============================================
// UNOBIX - Reconciliação de Depósitos Pendentes
// api/reconcile-deposits.php
// Verifica depósitos pendentes na API ZettPay
// e confirma os que já foram pagos.
//
// Seguro para produção:
// - Idempotente (pode rodar várias vezes sem duplicar)
// - Usa FOR UPDATE para evitar race conditions
// - Reutiliza lógica existente do deposit-status.php
// - Só processa depósitos entre 5min e 48h
// ============================================

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/zettpay-client.php";

// Pode ser chamado via admin (POST), cron HTTP (GET com token) ou CLI
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    // Proteger endpoint: aceitar apenas com token válido ou via POST do admin
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    $isAdminPost = $_SERVER['REQUEST_METHOD'] === 'POST';

    if (!$isAdminPost && $token !== RECONCILE_CRON_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");
    ensureZettpayTable($pdo);
} catch (Exception $e) {
    $msg = "RECONCILE_DB_ERROR | " . $e->getMessage();
    secureLog($msg);
    if ($isCli) { echo $msg . "\n"; exit(1); }
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// Buscar depósitos pendentes entre 5 minutos e 48 horas
$stmt = $pdo->prepare("
    SELECT id, external_id, user_id, amount_brl, created_at
    FROM zettpay_transactions
    WHERE type = 'deposit'
      AND status = 'pending'
      AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
      AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ORDER BY created_at ASC
    LIMIT 50
");
$stmt->execute();
$pendingDeposits = $stmt->fetchAll();

$results = [
    'total_checked' => count($pendingDeposits),
    'confirmed' => 0,
    'expired' => 0,
    'still_pending' => 0,
    'errors' => 0,
    'details' => []
];

if ($isCli) {
    echo "Reconciliação iniciada | " . count($pendingDeposits) . " depósito(s) pendente(s)\n";
}

foreach ($pendingDeposits as $tx) {
    $externalId = $tx['external_id'];

    try {
        // Consultar status na ZettPay
        $apiResult = zettpayLookupDeposit($externalId);

        if (!$apiResult['success'] || empty($apiResult['data'])) {
            $results['still_pending']++;
            $results['details'][] = ['external_id' => $externalId, 'result' => 'api_error'];
            secureLog("RECONCILE_API_FAIL | external_id: {$externalId} | error: " . ($apiResult['error'] ?? 'sem dados'));
            continue;
        }

        $apiData = $apiResult['data'];
        if (isset($apiData['data']) && is_array($apiData['data'])) {
            $apiData = $apiData['data'];
        }

        $apiStatus = strtolower($apiData['status'] ?? '');
        $zettpayId = $apiData['id'] ?? '';

        if (in_array($apiStatus, ['paid', 'completed', 'approved'])) {
            // Pago! Confirmar depósito
            $confirmed = confirmPendingDeposit($pdo, $tx, $zettpayId, json_encode($apiData));
            if ($confirmed) {
                $results['confirmed']++;
                $results['details'][] = ['external_id' => $externalId, 'result' => 'confirmed', 'amount' => $tx['amount_brl']];
                secureLog("RECONCILE_CONFIRMED | external_id: {$externalId} | user_id: {$tx['user_id']} | amount: R\${$tx['amount_brl']}");
            } else {
                $results['still_pending']++;
                $results['details'][] = ['external_id' => $externalId, 'result' => 'already_confirmed'];
            }

        } elseif (in_array($apiStatus, ['expired', 'failed', 'cancelled', 'canceled'])) {
            // Expirado/falhou — marcar no banco
            $finalStatus = in_array($apiStatus, ['cancelled', 'canceled']) ? 'expired' : $apiStatus;
            $stmt2 = $pdo->prepare("UPDATE zettpay_transactions SET status = ?, error_message = ? WHERE id = ? AND status = 'pending'");
            $stmt2->execute([$finalStatus, "Reconciliação: {$apiStatus}", $tx['id']]);

            // Atualizar histórico
            $userStmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
            $userStmt->execute([$tx['user_id']]);
            $user = $userStmt->fetch();
            if ($user) {
                $pdo->prepare("UPDATE transactions SET status = 'failed' WHERE google_uid = ? AND description LIKE ? AND status = 'pending' LIMIT 1")
                    ->execute([$user['google_uid'], '%' . $externalId . '%']);
            }

            $results['expired']++;
            $results['details'][] = ['external_id' => $externalId, 'result' => $finalStatus];
            secureLog("RECONCILE_{$finalStatus} | external_id: {$externalId} | user_id: {$tx['user_id']}");

        } else {
            // Ainda pendente na ZettPay
            $results['still_pending']++;
            $results['details'][] = ['external_id' => $externalId, 'result' => 'pending', 'api_status' => $apiStatus];
        }

    } catch (Exception $e) {
        $results['errors']++;
        $results['details'][] = ['external_id' => $externalId, 'result' => 'error', 'message' => $e->getMessage()];
        secureLog("RECONCILE_ERROR | external_id: {$externalId} | " . $e->getMessage());
    }

    // Pequena pausa entre chamadas para não sobrecarregar a API
    usleep(300000); // 300ms
}

$results['success'] = true;

secureLog("RECONCILE_DONE | checked: {$results['total_checked']} | confirmed: {$results['confirmed']} | expired: {$results['expired']} | pending: {$results['still_pending']} | errors: {$results['errors']}");

if ($isCli) {
    echo "Concluído | Confirmados: {$results['confirmed']} | Expirados: {$results['expired']} | Pendentes: {$results['still_pending']} | Erros: {$results['errors']}\n";
} else {
    echo json_encode($results);
}

// ============================================
// CONFIRMAR DEPÓSITO (reutiliza lógica existente)
// ============================================
function confirmPendingDeposit($pdo, $tx, $zettpayId, $rawPayload) {
    $externalId = $tx['external_id'];
    $depositAmount = (float)$tx['amount_brl'];
    $isCreditPurchase = strpos($externalId, 'CRD-') === 0;

    $pdo->beginTransaction();

    try {
        // Lock na transação
        $stmt = $pdo->prepare("SELECT * FROM zettpay_transactions WHERE id = ? FOR UPDATE");
        $stmt->execute([$tx['id']]);
        $lockedTx = $stmt->fetch();

        // Idempotência
        if ($lockedTx['status'] === 'confirmed') {
            $pdo->rollBack();
            return false;
        }

        // Lock no usuário
        $stmt = $pdo->prepare("SELECT id, google_uid, balance_brl FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$tx['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            $pdo->rollBack();
            secureLog("RECONCILE_USER_NOT_FOUND | user_id: {$tx['user_id']} | external_id: {$externalId}");
            return false;
        }

        if ($isCreditPurchase) {
            $stmt = $pdo->prepare("SELECT * FROM credit_purchases WHERE external_id = ? LIMIT 1");
            $stmt->execute([$externalId]);
            $purchase = $stmt->fetch();

            if (!$purchase) {
                $pdo->rollBack();
                secureLog("RECONCILE_CREDIT_PURCHASE_NOT_FOUND | external_id: {$externalId}");
                return false;
            }

            if ($purchase['status'] !== 'confirmed') {
                $creditsToAdd = (int)$purchase['credits_amount'];
                $pdo->prepare("UPDATE users SET credits = credits + ?, updated_at = NOW() WHERE id = ?")->execute([$creditsToAdd, $user['id']]);
                $pdo->prepare("UPDATE credit_purchases SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?")->execute([$purchase['id']]);
                secureLog("RECONCILE_CREDIT_CONFIRMED | external_id: {$externalId} | user_id: {$user['id']} | credits: {$creditsToAdd}");
            }
        } else {
            // Depósito normal: creditar saldo
            $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?, total_earned_brl = total_earned_brl + ?, updated_at = NOW() WHERE id = ?")->execute([$depositAmount, $depositAmount, $user['id']]);
        }

        // Atualizar zettpay_transactions
        $pdo->prepare("UPDATE zettpay_transactions SET status = 'confirmed', zettpay_id = ?, webhook_payload = ?, confirmed_at = NOW() WHERE id = ?")->execute([$zettpayId, $rawPayload, $tx['id']]);

        // Atualizar histórico
        $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE google_uid = ? AND type IN ('deposit', 'credit_purchase') AND description LIKE ? AND status = 'pending' LIMIT 1")->execute([$user['google_uid'], '%' . $externalId . '%']);

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        secureLog("RECONCILE_CONFIRM_ERROR | external_id: {$externalId} | " . $e->getMessage());
        throw $e;
    }
}
