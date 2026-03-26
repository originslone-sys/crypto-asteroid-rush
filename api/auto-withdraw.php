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

// Autenticação: token via query (cron/admin) ou sessão admin (POST)
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

// Forçar execução? (admin clicou "Processar Agora")
$forceRun = isset($_GET['force']) || (isset($_POST['force']) && $_POST['force']);

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão: ' . $e->getMessage()]);
    exit;
}

// Carregar configurações do banco
$settings = [];
try {
    $result = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key LIKE 'auto_withdraw_%'");
    while ($row = $result->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Tabela pode não existir ainda, continuar com defaults
}

$enabled = ($settings['auto_withdraw_enabled'] ?? 'false') === 'true';
$batchSize = max(1, min(50, (int)($settings['auto_withdraw_batch_size'] ?? 5)));

// Se desativado e não é forçado, sai
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

// Garantir tabela zettpay_transactions ANTES de qualquer transação
ensureZettpayTable($pdo);

// Buscar saques pendentes (mais antigos primeiro)
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
        if (!is_array($notes)) $notes = [];
        $pixKey = $notes['details'] ?? '';
        $pixKeyType = $notes['pix_key_type'] ?? 'cpf';

        if (empty($pixKey)) {
            secureLog("AUTO_WITHDRAW_SKIP | id: {$wId} | reason: no_pix_key | admin_notes: " . substr($withdrawal['admin_notes'] ?? '', 0, 200));
            $results['skipped']++;
            $results['errors'][] = "#{$wId}: chave PIX não encontrada nos dados";
            continue;
        }

        $zettpayKeyType = $keyTypeMap[strtolower($pixKeyType)] ?? 'document';
        $amount = (float)$withdrawal['amount_brl'];
        $userId = $withdrawal['user_id'];

        // PASSO 1: Lock rápido para verificar status e marcar como 'processing' ANTES da API
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

        // Gerar external_id
        $externalId = zettpayWithdrawExternalId($wId);

        // Marcar como 'processing' ANTES de chamar a API (evita que outro processo tente o mesmo saque)
        $pdo->prepare("
            UPDATE withdrawals
            SET status = 'processing', zettpay_external_id = ?, zettpay_status = 'processing'
            WHERE id = ? AND status = 'pending'
        ")->execute([$externalId, $wId]);

        $pdo->commit(); // Libera o lock rápido

        // PASSO 2: Chamar API ZettPay FORA da transação (pode demorar)
        secureLog("AUTO_WITHDRAW_CALLING_API | id: {$wId} | external_id: {$externalId} | amount: R\${$amount} | key_type: {$zettpayKeyType}");

        $apiResult = zettpayCreateCashout(
            $amount,
            $pixKey,
            $zettpayKeyType,
            $externalId,
            ['user_id' => (string)$userId, 'withdrawal_id' => (string)$wId]
        );

        if ($apiResult['success']) {
            // PASSO 3a: API ok - registrar na zettpay_transactions
            $apiData = $apiResult['data']['data'] ?? $apiResult['data'] ?? [];

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

            $results['processed']++;
            secureLog("AUTO_WITHDRAW_SENT | id: {$wId} | external_id: {$externalId} | amount: R\${$amount}");

        } else {
            // PASSO 3b: API falhou - analisar tipo de erro
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

            // Erros de chave inválida: cancelar e devolver saldo
            $invalidKeyErrors = ['invalid_key', 'invalid_pix_key', 'key_not_found', 'invalid_document', 'receiver_not_found', 'pix_key_not_found', 'invalid_receiver'];
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
                    UPDATE withdrawals SET status = 'rejected',
                    admin_notes = JSON_SET(COALESCE(admin_notes, '{}'), '$.auto_reject_reason', ?)
                    WHERE id = ?
                ")->execute(["Chave PIX inválida: {$errorMsg}", $wId]);

                $pdo->commit();
                $results['failed_invalid_key']++;
                $results['errors'][] = "#{$wId}: chave inválida - saldo devolvido";
                secureLog("AUTO_WITHDRAW_REJECTED | id: {$wId} | reason: invalid_key | error: {$errorMsg}");

            } else {
                // Erro temporário: voltar para 'pending' para tentar novamente
                $pdo->prepare("
                    UPDATE withdrawals SET status = 'pending', zettpay_status = NULL, zettpay_external_id = NULL
                    WHERE id = ? AND status = 'processing'
                ")->execute([$wId]);

                $results['failed_api']++;
                $results['errors'][] = "#{$wId}: {$errorMsg} (tentará novamente)";
                secureLog("AUTO_WITHDRAW_RETRY_LATER | id: {$wId} | http: {$httpCode} | error_code: {$errorCode} | error: {$errorMsg}");
            }
        }

        // Delay entre requisições
        usleep(500000); // 500ms

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        // Se já marcou como processing, voltar para pending
        try {
            $pdo->prepare("UPDATE withdrawals SET status = 'pending', zettpay_status = NULL, zettpay_external_id = NULL WHERE id = ? AND status = 'processing'")->execute([$wId]);
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
