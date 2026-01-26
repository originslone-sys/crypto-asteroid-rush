<?php
// api/stake.php — (Opção A) Mesma lógica do stake, apenas corrigido para Railway:
// - require com __DIR__
// - entrada híbrida JSON/POST/GET
// - locks + transação (consistência)
// - insert em stakes compatível com schema (total_earned, completed_at)
// - transactions com schema variável (amount_usdt vs amount, created_at vs date)

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

$amountRaw =
    $input['amount'] ??
    $_POST['amount'] ??
    $_GET['amount'] ??
    0;

$amount = floatval($amountRaw);

// Normaliza wallet para bater com DB
$wallet = strtolower($wallet);

// Validações (preservadas)
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
    // Schema variável (principalmente transactions)
    $txAmountCol  = pickColumn($pdo, 'transactions', ['amount_usdt', 'amount']);
    $txDateCol    = pickColumn($pdo, 'transactions', ['created_at', 'date']);

    $pdo->beginTransaction();

    // 1) Lock do player e saldo
    $stmt = $pdo->prepare("
        SELECT balance_usdt
        FROM players
        WHERE wallet_address = :wallet
        FOR UPDATE
    ");
    $stmt->execute([':wallet' => $wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
        exit;
    }

    $currentBalanceUsdt = (float)$player['balance_usdt'];
    if ($currentBalanceUsdt < $amount) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Saldo insuficiente. Disponível: $' . number_format($currentBalanceUsdt, 6)
        ]);
        exit;
    }

    // 2) Lock do stake ativo (se existir)
    $stmt = $pdo->prepare("
        SELECT id, amount
        FROM stakes
        WHERE wallet_address = :wallet AND status = 'active'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':wallet' => $wallet]);
    $existingStake = $stmt->fetch(PDO::FETCH_ASSOC);

    $currentDateTime = date('Y-m-d H:i:s');

    // Unidades (8 casas) para stakes.amount
    $amountInUnits = (int)round($amount * 100000000);

    if ($existingStake) {
        // Soma no stake existente (amount já em unidades)
        $newAmountUnits = (int)$existingStake['amount'] + $amountInUnits;

        $stmt = $pdo->prepare("
            UPDATE stakes
            SET amount = :amount,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            ':amount' => $newAmountUnits,
            ':updated_at' => $currentDateTime,
            ':id' => (int)$existingStake['id']
        ]);

        $stakeId = (int)$existingStake['id'];
        $totalStaked = $newAmountUnits / 100000000;
        $message = 'Valor adicionado ao stake existente';
    } else {
        // ✅ Schema Railway confirmado (stakes tem total_earned e completed_at)
        $stmt = $pdo->prepare("
            INSERT INTO stakes (wallet_address, amount, apy, total_earned, status, created_at, updated_at, completed_at)
            VALUES (:wallet, :amount, :apy, 0, 'active', :created_at, :updated_at, NULL)
        ");
        $stmt->execute([
            ':wallet' => $wallet,
            ':amount' => $amountInUnits,
            ':apy' => STAKE_APY,
            ':created_at' => $currentDateTime,
            ':updated_at' => $currentDateTime
        ]);

        $stakeId = (int)$pdo->lastInsertId();
        $totalStaked = $amount;
        $message = 'Stake criado com sucesso!';
    }

    // 3) Deduz saldo do player (USDT normal)
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

    // 4) Registrar transação (schema variável)
    if ($txAmountCol !== null && $txDateCol !== null) {
        $stmt = $pdo->prepare("
            INSERT INTO transactions (wallet_address, type, `$txAmountCol`, description, status, `$txDateCol`)
            VALUES (:wallet, 'stake', :amount, 'Stake criado/atualizado', 'completed', NOW())
        ");
        $stmt->execute([
            ':wallet' => $wallet,
            ':amount' => $amount
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $message,
        'stake_id' => $stakeId,
        'amount_staked' => $amount,
        'total_staked' => $totalStaked,
        'new_balance' => $newBalanceUsdt
    ]);

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro ao criar stake: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar stake']);
}
?>
