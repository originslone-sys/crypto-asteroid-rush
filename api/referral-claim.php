<?php
// ============================================
// UNOBIX - Resgatar Comissões de Referral
// Arquivo: api/referral-claim.php
// v2.0 - Google Auth + BRL
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// ===============================
// Input (JSON + POST)
// ===============================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$googleUid = $input['google_uid'] ?? ($_POST['google_uid'] ?? '');
$wallet = $input['wallet'] ?? ($_POST['wallet'] ?? '');

$googleUid = trim($googleUid);
$wallet = trim(strtolower($wallet));

// Determinar identificador
$identifier = '';
$identifierType = '';

if (!empty($googleUid) && validateGoogleUid($googleUid)) {
    $identifier = $googleUid;
    $identifierType = 'google_uid';
} elseif (!empty($wallet) && validateWallet($wallet)) {
    $identifier = $wallet;
    $identifierType = 'wallet';
} else {
    echo json_encode(['success' => false, 'error' => 'Identificação inválida']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Erro ao conectar ao banco');

    // Verificar tabelas
    $referralsExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
    $playersExists = $pdo->query("SHOW TABLES LIKE 'players'")->fetch();

    if (!$referralsExists || !$playersExists) {
        echo json_encode(['success' => false, 'error' => 'Sistema não configurado']);
        exit;
    }

    $pdo->beginTransaction();

    // ============================================
    // 1. BUSCAR COMISSÕES DISPONÍVEIS (LOCK)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, commission_amount
        FROM referrals
        WHERE (referrer_google_uid = ? OR referrer_wallet = ?)
          AND status = 'completed'
        FOR UPDATE
    ");
    $stmt->execute([$googleUid, $wallet]);
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
        SET status = 'claimed', claimed_at = NOW()
        WHERE id IN ({$placeholders})
    ");
    $stmt->execute($referralIds);

    // ============================================
    // 3. CREDITAR NO SALDO DO JOGADOR (BRL)
    // ============================================
    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_brl = balance_brl + ?,
            total_earned_brl = total_earned_brl + ?
        WHERE google_uid = ? OR wallet_address = ?
    ");
    $stmt->execute([$totalAmount, $totalAmount, $googleUid, $wallet]);

    $rowsAffected = $stmt->rowCount();

    if ($rowsAffected === 0) {
        // Jogador não existe, criar
        // Gerar wallet temporária se necessário
        $tempWallet = $wallet ?: '0x' . substr(hash('sha256', $googleUid . time()), 0, 40);
        
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, wallet_address, balance_brl, total_earned_brl, total_played, created_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$googleUid ?: null, $tempWallet, $totalAmount, $totalAmount]);
    }

    // ============================================
    // 4. REGISTRAR TRANSAÇÃO
    // ============================================
    $txExists = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    if ($txExists) {
        $description = 'Comissão de afiliados (' . count($referralIds) . ' indicação(ões))';

        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                google_uid, wallet_address, type, amount, amount_brl, 
                description, status, created_at
            ) VALUES (?, ?, 'referral_commission', ?, ?, ?, 'completed', NOW())
        ");
        $stmt->execute([
            $googleUid ?: null,
            $wallet ?: null,
            $totalAmount,
            $totalAmount,
            $description
        ]);
    }

    // ============================================
    // 5. BUSCAR NOVO SALDO
    // ============================================
    $stmt = $pdo->prepare("
        SELECT balance_brl FROM players 
        WHERE google_uid = ? OR wallet_address = ? 
        LIMIT 1
    ");
    $stmt->execute([$googleUid, $wallet]);
    $newBalance = (float)$stmt->fetchColumn();

    $pdo->commit();

    secureLog("REFERRAL_CLAIMED | UID: {$googleUid} | Wallet: {$wallet} | Amount: R$ {$totalAmount} | Referrals: " . implode(',', $referralIds));

    echo json_encode([
        'success' => true,
        'message' => 'Comissões resgatadas com sucesso!',
        'amount_claimed_brl' => number_format($totalAmount, 2, '.', ''),
        'referrals_claimed' => count($referralIds),
        'new_balance_brl' => number_format($newBalance, 2, '.', '')
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro em referral-claim.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
