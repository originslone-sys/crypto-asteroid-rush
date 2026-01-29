<?php
// ============================================
// UNOBIX - Solicitação de Saque
// Arquivo: api/withdraw.php
// v2.0 - PIX + PayPal + USDT BEP20 + BRL
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// ============================================
// LER INPUT
// ============================================
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = [];

// Suporte híbrido para entrada
$googleUid = $input['google_uid'] ?? $_POST['google_uid'] ?? $_GET['google_uid'] ?? '';
$wallet = $input['wallet'] ?? $_POST['wallet'] ?? $_GET['wallet'] ?? '';
$amountRaw = $input['amount'] ?? $_POST['amount'] ?? $_GET['amount'] ?? 0;
$paymentMethod = $input['payment_method'] ?? $_POST['payment_method'] ?? 'pix';
$paymentDetails = $input['payment_details'] ?? $_POST['payment_details'] ?? [];

$googleUid = trim($googleUid);
$wallet = trim(strtolower($wallet));
$amount = (float)$amountRaw;
$paymentMethod = strtolower(trim($paymentMethod));

// Validar método de pagamento
if (!in_array($paymentMethod, WITHDRAW_METHODS)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Método de pagamento inválido. Use: ' . implode(', ', WITHDRAW_METHODS)
    ]);
    exit;
}

// Validar detalhes do pagamento por método
$paymentDetailsJson = null;
switch ($paymentMethod) {
    case 'pix':
        $pixKey = $paymentDetails['pix_key'] ?? $input['pix_key'] ?? '';
        $pixKeyType = $paymentDetails['pix_key_type'] ?? $input['pix_key_type'] ?? 'cpf';
        
        if (empty($pixKey)) {
            echo json_encode(['success' => false, 'message' => 'Chave PIX é obrigatória']);
            exit;
        }
        
        // Validações básicas de chave PIX
        $pixKeyType = strtolower($pixKeyType);
        if (!in_array($pixKeyType, ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'])) {
            $pixKeyType = 'aleatoria';
        }
        
        $paymentDetailsJson = json_encode([
            'pix_key' => $pixKey,
            'pix_key_type' => $pixKeyType
        ]);
        break;
        
    case 'paypal':
        $paypalEmail = $paymentDetails['paypal_email'] ?? $input['paypal_email'] ?? '';
        
        if (empty($paypalEmail) || !filter_var($paypalEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'E-mail PayPal inválido']);
            exit;
        }
        
        $paymentDetailsJson = json_encode([
            'paypal_email' => $paypalEmail
        ]);
        break;
        
    case 'usdt_bep20':
        $bscWallet = $paymentDetails['bsc_wallet'] ?? $input['bsc_wallet'] ?? $wallet;
        
        if (!validateWallet($bscWallet)) {
            echo json_encode(['success' => false, 'message' => 'Carteira BSC inválida']);
            exit;
        }
        
        $paymentDetailsJson = json_encode([
            'bsc_wallet' => strtolower($bscWallet)
        ]);
        break;
}

// Determinar identificador
$identifier = '';
$identifierType = '';

if (!empty($googleUid) && validateGoogleUid($googleUid)) {
    $identifier = $googleUid;
    $identifierType = 'google_uid';
} elseif (!empty($wallet) && validateWallet($wallet)) {
    $identifier = $wallet;
    $identifierType = 'wallet';
} else {
    echo json_encode(['success' => false, 'message' => 'Identificação inválida. Faça login novamente.']);
    exit;
}

// Validar valor mínimo
if ($amount < MIN_WITHDRAW_BRL) {
    echo json_encode([
        'success' => false, 
        'message' => "Valor mínimo para saque: R$ " . number_format(MIN_WITHDRAW_BRL, 2, ',', '.')
    ]);
    exit;
}

