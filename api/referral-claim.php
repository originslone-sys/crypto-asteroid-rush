<?php
// ============================================
// CRYPTO ASTEROID RUSH - Resgatar Comissões
// Arquivo: api/referral-claim.php
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true]);
    exit;
}

// Entrada híbrida: JSON + POST + GET
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
if ($wallet === '') $wallet = isset($_POST['wallet']) ? trim(strtolower($_POST['wallet'])) : $wallet;
if ($wallet === '') $wallet = isset($_GET['wallet']) ? trim(strtolower($_GET['wallet'])) : $wallet;

// Validar wallet
if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Iniciar transação
    $pdo->beginTransaction();

    // ============================================
    // 1. BUSCAR COMISSÕES DISPONÍVEIS (LOCK)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, commission_amount, referred_wallet
        FROM referrals
        WHERE referrer_wallet = ?
          AND status = 'completed'
        FOR UPDATE
    ");
    $stmt->execute([$wallet]);
    $pendingCommissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendingCommissions)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Nenhuma comissão disponível para resgate']);
        exit;
    }

    // Calcular total
    $totalAmount = 0;
    $referralIds = array();

    foreach ($pendingCommissions as $commission) {
        $totalAmount += (float)$commission['commission_amount'];
        $referralIds[] = $commission['id'];
    }

    // ============================================
    // 2. ATUALIZAR STATUS DOS REFERRALS
    // ============================================
    $placeholders = implode(',', array_fill(0, count($referralIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE referrals
        SET status = 'claimed', claimed_at = NOW()
        WHERE id IN ({$placeholders})
    ");
    $stmt->execute($referralIds);

    // ============================================
    // 3. ADICIONAR AO BALANCE DO JOGADOR
    // ============================================
    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_usdt = balance_usdt + ?
        WHERE wallet_address = ?
    ");
    $stmt->execute([$totalAmount, $wallet]);

    // Verificar se atualizou algum registro
    if ($stmt->rowCount() === 0) {
        // Jogador não existe, criar
        $stmt = $pdo->prepare("
            INSERT INTO players (wallet_address, balance_usdt, total_played)
            VALUES (?, ?, 0)
        ");
        $stmt->execute([$wallet, $totalAmount]);
    }

    // ============================================
    // 4. REGISTRAR TRANSAÇÃO (schema confirmado)
    // ============================================
    $tableExists = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();

    if ($tableExists) {
        $description = 'Comissão de afiliados (' . count($referralIds) . ' indicações)';

        $stmt = $pdo->prepare("
            INSERT INTO transactions
            (wallet_address, type, amount, description, status, created_at)
            VALUES (?, 'referral_commission', ?, ?, 'completed', NOW())
        ");
        $stmt->execute([$wallet, $totalAmount, $description]);
    }

    // ============================================
    // 5. BUSCAR NOVO SALDO
    // ============================================
    $stmt = $pdo->prepare("SELECT balance_usdt FROM players WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $newBalance = (float)$stmt->fetchColumn();

    // Commit
    $pdo->commit();

    // Log para debug
    error_log("Comissão resgatada: Wallet={$wallet}, Amount={$totalAmount}, Referrals=" . implode(',', $referralIds));

    echo json_encode([
        'success' => true,
        'message' => 'Comissões resgatadas com sucesso!',
        'amount_claimed' => number_format($totalAmount, 2, '.', ''),
        'referrals_claimed' => count($referralIds),
        'new_balance' => number_format($newBalance, 8, '.', '')
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro em referral-claim.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro no servidor: ' . $e->getMessage()]);
}
?>
