<?php
// api/balance.php — Retorna saldo do jogador

require_once "../config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$wallet = $_GET['wallet'] ?? '';
if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    echo json_encode(['success' => false, 'balance' => '0.00000000']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $stmt = $pdo->prepare("SELECT balance_usdt FROM players WHERE wallet_address = LOWER(?)");
    $stmt->execute([strtolower($wallet)]);
    $player = $stmt->fetch();
    
    $balance = $player ? (float)$player['balance_usdt'] : 0.0;
    echo json_encode(['success' => true, 'balance' => number_format($balance, 8, '.', '')]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'balance' => '0.00000000']);
}
?>