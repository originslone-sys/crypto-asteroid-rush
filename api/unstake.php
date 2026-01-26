<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true]);
    exit;
}

require_once __DIR__ . '/config.php';

// Entrada híbrida: JSON + POST + GET
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet =
    (isset($input['wallet']) ? trim($input['wallet']) : '') ?:
    (isset($_POST['wallet']) ? trim($_POST['wallet']) : '') ?:
    (isset($_GET['wallet']) ? trim($_GET['wallet']) : '');

// Normaliza para bater com DB
$wallet = strtolower($wallet);

if (empty($wallet) || !validateWallet($wallet)) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$pdo = getDatabaseConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
    exit;
}

// Helper: detectar colunas dinamicamente (compat cPanel/Railway)
function pickColumn(PDO $pdo, string $table, array $candidates): ?string {
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[$row['Field']] = true;
    }
    foreach ($candidates as $c) {
        if (isset($cols[$c])) return $c;
    }
    return null;
}

try {
    // Schema variável de transactions
    $txAmountCol = pickColumn($pdo, 'transactions', ['amount_usdt', 'amount']);
    $txDateCol   = pickColumn($pdo, 'transactions', ['created_at', 'date']);

    $pdo->beginTransaction();

    // Buscar stake ativo (LOCK)
    $stmt = $pdo->prepare("
        SELECT id, amount, total_earned, created_at
        FROM stakes
        WHERE wallet_address = :wallet AND status = 'active'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':wallet' => $wallet]);
    $stake = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stake) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Stake não encontrado ou já processado']);
        exit;
    }

    // Converter amounts de unidades para USDT
    $amountUsdt = (float)$stake['amount'] / 100000000;
    $totalEarnedUsdt = (float)$stake['total_earned'] / 100000000;

    // Calcular ganhos extras desde o início (mantém lógica original)
    $startTime = strtotime($stake['created_at']);
    $now = time();
    $hoursPassed = ($now - $startTime) / 3600;

    $hourlyRate = STAKE_APY / (365 * 24);
    $earnedExtraUsdt = $amountUsdt * $hourlyRate * $hoursPassed;

    // Total a receber
    $totalToReceive = $amountUsdt + $totalEarnedUsdt + $earnedExtraUsdt;

    // Atualizar saldo do jogador (LOCK)
    $stmt = $pdo->prepare("
        SELECT balance_usdt
        FROM players
        WHERE wallet_address = :wallet
        FOR UPDATE
    ");
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

    // ✅ Corrige unidade: total_earned no DB é em UNIDADES (1e8)
    $newTotalEarnedUsdt = $totalEarnedUsdt + $earnedExtraUsdt;
    $newTotalEarnedUnits = (int)round($newTotalEarnedUsdt * 100000000);

    // Marcar stake como completed
    $stmt = $pdo->prepare("
        UPDATE stakes
        SET status = 'completed',
            total_earned = :total_earned,
            completed_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':total_earned' => $newTotalEarnedUnits,
        ':id' => (int)$stake['id']
    ]);

    // Registrar transação (schema variável)
    if ($txAmountCol !== null && $txDateCol !== null) {
        $stmt = $pdo->prepare("
            INSERT INTO transactions (wallet_address, type, `$txAmountCol`, description, status, `$txDateCol`)
            VALUES (:wallet, 'unstake', :amount, 'Unstake realizado', 'completed', NOW())
        ");
        $stmt->execute([
            ':wallet' => $wallet,
            ':amount' => $totalToReceive
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Saque solicitado! O valor será processado.',
        'amount_staked' => $amountUsdt,
        'earnings' => $newTotalEarnedUsdt,
        'net_amount' => $totalToReceive,
        'processing_time' => '48h'
    ]);

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro ao processar unstake: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar unstake']);
}
?>
