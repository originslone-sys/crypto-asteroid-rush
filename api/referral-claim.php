<?php
// ============================================
// UNOBIX - Resgatar Comissões de Referral
// Arquivo: api/referral-claim.php
// v4.0 - Fix 500 error
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// Responder OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Erro ao conectar ao banco');

    // Verificar tabelas
    $referralsExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
    $usersExists = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();

    if (!$referralsExists || !$usersExists) {
        echo json_encode(['success' => false, 'error' => 'Sistema não configurado']);
        exit;
    }

    // ============================================
    // ANTES da transação: garantir colunas extras
    // ALTER TABLE causa commit implícito no MySQL
    // ============================================
    try {
        $col = $pdo->query("SHOW COLUMNS FROM referrals LIKE 'commission_paid_at'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE referrals ADD COLUMN commission_paid_at DATETIME DEFAULT NULL");
        }
    } catch (Exception $e) {
        error_log("referral-claim: ALTER referrals warning: " . $e->getMessage());
    }

    // Garantir que status aceita 'claimed' (pode ser ENUM sem esse valor)
    try {
        $col = $pdo->query("SHOW COLUMNS FROM referrals LIKE 'status'")->fetch();
        if ($col && stripos($col['Type'], 'enum') !== false) {
            // Verificar se 'claimed' está no ENUM
            if (stripos($col['Type'], 'claimed') === false) {
                // Extrair valores atuais e adicionar 'claimed'
                preg_match_all("/'([^']+)'/", $col['Type'], $matches);
                $values = $matches[1] ?? [];
                $values[] = 'claimed';
                $enumStr = implode("','", $values);
                $pdo->exec("ALTER TABLE referrals MODIFY COLUMN status ENUM('{$enumStr}') NOT NULL DEFAULT 'pending'");
            }
        }
    } catch (Exception $e) {
        error_log("referral-claim: ALTER status ENUM warning: " . $e->getMessage());
    }

    // Verificar se transactions tem amount_brl
    $hasAmountBrl = false;
    try {
        $txTable = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
        if ($txTable) {
            $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'amount_brl'")->fetch();
            $hasAmountBrl = (bool)$col;
        }
    } catch (Exception $e) {}

    // ============================================
    // INICIAR TRANSAÇÃO
    // ============================================
    $pdo->beginTransaction();

    // 1. BUSCAR COMISSÕES DISPONÍVEIS (LOCK)
    $stmt = $pdo->prepare("
        SELECT id, commission_brl
        FROM referrals
        WHERE referrer_google_uid = ?
          AND status = 'qualified'
        FOR UPDATE
    ");
    $stmt->execute([$googleUid]);
    $pendingCommissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendingCommissions)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Nenhuma comissão disponível para resgate']);
        exit;
    }

    $totalAmount = 0.0;
    $referralIds = [];

    foreach ($pendingCommissions as $commission) {
        $totalAmount += (float)$commission['commission_brl'];
        $referralIds[] = (int)$commission['id'];
    }

    // 2. ATUALIZAR STATUS PARA 'claimed'
    $placeholders = implode(',', array_fill(0, count($referralIds), '?'));
    $stmt = $pdo->prepare("
        UPDATE referrals
        SET status = 'claimed',
            commission_paid_at = NOW()
        WHERE id IN ({$placeholders})
    ");
    $stmt->execute($referralIds);

    // 3. CREDITAR NO SALDO DO USUÁRIO
    $stmt = $pdo->prepare("
        UPDATE users
        SET balance_brl = balance_brl + ?,
            total_earned_brl = total_earned_brl + ?,
            updated_at = NOW()
        WHERE google_uid = ?
    ");
    $stmt->execute([$totalAmount, $totalAmount, $googleUid]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    // 4. REGISTRAR TRANSAÇÃO
    $description = 'Comissão de afiliados (' . count($referralIds) . ' indicação(ões))';

    if ($hasAmountBrl) {
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                google_uid, type, amount, amount_brl,
                description, status, created_at
            ) VALUES (?, 'referral_commission', ?, ?, ?, 'completed', NOW())
        ");
        $stmt->execute([$googleUid, $totalAmount, $totalAmount, $description]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                google_uid, type, amount,
                description, status, created_at
            ) VALUES (?, 'referral_commission', ?, ?, 'completed', NOW())
        ");
        $stmt->execute([$googleUid, $totalAmount, $description]);
    }

    // 5. BUSCAR NOVO SALDO
    $stmt = $pdo->prepare("SELECT balance_brl FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $newBalance = (float)$stmt->fetchColumn();

    $pdo->commit();

    secureLog("REFERRAL_CLAIMED | UID: {$googleUid} | Amount: R$ {$totalAmount} | Referrals: " . implode(',', $referralIds));

    echo json_encode([
        'success' => true,
        'message' => 'Comissões resgatadas com sucesso!',
        'amount_claimed_brl' => number_format($totalAmount, 6, '.', ''),
        'referrals_claimed' => count($referralIds),
        'new_balance_brl' => number_format($newBalance, 6, '.', '')
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro em referral-claim.php: " . $e->getMessage() . " | Line: " . $e->getLine() . " | File: " . $e->getFile());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor: ' . $e->getMessage()]);
}
