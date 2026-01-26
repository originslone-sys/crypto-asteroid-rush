<?php
// ============================================
// CRYPTO ASTEROID RUSH - Resgatar Comissões
// Arquivo: api/referral-claim.php
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================
// Input (JSON + POST)
// ===============================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet = $input['wallet'] ?? ($_POST['wallet'] ?? '');
$wallet = trim(strtolower($wallet));

// Validar wallet
if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Erro ao conectar ao banco');

    // Verificar tabelas essenciais
    $referralsExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
    $playersExists   = $pdo->query("SHOW TABLES LIKE 'players'")->fetch();

    if (!$referralsExists || !$playersExists) {
        echo json_encode(['success' => false, 'error' => 'Tabelas necessárias não encontradas']);
        exit;
    }

    $pdo->beginTransaction();

    // ============================================
    // 1. BUSCAR COMISSÕES DISPONÍVEIS (LOCK)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, commission_amount
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

    $totalAmount = 0.0;
    $referralIds = [];

    foreach ($pendingCommissions as $commission) {
        $totalAmount += (float)$commission['commission_amount'];
        $referralIds[] = (int)$commission['id'];
    }

    // ============================================
    // 2. ATUALIZAR STATUS PARA 'claimed'
    // ============================================
    $placeholders = implode(',', array_fill(0, count($referralIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE referrals
        SET status = 'claimed',
            claimed_at = NOW()
        WHERE id IN ({$placeholders})
    ");
    $stmt->execute($referralIds);

    // ============================================
    // 3. CREDITAR NO SALDO DO JOGADOR
    // ============================================
    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_usdt = balance_usdt + ?
        WHERE wallet_address = ?
    ");
    $stmt->execute([$totalAmount, $wallet]);

    if ($stmt->rowCount() === 0) {
        // Jogador não existe, criar
        $stmt = $pdo->prepare("
            INSERT INTO players (wallet_address, balance_usdt, total_played, created_at, updated_at)
            VALUES (?, ?, 0, NOW(), NOW())
        ");
        $stmt->execute([$wallet, $totalAmount]);
    }

    // ============================================
    // 4. REGISTRAR TRANSAÇÃO (SE EXISTIR)
    // ============================================
    $txExists = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    if ($txExists) {
        $cols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN, 0);

        $amountCol = in_array('amount_usdt', $cols, true) ? 'amount_usdt' : (in_array('amount', $cols, true) ? 'amount' : null);
        $dateCol   = in_array('created_at', $cols, true) ? 'created_at' : (in_array('date', $cols, true) ? 'date' : null);

        if ($amountCol && $dateCol) {
            $description = 'Comissão de afiliados (' . count($referralIds) . ' indicações)';

            // Monta insert respeitando schema real
            $fields = ['wallet_address', 'type', $amountCol, 'description', 'status', $dateCol];
            $sql = "INSERT INTO transactions (" . implode(',', $fields) . ") VALUES (?, 'referral_commission', ?, ?, 'completed', NOW())";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$wallet, $totalAmount, $description]);
        }
    }

    // ============================================
    // 5. BUSCAR NOVO SALDO
    // ============================================
    $stmt = $pdo->prepare("SELECT balance_usdt FROM players WHERE wallet_address = ? LIMIT 1");
    $stmt->execute([$wallet]);
    $newBalance = (float)$stmt->fetchColumn();

    $pdo->commit();

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
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
