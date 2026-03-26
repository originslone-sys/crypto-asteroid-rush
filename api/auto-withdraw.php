<?php
/**
 * Auto-Withdraw - Processamento automático de saques via ZettPay PIX
 * Roda via Cloud Scheduler (cron) ou manualmente pelo admin
 *
 * Proteção por token (cron) ou sessão admin (POST)
 * Configurável via game_settings no painel admin
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zettpay-client.php';

// Autenticação: token via query (cron) ou sessão admin
$token = $_GET['token'] ?? '';
$isAuthorized = false;

if ($token === (defined('RECONCILE_CRON_TOKEN') ? RECONCILE_CRON_TOKEN : '')) {
    $isAuthorized = true;
}

if (!$isAuthorized && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    if (isset($_SESSION['admin'])) {
        $isAuthorized = true;
    }
}

if (!$isAuthorized) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// Carregar configurações do banco
$settings = [];
try {
    $result = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key LIKE 'auto_withdraw_%'");
    while ($row = $result->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$enabled = ($settings['auto_withdraw_enabled'] ?? 'false') === 'true';
$batchSize = max(1, min(50, (int)($settings['auto_withdraw_batch_size'] ?? 5)));

// Se desativado, sai silenciosamente (cron continua rodando mas não processa)
if (!$enabled) {
    echo json_encode([
        'success' => true,
        'message' => 'Auto-withdraw desativado',
        'enabled' => false,
        'processed' => 0
    ]);
    exit;
}

secureLog("AUTO_WITHDRAW_START | batch_size: {$batchSize}");

// Garantir tabela zettpay_transactions
ensureZettpayTable($pdo);

// Buscar saques pendentes (mais antigos primeiro, apenas com dados PIX válidos)
$stmt = $pdo->prepare("
    SELECT id, user_id, amount_brl, admin_notes, created_at
    FROM withdrawals
    WHERE status = 'pending'
      AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY created_at ASC
    LIMIT ?
");
$stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
$stmt->execute();
$pendingWithdrawals = $stmt->fetchAll();

$results = [
    'total_found' => count($pendingWithdrawals),
    'processed' => 0,
    'failed_api' => 0,
    'failed_invalid_key' => 0,
    'skipped' => 0,
    'errors' => []
];

// Mapeamento de tipos de chave para ZettPay
$keyTypeMap = [
    'cpf' => 'document',
    'cnpj' => 'document',
    'document' => 'document',
    'email' => 'email',
    'phone' => 'phone',
    'celular' => 'phone',
    'aleatoria' => 'evp',
    'evp' => 'evp'
];

foreach ($pendingWithdrawals as $withdrawal) {
    $wId = $withdrawal['id'];

    try {
        // Extrair dados PIX
        $notes = json_decode($withdrawal['admin_notes'] ?? '{}', true);
        $pixKey = $notes['details'] ?? '';
        $pixKeyType = $notes['pix_key_type'] ?? 'cpf';

        if (empty($pixKey)) {
            secureLog("AUTO_WITHDRAW_SKIP | id: {$wId} | reason: no_pix_key");
            $results['skipped']++;
            continue;
        }

        $zettpayKeyType = $keyTypeMap[strtolower($pixKeyType)] ?? 'document';
        $amount = (float)$withdrawal['amount_brl'];
        $userId = $withdrawal['user_id'];

        // Lock e verificar status novamente (evitar race condition com admin)
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare("SELECT id, status FROM withdrawals WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$wId]);
        $locked = $lockStmt->fetch();

        if (!$locked || $locked['status'] !== 'pending') {
            $pdo->rollBack();
            secureLog("AUTO_WITHDRAW_SKIP | id: {$wId} | reason: status_changed ({$locked['status']})");
            $results['skipped']++;
            continue;
        }

        // Gerar external_id e chamar ZettPay
        $externalId = zettpayWithdrawExternalId($wId);

        $apiResult = zettpayCreateCashout(
            $amount,
            $pixKey,
            $zettpayKeyType,
            $externalId,
            ['user_id' => (string)$userId, 'withdrawal_id' => (string)$wId]
        );

        if ($apiResult['success']) {
            // Sucesso: marcar como processing
            $apiData = $apiResult['data']['data'] ?? $apiResult['data'] ?? [];

            $pdo->prepare("
                UPDATE withdrawals
                SET status = 'processing', zettpay_external_id = ?, zettpay_status = 'processing'
                WHERE id = ?
            ")->execute([$externalId, $wId]);

            $pdo->prepare("
                INSERT INTO zettpay_transactions (
                    user_id, external_id, zettpay_id, type, amount_brl, fee_brl,
                    status, pix_key, pix_key_type, withdrawal_id, created_at
                ) VALUES (?, ?, ?, 'cashout', ?, 0, 'processing', ?, ?, ?, NOW())
            ")->execute([
                $userId,
                $externalId,
                $apiData['provider_transaction_id'] ?? $apiData['id'] ?? null,
                $amount,
                $pixKey,
                $zettpayKeyType,
                $wId
            ]);

            $pdo->commit();
            $results['processed']++;
            secureLog("AUTO_WITHDRAW_SENT | id: {$wId} | external_id: {$externalId} | amount: R\${$amount} | key_type: {$zettpayKeyType}");

        } else {
            // Falha: analisar o tipo de erro
            $errorMsg = $apiResult['error'] ?? 'Unknown error';
            $httpCode = $apiResult['http_code'] ?? 0;
            $errorData = $apiResult['data'] ?? [];
            $errorCode = '';

            // Tentar extrair código de erro específico
            if (is_array($errorData)) {
                $errorCode = $errorData['error']['code'] ?? $errorData['code'] ?? '';
                if (empty($errorCode) && isset($errorData['error']) && is_string($errorData['error'])) {
                    $errorCode = $errorData['error'];
                }
            }

            $errorCodeLower = strtolower($errorCode);
            $errorMsgLower = strtolower($errorMsg);

            // Erros de chave inválida: cancelar e devolver saldo
            $invalidKeyErrors = ['invalid_key', 'invalid_pix_key', 'key_not_found', 'invalid_document', 'receiver_not_found'];
            $isInvalidKey = false;
            foreach ($invalidKeyErrors as $ike) {
                if (strpos($errorCodeLower, $ike) !== false || strpos($errorMsgLower, $ike) !== false) {
                    $isInvalidKey = true;
                    break;
                }
            }

            if ($isInvalidKey) {
                // Chave PIX inválida: rejeitar e devolver saldo
                $userStmt = $pdo->prepare("SELECT id, google_uid FROM users WHERE id = ? FOR UPDATE");
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch();

                if ($user) {
                    $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE id = ?")->execute([$amount, $userId]);

                    $pdo->prepare("
                        INSERT INTO transactions (google_uid, type, amount_brl, description, status)
                        VALUES (?, 'withdraw_reject', ?, ?, 'completed')
                    ")->execute([
                        $user['google_uid'],
                        $amount,
                        "Saque #{$wId} cancelado: chave PIX inválida. Saldo devolvido."
                    ]);
                }

                $pdo->prepare("
                    UPDATE withdrawals
                    SET status = 'rejected', admin_notes = JSON_SET(COALESCE(admin_notes, '{}'), '$.auto_reject_reason', ?)
                    WHERE id = ?
                ")->execute(["Chave PIX inválida: {$errorMsg}", $wId]);

                $pdo->commit();
                $results['failed_invalid_key']++;
                secureLog("AUTO_WITHDRAW_REJECTED | id: {$wId} | reason: invalid_key | error: {$errorMsg}");

            } else {
                // Erro temporário (fundos insuficientes, falha de comunicação, etc): NÃO cancelar
                $pdo->rollBack();
                $results['failed_api']++;
                $results['errors'][] = "#{$wId}: {$errorMsg}";
                secureLog("AUTO_WITHDRAW_RETRY_LATER | id: {$wId} | http: {$httpCode} | error_code: {$errorCode} | error: {$errorMsg}");
            }
        }

        // Delay entre requisições para não sobrecarregar a API
        usleep(500000); // 500ms

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $results['errors'][] = "#{$wId}: " . $e->getMessage();
        secureLog("AUTO_WITHDRAW_ERROR | id: {$wId} | " . $e->getMessage());
    }
}

secureLog("AUTO_WITHDRAW_DONE | found: {$results['total_found']} | processed: {$results['processed']} | invalid_key: {$results['failed_invalid_key']} | api_fail: {$results['failed_api']} | skipped: {$results['skipped']}");

echo json_encode([
    'success' => true,
    'enabled' => true,
    'results' => $results
]);
