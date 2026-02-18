<?php
// ============================================
// UNOBIX - Consultar Stakes
// Arquivo: api/get-stakes.php
// v3.0 - Tabela staking + users + Google Auth
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

// ============================================
// INPUT
// ============================================
$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');

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

    // ============================================
    // 1. BUSCAR USUÁRIO
    // ============================================
    $user = findPlayer($pdo, $googleUid);
    if (!$user) {
        echo json_encode([
            'success' => true,
            'stakes' => [],
            'total_earned_brl' => 0,
            'pending_reward_brl' => 0,
            'summary' => [
                'total_staked_brl' => 0,
                'total_earned_brl' => 0,
                'today_earnings_brl' => 0,
                'pending_reward_brl' => 0,
                'staked_balance_brl' => 0
            ],
            'config' => [
                'apy_percent' => STAKE_APY * 100,
                'min_stake_brl' => MIN_STAKE_BRL,
                'max_stake_brl' => MAX_STAKE_BRL,
                'compound_frequency' => 'daily'
            ]
        ]);
        exit;
    }

    $userId = (int)$user['id'];

    // ============================================
    // 2. BUSCAR STAKES ATIVOS
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, user_id, google_uid, amount, amount_brl, apy, 
               earnings, total_earned_brl, status, start_date, 
               end_date, created_at, updated_at
        FROM staking
        WHERE user_id = :user_id AND status = 'active'
        ORDER BY start_date DESC
    ");
    $stmt->execute([':user_id' => $userId]);
    $stakes = $stmt->fetchAll();

    $totalStaked = 0;
    $totalEarned = 0;
    $totalEarnedToday = 0;
    $totalPendingReward = 0;
    $stakesFormatted = [];

    $now = time();
    $dailyRate = STAKE_APY / 365;

    foreach ($stakes as $stake) {
        // Valor em BRL: priorizar amount_brl, fallback para amount
        $amountBrl = (float)($stake['amount_brl'] ?? 0);
        if ($amountBrl == 0) {
            $amountBrl = (float)($stake['amount'] ?? 0);
        }

        // Rendimentos já contabilizados pelo cron
        $earnedBrl = (float)($stake['total_earned_brl'] ?? 0);
        if ($earnedBrl == 0) {
            $earnedBrl = (float)($stake['earnings'] ?? 0);
        }

        // Data de início
        $startDate = $stake['start_date'] ?? $stake['created_at'] ?? date('Y-m-d H:i:s');
        $startTime = strtotime($startDate);
        if (!$startTime) $startTime = time();

        // Calcular rendimento pendente desde última atualização do cron
        $lastUpdate = $stake['updated_at'] ?? $startDate;
        $lastUpdateTime = strtotime($lastUpdate);
        if (!$lastUpdateTime) $lastUpdateTime = $startTime;

        $daysSinceUpdate = max(0, ($now - $lastUpdateTime) / 86400);
        $pendingReward = $amountBrl * (pow(1 + $dailyRate, $daysSinceUpdate) - 1);

        // Dias totais desde início
        $totalDays = max(0, ($now - $startTime) / 86400);

        // Rendimentos nas últimas 24 horas (aproximado)
        $earnedToday = $amountBrl * $dailyRate;

        // Taxa diária em BRL
        $dailyRateBrl = $amountBrl * $dailyRate;

        $stakesFormatted[] = [
            'id' => (int)$stake['id'],
            'amount_brl' => round($amountBrl, 6),
            'apy' => STAKE_APY * 100,
            'start_date' => $startDate,
            'total_earned_brl' => round($earnedBrl + $pendingReward, 6),
            'earned_today_brl' => round($earnedToday, 6),
            'daily_rate_brl' => round($dailyRateBrl, 6),
            'days_staked' => round($totalDays, 2),
            'pending_reward_brl' => round($pendingReward, 6),
            'start_date_formatted' => date('d/m/Y H:i', $startTime)
        ];

        $totalStaked += $amountBrl;
        $totalEarned += $earnedBrl + $pendingReward;
        $totalEarnedToday += $earnedToday;
        $totalPendingReward += $pendingReward;
    }

    // ============================================
    // 3. SALDO DE STAKE DO USUÁRIO (tabela users)
    // ============================================
    $playerStakedBalance = (float)($user['staked_balance_brl'] ?? 0);

    // ============================================
    // RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'stakes' => $stakesFormatted,
        'total_earned_brl' => round($totalEarned, 6),
        'pending_reward_brl' => round($totalPendingReward, 6),
        'summary' => [
            'total_staked_brl' => round($totalStaked, 6),
            'total_earned_brl' => round($totalEarned, 6),
            'today_earnings_brl' => round($totalEarnedToday, 6),
            'pending_reward_brl' => round($totalPendingReward, 6),
            'staked_balance_brl' => round($playerStakedBalance, 6)
        ],
        'config' => [
            'apy_percent' => STAKE_APY * 100,
            'min_stake_brl' => MIN_STAKE_BRL,
            'max_stake_brl' => MAX_STAKE_BRL,
            'compound_frequency' => 'daily'
        ]
    ]);

} catch (Throwable $e) {
    error_log("get-stakes.php error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar dados',
        'stakes' => [],
        'total_earned_brl' => 0,
        'pending_reward_brl' => 0,
        'summary' => [
            'total_staked_brl' => 0,
            'total_earned_brl' => 0,
            'today_earnings_brl' => 0,
            'pending_reward_brl' => 0
        ]
    ]);
}
