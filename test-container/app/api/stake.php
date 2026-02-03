<?php
// ============================================
// UNOBIX - Criar Stake
// Arquivo: api/stake.php
// v2.0 - APY 5% + BRL + Google Auth
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

// ============================================
// LER INPUT (híbrido: JSON + POST + GET)
// ============================================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$googleUid = 
    $input['google_uid'] ?? 
    $_POST['google_uid'] ?? 
    $_GET['google_uid'] ?? '';

$wallet = 
    $input['wallet'] ?? 
    $_POST['wallet'] ?? 
    $_GET['wallet'] ?? '';

$amountRaw = 
    $input['amount'] ?? 
    $_POST['amount'] ?? 
    $_GET['amount'] ?? 0;

$googleUid = trim($googleUid);
$wallet = trim(strtolower($wallet));
$amount = (float)$amountRaw;

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

// Validar valor
if ($amount < MIN_STAKE_BRL) {
    echo json_encode([
        'success' => false, 
        'error' => "Valor mínimo para stake: R$ " . number_format(MIN_STAKE_BRL, 2, ',', '.')
    ]);
    exit;
}

if ($amount > MAX_STAKE_BRL) {
    echo json_encode([
        'success' => false, 
        'error' => "Valor máximo para stake: R$ " . number_format(MAX_STAKE_BRL, 2, ',', '.')
    ]);
    exit;
}

$pdo = getDatabaseConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ============================================
    // 1. BUSCAR JOGADOR (com lock)
    // ============================================
    $wherePlayer = $identifierType === 'google_uid'
        ? "google_uid = :id"
        : "wallet_address = :id";

    $stmt = $pdo->prepare("
        SELECT id, google_uid, wallet_address, balance_brl, staked_balance_brl
        FROM players
        WHERE {$wherePlayer}
        FOR UPDATE
    ");
    $stmt->execute([':id' => $identifier]);
    $player = $stmt->fetch();

    if (!$player) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
        exit;
    }

    $currentBalance = (float)($player['balance_brl'] ?? 0);
    
    if ($currentBalance < $amount) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'error' => 'Saldo insuficiente',
            'current_balance' => $currentBalance
        ]);
        exit;
    }

    // ============================================
    // 2. VERIFICAR LIMITE DE STAKES ATIVOS
    // ============================================
    $whereClause = $identifierType === 'google_uid'
        ? "(google_uid = :id OR wallet_address = :id2)"
        : "wallet_address = :id";
    
    $params = $identifierType === 'google_uid'
        ? [':id' => $identifier, ':id2' => $identifier]
        : [':id' => $identifier];

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, COALESCE(SUM(amount_brl), 0) as total
        FROM stakes
        WHERE {$whereClause} AND status = 'active'
    ");
    $stmt->execute($params);
    $activeStakes = $stmt->fetch();

    $currentTotalStaked = (float)($activeStakes['total'] ?? 0);
    
    if (($currentTotalStaked + $amount) > MAX_STAKE_BRL) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'error' => "Limite total de stake: R$ " . number_format(MAX_STAKE_BRL, 2, ',', '.'),
            'current_staked' => $currentTotalStaked
        ]);
        exit;
    }

    // ============================================
    // 3. CRIAR STAKE
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO stakes (
            google_uid,
            wallet_address,
            amount,
            amount_brl,
            apy,
            total_earned,
            total_earned_brl,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, 0, 0, 'active', NOW())
    ");
    
    // amount em unidades antigas (para compatibilidade) = amount_brl * 100000000
    $amountUnits = (int)round($amount * 100000000);
    
    $stmt->execute([
        $player['google_uid'],
        $player['wallet_address'],
        $amountUnits,
        $amount,
        STAKE_APY
    ]);

    $stakeId = $pdo->lastInsertId();

    // ============================================
    // 4. DEDUZIR SALDO DO JOGADOR
    // ============================================
    $newBalance = $currentBalance - $amount;
    $newStakedBalance = (float)($player['staked_balance_brl'] ?? 0) + $amount;

    $stmt = $pdo->prepare("
        UPDATE players
        SET balance_brl = :balance,
            staked_balance_brl = :staked,
            last_stake_update = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':balance' => $newBalance,
        ':staked' => $newStakedBalance,
        ':id' => (int)$player['id']
    ]);

    // ============================================
    // 5. REGISTRAR TRANSAÇÃO
    // ============================================
    $pdo->prepare("
        INSERT INTO transactions (
            google_uid, wallet_address, type, amount, amount_brl, 
            description, status, created_at
        ) VALUES (?, ?, 'stake', ?, ?, ?, 'completed', NOW())
    ")->execute([
        $player['google_uid'],
        $player['wallet_address'],
        $amount,
        $amount,
        "Stake de R$ " . number_format($amount, 2, ',', '.') . " - APY " . (STAKE_APY * 100) . "%"
    ]);

    $pdo->commit();

    // Log
    secureLog("STAKE_CREATED | ID: {$identifier} | Amount: R$ {$amount} | Stake: #{$stakeId}");

    // ============================================
    // CALCULAR PROJEÇÕES
    // ============================================
    $dailyRate = STAKE_APY / 365;
    $monthlyRate = pow(1 + $dailyRate, 30) - 1;
    $yearlyRate = STAKE_APY;

    $projectedDaily = $amount * $dailyRate;
    $projectedMonthly = $amount * $monthlyRate;
    $projectedYearly = $amount * $yearlyRate;

    // ============================================
    // RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'message' => 'Stake criado com sucesso!',
        'stake_id' => (int)$stakeId,
        'amount_brl' => round($amount, 2),
        'apy_percent' => STAKE_APY * 100,
        'new_balance_brl' => round($newBalance, 2),
        'total_staked_brl' => round($newStakedBalance, 2),
        'projections' => [
            'daily_brl' => round($projectedDaily, 4),
            'monthly_brl' => round($projectedMonthly, 4),
            'yearly_brl' => round($projectedYearly, 2)
        ],
        'config' => [
            'min_stake_brl' => MIN_STAKE_BRL,
            'max_stake_brl' => MAX_STAKE_BRL,
            'compound_frequency' => 'hourly'
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    secureLog("STAKE_ERROR | ID: {$identifier} | Error: " . $e->getMessage());
    error_log("Erro ao criar stake: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao criar stake']);
}
