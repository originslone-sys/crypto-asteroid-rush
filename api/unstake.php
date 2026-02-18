<?php
// ============================================
// UNOBIX - Realizar Unstake (Resgatar Tudo)
// Arquivo: api/unstake.php
// v3.0 - Tabela staking + users + Google Auth
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

// ============================================
// INPUT
// ============================================
$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');
$stakeId = $input['stake_id'] ?? null; // Opcional: unstake específico

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
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

    // ============================================
    // 2. BUSCAR STAKES ATIVOS (com lock)
    // ============================================
    if ($stakeId) {
        // Unstake específico
        $stmt = $pdo->prepare("
            SELECT id, amount, amount_brl, earnings, total_earned_brl, 
                   start_date, created_at, updated_at
            FROM staking
            WHERE id = ? AND user_id = ? AND status = 'active'
            FOR UPDATE
        ");
        $stmt->execute([(int)$stakeId, $userId]);
    } else {
        // Unstake de TODOS os stakes ativos
        $stmt = $pdo->prepare("
            SELECT id, amount, amount_brl, earnings, total_earned_brl, 
                   start_date, created_at, updated_at
            FROM staking
            WHERE user_id = ? AND status = 'active'
            FOR UPDATE
        ");
        $stmt->execute([$userId]);
    }

    $activeStakes = $stmt->fetchAll();

    if (empty($activeStakes)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Nenhum stake ativo encontrado']);
        exit;
    }

    // ============================================
    // 3. CALCULAR RENDIMENTOS E PROCESSAR CADA STAKE
    // ============================================
    $now = time();
    $dailyRate = STAKE_APY / 365;

    $totalPrincipal = 0;     // Total do valor original
    $totalEarnings = 0;      // Total de rendimentos
    $totalToReceive = 0;     // Total a devolver ao saldo
    $stakeIds = [];

    foreach ($activeStakes as $stake) {
        // Valor principal
        $amountBrl = (float)($stake['amount_brl'] ?? 0);
        if ($amountBrl == 0) {
            $amountBrl = (float)($stake['amount'] ?? 0);
        }

        // Rendimentos já contabilizados pelo cron
        $earnedBrl = (float)($stake['total_earned_brl'] ?? 0);
        if ($earnedBrl == 0) {
            $earnedBrl = (float)($stake['earnings'] ?? 0);
        }

        // Calcular rendimento pendente desde última atualização
        $startDate = $stake['start_date'] ?? $stake['created_at'];
        $lastUpdate = $stake['updated_at'] ?? $startDate;
        $lastUpdateTime = strtotime($lastUpdate);
        if (!$lastUpdateTime) $lastUpdateTime = strtotime($startDate);

        $daysSinceUpdate = max(0, ($now - $lastUpdateTime) / 86400);
        $pendingReward = $amountBrl * (pow(1 + $dailyRate, $daysSinceUpdate) - 1);

        // Total de rendimentos deste stake
        $stakeEarnings = $earnedBrl + $pendingReward;
        $stakeTotal = $amountBrl + $stakeEarnings;

        $totalPrincipal += $amountBrl;
        $totalEarnings += $stakeEarnings;
        $totalToReceive += $stakeTotal;
        $stakeIds[] = (int)$stake['id'];

        // Marcar stake como completado
        $stmt = $pdo->prepare("
            UPDATE staking
            SET status = 'completed',
                end_date = NOW(),
                earnings = :earnings,
                total_earned_brl = :total_earned_brl,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':earnings' => round($stakeEarnings, 6),
            ':total_earned_brl' => round($stakeEarnings, 6),
            ':id' => (int)$stake['id']
        ]);
    }

    // ============================================
    // 4. CREDITAR SALDO DO USUÁRIO
    // ============================================
    $newBalance = $currentBalance + $totalToReceive;
    $newStaked = max(0, $currentStaked - $totalPrincipal);

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
    $description = sprintf(
        'Unstake de R$ %s + rendimentos R$ %s (%d stake(s))',
        number_format($totalPrincipal, 6, ',', '.'),
        number_format($totalEarnings, 6, ',', '.'),
        count($stakeIds)
    );

    $stmt = $pdo->prepare("
        INSERT INTO transactions (
            google_uid, type, amount, amount_brl,
            description, status, created_at
        ) VALUES (?, 'unstake', ?, ?, ?, 'completed', NOW())
    ");
    $stmt->execute([
        $googleUid,
        round($totalToReceive, 6),
        round($totalToReceive, 6),
        $description
    ]);

    $pdo->commit();

    secureLog(sprintf(
        "UNSTAKE | UID: %s | Principal: R$ %.2f | Earnings: R$ %.4f | Total: R$ %.4f | Stakes: %s",
        $googleUid, $totalPrincipal, $totalEarnings, $totalToReceive, implode(',', $stakeIds)
    ));

    // ============================================
    // RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'message' => 'Unstake realizado com sucesso! O valor foi creditado no seu saldo.',
        'stakes_unstaked' => count($stakeIds),
        'amount_staked_brl' => round($totalPrincipal, 6),
        'reward_brl' => round($totalEarnings, 6),
        'total_returned_brl' => round($totalToReceive, 6),
        'new_balance_brl' => round($newBalance, 6),
        'new_staked_brl' => round($newStaked, 6)
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    secureLog("UNSTAKE_ERROR | UID: {$googleUid} | Error: " . $e->getMessage());
    error_log("unstake.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar unstake']);
}
