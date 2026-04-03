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

// Apenas CPF é aceito
$pixKeyType = 'cpf';
// Normalizar CPF: remover pontuação
$paymentDetails = preg_replace('/\D/', '', $paymentDetails);

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
    echo json_encode(['success' => false, 'error' => 'CPF inválido. Verifique o número informado.']);
    exit;
}

// Verificar se este CPF já está sendo usado por outra conta
try {
    $pdoCheck = getDatabaseConnection();
    if ($pdoCheck) {
        $cpfCheck = $pdoCheck->prepare("
            SELECT spk.user_id FROM saved_pix_keys spk
            INNER JOIN users u ON u.id = spk.user_id
            WHERE spk.pix_key = ? AND spk.pix_key_type = 'cpf' AND u.google_uid != ?
            LIMIT 1
        ");
        $cpfCheck->execute([$paymentDetails, $googleUid]);
        if ($cpfCheck->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Este CPF já está cadastrado em outra conta. Cada CPF só pode ser vinculado a uma conta.']);
            exit;
        }
    }
} catch (Throwable $e) {
    // não bloquear saque por falha de checagem de CPF duplicado
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

    // Bloquear nova solicitação enquanto houver saque pendente/em processamento
    $stmtPending = $pdo->prepare("SELECT id, amount_brl, created_at FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'processing') LIMIT 1");
    $stmtPending->execute([(int)$player['id']]);
    $pendingWithdraw = $stmtPending->fetch();

    if ($pendingWithdraw) {
        $pdo->rollBack();
        $amountFmt = 'R$ ' . number_format((float)$pendingWithdraw['amount_brl'], 2, ',', '.');
        echo json_encode([
            'success'              => false,
            'error'                => "Você já tem um saque de {$amountFmt} aguardando processamento. Aguarde a conclusão antes de solicitar outro.",
            'has_pending'          => true,
            'pending_withdrawal_id'=> (int)$pendingWithdraw['id']
        ]);
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

    // Auto-registrar CPF em saved_pix_keys (garante checagem de duplicidade cross-account futura)
    try {
        $existsKey = $pdo->prepare("SELECT id FROM saved_pix_keys WHERE user_id = ? AND pix_key_type = 'cpf' LIMIT 1");
        $existsKey->execute([(int)$player['id']]);
        if (!$existsKey->fetch()) {
            $pdo->prepare("
                INSERT IGNORE INTO saved_pix_keys (user_id, pix_key, pix_key_type, created_at)
                VALUES (?, ?, 'cpf', NOW())
            ")->execute([(int)$player['id'], $paymentDetails]);
        }
    } catch (Throwable $e) {
        // não bloquear o saque por falha no auto-save
    }

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
