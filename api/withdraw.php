<?php
// ============================================
// UNOBIX - Solicitação de Saque
// Arquivo: api/withdraw.php
// v3.0 - Unobix-only (google_uid) + PIX/PayPal/USDT BEP20 + BRL
// ============================================

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================
// INPUT (JSON + POST + GET)
// ============================================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$googleUid = $input['google_uid'] ?? $_POST['google_uid'] ?? $_GET['google_uid'] ?? '';
$amountRaw = $input['amount'] ?? $_POST['amount'] ?? $_GET['amount'] ?? 0;
$paymentMethod = $input['payment_method'] ?? $_POST['payment_method'] ?? $_GET['payment_method'] ?? 'pix';
$paymentDetails = $input['payment_details'] ?? $_POST['payment_details'] ?? [];
$requestId = $input['request_id'] ?? $_POST['request_id'] ?? $_GET['request_id'] ?? null;

$googleUid = trim((string)$googleUid);
$amount = (float)$amountRaw;
$paymentMethod = strtolower(trim((string)$paymentMethod));
if (!is_array($paymentDetails)) $paymentDetails = [];
$requestId = is_string($requestId) ? trim($requestId) : null;

// ============================================
// VALIDAR IDENTIDADE (Unobix-only)
// ============================================
if ($googleUid === '' || !validateGoogleUid($googleUid)) {
    jsonOut(['success' => false, 'message' => 'Identificação inválida. Envie google_uid.'], 400);
}

// ============================================
// VALIDAR MÉTODO
// ============================================
if (!defined('WITHDRAW_METHODS')) {
    // fallback seguro se a constante não existir
    define('WITHDRAW_METHODS', ['pix', 'paypal', 'usdt_bep20']);
}
if (!in_array($paymentMethod, WITHDRAW_METHODS, true)) {
    jsonOut([
        'success' => false,
        'message' => 'Método de pagamento inválido. Use: ' . implode(', ', WITHDRAW_METHODS)
    ], 400);
}

// ============================================
// VALIDAR VALOR
// ============================================
$min = defined('MIN_WITHDRAW_BRL') ? (float)MIN_WITHDRAW_BRL : 1.00;
$max = defined('MAX_WITHDRAW_BRL') ? (float)MAX_WITHDRAW_BRL : 999999.00;

if ($amount < $min) {
    jsonOut(['success' => false, 'message' => "Valor mínimo para saque: R$ " . number_format($min, 2, ',', '.')], 400);
}
if ($amount > $max) {
    jsonOut(['success' => false, 'message' => "Valor máximo para saque: R$ " . number_format($max, 2, ',', '.')], 400);
}

// ============================================
// VALIDAR PAYMENT DETAILS
// ============================================
$paymentDetailsJson = null;

