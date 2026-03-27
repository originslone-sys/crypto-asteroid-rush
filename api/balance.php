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
    $hasCredits = in_array('credits', $availableCols);
    $hasWhatsapp = in_array('whatsapp', $availableCols);
    $hasIsPremium = in_array('is_premium', $availableCols);
    $hasPremiumExpires = in_array('premium_expires_at', $availableCols);

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
    $credits = $hasCredits ? (int)($player['credits'] ?? 0) : 0;
    $whatsapp = $hasWhatsapp ? ($player['whatsapp'] ?? null) : null;
    $isPremium = false;
    $premiumExpiresAt = null;
    if ($hasIsPremium && $hasPremiumExpires) {
        $premiumExpiresAt = $player['premium_expires_at'] ?? null;
        $isPremium = !empty($player['is_premium']) && !empty($premiumExpiresAt) && strtotime($premiumExpiresAt) > time();
        if (!$isPremium) $premiumExpiresAt = null;
    }

    // Staking descontinuado — reward sempre zero
    $stakeReward = 0.0;

    // Calcular saldo pendente de saques
    $pendingWithdrawalBrl = 0.0;
    try {
        $wdStmt = $pdo->prepare("SELECT SUM(amount_brl) FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'processing')");
        $wdStmt->execute([$player['id']]);
        $pendingWithdrawalBrl = (float)($wdStmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        // Tabela pode não existir ainda
    }

    echo json_encode([
        'success' => true,
        'google_uid' => $googleUid,
        'balance_brl' => number_format($balanceBrl, 6, '.', ''),
        'staked_balance_brl' => number_format($stakedBrl, 6, '.', ''),
        'pending_stake_reward' => number_format($stakeReward, 6, '.', ''),
        'pending_withdrawal_brl' => number_format($pendingWithdrawalBrl, 6, '.', ''),
        'total_earned_brl' => number_format($totalEarnedBrl, 6, '.', ''),
        'total_withdrawn_brl' => number_format($totalWithdrawnBrl, 6, '.', ''),
        'total_played' => $totalPlayed,
        'credits' => $credits,
        'credits_per_game' => defined('CREDITS_PER_GAME') ? CREDITS_PER_GAME : 1,
        'is_banned' => $isBanned,
        'display_name' => $player['display_name'] ?? null,
        'email' => $player['email'] ?? null,
        'is_new_player' => false,
        'has_whatsapp' => !empty($whatsapp),
        'is_premium' => $isPremium,
        'premium_expires_at' => $premiumExpiresAt
    ]);

} catch (Throwable $e) {
    error_log("balance.php error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'balance_brl' => '0.000000',
        'error' => 'Erro no servidor'
    ]);
}
