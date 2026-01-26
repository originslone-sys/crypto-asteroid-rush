<?php
require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ---------- Input (JSON + POST + GET) ----------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet = $input['wallet'] ?? ($_POST['wallet'] ?? ($_GET['wallet'] ?? ''));
$wallet = trim(strtolower($wallet));

if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Erro ao conectar ao banco');

    // ---------- Player ----------
    $stmt = $pdo->prepare("
        SELECT id, balance_usdt, total_played
        FROM players
        WHERE wallet_address = ?
        LIMIT 1
    ");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        // cria player (comportamento igual ao seu original)
        $pdo->prepare("INSERT INTO players (wallet_address, balance_usdt, total_played) VALUES (?, 0.0, 0)")
            ->execute([$wallet]);

        echo json_encode([
            'success' => true,
            'player_exists' => false,
            'balance_usdt' => 0.0,
            'total_played' => 0,
            'total_earned' => 0.0,
            'total_withdrawn' => 0.0,
            'pending_withdrawal' => 0.0,
            'transactions' => []
        ]);
        exit;
    }

    $balanceUsdt = (float)$player['balance_usdt'];
    $totalPlayed = (int)$player['total_played'];

    // ---------- Total earned (mesma lógica do seu original: soma de entradas) ----------
    $totalEarned = 0.0;

    // 1) ganhos de missões (game_sessions.earnings_usdt)
    $hasGameSessions = (bool)$pdo->query("SHOW TABLES LIKE 'game_sessions'")->fetch();
    if ($hasGameSessions) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(earnings_usdt), 0) AS total
            FROM game_sessions
            WHERE wallet_address = ?
        ");
        $stmt->execute([$wallet]);
        $totalEarned += (float)$stmt->fetchColumn();
    }

    // 2) comissões (transactions) — coluna pode ser amount_usdt OU amount
    $transactions = [];
    $hasTransactions = (bool)$pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();

    $amountCol = null;
    $createdCol = null;

    if ($hasTransactions) {
        $cols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN, 0);

        if (in_array('amount_usdt', $cols, true)) $amountCol = 'amount_usdt';
        elseif (in_array('amount', $cols, true))  $amountCol = 'amount';

        if (in_array('created_at', $cols, true)) $createdCol = 'created_at';
        elseif (in_array('date', $cols, true))   $createdCol = 'date';

        // total de referral_commission
        if ($amountCol) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM($amountCol), 0) AS total
                FROM transactions
                WHERE wallet_address = ?
                  AND type = 'referral_commission'
            ");
            $stmt->execute([$wallet]);
            $totalEarned += (float)$stmt->fetchColumn();
        }

        // lista de transações (se tiver colunas necessárias)
        if ($amountCol && $createdCol) {
            $stmt = $pdo->prepare("
                SELECT type, $amountCol AS amount, $createdCol AS created_at
                FROM transactions
                WHERE wallet_address = ?
                ORDER BY $createdCol DESC
                LIMIT 50
            ");
            $stmt->execute([$wallet]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // ---------- Withdrawals (opcional: só se você tiver tabela/colunas) ----------
    $totalWithdrawn = 0.0;
    $pendingWithdrawal = 0.0;

    // Se existir tabela withdrawals, tenta calcular
    $hasWithdrawals = (bool)$pdo->query("SHOW TABLES LIKE 'withdrawals'")->fetch();
    if ($hasWithdrawals) {
        $wcols = $pdo->query("SHOW COLUMNS FROM withdrawals")->fetchAll(PDO::FETCH_COLUMN, 0);

        $wAmountCol = in_array('amount_usdt', $wcols, true) ? 'amount_usdt' : (in_array('amount', $wcols, true) ? 'amount' : null);
        $wStatusCol = in_array('status', $wcols, true) ? 'status' : null;

        if ($wAmountCol) {
            // total withdraw (status paid/approved)
            if ($wStatusCol) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM($wAmountCol), 0)
                    FROM withdrawals
                    WHERE wallet_address = ?
                      AND $wStatusCol IN ('paid', 'approved', 'completed')
                ");
                $stmt->execute([$wallet]);
                $totalWithdrawn = (float)$stmt->fetchColumn();

                // pending
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM($wAmountCol), 0)
                    FROM withdrawals
                    WHERE wallet_address = ?
                      AND $wStatusCol IN ('pending', 'processing')
                ");
                $stmt->execute([$wallet]);
                $pendingWithdrawal = (float)$stmt->fetchColumn();
            }
        }
    }

    echo json_encode([
        'success' => true,
        'player_exists' => true,
        'balance_usdt' => $balanceUsdt,
        'total_played' => $totalPlayed,
        'total_earned' => (float)$totalEarned,
        'total_withdrawn' => (float)$totalWithdrawn,
        'pending_withdrawal' => (float)$pendingWithdrawal,
        'transactions' => $transactions
    ]);

} catch (Exception $e) {
    error_log("wallet-info error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
