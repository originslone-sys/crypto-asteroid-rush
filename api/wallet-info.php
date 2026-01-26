<?php
require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================
// Ler entrada (JSON + POST + GET)
// ===============================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet = $input['wallet']
    ?? $_POST['wallet']
    ?? $_GET['wallet']
    ?? '';

$wallet = trim(strtolower($wallet));

// ===============================
// Validação
// ===============================
if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
    echo json_encode([
        'success' => false,
        'error' => 'Carteira inválida'
    ]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception('Erro ao conectar ao banco');
    }

    // ===============================
    // Player
    // ===============================
    $stmt = $pdo->prepare("
        SELECT id, balance_usdt, total_played
        FROM players
        WHERE wallet_address = ?
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        echo json_encode([
            'success' => true,
            'player_exists' => false,
            'balance_usdt' => 0,
            'total_played' => 0,
            'transactions' => []
        ]);
        exit;
    }

    // ===============================
    // Transações
    // ===============================
    $stmt = $pdo->prepare("
        SELECT
            type,
            amount_usdt,
            created_at
        FROM transactions
        WHERE wallet_address = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$wallet]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===============================
    // Resposta
    // ===============================
    echo json_encode([
        'success' => true,
        'player_exists' => true,
        'balance_usdt' => (float)$player['balance_usdt'],
        'total_played' => (int)$player['total_played'],
        'transactions' => $transactions
    ]);

} catch (Exception $e) {
    error_log("wallet-info error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno'
    ]);
}