// Validar valor máximo
if ($amount > MAX_WITHDRAW_BRL) {
    echo json_encode([
        'success' => false, 
        'message' => "Valor máximo para saque: R$ " . number_format(MAX_WITHDRAW_BRL, 2, ',', '.')
    ]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco']);
        exit;
    }

    // ============================================
    // 1. USAR STORED PROCEDURE PARA VALIDAR
    // ============================================
    try {
        $stmt = $pdo->prepare("CALL sp_can_withdraw(?, ?)");
        $stmt->execute([$identifier, $amount]);
        $canWithdraw = $stmt->fetch();
        $stmt->closeCursor();
        
        if (!$canWithdraw['can_withdraw']) {
            echo json_encode([
                'success' => false,
                'message' => $canWithdraw['message'],
                'current_balance' => (float)$canWithdraw['current_balance'],
                'weekly_requests' => (int)$canWithdraw['weekly_requests']
            ]);
            exit;
        }
    } catch (Exception $e) {
        // Fallback: validação manual
        secureLog("SP_CAN_WITHDRAW_ERROR | " . $e->getMessage());
    }

    $pdo->beginTransaction();

    // ============================================
    // 2. BUSCAR JOGADOR (com lock)
    // ============================================
    $whereClause = $identifierType === 'google_uid' 
        ? "google_uid = ?" 
        : "wallet_address = ?";
    
    $stmt = $pdo->prepare("
        SELECT id, google_uid, wallet_address, balance_brl, email
        FROM players
        WHERE {$whereClause}
        FOR UPDATE
    ");
    $stmt->execute([$identifier]);
    $player = $stmt->fetch();

    if (!$player) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Jogador não encontrado.']);
        exit;
    }

    if ((float)$player['balance_brl'] < $amount) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'message' => 'Saldo insuficiente.',
            'current_balance' => (float)$player['balance_brl']
        ]);
        exit;
    }

    // ============================================
    // 3. VERIFICAR LIMITE SEMANAL (1 saque por semana)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM withdrawals
        WHERE (google_uid = ? OR wallet_address = ?)
        AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND status NOT IN ('rejected')
    ");
    $stmt->execute([$player['google_uid'], $player['wallet_address']]);
    $weeklyCount = $stmt->fetch();

    if ((int)$weeklyCount['count'] >= WEEKLY_WITHDRAW_LIMIT) {
        $pdo->rollBack();
        
        // Calcular tempo de espera
        $stmt = $pdo->prepare("
            SELECT created_at FROM withdrawals
            WHERE (google_uid = ? OR wallet_address = ?)
            AND status NOT IN ('rejected')
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$player['google_uid'], $player['wallet_address']]);
        $lastWithdraw = $stmt->fetch();
        
        $waitDays = 7;
        if ($lastWithdraw) {
            $daysSince = (time() - strtotime($lastWithdraw['created_at'])) / 86400;
            $waitDays = max(1, ceil(7 - $daysSince));
        }
        
        echo json_encode([
            'success' => false, 
            'message' => "Limite semanal atingido. Aguarde {$waitDays} dia(s) para solicitar novo saque.",
            'wait_days' => $waitDays
        ]);
        exit;
    }

    // ============================================
    // 4. CRIAR SOLICITAÇÃO DE SAQUE
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO withdrawals (
            player_id, 
            google_uid,
            wallet_address, 
            amount_usdt,
            amount_brl,
            payment_method,
            payment_details,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        (int)$player['id'],
        $player['google_uid'],
        $player['wallet_address'],
        $amount, // amount_usdt para compatibilidade
        $amount,
        $paymentMethod,
        $paymentDetailsJson
    ]);

    $withdrawalId = $pdo->lastInsertId();

    // ============================================
    // 5. DEDUZIR SALDO DO JOGADOR
    // ============================================
    $newBalance = (float)$player['balance_brl'] - $amount;

    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_brl = ?,
            total_withdrawn_brl = total_withdrawn_brl + ?
        WHERE id = ?
    ");
    $stmt->execute([$newBalance, $amount, (int)$player['id']]);

    // ============================================
    // 6. REGISTRAR TRANSAÇÃO
    // ============================================
    $methodLabel = [
        'pix' => 'PIX',
        'paypal' => 'PayPal',
        'usdt_bep20' => 'USDT BEP20'
    ][$paymentMethod];
    
    $pdo->prepare("
        INSERT INTO transactions (
            google_uid, wallet_address, type, amount, amount_brl, 
            description, status, created_at
        ) VALUES (?, ?, 'withdraw', ?, ?, ?, 'pending', NOW())
    ")->execute([
        $player['google_uid'],
        $player['wallet_address'],
        $amount,
        $amount,
        "Saque via {$methodLabel} - #{$withdrawalId}"
    ]);

    $pdo->commit();

    // Log de segurança
    secureLog("WITHDRAW_REQUEST | ID: {$identifier} | Amount: R$ {$amount} | Method: {$paymentMethod} | Withdrawal: #{$withdrawalId}");

    // ============================================
    // RESPOSTA
    // ============================================
    $processingInfo = "Processamento nos dias " . PROCESSING_START_DAY . " a " . PROCESSING_END_DAY . " de cada mês";
    
    echo json_encode([
        'success' => true,
        'message' => 'Solicitação de saque enviada com sucesso!',
        'withdrawal_id' => $withdrawalId,
        'amount_brl' => $amount,
        'payment_method' => $paymentMethod,
        'new_balance' => $newBalance,
        'processing_info' => $processingInfo,
        'status' => 'pending'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    secureLog("WITHDRAW_ERROR | ID: {$identifier} | Error: " . $e->getMessage());
    error_log("Erro no withdraw.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no servidor. Tente novamente.']);
}