switch ($paymentMethod) {
    case 'pix': {
        $pixKey = $paymentDetails['pix_key'] ?? $input['pix_key'] ?? '';
        $pixKeyType = $paymentDetails['pix_key_type'] ?? $input['pix_key_type'] ?? 'cpf';

        $pixKey = trim((string)$pixKey);
        $pixKeyType = strtolower(trim((string)$pixKeyType));

        if ($pixKey === '') jsonOut(['success' => false, 'message' => 'Chave PIX é obrigatória'], 400);

        $allowed = ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'];
        if (!in_array($pixKeyType, $allowed, true)) $pixKeyType = 'aleatoria';

        $paymentDetailsJson = json_encode([
            'pix_key' => $pixKey,
            'pix_key_type' => $pixKeyType
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'paypal': {
        $paypalEmail = $paymentDetails['paypal_email'] ?? $input['paypal_email'] ?? '';
        $paypalEmail = trim((string)$paypalEmail);

        if ($paypalEmail === '' || !filter_var($paypalEmail, FILTER_VALIDATE_EMAIL)) {
            jsonOut(['success' => false, 'message' => 'E-mail PayPal inválido'], 400);
        }

        $paymentDetailsJson = json_encode([
            'paypal_email' => $paypalEmail
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'usdt_bep20': {
        $bscWallet = $paymentDetails['bsc_wallet'] ?? $input['bsc_wallet'] ?? '';
        $bscWallet = strtolower(trim((string)$bscWallet));

        if (!validateWallet($bscWallet)) {
            jsonOut(['success' => false, 'message' => 'Carteira BEP20 inválida'], 400);
        }

        $paymentDetailsJson = json_encode([
            'bsc_wallet' => $bscWallet
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        break;
    }
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) jsonOut(['success' => false, 'message' => 'Erro ao conectar ao banco'], 500);

    $pdo->beginTransaction();

    // ============================================
    // 1) LOCK PLAYER
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, google_uid, balance_brl, total_withdrawn_brl
        FROM players
        WHERE google_uid = ?
        FOR UPDATE
    ");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        $pdo->rollBack();
        jsonOut(['success' => false, 'message' => 'Jogador não encontrado.'], 404);
    }

    $currentBalance = (float)($player['balance_brl'] ?? 0);
    if ($currentBalance < $amount) {
        $pdo->rollBack();
        jsonOut([
            'success' => false,
            'message' => 'Saldo insuficiente.',
            'current_balance' => $currentBalance
        ], 400);
    }

    // ============================================
    // 2) IDEMPOTÊNCIA (opcional mas recomendado)
    // - Se request_id vier, impedimos duplicar submissão.
    // Requer coluna withdrawals.request_id (se não existir, ignora).
    // ============================================
    if ($requestId) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'request_id'")->fetch(PDO::FETCH_ASSOC);
            if ($col) {
                $stmt = $pdo->prepare("
                    SELECT id, status, amount_brl
                    FROM withdrawals
                    WHERE google_uid = ? AND request_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$googleUid, $requestId]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $pdo->commit();
                    jsonOut([
                        'success' => true,
                        'message' => 'Solicitação já registrada.',
                        'withdrawal_id' => (int)$existing['id'],
                        'amount_brl' => (float)$existing['amount_brl'],
                        'status' => $existing['status']
                    ]);
                }
            }
        } catch (Throwable $e) {
            // ignora idempotência se schema não suporta
        }
    }

    // ============================================
    // 3) LIMITE SEMANAL
    // ============================================
    $weeklyLimit = defined('WEEKLY_WITHDRAW_LIMIT') ? (int)WEEKLY_WITHDRAW_LIMIT : 1;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM withdrawals
        WHERE google_uid = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
          AND status NOT IN ('rejected', 'cancelled')
    ");
    $stmt->execute([$googleUid]);
    $weeklyCount = (int)$stmt->fetchColumn();

    if ($weeklyCount >= $weeklyLimit) {
        $pdo->rollBack();
        jsonOut([
            'success' => false,
            'message' => "Limite semanal atingido. Você pode fazer {$weeklyLimit} solicitação(ões) por semana."
        ], 429);
    }

    // ============================================
    // 4) INSERT WITHDRAWAL
    // Compat: se existirem colunas legadas, preenche sem usar como vínculo.
    // ============================================
    $hasRequestId = false;
    try {
        $hasRequestId = (bool)$pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'request_id'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    // Detectar se existe wallet_address na withdrawals (legado)
    $hasWalletAddress = false;
    try {
        $hasWalletAddress = (bool)$pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'wallet_address'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $cols = ["player_id", "google_uid", "amount_brl", "payment_method", "payment_details", "status", "created_at"];
    $vals = ["?", "?", "?", "?", "?", "'pending'", "NOW()"];
    $params = [
        (int)$player['id'],
        $googleUid,
        $amount,
        $paymentMethod,
        $paymentDetailsJson
    ];

    if ($hasWalletAddress) {
        $cols[] = "wallet_address";
        $vals[] = "?";
        $params[] = null; // Unobix: não usamos wallet_address como vínculo
    }

    // Compat legado: amount_usdt se existir (não é usado no Unobix, mas evita quebrar schema)
    try {
        $hasAmountUsdt = (bool)$pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'amount_usdt'")->fetch(PDO::FETCH_ASSOC);
        if ($hasAmountUsdt) {
            $cols[] = "amount_usdt";
            $vals[] = "?";
            $params[] = $amount;
        }
    } catch (Throwable $e) {}

    if ($hasRequestId && $requestId) {
        $cols[] = "request_id";
        $vals[] = "?";
        $params[] = $requestId;
    }

    $sql = "INSERT INTO withdrawals (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $withdrawalId = (int)$pdo->lastInsertId();

    // ============================================
    // 5) DEBITAR SALDO
    // ============================================
    $newBalance = $currentBalance - $amount;

    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_brl = ?,
            total_withdrawn_brl = total_withdrawn_brl + ?
        WHERE id = ?
    ");
    $stmt->execute([$newBalance, $amount, (int)$player['id']]);

    // ============================================
    // 6) TRANSAÇÃO (DÉBITO)
    // amount_brl NEGATIVO
    // ============================================
    $methodLabel = [
        'pix' => 'PIX',
        'paypal' => 'PayPal',
        'usdt_bep20' => 'USDT BEP20'
    ][$paymentMethod] ?? strtoupper($paymentMethod);

    $txAmount = -abs($amount);

    $stmt = $pdo->prepare("
        INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
        VALUES (?, 'withdraw', ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $googleUid,
        $txAmount,
        "Saque via {$methodLabel} - #{$withdrawalId}"
    ]);

    $pdo->commit();

    if (function_exists('secureLog')) {
        secureLog("WITHDRAW_REQUEST | UID: {$googleUid} | Amount: R$ {$amount} | Method: {$paymentMethod} | Withdrawal: #{$withdrawalId}");
    }

    $processingInfo = "Processamento nos dias " . (defined('PROCESSING_START_DAY') ? PROCESSING_START_DAY : 20)
        . " a " . (defined('PROCESSING_END_DAY') ? PROCESSING_END_DAY : 25) . " de cada mês";

    jsonOut([
        'success' => true,
        'message' => 'Solicitação de saque enviada com sucesso!',
        'withdrawal_id' => $withdrawalId,
        'amount_brl' => number_format($amount, 2, '.', ''),
        'payment_method' => $paymentMethod,
        'new_balance' => number_format($newBalance, 2, '.', ''),
        'processing_info' => $processingInfo,
        'status' => 'pending'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();

    if (function_exists('secureLog')) {
        secureLog("WITHDRAW_ERROR | UID: {$googleUid} | Error: " . $e->getMessage());
    }
    error_log("Erro no withdraw.php: " . $e->getMessage());

    jsonOut(['success' => false, 'message' => 'Erro no servidor. Tente novamente.'], 500);
}
