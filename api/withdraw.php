<?php
// ============================================
// UNOBIX - Solicitação de Saque
// api/withdraw.php v8.0 - PIX only
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? '');
$amount = (float)($input['amount'] ?? $input['amount_brl'] ?? 0);
$paymentDetails = trim($input['payment_details'] ?? $input['pix_key'] ?? '');
$paymentMethod = strtolower(trim($input['payment_method'] ?? 'pix'));
$pixKeyType = strtolower(trim($input['pix_key_type'] ?? ''));

// Auto-detectar tipo de chave PIX se não informado
if ($paymentMethod === 'pix' && empty($pixKeyType)) {
    $pixKeyType = detectPixKeyType($paymentDetails);
}

// Para chave tipo telefone, garantir prefixo +55
if ($pixKeyType === 'phone' || $pixKeyType === 'celular') {
    $phoneDigits = preg_replace('/\D/', '', $paymentDetails);
    if (!str_starts_with($phoneDigits, '55')) {
        $paymentDetails = '+55' . $phoneDigits;
    } elseif (!str_starts_with($paymentDetails, '+')) {
        $paymentDetails = '+' . $phoneDigits;
    }
    $pixKeyType = 'phone';
}

// Validar google_uid
if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido. Faça login novamente.']);
    exit;
}

// Validar método de pagamento
if (!in_array($paymentMethod, WITHDRAW_METHODS)) {
    echo json_encode(['success' => false, 'error' => 'Método de pagamento inválido. Use: ' . implode(', ', WITHDRAW_METHODS)]);
    exit;
}

// Validar valor mínimo
if ($amount < MIN_WITHDRAW_BRL) {
    echo json_encode(['success' => false, 'error' => 'Valor mínimo: R$ ' . number_format(MIN_WITHDRAW_BRL, 2, ',', '.')]);
    exit;
}

// Validar chave PIX
if (empty($paymentDetails)) {
    echo json_encode(['success' => false, 'error' => 'Chave PIX é obrigatória']);
    exit;
}

if (!validatePixKey($paymentDetails, $pixKeyType)) {
    echo json_encode(['success' => false, 'error' => 'Chave PIX inválida para o tipo selecionado']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    $pdo->beginTransaction();

    // Buscar e bloquear player
    $stmt = $pdo->prepare("SELECT id, google_uid, balance_brl FROM users WHERE google_uid = ? FOR UPDATE");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch();

    if (!$player) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    $balance = (float)$player['balance_brl'];
    if ($amount <= 0 || $amount > $balance) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Saldo insuficiente', 'current_balance' => $balance]);
        exit;
    }

    // Criar withdrawal - usando colunas corretas da tabela
    $withdrawDetails = json_encode([
        'method' => 'pix',
        'details' => $paymentDetails,
        'pix_key_type' => $pixKeyType,
        'google_uid' => $googleUid
    ]);

    $methodLabel = 'PIX';

    $stmt = $pdo->prepare("
        INSERT INTO withdrawals (
            user_id,
            amount_brl,
            amount_usdt,
            wallet_address,
            status,
            admin_notes,
            created_at
        ) VALUES (?, ?, 0, ?, 'pending', ?, NOW())
    ");
    $stmt->execute([
        (int)$player['id'],
        $amount,
        $methodLabel, // Usando wallet_address para indicar método
        $withdrawDetails // Detalhes no campo admin_notes
    ]);

    $withdrawalId = (int)$pdo->lastInsertId();

    // Debitar saldo
    $newBalance = $balance - $amount;
    $stmt = $pdo->prepare("UPDATE users SET balance_brl = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newBalance, (int)$player['id']]);

    // Atualizar total_withdrawn_brl
    $stmt = $pdo->prepare("UPDATE users SET total_withdrawn_brl = total_withdrawn_brl + ? WHERE id = ?");
    $stmt->execute([$amount, (int)$player['id']]);

    // Registrar transação
    $stmt = $pdo->prepare("
        INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
        VALUES (?, 'withdraw', ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$googleUid, -abs($amount), -abs($amount), "Saque $methodLabel #$withdrawalId"]);

    $pdo->commit();

    secureLog("WITHDRAW_REQUEST | UID: $googleUid | Amount: R$$amount | ID: $withdrawalId");

    echo json_encode([
        'success' => true,
        'message' => 'Solicitação enviada com sucesso',
        'withdrawal_id' => $withdrawalId,
        'amount_brl' => $amount,
        'payment_method' => $paymentMethod,
        'new_balance' => round($newBalance, 6),
        'status' => 'pending',
        'estimated_processing' => 'Acompanhe o processamento em tempo real na fila de saques'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    secureLog("WITHDRAW_ERROR | " . $e->getMessage());
    error_log("withdraw.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
