<?php
// ============================================
// UNOBIX - Wallet Info (Dashboard/Wallet/Staking)
// Arquivo: api/wallet-info.php
// v2.0 - Google Auth + BRL
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// ----------------------------
// Input (JSON + POST + GET)
// ----------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$googleUid = $input['google_uid'] ?? ($_POST['google_uid'] ?? ($_GET['google_uid'] ?? ''));
$wallet = $input['wallet'] ?? ($_POST['wallet'] ?? ($_GET['wallet'] ?? ''));

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

function tableExists(PDO $pdo, string $table): bool {
    $q = $pdo->quote($table);
    $stmt = $pdo->query("SHOW TABLES LIKE {$q}");
    return (bool)$stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array {
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[] = $row['Field'];
    }
    return $cols;
}

function pickFirstExisting(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return null;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    // Defaults
    $balanceBrl = 0.0;
    $totalPlayed = 0;
    $totalWithdrawnBrl = 0.0;
    $pendingWithdrawalBrl = 0.0;
    $totalEarnedBrl = 0.0;
    $stakedBalanceBrl = 0.0;
    $playerData = null;

    // ============================================
    // 1) Players (saldo + total_played)
    // ============================================
    if (tableExists($pdo, 'players')) {
        $whereClause = $identifierType === 'google_uid'
            ? "(google_uid = ? OR wallet_address = ?)"
            : "wallet_address = ?";
        $params = $identifierType === 'google_uid'
            ? [$identifier, $identifier]
            : [$identifier];

        $stmt = $pdo->prepare("
            SELECT id, google_uid, wallet_address, email, display_name,
                   balance_brl, balance_usdt, total_played, 
                   total_earned_brl, total_withdrawn_brl, staked_balance_brl
            FROM players
            WHERE {$whereClause}
            LIMIT 1
        ");
        $stmt->execute($params);
        $playerData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($playerData) {
            $balanceBrl = (float)($playerData['balance_brl'] ?? 0);
            $totalPlayed = (int)($playerData['total_played'] ?? 0);
            $totalEarnedBrl = (float)($playerData['total_earned_brl'] ?? 0);
            $totalWithdrawnBrl = (float)($playerData['total_withdrawn_brl'] ?? 0);
            $stakedBalanceBrl = (float)($playerData['staked_balance_brl'] ?? 0);
            
            // Pegar wallet e google_uid para usar nas próximas queries
            $wallet = $playerData['wallet_address'] ?? '';
            $googleUid = $playerData['google_uid'] ?? '';
        }
    }

    // ============================================
    // 2) Withdrawals (pending + total withdrawn)
    // ============================================
    if (tableExists($pdo, 'withdrawals') && $playerData) {
        $wCols = getColumns($pdo, 'withdrawals');
        $amountCol = pickFirstExisting($wCols, ['amount_brl', 'amount_usdt', 'amount']);
        $statusCol = pickFirstExisting($wCols, ['status']);

        if ($amountCol && $statusCol) {
            // Pending
            $pendingStatuses = ['pending', 'requested', 'processing', 'awaiting', 'waiting'];
            $inPending = implode(',', array_fill(0, count($pendingStatuses), '?'));
            
            $params = array_merge([$googleUid, $wallet], $pendingStatuses);
            
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM({$amountCol}), 0) as total
                FROM withdrawals
                WHERE (google_uid = ? OR LOWER(wallet_address) = ?)
                  AND {$statusCol} IN ({$inPending})
            ");
            $stmt->execute($params);
            $pendingWithdrawalBrl = (float)$stmt->fetchColumn();

            // Completed (se não tiver no players)
            if ($totalWithdrawnBrl == 0) {
                $doneStatuses = ['approved', 'completed', 'paid', 'success'];
                $inDone = implode(',', array_fill(0, count($doneStatuses), '?'));
                $params2 = array_merge([$googleUid, $wallet], $doneStatuses);

                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM({$amountCol}), 0) as total
                    FROM withdrawals
                    WHERE (google_uid = ? OR LOWER(wallet_address) = ?)
                      AND {$statusCol} IN ({$inDone})
                ");
                $stmt->execute($params2);
                $totalWithdrawnBrl = (float)$stmt->fetchColumn();
            }
        }
    }

    // ============================================
    // 3) Total Earned (se não tiver no players)
    // ============================================
    if ($totalEarnedBrl == 0 && $playerData) {
        // 3a) Missões: game_sessions.earnings_brl
        if (tableExists($pdo, 'game_sessions')) {
            $gsCols = getColumns($pdo, 'game_sessions');
            $earningsCol = pickFirstExisting($gsCols, ['earnings_brl', 'earnings_usdt']);
            
            if ($earningsCol) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM({$earningsCol}), 0)
                    FROM game_sessions
                    WHERE (google_uid = ? OR LOWER(wallet_address) = ?)
                      AND status = 'completed'
                ");
                $stmt->execute([$googleUid, $wallet]);
                $totalEarnedBrl += (float)$stmt->fetchColumn();
            }
        }

        // 3b) Transactions: créditos
        if (tableExists($pdo, 'transactions')) {
            $txCols = getColumns($pdo, 'transactions');
            $amountCol = pickFirstExisting($txCols, ['amount_brl', 'amount_usdt', 'amount']);
            $typeCol = pickFirstExisting($txCols, ['type']);
            $statusCol = pickFirstExisting($txCols, ['status']);

            if ($amountCol && $typeCol) {
                $creditTypes = ['game_reward', 'referral_commission', 'unstake', 'withdrawal_rejected'];
                $inTypes = implode(',', array_fill(0, count($creditTypes), '?'));
                $params = array_merge([$googleUid, $wallet], $creditTypes);

                $statusFilter = $statusCol ? "AND {$statusCol} IN ('completed','success','approved')" : "";
                
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM({$amountCol}), 0)
                    FROM transactions
                    WHERE (google_uid = ? OR LOWER(wallet_address) = ?)
                      AND {$typeCol} IN ({$inTypes})
                      {$statusFilter}
                ");
                $stmt->execute($params);
                $totalEarnedBrl += (float)$stmt->fetchColumn();
            }
        }
    }

    // ============================================
    // 4) Calcular rendimento pendente de stake
    // ============================================
    $pendingStakeReward = 0;
    if ($playerData) {
        try {
            $stmt = $pdo->prepare("CALL sp_calculate_stake_reward(?)");
            $stmt->execute([$identifier]);
            $rewardCalc = $stmt->fetch();
            $stmt->closeCursor();
            
            if ($rewardCalc) {
                $pendingStakeReward = (float)($rewardCalc['reward'] ?? 0);
            }
        } catch (Exception $e) {
            // SP pode não existir
        }
    }

    // ============================================
    // Output
    // ============================================
    $response = [
        'success' => true,
        'identifier' => $identifier,
        'identifier_type' => $identifierType,

        // Valores em BRL (novo sistema)
        'balance_brl' => round($balanceBrl, 2),
        'total_earned_brl' => round($totalEarnedBrl, 2),
        'total_withdrawn_brl' => round($totalWithdrawnBrl, 2),
        'pending_withdrawal_brl' => round($pendingWithdrawalBrl, 2),
        'staked_balance_brl' => round($stakedBalanceBrl, 2),
        'pending_stake_reward_brl' => round($pendingStakeReward, 4),
        'total_played' => $totalPlayed,

        // Compatibilidade com sistema antigo (mesmos valores)
        'balance' => number_format($balanceBrl, 8, '.', ''),
        'balance_usdt' => number_format($balanceBrl, 8, '.', ''),
        'total_earned' => number_format($totalEarnedBrl, 8, '.', ''),
        'total_withdrawn' => number_format($totalWithdrawnBrl, 8, '.', ''),
        'pending_withdrawal' => number_format($pendingWithdrawalBrl, 8, '.', ''),
    ];

    // Adicionar dados do player se existir
    if ($playerData) {
        $response['player'] = [
            'id' => (int)$playerData['id'],
            'google_uid' => $playerData['google_uid'],
            'email' => $playerData['email'],
            'display_name' => $playerData['display_name'],
            'wallet_address' => $playerData['wallet_address']
        ];
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log("wallet-info.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
