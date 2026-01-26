<?php
// api/withdraw.php — solicita saque

require_once "../config.php";

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$wallet = $input['wallet'] ?? '';
$amount = (float)($input['amount'] ?? 0);

if (!$wallet || !preg_match('/^0x[a-f0-9]{40}$/i', $wallet) || $amount < 1) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos. Mínimo: 1 USDT.']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verifica saldo
    $stmt = $pdo->prepare("SELECT id, balance_usdt FROM players WHERE wallet_address = LOWER(?)");
    $stmt->execute([strtolower($wallet)]);
    $player = $stmt->fetch();
    
    if (!$player) {
        echo json_encode(['success' => false, 'message' => 'Jogador não encontrado.']);
        exit;
    }
    
    if ($player['balance_usdt'] < $amount) {
        echo json_encode(['success' => false, 'message' => 'Saldo insuficiente para saque.']);
        exit;
    }
    
    // Cria solicitação de saque
    $pdo->prepare("INSERT INTO withdrawals (player_id, wallet_address, amount_usdt) VALUES (?, ?, ?)")
        ->execute([$player['id'], strtolower($wallet), $amount]);
    
    // Atualiza saldo (deduz valor)
    $newBalance = $player['balance_usdt'] - $amount;
    $pdo->prepare("UPDATE players SET balance_usdt = ? WHERE id = ?")
        ->execute([$newBalance, $player['id']]);
    
    echo json_encode(['success' => true, 'message' => 'Solicitação de saque enviada! Aguarde aprovação.']);
    
} catch (Exception $e) {
    error_log("Erro no withdraw.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no servidor. Tente novamente.']);
}
?>