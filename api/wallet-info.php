<?php
// ============================================
// UNOBIX - Wallet Info (Dashboard)
// Arquivo: api/wallet-info.php v3.0
// Usa config.php, trata colunas opcionais
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? '');

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    // Buscar usuário
    $player = findPlayer($pdo, $googleUid);

    if (!$player) {
        echo json_encode([
            'success' => true,
            'balance_brl' => 0,
            'total_earned_brl' => 0,
            'total_withdrawn_brl' => 0,
            'pending_withdrawal_brl' => 0,
            'staked_balance_brl' => 0,
            'total_played' => 0,
            'is_new_player' => true
        ]);
        exit;
    }

    // Valores básicos
    $balanceBrl = (float)($player['balance_brl'] ?? 0);
    $totalPlayed = (int)($player['total_played'] ?? 0);
    $totalEarnedBrl = (float)($player['total_earned_brl'] ?? 0);
    $totalWithdrawnBrl = (float)($player['total_withdrawn_brl'] ?? 0);
    $stakedBalanceBrl = (float)($player['staked_balance_brl'] ?? 0);

    // Buscar saques pendentes
    $pendingWithdrawalBrl = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_brl), 0) as total
            FROM withdrawals
            WHERE google_uid = ?
              AND status IN ('pending', 'processing')
        ");
        $stmt->execute([$player['google_uid']]);
        $pendingWithdrawalBrl = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        // Tabela pode não existir
    }

    // Calcular rendimento de stake pendente
    $pendingStakeReward = 0;
    if ($stakedBalanceBrl > 0 && !empty($player['last_stake_update'])) {
        $secondsPassed = time() - strtotime($player['last_stake_update']);
        $dailyRate = STAKE_APY / 365;
        $daysElapsed = $secondsPassed / 86400;
        $pendingStakeReward = $stakedBalanceBrl * (pow(1 + $dailyRate, $daysElapsed) - 1);
    }

    echo json_encode([
        'success' => true,
        'google_uid' => $googleUid,
        'balance_brl' => round($balanceBrl, 2),
        'total_earned_brl' => round($totalEarnedBrl, 2),
        'total_withdrawn_brl' => round($totalWithdrawnBrl, 2),
        'pending_withdrawal_brl' => round($pendingWithdrawalBrl, 2),
        'staked_balance_brl' => round($stakedBalanceBrl, 2),
        'pending_stake_reward_brl' => round($pendingStakeReward, 4),
        'total_played' => $totalPlayed,
        'player' => [
            'id' => (int)$player['id'],
            'google_uid' => $player['google_uid'],
            'email' => $player['email'] ?? null,
            'display_name' => $player['display_name'] ?? null
        ]
    ]);

} catch (Exception $e) {
    error_log("wallet-info.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
