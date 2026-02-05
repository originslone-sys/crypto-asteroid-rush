<?php
// ============================================
// UNOBIX - Criar Stake
// Arquivo: api/stake.php
// v3.0 - Tabela staking + users + Google Auth
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

// ============================================
// INPUT
// ============================================
$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');

// Aceitar amount ou amount_brl
$amount = (float)($input['amount_brl'] ?? $input['amount'] ?? 0);

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

// Validar valor mínimo
if ($amount < MIN_STAKE_BRL) {
    echo json_encode([
        'success' => false,
        'error' => 'Valor mínimo para stake: R$ ' . number_format(MIN_STAKE_BRL, 2, ',', '.')
    ]);
    exit;
}

// Validar valor máximo
if ($amount > MAX_STAKE_BRL) {
    echo json_encode([
        'success' => false,
        'error' => 'Valor máximo para stake: R$ ' . number_format(MAX_STAKE_BRL, 2, ',', '.')
    ]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
        exit;
    }

    $pdo->beginTransaction();

    // ============================================
    // 1. BUSCAR USUÁRIO (com lock)
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, google_uid, balance_brl, staked_balance_brl
        FROM users
        WHERE google_uid = ?
        FOR UPDATE
    ");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch();

    if (!$user) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    $userId = (int)$user['id'];
    $currentBalance = (float)($user['balance_brl'] ?? 0);
    $currentStaked = (float)($user['staked_balance_brl'] ?? 0);

    // Verificar saldo
    if ($currentBalance < $amount) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Saldo insuficiente',
            'current_balance' => round($currentBalance, 2)
        ]);
        exit;
    }

    // ============================================
    // 2. VERIFICAR LIMITE TOTAL DE STAKE
    // ============================================
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_brl), 0) as total
        FROM staking
        WHERE user_id = :user_id AND status = 'active'
    ");
    $stmt->execute([':user_id' => $userId]);
    $currentTotalStaked = (float)$stmt->fetchColumn();

    if (($currentTotalStaked + $amount) > MAX_STAKE_BRL) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Limite total de stake: R$ ' . number_format(MAX_STAKE_BRL, 2, ',', '.'),
            'current_staked' => round($currentTotalStaked, 2)
        ]);
        exit;
    }

    // ============================================
    // 3. CRIAR STAKE
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO staking (
            user_id, google_uid, amount, amount_brl, apy,
            earnings, total_earned_brl, status, start_date, created_at
        ) VALUES (?, ?, ?, ?, ?, 0, 0, 'active', NOW(), NOW())
    ");
    $stmt->execute([
        $userId,
        $googleUid,
        $amount,         // amount (coluna original)
        $amount,         // amount_brl (nova coluna)
        STAKE_APY
    ]);

    $stakeId = (int)$pdo->lastInsertId();

    // ============================================
    // 4. DEBITAR SALDO DO USUÁRIO
    // ============================================
    $newBalance = $currentBalance - $amount;
    $newStaked = $currentStaked + $amount;

    $stmt = $pdo->prepare("
        UPDATE users
        SET balance_brl = :balance,
            staked_balance_brl = :staked,
            last_stake_update = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':balance' => $newBalance,
        ':staked' => $newStaked,
        ':id' => $userId
    ]);

    // ============================================
    // 5. REGISTRAR TRANSAÇÃO
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO transactions (
            google_uid, type, amount, amount_brl,
            description, status, created_at
        ) VALUES (?, 'stake', ?, ?, ?, 'completed', NOW())
    ");
    $stmt->execute([
        $googleUid,
        $amount,
        $amount,
        'Stake de R$ ' . number_format($amount, 2, ',', '.') . ' - APY ' . (STAKE_APY * 100) . '%'
    ]);

    $pdo->commit();

    secureLog("STAKE_CREATED | UID: {$googleUid} | Amount: R$ {$amount} | Stake: #{$stakeId}");

    // ============================================
    // PROJEÇÕES
    // ============================================
    $dailyRate = STAKE_APY / 365;
    $projectedDaily = $amount * $dailyRate;
    $projectedMonthly = $amount * (pow(1 + $dailyRate, 30) - 1);
    $projectedYearly = $amount * (pow(1 + $dailyRate, 365) - 1);

    // ============================================
    // RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'message' => 'Stake criado com sucesso!',
        'stake_id' => $stakeId,
        'amount_brl' => round($amount, 2),
        'apy_percent' => STAKE_APY * 100,
        'new_balance_brl' => round($newBalance, 2),
        'total_staked_brl' => round($newStaked, 2),
        'projections' => [
            'daily_brl' => round($projectedDaily, 4),
            'monthly_brl' => round($projectedMonthly, 4),
            'yearly_brl' => round($projectedYearly, 2)
        ],
        'config' => [
            'min_stake_brl' => MIN_STAKE_BRL,
            'max_stake_brl' => MAX_STAKE_BRL,
            'compound_frequency' => 'daily'
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    secureLog("STAKE_ERROR | UID: {$googleUid} | Error: " . $e->getMessage());
    error_log("stake.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao criar stake']);
}
