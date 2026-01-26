<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
require_once 'config.php';
$data = json_decode(file_get_contents('php://input'), true);
$wallet = isset($data['wallet']) ? trim($data['wallet']) : '';
$amount = isset($data['amount']) ? floatval($data['amount']) : 0;

// Validações
if (empty($wallet) || !validateWallet($wallet)) {
    echo json_encode(['success' => false, 'error' => 'Wallet inválida']);
    exit;
}
if ($amount < MIN_STAKE_AMOUNT) {
    echo json_encode(['success' => false, 'error' => 'Valor mínimo: $' . MIN_STAKE_AMOUNT . ' USDT']);
    exit;
}

$pdo = getDatabaseConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
    exit;
}

try {
    // 1. Verificar saldo do usuário
    $stmt = $pdo->prepare("SELECT balance_usdt FROM players WHERE wallet_address = :wallet");
    $stmt->execute([':wallet' => $wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
        exit;
    }
    
    $currentBalanceUsdt = (float)$player['balance_usdt'];
    if ($currentBalanceUsdt < $amount) {
        echo json_encode(['success' => false, 'error' => 'Saldo insuficiente. Disponível: $' . number_format($currentBalanceUsdt, 6)]);
        exit;
    }
    
    // 2. Verificar se já tem stake ativo
    $stmt = $pdo->prepare("
        SELECT id, amount FROM stakes
        WHERE wallet_address = :wallet AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([':wallet' => $wallet]);
    $existingStake = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Garantir data/hora atual válida
    $currentDateTime = date('Y-m-d H:i:s');
    
    // Converter para unidades de armazenamento (8 casas decimais)
    $amountInUnits = (int)round($amount * 100000000);
    
    if ($existingStake) {
        // Adicionar ao stake existente (já está em unidades)
        $newAmountUnits = $existingStake['amount'] + $amountInUnits;
        $existingAmountUsdt = $existingStake['amount'] / 100000000;
        
        $stmt = $pdo->prepare("
            UPDATE stakes
            SET amount = :amount,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            ':amount' => $newAmountUnits,
            ':updated_at' => $currentDateTime,
            ':id' => $existingStake['id']
        ]);
        
        $stakeId = $existingStake['id'];
        $totalStaked = $newAmountUnits / 100000000;
        $message = 'Valor adicionado ao stake existente';
    } else {
        // Criar novo stake em unidades
        $stmt = $pdo->prepare("
            INSERT INTO stakes (wallet_address, amount, apy, status, created_at, updated_at)
            VALUES (:wallet, :amount, :apy, 'active', :created_at, :updated_at)
        ");
        $stmt->execute([
            ':wallet' => $wallet,
            ':amount' => $amountInUnits,
            ':apy' => STAKE_APY,
            ':created_at' => $currentDateTime,
            ':updated_at' => $currentDateTime
        ]);
        
        $stakeId = $pdo->lastInsertId();
        $totalStaked = $amount;
        $message = 'Stake criado com sucesso!';
    }
    
    // 3. Deduzir do saldo do jogador (em USDT normal)
    $newBalanceUsdt = $currentBalanceUsdt - $amount;
    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_usdt = :balance
        WHERE wallet_address = :wallet
    ");
    $stmt->execute([
        ':balance' => $newBalanceUsdt,
        ':wallet' => $wallet
    ]);
    
    // 4. Registrar transação (em USDT normal)
    $stmt = $pdo->prepare("
        INSERT INTO transactions (wallet_address, type, amount, description, status, created_at)
        VALUES (:wallet, 'stake', :amount, 'Stake criado/atualizado', 'completed', NOW())
    ");
    $stmt->execute([
        ':wallet' => $wallet,
        ':amount' => $amount
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'stake_id' => $stakeId,
        'amount_staked' => $amount,
        'total_staked' => $totalStaked,
        'new_balance' => $newBalanceUsdt
    ]);
    
} catch(PDOException $e) {
    error_log("Erro ao criar stake: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar stake']);
}
?>