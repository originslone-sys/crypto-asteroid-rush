<?php
// api/balance.php — Retorna saldo do jogador (Railway + Opção A)

require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true]);
    exit;
}

// Entrada híbrida: JSON + POST + GET
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet = $input['wallet'] ?? $_POST['wallet'] ?? $_GET['wallet'] ?? '';
$wallet = trim(strtolower($wallet));

if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    echo json_encode(['success' => false, 'balance' => '0.00000000']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("DB fail");

    $stmt = $pdo->prepare("SELECT balance_usdt FROM players WHERE wallet_address = ? LIMIT 1");
    $stmt->execute([$wallet]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $balance = $row ? (float)$row['balance_usdt'] : 0.0;

    echo json_encode([
        'success' => true,
        'balance' => number_format($balance, 8, '.', '')
    ]);
} catch (Exception $e) {
    error_log("balance.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'balance' => '0.00000000']);
}
?>
