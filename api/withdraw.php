<?php
// ============================================
// UNOBIX - Solicitação de Saque
// Arquivo: api/withdraw.php
// Unobix-only: exige google_uid; USDT BEP20 wallet vai em payment_details
// ============================================

require_once __DIR__ . "/config.php";

ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function readJsonInput(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$input = array_merge($_GET, $_POST, readJsonInput());

$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$amount = (float)($input['amount'] ?? 0);
$paymentMethod = strtolower(trim($input['payment_method'] ?? 'pix'));
$paymentDetails = $input['payment_details'] ?? [];

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'message' => 'google_uid inválido. Faça login novamente.']);
    exit;
}

if (!defined('WITHDRAW_METHODS')) {
    define('WITHDRAW_METHODS', ['pix','paypal','usdt_bep20']);
}
if (!in_array($paymentMethod, WITHDRAW_METHODS, true)) {
    echo json_encode(['success' => false, 'message' => 'Método inválido']);
    exit;
}

if (defined('MIN_WITHDRAW_BRL') && $amount < MIN_WITHDRAW_BRL) {
    echo json_encode(['success' => false, 'message' => "Valor mínimo: R$ " . number_format(MIN_WITHDRAW_BRL, 2, ',', '.')]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco']);
        exit;
    }

    // validar details
    $details = [];
    if ($paymentMethod === 'pix') {
        $pixKey = $paymentDetails['pix_key'] ?? ($input['pix_key'] ?? '');
        $pixKeyType = strtolower($paymentDetails['pix_key_type'] ?? ($input['pix_key_type'] ?? 'cpf'));
        if (!$pixKey) { echo json_encode(['success'=>false,'message'=>'Chave PIX é obrigatória']); exit; }
        $details = ['pix_key' => $pixKey, 'pix_key_type' => $pixKeyType];
    } elseif ($paymentMethod === 'paypal') {
        $paypalEmail = $paymentDetails['paypal_email'] ?? ($input['paypal_email'] ?? '');
        if (!$paypalEmail || !filter_var($paypalEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success'=>false,'message'=>'E-mail PayPal inválido']); exit;
        }
        $details = ['paypal_email' => $paypalEmail];
    } else { // usdt_bep20
        $bscWallet = strtolower(trim($paymentDetails['bsc_wallet'] ?? ($input['bsc_wallet'] ?? '')));
        if (!validateWallet($bscWallet)) { echo json_encode(['success'=>false,'message'=>'Carteira BSC inválida']); exit; }
        $details = ['bsc_wallet' => $bscWallet];
    }

    $pdo->beginTransaction();

    // lock player
    $stmt = $pdo->prepare("SELECT id, balance_brl FROM players WHERE google_uid = ? FOR UPDATE");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Jogador não encontrado']);
        exit;
    }

    $balance = (float)$player['balance_brl'];
    if ($amount <= 0 || $amount > $balance) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Saldo insuficiente', 'current_balance' => $balance]);
        exit;
    }

    // limite semanal (se tiver constantes)
    $weeklyLimit = defined('WEEKLY_WITHDRAW_LIMIT') ? (int)WEEKLY_WITHDRAW_LIMIT : 1;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM withdrawals
        WHERE google_uid = ?
          AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
          AND status NOT IN ('rejected')
    ");
    $stmt->execute([$googleUid]);
    $weeklyCount = (int)$stmt->fetchColumn();

    if ($weeklyCount >= $weeklyLimit) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Limite semanal atingido.']);
        exit;
    }

    // criar withdrawal (wallet_address NULL)
    $stmt = $pdo->prepare("
        INSERT INTO withdrawals (
            player_id, google_uid, wallet_address,
            amount_usdt, amount_brl,
            payment_method, payment_details,
            status, created_at
        ) VALUES (?, ?, NULL, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        (int)$player['id'],
        $googleUid,
        $amount, // compat amount_usdt
        $amount,
        $paymentMethod,
        json_encode($details)
    ]);

    $withdrawalId = (int)$pdo->lastInsertId();

    // debitar saldo
    $newBalance = $balance - $amount;
    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_brl = ?, total_withdrawn_brl = total_withdrawn_brl + ?
        WHERE id = ?
    ");
    $stmt->execute([$newBalance, $amount, (int)$player['id']]);

    // registrar transaction (wallet NULL)
    $stmt = $pdo->prepare("
        INSERT INTO transactions (google_uid, wallet_address, type, amount, amount_brl, description, status, created_at)
        VALUES (?, NULL, 'withdraw', ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $googleUid,
        $amount,
        $amount,
        "Saque {$paymentMethod} - #{$withdrawalId}"
    ]);

    $pdo->commit();

    if (function_exists('secureLog')) secureLog("WITHDRAW_REQUEST | UID: {$googleUid} | Amount: {$amount} | Method: {$paymentMethod} | #{$withdrawalId}");

    echo json_encode([
        'success' => true,
        'message' => 'Solicitação enviada com sucesso',
        'withdrawal_id' => $withdrawalId,
        'amount_brl' => $amount,
        'payment_method' => $paymentMethod,
        'new_balance' => $newBalance,
        'status' => 'pending'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('secureLog')) secureLog("WITHDRAW_ERROR | " . $e->getMessage());
    error_log("withdraw.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro no servidor']);
}
