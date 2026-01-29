<?php
// ============================================
// UNOBIX - Realizar Unstake
// Arquivo: api/unstake.php
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

$stakeId = 
    $input['stake_id'] ?? 
    $_POST['stake_id'] ?? 
    $_GET['stake_id'] ?? null;

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

$pdo = getDatabaseConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ============================================
    // 1. BUSCAR STAKE ATIVO (com lock)
    // ============================================
    $whereClause = $identifierType === 'google_uid'
        ? "(google_uid = :id OR wallet_address = :id2)"
        : "wallet_address = :id";
    
    $params = $identifierType === 'google_uid'
        ? [':id' => $identifier, ':id2' => $identifier]
        : [':id' => $identifier];

    // Se stake_id específico, buscar esse
    if ($stakeId) {
        $stmt = $pdo->prepare("
            SELECT id, google_uid, wallet_address, amount, amount_brl, total_earned, total_earned_brl, created_at
            FROM stakes
            WHERE id = :stake_id AND ({$whereClause}) AND status = 'active'
            LIMIT 1
            FOR UPDATE
        ");
        $params[':stake_id'] = (int)$stakeId;
    } else {
        // Buscar qualquer stake ativo
        $stmt = $pdo->prepare("
            SELECT id, google_uid, wallet_address, amount, amount_brl, total_earned, total_earned_brl, created_at
            FROM stakes
            WHERE {$whereClause} AND status = 'active'
            ORDER BY created_at ASC
            LIMIT 1
            FOR UPDATE
        ");
    }
    
    $stmt->execute($params);
    $stake = $stmt->fetch();

    if (!$stake) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Stake não encontrado ou já processado']);
        exit;
    }

    // ============================================
    // 2. CALCULAR VALORES
    // ============================================
    // Priorizar amount_brl, fallback para conversão
    $amountBrl = (float)($stake['amount_brl'] ?? 0);
    if ($amountBrl == 0 && isset($stake['amount'])) {
        $amountBrl = (float)$stake['amount'] / 100000000;
    }
    
    $totalEarnedBrl = (float)($stake['total_earned_brl'] ?? 0);
    if ($totalEarnedBrl == 0 && isset($stake['total_earned'])) {
        $totalEarnedBrl = (float)$stake['total_earned'] / 100000000;
    }

    // Calcular ganhos desde o início
    $startTime = strtotime($stake['created_at']);
    $now = time();
    $hoursPassed = ($now - $startTime) / 3600;

    // APY 5% = taxa horária
    $hourlyRate = STAKE_APY / (365 * 24);
    
    // Ganhos compostos
    $earnedExtraBrl = $amountBrl * (pow(1 + $hourlyRate, $hoursPassed) - 1);

    // Total a receber
    $totalEarningsNow = $totalEarnedBrl + $earnedExtraBrl;
    $totalToReceive = $amountBrl + $totalEarningsNow;

    // ============================================
    // 3. ATUALIZAR SALDO DO JOGADOR
    // ============================================
    $wherePlayer = $identifierType === 'google_uid'
        ? "google_uid = :id"
        : "wallet_address = :id";

    $stmt = $pdo->prepare("
        SELECT id, balance_brl, staked_balance_brl
        FROM players
        WHERE {$wherePlayer}
        FOR UPDATE
    ");
    $stmt->execute([':id' => $identifier]);
    $player = $stmt->fetch();

    if ($player) {
        $currentBalanceBrl = (float)($player['balance_brl'] ?? 0);
        $currentStakedBrl = (float)($player['staked_balance_brl'] ?? 0);
        
        $newBalanceBrl = $currentBalanceBrl + $totalToReceive;
        $newStakedBrl = max(0, $currentStakedBrl - $amountBrl);

        $stmt = $pdo->prepare("
            UPDATE players
            SET balance_brl = :balance,
                staked_balance_brl = :staked,
                last_stake_update = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':balance' => $newBalanceBrl,
            ':staked' => $newStakedBrl,
            ':id' => (int)$player['id']
        ]);
    }

    // ============================================
    // 4. MARCAR STAKE COMO COMPLETED
    // ============================================
    $stmt = $pdo->prepare("
        UPDATE stakes
        SET status = 'completed',
            total_earned_brl = :total_earned_brl,
            completed_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':total_earned_brl' => round($totalEarningsNow, 4),
        ':id' => (int)$stake['id']
    ]);

    // ============================================
    // 5. REGISTRAR TRANSAÇÃO
    // ============================================
    $pdo->prepare("
        INSERT INTO transactions (
            google_uid, wallet_address, type, amount, amount_brl, 
            description, status, created_at
        ) VALUES (?, ?, 'unstake', ?, ?, ?, 'completed', NOW())
    ")->execute([
        $stake['google_uid'],
        $stake['wallet_address'],
        $totalToReceive,
        round($totalToReceive, 2),
        "Unstake de R$ " . number_format($amountBrl, 2, ',', '.') . " + rendimentos"
    ]);

    $pdo->commit();

    // Log
    secureLog("UNSTAKE | ID: {$identifier} | Stake: {$stake['id']} | Amount: R$ {$amountBrl} | Earnings: R$ " . round($totalEarningsNow, 4) . " | Total: R$ " . round($totalToReceive, 4));

    // ============================================
    // RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'message' => 'Unstake realizado com sucesso! O valor foi creditado no seu saldo.',
        'stake_id' => (int)$stake['id'],
        'amount_staked_brl' => round($amountBrl, 2),
        'earnings_brl' => round($totalEarningsNow, 4),
        'total_received_brl' => round($totalToReceive, 4),
        'new_balance_brl' => round($newBalanceBrl ?? $totalToReceive, 2),
        'hours_staked' => round($hoursPassed, 2),
        'effective_apy' => round(($totalEarningsNow / $amountBrl) * (365 * 24 / $hoursPassed) * 100, 2) . '%'
    ]);

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    secureLog("UNSTAKE_ERROR | ID: {$identifier} | Error: " . $e->getMessage());
    error_log("Erro ao processar unstake: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar unstake']);
}
