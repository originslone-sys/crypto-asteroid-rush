<?php
/**
 * Auto-Withdraw - Processamento automático de saques via ZettPay PIX
 * Roda via Cloud Scheduler (cron) ou manualmente pelo admin
 *
 * Proteção por token (cron) ou sessão admin
 * Configurável via game_settings no painel admin
 *
 * FLUXO: O status 'pending' só muda para 'processing' APÓS a API confirmar aceite.
 * Usa zettpay_status='sending' como lock temporário para evitar duplicatas.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zettpay-client.php';

// Autenticação: token via query (cron/admin) ou sessão admin
$token = $_GET['token'] ?? '';
$isAuthorized = false;
$cronToken = defined('RECONCILE_CRON_TOKEN') ? RECONCILE_CRON_TOKEN : '';

if (!empty($cronToken) && $token === $cronToken) {
    $isAuthorized = true;
}

if (!$isAuthorized) {
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

$forceRun = isset($_GET['force']) || (isset($_POST['force']) && $_POST['force']);

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão: ' . $e->getMessage()]);
    exit;
}

// Carregar configurações
$settings = [];
try {
    $result = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key LIKE 'auto_withdraw_%'");
    while ($row = $result->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$enabled = ($settings['auto_withdraw_enabled'] ?? 'false') === 'true';
$batchSize = max(1, min(50, (int)($settings['auto_withdraw_batch_size'] ?? 5)));

if (!$enabled && !$forceRun) {
    echo json_encode([
        'success' => true,
        'message' => 'Auto-withdraw desativado. Ative nas Configurações ou use ?force=1',
        'enabled' => false,
        'processed' => 0
    ]);
    exit;
}

secureLog("AUTO_WITHDRAW_START | batch_size: {$batchSize} | forced: " . ($forceRun ? 'yes' : 'no'));

ensureZettpayTable($pdo);

// Limpar locks antigos (sending há mais de 5 min = travou, liberar)
try {
    $pdo->exec("UPDATE withdrawals SET zettpay_status = NULL WHERE status = 'pending' AND zettpay_status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
} catch (Exception $e) {
    // Coluna updated_at pode não existir, ignorar
}

// Buscar saques pendentes que NÃO estão sendo processados por outro processo
$stmt = $pdo->prepare("
    SELECT id, user_id, amount_brl, admin_notes, created_at
    FROM withdrawals
    WHERE status = 'pending'
      AND (zettpay_status IS NULL OR zettpay_status = '' OR zettpay_status = 'retry')
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

if (count($pendingWithdrawals) === 0) {
    secureLog("AUTO_WITHDRAW_DONE | no pending withdrawals found");
    echo json_encode([
        'success' => true,
        'enabled' => true,
        'message' => 'Nenhum saque pendente encontrado',
        'results' => $results
    ]);
    exit;
}

$keyTypeMap = [
    'cpf' => 'document', 'cnpj' => 'document', 'document' => 'document',
    'email' => 'email', 'phone' => 'phone', 'celular' => 'phone',
    'aleatoria' => 'evp', 'evp' => 'evp'
];

foreach ($pendingWithdrawals as $withdrawal) {
    $wId = $withdrawal['id'];

    try {
        // Extrair dados PIX
        $notes = json_decode($withdrawal['admin_notes'] ?? '{}', true);
        if (!is_array($notes)) $notes = [];
        $pixKey = $notes['details'] ?? '';
        $pixKeyType = $notes['pix_key_type'] ?? 'cpf';

        if (empty($pixKey)) {
            secureLog("AUTO_WITHDRAW_SKIP | id: {$wId} | reason: no_pix_key");
            $results['skipped']++;
            $results['errors'][] = "#{$wId}: chave PIX não encontrada nos dados";
            continue;
        }

        $zettpayKeyType = $keyTypeMap[strtolower($pixKeyType)] ?? 'document';
        $amount = (float)$withdrawal['amount_brl'];
        $userId = $withdrawal['user_id'];

        // PASSO 1: Soft-lock - marcar zettpay_status='sending' sem mudar o status principal
        // Usa UPDATE condicional como lock atômico (se outra instância já marcou, affected=0)
        $lockStmt = $pdo->prepare("
            UPDATE withdrawals
            SET zettpay_status = 'sending'
            WHERE id = ? AND status = 'pending' AND (zettpay_status IS NULL OR zettpay_status = '' OR zettpay_status = 'retry')
        ");
        $lockStmt->execute([$wId]);

        if ($lockStmt->rowCount() === 0) {
            // Outro processo já está tratando este saque
            secureLog("AUTO_WITHDRAW_SKIP | id: {$wId} | reason: already_locked");
            $results['skipped']++;
            continue;
        }

        // PASSO 2: Chamar API ZettPay (status ainda é 'pending', visível no admin)
        $externalId = zettpayWithdrawExternalId($wId);

        secureLog("AUTO_WITHDRAW_CALLING_API | id: {$wId} | external_id: {$externalId} | amount: R\${$amount} | key: {$zettpayKeyType}");

        $apiResult = zettpayCreateCashout(
            $amount,
            $pixKey,
            $zettpayKeyType,
            $externalId,
            ['user_id' => (string)$userId, 'withdrawal_id' => (string)$wId]
        );

        if ($apiResult['success']) {
            // PASSO 3a: API aceitou - AGORA sim mudar para 'processing'
            $apiData = $apiResult['data']['data'] ?? $apiResult['data'] ?? [];

            $pdo->beginTransaction();

            // Verificar novamente se ninguém mexeu enquanto a API rodava
            $checkStmt = $pdo->prepare("SELECT status FROM withdrawals WHERE id = ? FOR UPDATE");
            $checkStmt->execute([$wId]);
            $current = $checkStmt->fetch();

            if (!$current || $current['status'] !== 'pending') {
                $pdo->rollBack();
                secureLog("AUTO_WITHDRAW_SKIP | id: {$wId} | reason: status_changed_during_api ({$current['status']})");
                $results['skipped']++;
                continue;
            }

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
            secureLog("AUTO_WITHDRAW_SENT | id: {$wId} | external_id: {$externalId} | amount: R\${$amount}");

        } else {
            // PASSO 3b: API falhou - analisar erro
            $errorMsg = $apiResult['error'] ?? 'Unknown error';
            $httpCode = $apiResult['http_code'] ?? 0;
            $errorData = $apiResult['data'] ?? [];
            $errorCode = '';

            if (is_array($errorData)) {
                $errorCode = $errorData['error']['code'] ?? $errorData['code'] ?? '';
                if (empty($errorCode) && isset($errorData['error']) && is_string($errorData['error'])) {
                    $errorCode = $errorData['error'];
                }
            }

            $errorCodeLower = strtolower((string)$errorCode);
            $errorMsgLower = strtolower((string)$errorMsg);

            $invalidKeyErrors = ['invalid_key', 'invalid_pix_key', 'key_not_found', 'invalid_document',
                                 'receiver_not_found', 'pix_key_not_found', 'invalid_receiver'];
            $isInvalidKey = false;
            foreach ($invalidKeyErrors as $ike) {
                if (strpos($errorCodeLower, $ike) !== false || strpos($errorMsgLower, $ike) !== false) {
                    $isInvalidKey = true;
                    break;
                }
            }

            if ($isInvalidKey) {
                // Chave inválida: rejeitar e devolver saldo
                $pdo->beginTransaction();

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
                        "Saque #{$wId} cancelado automaticamente: chave PIX inválida. Saldo devolvido."
                    ]);
                }

                $pdo->prepare("
                    UPDATE withdrawals SET status = 'rejected', zettpay_status = 'failed',
                    admin_notes = JSON_SET(COALESCE(admin_notes, '{}'), '$.auto_reject_reason', ?)
                    WHERE id = ?
                ")->execute(["Chave PIX inválida: {$errorMsg}", $wId]);

                $pdo->commit();
                $results['failed_invalid_key']++;
                $results['errors'][] = "#{$wId}: chave inválida - saldo devolvido";
                secureLog("AUTO_WITHDRAW_REJECTED | id: {$wId} | reason: invalid_key | error: {$errorMsg}");

            } else {
                // Erro temporário: liberar o lock, manter como 'pending'
                $pdo->prepare("
                    UPDATE withdrawals SET zettpay_status = 'retry'
                    WHERE id = ? AND status = 'pending'
                ")->execute([$wId]);

                $results['failed_api']++;
                $results['errors'][] = "#{$wId}: {$errorMsg} (tentará novamente)";
                secureLog("AUTO_WITHDRAW_RETRY_LATER | id: {$wId} | http: {$httpCode} | error_code: {$errorCode} | error: {$errorMsg}");
            }
        }

        usleep(500000);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        // Liberar lock se travou
        try {
            $pdo->prepare("UPDATE withdrawals SET zettpay_status = NULL WHERE id = ? AND status = 'pending'")->execute([$wId]);
        } catch (Exception $e2) {}

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
