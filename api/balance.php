<?php
require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true]);
    exit;
}

// Input (JSON + POST + GET)
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

function pick_first($arr, $keys) {
    foreach ($keys as $k) {
        if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
    }
    return null;
}

$wallet =
    pick_first($input, ['wallet','wallet_address','walletAddress','address']) ??
    pick_first($_POST, ['wallet','wallet_address','walletAddress','address']) ??
    pick_first($_GET,  ['wallet','wallet_address','walletAddress','address']) ??
    '';

$wallet = trim(strtolower($wallet));

// Debug opcional: /api/balance.php?debug=1
$debug = (isset($_GET['debug']) && $_GET['debug'] == '1');

if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    $resp = ['success' => false, 'balance' => '0.00000000', 'error' => 'Invalid wallet'];
    if ($debug) {
        $resp['debug'] = [
            'received_wallet' => $wallet,
            'content_type' => ($_SERVER['CONTENT_TYPE'] ?? ''),
            'raw_len' => strlen($raw),
            'input_keys' => array_keys($input),
            'get_keys' => array_keys($_GET),
            'post_keys' => array_keys($_POST),
        ];
    }
    echo json_encode($resp);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("DB connection failed");

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
    echo json_encode(['success' => false, 'balance' => '0.00000000', 'error' => 'Server error']);
}
?>
