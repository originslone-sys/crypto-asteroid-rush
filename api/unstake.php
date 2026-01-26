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
if (empty($wallet) || !validateWallet($wallet)) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}
$pdo = getDatabaseConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
    exit;
}
try {
    // Buscar stake ativo
    $stmt = $pdo->prepare("
        SELECT id, amount, total_earned, created_at FROM stakes
        WHERE wallet_address = :wallet AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([':wallet' => $wallet]);
    $stake = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stake) {
        echo json_encode(['success' => false, 'error' => 'Stake não encontrado ou já processado']);
        exit;
    }
    
    // Converter amounts de unidades para USDT
    $amountUsdt = (float)$stake['amount'] / 100000000;
    $totalEarnedUsdt = (float)$stake['total_earned'] / 100000000;
    
    // Calcular ganhos extras desde última atualização
    $startTime = strtotime($stake['created_at']);
    $now = time();
    $hoursPassed = ($now - $startTime) / 3600;
    $hourlyRate = STAKE_APY / (365 * 24);
    $earnedExtraUsdt = $amountUsdt * $hourlyRate * $hoursPassed;
    
    // Total a receber
    $totalToReceive = $amountUsdt + $totalEarnedUsdt + $earnedExtraUsdt;
    
    // Atualizar saldo do jogador
    $stmt = $pdo->prepare("SELECT balance_usdt FROM players WHERE wallet_address = :wallet");
    $stmt->execute([':wallet' => $wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($player) {
        $currentBalanceUsdt = (float)$player['balance_usdt'];
        $newBalanceUsdt = $currentBalanceUsdt + $totalToReceive;
        
        $stmt = $pdo->prepare("
            UPDATE players
            SET balance_usdt = :balance
            WHERE wallet_address = :wallet
        ");
        $stmt->execute([
            ':balance' => $newBalanceUsdt,
            ':wallet' => $wallet
        ]);
    }
    
    // Marcar stake como completed
    $stmt = $pdo->prepare("
        UPDATE stakes
        SET status = 'completed',
            total_earned = :total_earned,
            completed_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':total_earned' => $totalEarnedUsdt + $earnedExtraUsdt,
        ':id' => $stake['id']
    ]);
    
    // Registrar transação
    $stmt = $pdo->prepare("
        INSERT INTO transactions (wallet_address, type, amount, description, status, created_at)
        VALUES (:wallet, 'unstake', :amount, 'Unstake realizado', 'completed', NOW())
    ");
    $stmt->execute([
        ':wallet' => $wallet,
        ':amount' => $totalToReceive
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Saque solicitado! O valor será processado.',
        'amount_staked' => $amountUsdt,
        'earnings' => $totalEarnedUsdt + $earnedExtraUsdt,
        'net_amount' => $totalToReceive,
        'processing_time' => '48h'
    ]);
    
} catch(PDOException $e) {
    error_log("Erro ao processar unstake: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar unstake']);
}
?>