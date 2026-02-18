<?php
// ============================================
// UNOBIX - API de Saldo
// Arquivo: api/balance.php v6.0
// Usa config.php, trata colunas opcionais
// ============================================

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";

setCorsHeaders();

// Obter input
$input = getRequestInput();

// Resolver google_uid
$googleUid = null;
$candidates = [
    $input['google_uid'] ?? null,
    $input['googleUid'] ?? null,
    $input['uid'] ?? null
];

foreach ($candidates as $c) {
    if (is_string($c) && !empty(trim($c)) && validateGoogleUid(trim($c))) {
        $googleUid = trim($c);
        break;
    }
}

if (!$googleUid) {
    echo json_encode([
        'success' => false,
        'balance_brl' => '0.000000',
        'error' => 'Identificador inválido. Envie google_uid.'
    ]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    // Detectar colunas disponíveis
    $availableCols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $availableCols[] = $row['Field'];
    }

    $hasStakedBalance = in_array('staked_balance_brl', $availableCols);
    $hasLastStakeUpdate = in_array('last_stake_update', $availableCols);
    $hasTotalWithdrawn = in_array('total_withdrawn_brl', $availableCols);
    $hasIsBanned = in_array('is_banned', $availableCols);

    // Buscar usuário
    $player = findPlayer($pdo, $googleUid);

    if (!$player) {
        echo json_encode([
            'success' => true,
            'balance_brl' => '0.000000',
            'staked_balance_brl' => '0.000000',
            'pending_stake_reward' => '0.000000',
            'total_earned_brl' => '0.000000',
            'total_withdrawn_brl' => '0.000000',
            'total_played' => 0,
            'is_new_player' => true
        ]);
        exit;
    }

    // Extrair valores
    $balanceBrl = (float)($player['balance_brl'] ?? 0);
    $totalEarnedBrl = (float)($player['total_earned_brl'] ?? 0);
    $totalPlayed = (int)($player['total_played'] ?? 0);
    
    // Colunas opcionais
    $stakedBrl = $hasStakedBalance ? (float)($player['staked_balance_brl'] ?? 0) : 0;
    $totalWithdrawnBrl = $hasTotalWithdrawn ? (float)($player['total_withdrawn_brl'] ?? 0) : 0;
    $isBanned = $hasIsBanned ? (bool)($player['is_banned'] ?? 0) : false;

    // Calcular stake rewards pendentes
    $stakeReward = 0.0;
    if ($hasStakedBalance && $hasLastStakeUpdate && $stakedBrl > 0) {
        $lastUpdate = $player['last_stake_update'] ?? null;
        if ($lastUpdate) {
            $secondsPassed = time() - strtotime($lastUpdate);
            $dailyRate = STAKE_APY / 365;
            $daysElapsed = $secondsPassed / 86400;
            $stakeReward = $stakedBrl * (pow(1 + $dailyRate, $daysElapsed) - 1);
            if ($stakeReward < 0) $stakeReward = 0.0;
        }
    }

    echo json_encode([
        'success' => true,
        'google_uid' => $googleUid,
        'balance_brl' => number_format($balanceBrl, 6, '.', ''),
        'staked_balance_brl' => number_format($stakedBrl, 6, '.', ''),
        'pending_stake_reward' => number_format($stakeReward, 6, '.', ''),
        'total_earned_brl' => number_format($totalEarnedBrl, 6, '.', ''),
        'total_withdrawn_brl' => number_format($totalWithdrawnBrl, 6, '.', ''),
        'total_played' => $totalPlayed,
        'is_banned' => $isBanned,
        'display_name' => $player['display_name'] ?? null,
        'email' => $player['email'] ?? null,
        'is_new_player' => false
    ]);

} catch (Throwable $e) {
    error_log("balance.php error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'balance_brl' => '0.000000',
        'error' => 'Erro no servidor'
    ]);
}
