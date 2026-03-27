<?php
// ============================================
// UNOBIX - ZettPay Webhook Receiver
// api/zettpay-webhook.php v1.0
// Recebe notificações de depósitos e saques
// CRÍTICO: Nunca credita sem validação de assinatura
// ============================================

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/zettpay-client.php";

// Sem CORS - endpoint server-to-server
header('Content-Type: application/json; charset=utf-8');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ============================================
// 1. LER BODY
// ============================================
$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

// ============================================
// 3. PARSEAR PAYLOAD
// ============================================
$payload = json_decode($rawBody, true);

if (!$payload || empty($payload['event']) || empty($payload['data'])) {
    secureLog("ZETTPAY_WEBHOOK_INVALID_PAYLOAD | body: " . substr($rawBody, 0, 200));
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$event = $payload['event'];
$type = $payload['type'] ?? '';
$data = $payload['data'];
$externalId = $data['external_id'] ?? '';
$status = strtoupper($data['status'] ?? '');
$zettpayId = $data['provider_transaction_id'] ?? $data['id'] ?? '';
$amount = (float)($data['amount'] ?? 0);

secureLog("ZETTPAY_WEBHOOK_RECEIVED | event: {$event} | type: {$type} | external_id: {$externalId} | status: {$status} | amount: {$amount}");

if (empty($externalId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing external_id']);
    exit;
}

// ============================================
// 4. CONECTAR AO BANCO
// ============================================
try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");
    ensureZettpayTable($pdo);
} catch (Exception $e) {
    secureLog("ZETTPAY_WEBHOOK_DB_ERROR | " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

// ============================================
// 5. PROCESSAR POR TIPO DE EVENTO
// ============================================
try {
    if ($event === 'transaction.updated' && $type === 'cashin') {
        processDeposit($pdo, $data, $rawBody);
    } elseif ($event === 'cashout.updated' && $type === 'cashout') {
        processCashout($pdo, $data, $rawBody);
    } else {
        secureLog("ZETTPAY_WEBHOOK_UNKNOWN_EVENT | event: {$event} | type: {$type}");
    }

    // Sempre retornar 200 para evitar retries desnecessários
    echo json_encode(['received' => true]);

} catch (Exception $e) {
    secureLog("ZETTPAY_WEBHOOK_PROCESS_ERROR | external_id: {$externalId} | " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Processing error']);
}

// ============================================
// PROCESSAR DEPÓSITO (CASH-IN)
// ============================================
function processDeposit($pdo, $data, $rawBody) {
    $externalId = $data['external_id'];
    $status = strtoupper($data['status'] ?? '');
    $amount = (float)($data['amount'] ?? 0);
    $zettpayId = $data['provider_transaction_id'] ?? $data['id'] ?? '';

    // Buscar transação no banco
    $stmt = $pdo->prepare("SELECT * FROM zettpay_transactions WHERE external_id = ? AND type = 'deposit' LIMIT 1");
    $stmt->execute([$externalId]);
    $tx = $stmt->fetch();

    if (!$tx) {
        secureLog("ZETTPAY_WEBHOOK_DEPOSIT_NOT_FOUND | external_id: {$externalId}");
        return;
    }

    // Idempotência: se já confirmado, ignorar
    if ($tx['status'] === 'confirmed') {
        secureLog("ZETTPAY_WEBHOOK_DEPOSIT_ALREADY_CONFIRMED | external_id: {$externalId}");
        return;
    }

    // Status de sucesso: PAID, APPROVED ou COMPLETED
    if ($status === 'PAID' || $status === 'COMPLETED' || $status === 'APPROVED') {
        $pdo->beginTransaction();

        try {
            // Bloquear linha da transação
            $stmt = $pdo->prepare("SELECT * FROM zettpay_transactions WHERE id = ? FOR UPDATE");
            $stmt->execute([$tx['id']]);
            $tx = $stmt->fetch();

            // Verificar novamente após lock (race condition)
            if ($tx['status'] === 'confirmed') {
                $pdo->rollBack();
                return;
            }

            // Bloquear e atualizar saldo do usuário
            $stmt = $pdo->prepare("SELECT id, google_uid, balance_brl FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$tx['user_id']]);
            $user = $stmt->fetch();

            if (!$user) {
                $pdo->rollBack();
                secureLog("ZETTPAY_WEBHOOK_DEPOSIT_USER_NOT_FOUND | user_id: {$tx['user_id']} | external_id: {$externalId}");
                return;
            }

            $depositAmount = (float)$tx['amount_brl'];

            // Verificar tipo de depósito pelo prefixo do external_id
            $isCreditPurchase = strpos($externalId, 'CRD-') === 0;
            $isPremiumPurchase = strpos($externalId, 'PRM-') === 0;

            if ($isPremiumPurchase) {
                // COMPRA DE PREMIUM: ativar assinatura
                $premiumStmt = $pdo->prepare("SELECT * FROM premium_subscriptions WHERE external_id = ? LIMIT 1");
                $premiumStmt->execute([$externalId]);
                $premiumSub = $premiumStmt->fetch();

                if (!$premiumSub) {
                    $pdo->rollBack();
                    secureLog("PREMIUM_PURCHASE_NOT_FOUND | external_id: {$externalId} | user_id: {$user['id']} | PAGAMENTO RECEBIDO MAS SEM REGISTRO");
                    return;
                }

                if ($premiumSub['status'] === 'active') {
                    secureLog("PREMIUM_PURCHASE_ALREADY_ACTIVE | external_id: {$externalId}");
                } else {
                    $durationDays = (int)($premiumSub['duration_days'] ?: 30);
                    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));

                    // Activate premium on user
                    $pdo->prepare("UPDATE users SET is_premium = 1, premium_expires_at = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$expiresAt, $user['id']]);

                    // Update subscription record
                    $pdo->prepare("UPDATE premium_subscriptions SET status = 'active', activated_at = NOW(), expires_at = ? WHERE id = ?")
                        ->execute([$expiresAt, $premiumSub['id']]);

                    secureLog("PREMIUM_ACTIVATED | external_id: {$externalId} | user_id: {$user['id']} | duration: {$durationDays}d | expires: {$expiresAt}");
                }
            } elseif ($isCreditPurchase) {
                // COMPRA DE CRÉDITOS: creditar créditos, não saldo
                $purchaseStmt = $pdo->prepare("SELECT * FROM credit_purchases WHERE external_id = ? LIMIT 1");
                $purchaseStmt->execute([$externalId]);
                $purchase = $purchaseStmt->fetch();

                if (!$purchase) {
                    $pdo->rollBack();
                    secureLog("CREDIT_PURCHASE_NOT_FOUND | external_id: {$externalId} | user_id: {$user['id']} | PAGAMENTO RECEBIDO MAS SEM REGISTRO DE COMPRA");
                    return;
                }

                // Idempotência: se já confirmado, não duplicar créditos
                if ($purchase['status'] === 'confirmed') {
                    secureLog("CREDIT_PURCHASE_ALREADY_CONFIRMED | external_id: {$externalId}");
                    // Ainda marca zettpay_transactions como confirmed abaixo
                } else {
                    $creditsToAdd = (int)$purchase['credits_amount'];

                    // Creditar créditos ao usuário
                    $stmt = $pdo->prepare("UPDATE users SET credits = credits + ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$creditsToAdd, $user['id']]);

                    // Marcar compra como confirmada
                    $stmt = $pdo->prepare("UPDATE credit_purchases SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
                    $stmt->execute([$purchase['id']]);

                    secureLog("CREDIT_PURCHASE_CONFIRMED | external_id: {$externalId} | user_id: {$user['id']} | credits: {$creditsToAdd} | amount: R\${$depositAmount}");
                }
            } else {
                // DEPÓSITO NORMAL: creditar saldo BRL
                $stmt = $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?, total_earned_brl = total_earned_brl + ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$depositAmount, $depositAmount, $user['id']]);
            }

            // Atualizar transação ZettPay
            $stmt = $pdo->prepare("
                UPDATE zettpay_transactions
                SET status = 'confirmed',
                    zettpay_id = ?,
                    webhook_payload = ?,
                    confirmed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$zettpayId, $rawBody, $tx['id']]);

            // Atualizar transação no histórico
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'completed'
                WHERE google_uid = ? AND type IN ('deposit', 'credit_purchase', 'premium_purchase') AND description LIKE ? AND status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$user['google_uid'], '%' . $externalId . '%']);

            $pdo->commit();

            if ($isCreditPurchase) {
                secureLog("ZETTPAY_CREDIT_CONFIRMED | external_id: {$externalId} | user_id: {$user['id']} | amount: R\${$depositAmount}");
            } else {
                secureLog("ZETTPAY_DEPOSIT_CONFIRMED | external_id: {$externalId} | user_id: {$user['id']} | amount: R\${$depositAmount} | new_balance: R\$" . ($user['balance_brl'] + $depositAmount));
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    // Status de falha/expiração
    elseif ($status === 'EXPIRED' || $status === 'FAILED') {
        $stmt = $pdo->prepare("
            UPDATE zettpay_transactions
            SET status = ?,
                zettpay_id = ?,
                webhook_payload = ?,
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $zettpayId, $rawBody, "Depósito {$status}", $tx['id']]);

        // Atualizar transação no histórico
        $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
        $stmt->execute([$tx['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'failed'
                WHERE google_uid = ? AND type IN ('deposit', 'credit_purchase', 'premium_purchase') AND description LIKE ? AND status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$user['google_uid'], '%' . $externalId . '%']);
        }

        secureLog("ZETTPAY_DEPOSIT_{$status} | external_id: {$externalId} | user_id: {$tx['user_id']}");
    }
}

// ============================================
// PROCESSAR SAQUE (CASH-OUT)
// ============================================
function processCashout($pdo, $data, $rawBody) {
    $externalId = $data['external_id'];
    $status = strtoupper($data['status'] ?? '');
    $zettpayId = $data['provider_transaction_id'] ?? $data['id'] ?? '';

    // Buscar transação no banco
    $stmt = $pdo->prepare("SELECT * FROM zettpay_transactions WHERE external_id = ? AND type = 'cashout' LIMIT 1");
    $stmt->execute([$externalId]);
    $tx = $stmt->fetch();

    if (!$tx) {
        secureLog("ZETTPAY_WEBHOOK_CASHOUT_NOT_FOUND | external_id: {$externalId}");
        return;
    }

    // Idempotência: se já confirmado, ignorar
    if ($tx['status'] === 'confirmed') {
        secureLog("ZETTPAY_WEBHOOK_CASHOUT_ALREADY_CONFIRMED | external_id: {$externalId}");
        return;
    }

    $withdrawalId = $tx['withdrawal_id'];

    // Status de sucesso: APPROVED ou COMPLETED
    if ($status === 'APPROVED' || $status === 'COMPLETED') {
        $pdo->beginTransaction();

        try {
            // Bloquear linha da transação
            $stmt = $pdo->prepare("SELECT * FROM zettpay_transactions WHERE id = ? FOR UPDATE");
            $stmt->execute([$tx['id']]);
            $tx = $stmt->fetch();

            if ($tx['status'] === 'confirmed') {
                $pdo->rollBack();
                return;
            }

            // Atualizar transação ZettPay
            $stmt = $pdo->prepare("
                UPDATE zettpay_transactions
                SET status = 'confirmed',
                    zettpay_id = ?,
                    webhook_payload = ?,
                    confirmed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$zettpayId, $rawBody, $tx['id']]);

            // Atualizar withdrawal
            if ($withdrawalId) {
                $stmt = $pdo->prepare("
                    UPDATE withdrawals
                    SET status = 'completed',
                        processed_at = NOW(),
                        zettpay_status = 'confirmed'
                    WHERE id = ? AND status = 'processing'
                ");
                $stmt->execute([$withdrawalId]);

                // Buscar google_uid para log de transação
                $stmt = $pdo->prepare("SELECT u.google_uid FROM withdrawals w JOIN users u ON w.user_id = u.id WHERE w.id = ?");
                $stmt->execute([$withdrawalId]);
                $user = $stmt->fetch();

                if ($user) {
                    $stmt = $pdo->prepare("
                        INSERT INTO transactions
                        (google_uid, type, amount_brl, description, status, created_at)
                        VALUES (?, 'withdraw', ?, ?, 'completed', NOW())
                    ");
                    $stmt->execute([
                        $user['google_uid'],
                        -abs($tx['amount_brl']),
                        "Saque PIX #{$withdrawalId} confirmado via ZettPay"
                    ]);
                }
            }

            $pdo->commit();

            secureLog("ZETTPAY_CASHOUT_CONFIRMED | external_id: {$externalId} | withdrawal_id: {$withdrawalId} | amount: R\${$tx['amount_brl']}");

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    // Status de falha
    elseif ($status === 'FAILED' || $status === 'REJECTED') {
        $pdo->beginTransaction();

        try {
            // Atualizar transação ZettPay
            $stmt = $pdo->prepare("
                UPDATE zettpay_transactions
                SET status = 'failed',
                    zettpay_id = ?,
                    webhook_payload = ?,
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$zettpayId, $rawBody, "Saque {$status} pela ZettPay", $tx['id']]);

            // Devolver saldo ao usuário e reverter withdrawal
            if ($withdrawalId) {
                $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
                $stmt->execute([$withdrawalId]);
                $withdrawal = $stmt->fetch();

                if ($withdrawal && $withdrawal['status'] === 'processing') {
                    $amount = (float)$withdrawal['amount_brl'];
                    $userId = $withdrawal['user_id'];

                    // Devolver saldo
                    $stmt = $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$amount, $userId]);

                    // Marcar withdrawal como falho
                    $stmt = $pdo->prepare("
                        UPDATE withdrawals
                        SET status = 'rejected',
                            processed_at = NOW(),
                            zettpay_status = 'failed',
                            admin_notes = ?
                        WHERE id = ?
                    ");
                    $errorNote = json_encode([
                        'zettpay_error' => "Saque {$status} pela ZettPay",
                        'original_notes' => $withdrawal['admin_notes'],
                        'failed_at' => date('Y-m-d H:i:s')
                    ]);
                    $stmt->execute([$errorNote, $withdrawalId]);

                    // Log de estorno
                    $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();

                    if ($user) {
                        $stmt = $pdo->prepare("
                            INSERT INTO transactions
                            (google_uid, type, amount_brl, description, status, created_at)
                            VALUES (?, 'withdraw_reject', ?, ?, 'completed', NOW())
                        ");
                        $stmt->execute([
                            $user['google_uid'],
                            $amount,
                            "Saque PIX #{$withdrawalId} falhou na ZettPay - saldo devolvido"
                        ]);
                    }

                    secureLog("ZETTPAY_CASHOUT_FAILED_REFUND | external_id: {$externalId} | withdrawal_id: {$withdrawalId} | amount: R\${$amount} | user_id: {$userId}");
                }
            }

            $pdo->commit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        secureLog("ZETTPAY_CASHOUT_FAILED | external_id: {$externalId} | status: {$status}");
    }
}
