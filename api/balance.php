<?php
// ============================================
// UNOBIX - API de Saldo
// Arquivo: api/balance.php
// v4.0 - Suporta Google UID + Wallet + BRL
// ============================================

require_once __DIR__ . "/config.php";

setCORSHeaders();
header('Content-Type: application/json; charset=utf-8');

$input = getRequestInput();

// Debug opcional: /api/balance.php?debug=1
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

// Obter identificador do usuário
$identifier = getUserIdentifier($input);

if (!$identifier) {
    $resp = [
        'success' => false,
        'balance_brl' => '0.00',
        'balance' => '0.00', // Compatibilidade
        'error' => 'Identificador inválido. Envie google_uid ou wallet_address.'
    ];
    
    if ($debug) {
        $resp['debug'] = [
            'input_keys' => array_keys($input),
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        ];
    }
    
    echo json_encode($resp);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    $player = getPlayerByIdentifier($pdo, $identifier);

    if (!$player) {
        // Jogador não existe ainda - retornar saldo zero
        echo json_encode([
            'success' => true,
            'balance_brl' => '0.00',
            'balance' => '0.00000000', // Compatibilidade legacy
            'staked_balance_brl' => '0.00',
            'total_earned_brl' => '0.00',
            'total_played' => 0,
            'is_new_player' => true
        ]);
        exit;
    }

    // Calcular stake rewards pendentes
    $stakeReward = 0;
    if ($player['staked_balance_brl'] > 0 && $player['last_stake_update']) {
        $secondsPassed = time() - strtotime($player['last_stake_update']);
        $dailyRate = STAKE_APY / 365;
        $daysElapsed = $secondsPassed / 86400;
        $stakeReward = $player['staked_balance_brl'] * (pow(1 + $dailyRate, $daysElapsed) - 1);
    }

    echo json_encode([
        'success' => true,
        'balance_brl' => number_format((float)$player['balance_brl'], 2, '.', ''),
        'balance' => number_format((float)$player['balance_usdt'], 8, '.', ''), // Compatibilidade legacy
        'staked_balance_brl' => number_format((float)$player['staked_balance_brl'], 2, '.', ''),
        'pending_stake_reward' => number_format($stakeReward, 2, '.', ''),
        'total_earned_brl' => number_format((float)$player['total_earned_brl'], 2, '.', ''),
        'total_withdrawn_brl' => number_format((float)$player['total_withdrawn_brl'], 2, '.', ''),
        'total_played' => (int)$player['total_played'],
        'is_banned' => (bool)$player['is_banned'],
        'display_name' => $player['display_name'] ?? null,
        'email' => $player['email'] ?? null,
        'is_new_player' => false
    ]);

} catch (Exception $e) {
    error_log("balance.php error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'balance_brl' => '0.00',
        'balance' => '0.00000000',
        'error' => 'Erro no servidor'
    ]);
}
?>
