<?php
// api/withdraw.php — solicita saque (Opção A: lógica preservada + compatível Railway)

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

$amountRaw =
    $input['amount'] ??
    $_POST['amount'] ??
    $_GET['amount'] ??
    0;

$amount = (float)$amountRaw;

if (
    !$wallet ||
    !preg_match('/^0x[a-f0-9]{40}$/i', $wallet) ||
    $amount < 1
) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos. Mínimo: 1 USDT.']);
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

    $pdo->beginTransaction();

    // 1) Lock do player para evitar corrida de saque/saldo
    $stmt = $pdo->prepare("
        SELECT id, balance_usdt
        FROM players
        WHERE wallet_address = LOWER(?)
        FOR UPDATE
    ");
    $stmt->execute([strtolower($wallet)]);
    $player = $stmt->fetch();

    if (!$player) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Jogador não encontrado.']);
        exit;
    }

    if ((float)$player['balance_usdt'] < $amount) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Saldo insuficiente para saque.']);
        exit;
    }

    // 2) Cria solicitação de saque (mantém colunas originais)
    $stmt = $pdo->prepare("
        INSERT INTO withdrawals (player_id, wallet_address, amount_usdt)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([
        (int)$player['id'],
        strtolower($wallet),
        $amount
    ]);

    // 3) Deduz saldo do player
    $newBalance = (float)$player['balance_usdt'] - $amount;

    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_usdt = ?
        WHERE id = ?
    ");
    $stmt->execute([$newBalance, (int)$player['id']]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Solicitação de saque enviada! Aguarde aprovação.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro no withdraw.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro no servidor. Tente novamente.']);
}
?>
