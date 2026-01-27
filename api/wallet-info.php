<?php
// ============================================
// CRYPTO ASTEROID RUSH - Wallet Info (Dashboard/Wallet/Staking)
// Arquivo: api/wallet-info.php
// Opção A: preservar funcionalidades, apenas corrigir para Railway + schema variável
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true]);
    exit;
}

// ----------------------------
// Input (JSON + POST + GET)
// ----------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet = $input['wallet'] ?? ($_POST['wallet'] ?? ($_GET['wallet'] ?? ''));
$wallet = trim(strtolower($wallet));

if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

function tableExists(PDO $pdo, string $table): bool {
    // SHOW não aceita placeholder em prepares nativos
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

    $walletLower = $wallet;

    // Defaults
    $balance = 0.0;
    $totalPlayed = 0;
    $totalWithdrawn = 0.0;
    $pendingWithdrawal = 0.0;
    $totalEarned = 0.0;

    // ============================================
    // 1) Players (saldo + total_played)
    // ============================================
    if (tableExists($pdo, 'players')) {
        $stmt = $pdo->prepare("
            SELECT balance_usdt, total_played
            FROM players
            WHERE LOWER(wallet_address) = ?
            LIMIT 1
        ");
        $stmt->execute([$walletLower]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player) {
            $balance = (float)($player['balance_usdt'] ?? 0);
            $totalPlayed = (int)($player['total_played'] ?? 0);
        }
    }

    // ============================================
    // 2) Withdrawals (pending + total withdrawn)
    // ============================================
    if (tableExists($pdo, 'withdrawals')) {
        $wCols = getColumns($pdo, 'withdrawals');
        $amountCol = pickFirstExisting($wCols, ['amount_usdt', 'amount']);
        $statusCol = pickFirstExisting($wCols, ['status']);
        $walletCol = pickFirstExisting($wCols, ['wallet_address', 'wallet']);

        if ($amountCol && $statusCol && $walletCol) {
            // Pending statuses (compatível com variações)
            $pendingStatuses = ['pending', 'requested', 'processing', 'awaiting', 'waiting'];

            $inPending = implode(',', array_fill(0, count($pendingStatuses), '?'));
            $params = array_merge([$walletLower], $pendingStatuses);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM({$amountCol}), 0) as total
                FROM withdrawals
                WHERE LOWER({$walletCol}) = ?
                  AND {$statusCol} IN ({$inPending})
            ");
            $stmt->execute($params);
            $pendingWithdrawal = (float)$stmt->fetchColumn();

            // Completed/approved
            $doneStatuses = ['approved', 'completed', 'paid', 'success'];
            $inDone = implode(',', array_fill(0, count($doneStatuses), '?'));
            $params2 = array_merge([$walletLower], $doneStatuses);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM({$amountCol}), 0) as total
                FROM withdrawals
                WHERE LOWER({$walletCol}) = ?
                  AND {$statusCol} IN ({$inDone})
            ");
            $stmt->execute($params2);
            $totalWithdrawn = (float)$stmt->fetchColumn();
        }
    }

    // ============================================
    // 3) Total Earned (créditos, ignorando saídas)
    //    Regra: somar créditos de missões + créditos de transactions
    // ============================================

    // 3a) Missões: game_sessions.earnings_usdt (se existir)
    if (tableExists($pdo, 'game_sessions')) {
        $gsCols = getColumns($pdo, 'game_sessions');
        if (in_array('earnings_usdt', $gsCols, true)) {
            $statusCol = pickFirstExisting($gsCols, ['status']);
            $walletCol = pickFirstExisting($gsCols, ['wallet_address', 'wallet']);

            if ($walletCol) {
                if ($statusCol) {
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(SUM(earnings_usdt), 0)
                        FROM game_sessions
                        WHERE LOWER({$walletCol}) = ?
                          AND {$statusCol} = 'completed'
                    ");
                    $stmt->execute([$walletLower]);
                } else {
                    // Sem status: soma tudo do wallet (fallback)
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(SUM(earnings_usdt), 0)
                        FROM game_sessions
                        WHERE LOWER({$walletCol}) = ?
                    ");
                    $stmt->execute([$walletLower]);
                }

                $totalEarned += (float)$stmt->fetchColumn();
            }
        }
    }

    // 3b) Transactions: somar créditos comuns (e fallback amount>0)
    if (tableExists($pdo, 'transactions')) {
        $txCols = getColumns($pdo, 'transactions');
        $amountCol = pickFirstExisting($txCols, ['amount_usdt', 'amount']);
        $walletCol = pickFirstExisting($txCols, ['wallet_address', 'wallet']);
        $typeCol = pickFirstExisting($txCols, ['type']);
        $statusCol = pickFirstExisting($txCols, ['status']);

        if ($amountCol && $walletCol) {
            // Tipos de crédito mais comuns nesse projeto
            $creditTypes = ['game_reward', 'referral_commission', 'unstake', 'withdrawal_rejected'];

            if ($typeCol) {
                $inTypes = implode(',', array_fill(0, count($creditTypes), '?'));
                $params = array_merge([$walletLower], $creditTypes);

                // Se tiver status, preferir completed
                if ($statusCol) {
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(SUM({$amountCol}), 0)
                        FROM transactions
                        WHERE LOWER({$walletCol}) = ?
                          AND {$typeCol} IN ({$inTypes})
                          AND {$statusCol} IN ('completed','success','approved')
                    ");
                    $stmt->execute($params);
                    $totalEarned += (float)$stmt->fetchColumn();
                } else {
                    $stmt = $pdo->prepare("
                        SELECT COALESCE(SUM({$amountCol}), 0)
                        FROM transactions
                        WHERE LOWER({$walletCol}) = ?
                          AND {$typeCol} IN ({$inTypes})
                    ");
                    $stmt->execute($params);
                    $totalEarned += (float)$stmt->fetchColumn();
                }
            } else {
                // Fallback: sem coluna type → soma tudo positivo
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(CASE WHEN {$amountCol} > 0 THEN {$amountCol} ELSE 0 END), 0)
                    FROM transactions
                    WHERE LOWER({$walletCol}) = ?
                ");
                $stmt->execute([$walletLower]);
                $totalEarned += (float)$stmt->fetchColumn();
            }
        }
    }

    // ============================================
    // Output (campos usados pelo main.js)
    // main.js lê: balance, total_earned, total_played, total_withdrawn, pending_withdrawal
    // ============================================
    echo json_encode([
        'success' => true,
        'wallet' => $walletLower,

        'balance' => number_format($balance, 8, '.', ''),
        'balance_usdt' => number_format($balance, 8, '.', ''), // compatibilidade

        'total_earned' => number_format($totalEarned, 8, '.', ''),
        'total_withdrawn' => number_format($totalWithdrawn, 8, '.', ''),
        'pending_withdrawal' => number_format($pendingWithdrawal, 8, '.', ''),
        'total_played' => (int)$totalPlayed
    ]);

} catch (Exception $e) {
    error_log("wallet-info.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
