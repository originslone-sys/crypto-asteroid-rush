<?php
// ============================================
// UNOBIX - Consultar Stakes
// Arquivo: api/get-stakes.php
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
    echo json_encode(['error' => 'Identificação inválida']);
    exit;
}

$pdo = getDatabaseConnection();
if (!$pdo) {
    echo json_encode(['error' => 'Erro de conexão com o banco']);
    exit;
}

try {
    // ============================================
    // 1. USAR STORED PROCEDURE PARA CALCULAR REWARD
    // ============================================
    $pendingReward = 0;
    $stakedBalance = 0;
    $daysElapsed = 0;
    
    try {
        $stmt = $pdo->prepare("CALL sp_calculate_stake_reward(?)");
        $stmt->execute([$identifier]);
        $rewardCalc = $stmt->fetch();
        $stmt->closeCursor();
        
        if ($rewardCalc) {
            $pendingReward = (float)($rewardCalc['reward'] ?? 0);
            $stakedBalance = (float)($rewardCalc['staked_balance'] ?? 0);
            $daysElapsed = (float)($rewardCalc['days_elapsed'] ?? 0);
        }
    } catch (Exception $e) {
        secureLog("SP_CALCULATE_STAKE_ERROR | " . $e->getMessage());
    }

    // ============================================
    // 2. BUSCAR STAKES ATIVOS
    // ============================================
    $whereClause = $identifierType === 'google_uid'
        ? "(google_uid = :id OR wallet_address = :id2)"
        : "wallet_address = :id";
    
    $params = $identifierType === 'google_uid'
        ? [':id' => $identifier, ':id2' => $identifier]
        : [':id' => $identifier];

    $stmt = $pdo->prepare("
        SELECT * FROM stakes
        WHERE {$whereClause} AND status = 'active'
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);
    $stakes = $stmt->fetchAll();

    $totalStaked = 0;
    $totalEarned = 0;
    $totalEarnedToday = 0;
    $stakesFormatted = [];

    foreach ($stakes as $stake) {
        // Priorizar amount_brl, fallback para amount convertido
        $amountBrl = (float)($stake['amount_brl'] ?? 0);
        if ($amountBrl == 0 && isset($stake['amount'])) {
            // Converter de unidades antigas se necessário
            $amountBrl = (float)$stake['amount'] / 100000000;
        }
        
        $totalEarnedBrl = (float)($stake['total_earned_brl'] ?? 0);
        if ($totalEarnedBrl == 0 && isset($stake['total_earned'])) {
            $totalEarnedBrl = (float)$stake['total_earned'] / 100000000;
        }

        // Garantir created_at válido
        $createdAt = $stake['created_at'] ?? date('Y-m-d H:i:s');
        if (empty($createdAt) || $createdAt == '0000-00-00 00:00:00') {
            $createdAt = date('Y-m-d H:i:s');
        }

        $startTime = strtotime($createdAt);
        $now = time();
        $hoursPassed = max(0, ($now - $startTime) / 3600);
        
        // APY 5% anual = taxa horária
        $hourlyRate = STAKE_APY / (365 * 24);

        // Calcular ganhos acumulados (compostos)
        $earnedBrl = $amountBrl * (pow(1 + $hourlyRate, $hoursPassed) - 1);
        $totalEarnedForThisStake = $totalEarnedBrl + $earnedBrl;

        // Calcular ganhos nas últimas 24 horas
        $hoursToday = min($hoursPassed, 24);
        $earnedTodayBrl = $amountBrl * (pow(1 + $hourlyRate, $hoursToday) - 1);

        // Taxa horária em BRL
        $hourlyRateBrl = $amountBrl * $hourlyRate;

        $stakeFormatted = [
            'id' => (int)$stake['id'],
            'amount_brl' => round($amountBrl, 2),
            'apy' => STAKE_APY * 100, // 5%
            'start_time' => $createdAt,
            'total_earned_brl' => round($totalEarnedForThisStake, 4),
            'earned_today_brl' => round($earnedTodayBrl, 4),
            'hourly_rate_brl' => round($hourlyRateBrl, 6),
            'hours_passed' => round($hoursPassed, 2),
            'created_at_formatted' => date('d/m/Y H:i', strtotime($createdAt))
        ];

        $stakesFormatted[] = $stakeFormatted;
        $totalStaked += $amountBrl;
        $totalEarned += $totalEarnedForThisStake;
        $totalEarnedToday += $earnedTodayBrl;
    }

    // ============================================
    // 3. BUSCAR SALDO DE STAKE DO PLAYER (staked_balance_brl)
    // ============================================
    $playerStakedBalance = 0;
    $stmt = $pdo->prepare("
        SELECT staked_balance_brl, last_stake_update 
        FROM players 
        WHERE {$whereClause}
        LIMIT 1
    ");
    $stmt->execute($params);
    $playerData = $stmt->fetch();
    
    if ($playerData) {
        $playerStakedBalance = (float)($playerData['staked_balance_brl'] ?? 0);
    }

    // ============================================
    // RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'stakes' => $stakesFormatted,
        'summary' => [
            'total_staked_brl' => round($totalStaked, 2),
            'total_earned_brl' => round($totalEarned, 4),
            'today_earnings_brl' => round($totalEarnedToday, 4),
            'pending_reward_brl' => round($pendingReward, 4),
            'staked_balance_brl' => round($playerStakedBalance, 2)
        ],
        'config' => [
            'apy_percent' => STAKE_APY * 100,
            'min_stake_brl' => MIN_STAKE_BRL,
            'max_stake_brl' => MAX_STAKE_BRL,
            'compound_frequency' => 'hourly'
        ]
    ]);

} catch (PDOException $e) {
    error_log("Erro ao buscar stakes: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar dados',
        'stakes' => [],
        'summary' => [
            'total_staked_brl' => 0,
            'total_earned_brl' => 0,
            'today_earnings_brl' => 0
        ]
    ]);
}
