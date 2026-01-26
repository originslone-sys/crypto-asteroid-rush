<?php
// api/balance.php — Retorna saldo do jogador (Opção A: lógica preservada + adaptado Railway)

require_once __DIR__ . "/config.php";

header('Content-Type: application/json');
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

$wallet =
    $input['wallet'] ??
    $_POST['wallet'] ??
    $_GET['wallet'] ??
    '';

if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    echo json_encode(['success' => false, 'balance' => '0.00000000']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Schema confirmado: players.balance_usdt
    $stmt = $pdo->prepare("SELECT balance_usdt FROM players WHERE wallet_address = LOWER(?)");
    $stmt->execute([strtolower($wallet)]);
    $player = $stmt->fetch();

    $balance = $player ? (float)$player['balance_usdt'] : 0.0;

    echo json_encode([
        'success' => true,
        'balance' => number_format($balance, 8, '.', '')
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'balance' => '0.00000000']);
}
?>
