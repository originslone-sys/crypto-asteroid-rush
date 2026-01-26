<?php
// api/transactions.php - Historico de transacoes COMPLETO
// v2.3 - Corrigido duplicatas stake detalhados (common, rare, epic, legendary)
// v2.4 - Railway fixes: config path, JSON input, getDatabaseConnection, column compatibility

require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ---------- Helpers ----------
function tableExists(PDO $pdo, string $table): bool {
    // SHOW não aceita placeholder com prepares nativos do MySQL
    $quoted = $pdo->quote($table);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quoted}");
    return (bool)$stmt->fetchColumn();
}


function getColumns(PDO $pdo, string $table): array {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

function pickFirstExisting(array $cols, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return null;
}

// ---------- Input (JSON + POST + GET) ----------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

function pick_first($arr, $keys) {
    foreach ($keys as $k) {
        if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
    }
    return null;
}

$wallet = pick_first($input, ['wallet','wallet_address','walletAddress','address'])
      ?? pick_first($_POST, ['wallet','wallet_address','walletAddress','address'])
      ?? pick_first($_GET,  ['wallet','wallet_address','walletAddress','address'])
      ?? '';

$wallet = trim(strtolower($wallet));

$limit = $input['limit'] ?? ($_POST['limit'] ?? ($_GET['limit'] ?? 20));
$limit = (int)$limit;
$limit = max(1, min($limit, 50));

$debug = (isset($_GET['debug']) && $_GET['debug'] == '1');

if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    $resp = ['success' => false, 'transactions' => [], 'error' => 'Invalid wallet'];
    if ($debug) {
        $resp['debug'] = [
            'received_wallet' => $wallet,
            'content_type' => ($_SERVER['CONTENT_TYPE'] ?? ''),
            'raw_len' => strlen($raw),
            'input_keys' => array_keys($input),
            'get_keys' => array_keys($_GET),
            'post_keys' => array_keys($_POST),
        ];
    }
    echo json_encode($resp);
    exit;
}


try {
    // Railway-safe DB connection
    if (function_exists('getDatabaseConnection')) {
        $pdo = getDatabaseConnection();
        if (!$pdo) {
            throw new Exception("DB connection failed");
        }
    } else {
        // Fallback (não recomendado no Railway, mas mantém compatibilidade)
        $host = (defined('DB_HOST') && DB_HOST === 'localhost') ? '127.0.0.1' : (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
        $port = defined('DB_PORT') ? DB_PORT : 3306;
        $dsn  = "mysql:host={$host};port={$port};dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo  = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    $walletLower = strtolower($wallet);
    $transactions = [];

    // ============================================
// 1. BUSCAR MISSOES (APENAS de game_sessions)
// ============================================
if (tableExists($pdo, 'game_sessions')) {
    $columns = getColumns($pdo, 'game_sessions');

    // Detectar colunas (tudo que pode variar)
    $hasMissionNumber     = in_array('mission_number', $columns, true);
    $hasAsteroidsDestroyed= in_array('asteroids_destroyed', $columns, true);
    $hasCommon            = in_array('common_asteroids', $columns, true);
    $hasRare              = in_array('rare_asteroids', $columns, true);
    $hasEpic              = in_array('epic_asteroids', $columns, true);
    $hasLegendary         = in_array('legendary_asteroids', $columns, true);
    $hasEarnings          = in_array('earnings_usdt', $columns, true);
    $hasStatus            = in_array('status', $columns, true);

    // Data pode variar
    $createdCol = pickFirstExisting($columns, ['created_at', 'date', 'started_at']);
    if (!$createdCol) $createdCol = 'created_at'; // fallback (não deve ocorrer se existir tabela)

    // Monta select “à prova de schema”
    $selectFields = "
        id,
        " . ($hasMissionNumber ? "mission_number" : "0 AS mission_number") . ",
        " . ($hasAsteroidsDestroyed ? "asteroids_destroyed" : "0 AS asteroids_destroyed") . ",
        " . ($hasCommon ? "common_asteroids" : "0 AS common_asteroids") . ",
        " . ($hasRare ? "rare_asteroids" : "0 AS rare_asteroids") . ",
        " . ($hasEpic ? "epic_asteroids" : "0 AS epic_asteroids") . ",
        " . ($hasLegendary ? "legendary_asteroids" : "0 AS legendary_asteroids") . ",
        " . ($hasEarnings ? "earnings_usdt" : "0 AS earnings_usdt") . ",
        " . ($hasStatus ? "status" : "'completed' AS status") . ",
        {$createdCol} AS created_at
    ";

    $stmt = $pdo->prepare("
        SELECT {$selectFields}
        FROM game_sessions
        WHERE LOWER(wallet_address) = ? AND status = 'completed'
        ORDER BY {$createdCol} DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$walletLower]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sessions as $s) {
        $transactions[] = [
            'id' => 'mission_' . ($s['id'] ?? ''),
            'type' => 'mission',
            'amount' => (float)($s['earnings_usdt'] ?? 0),
            'description' => 'Mission #' . (int)($s['mission_number'] ?? 0),
            'status' => $s['status'] ?? 'completed',
            'tx_hash' => null,
            'created_at' => $s['created_at'] ?? null,
            'asteroids' => [
                'common' => (int)($s['common_asteroids'] ?? 0),
                'rare' => (int)($s['rare_asteroids'] ?? 0),
                'epic' => (int)($s['epic_asteroids'] ?? 0),
                'legendary' => (int)($s['legendary_asteroids'] ?? 0),
                'total' => (int)($s['asteroids_destroyed'] ?? 0)
            ]
        ];
    }
}

    // ============================================
    // 2. BUSCAR DA TABELA TRANSACTIONS
    // EXCLUINDO game_win e game_reward (já vem de game_sessions)
    // ============================================
    if (tableExists($pdo, 'transactions')) {
        $txCols = getColumns($pdo, 'transactions');

        $amountCol = pickFirstExisting($txCols, ['amount_usdt', 'amount']);
        $dateCol   = pickFirstExisting($txCols, ['created_at', 'date']);
        $hasTxHash = in_array('tx_hash', $txCols, true);
        $hasDesc   = in_array('description', $txCols, true);
        $hasStatus = in_array('status', $txCols, true);

        if ($amountCol && $dateCol) {
            $select = "
                id,
                type,
                {$amountCol} AS amount,
                " . ($hasDesc ? "description" : "'' AS description") . ",
                " . ($hasStatus ? "status" : "'completed' AS status") . ",
                " . ($hasTxHash ? "tx_hash" : "NULL AS tx_hash") . ",
                {$dateCol} AS created_at
            ";

            $stmt = $pdo->prepare("
                SELECT {$select}
                FROM transactions
                WHERE wallet_address = ?
                  AND type NOT IN ('game_win', 'game_reward', 'stake', 'unstake')
                ORDER BY {$dateCol} DESC
                LIMIT " . (int)$limit
            );
            $stmt->execute([$walletLower]);
            $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($txs as $tx) {
                $txType = $tx['type'] ?? '';
                $txAmount = isset($tx['amount']) ? (float)$tx['amount'] : 0.0;
                $txStatus = $tx['status'] ?? 'completed';
                $txHash = $tx['tx_hash'] ?? null;
                $txDesc = $tx['description'] ?? '';

                $displayType = $txType;
                $title = $txDesc;
                $isNegative = false;

                switch ($txType) {
                    case 'stake':
                        $title = 'Staked USDT';
                        $isNegative = true;
                        break;
                    case 'unstake':
                        $title = 'Unstaked USDT';
                        $isNegative = false;
                        break;
                    case 'withdraw':
                        $title = 'Withdrawal';
                        if ($txStatus === 'pending') $title = 'Withdrawal Pending';
                        elseif ($txStatus === 'completed') $title = 'Withdrawal Completed';
                        elseif ($txStatus === 'failed') $title = 'Withdrawal Failed';
                        $isNegative = true;
                        break;
                    case 'deposit':
                        $title = 'Deposit';
                        $isNegative = false;
                        break;
                    case 'referral_commission':
                        $title = $txDesc ? $txDesc : 'Referral Commission';
                        $displayType = 'referral';
                        $isNegative = false;
                        break;
                    default:
                        $title = $txDesc ? $txDesc : 'Transaction';
                }

                $transactions[] = [
                    'id' => 'tx_' . $tx['id'],
                    'type' => $displayType,
                    'amount' => $isNegative ? -abs($txAmount) : $txAmount,
                    'description' => $title,
                    'details' => [
                        'tx_hash' => $txHash,
                        'original_desc' => $txDesc
                    ],
                    'status' => $txStatus,
                    'created_at' => $tx['created_at']
                ];
            }
        }
    }

    // ============================================
    // 3. BUSCAR SAQUES (withdrawals)
    // ============================================
    if (tableExists($pdo, 'withdrawals')) {
        $wCols = getColumns($pdo, 'withdrawals');
        $wAmountCol = pickFirstExisting($wCols, ['amount_usdt', 'amount']);
        $wDateCol   = pickFirstExisting($wCols, ['created_at', 'date']);
        $wHasTxHash = in_array('tx_hash', $wCols, true);
        $wHasStatus = in_array('status', $wCols, true);

        if ($wAmountCol && $wDateCol) {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    {$wAmountCol} AS amount,
                    " . ($wHasStatus ? "status" : "'pending' AS status") . ",
                    {$wDateCol} AS created_at,
                    " . ($wHasTxHash ? "tx_hash" : "NULL AS tx_hash") . "
                FROM withdrawals
                WHERE wallet_address = ?
                ORDER BY {$wDateCol} DESC
                LIMIT " . (int)$limit
            );
            $stmt->execute([$walletLower]);
            $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($withdrawals as $withdrawal) {
                $status = $withdrawal['status'] ?? '';

                switch ($status) {
                    case 'pending':
                        $statusText = 'Withdrawal Pending';
                        break;
                    case 'approved':
                    case 'completed':
                        $statusText = 'Withdrawal Completed';
                        break;
                    case 'rejected':
                        $statusText = 'Withdrawal Rejected';
                        break;
                    default:
                        $statusText = 'Withdrawal';
                }

                $txHash = $withdrawal['tx_hash'] ?? null;

                $transactions[] = [
                    'id' => 'withdrawal_' . $withdrawal['id'],
                    'type' => 'withdrawal',
                    'amount' => -(float)($withdrawal['amount'] ?? 0),
                    'description' => $statusText,
                    'details' => [
                        'tx_hash' => $txHash
                    ],
                    'status' => $status,
                    'created_at' => $withdrawal['created_at']
                ];
            }
        }
    }

    // ============================================
    // 4. BUSCAR STAKING (stakes)
    // ============================================
    if (tableExists($pdo, 'stakes')) {
        $stmt = $pdo->prepare("
            SELECT
                id,
                amount,
                status,
                created_at
            FROM stakes
            WHERE wallet_address = ?
            ORDER BY created_at DESC
            LIMIT " . (int)$limit
        );
        $stmt->execute([$walletLower]);
        $stakes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($stakes as $stake) {
            $stakeAmount = (float)($stake['amount'] ?? 0) / 100000000;
            $stakeStatus = $stake['status'] ?? 'active';

            if ($stakeStatus === 'completed') {
                $transactions[] = [
                    'id' => 'unstake_' . $stake['id'],
                    'type' => 'unstake',
                    'amount' => $stakeAmount,
                    'description' => 'Unstaked USDT',
                    'details' => [],
                    'status' => 'completed',
                    'created_at' => $stake['created_at']
                ];
            } else {
                $transactions[] = [
                    'id' => 'stake_' . $stake['id'],
                    'type' => 'stake',
                    'amount' => -$stakeAmount,
                    'description' => 'Staked USDT',
                    'details' => [],
                    'status' => $stakeStatus,
                    'created_at' => $stake['created_at']
                ];
            }
        }
    }

    // ============================================
    // 5. REMOVER DUPLICATAS E ORDENAR
    // ============================================
    $uniqueTransactions = [];
    $seen = [];

    foreach ($transactions as $tx) {
        $key = $tx['id'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $uniqueTransactions[] = $tx;
        }
    }

    usort($uniqueTransactions, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    $uniqueTransactions = array_slice($uniqueTransactions, 0, $limit);

    // ============================================
    // 6. CALCULAR ESTATISTICAS
    // ============================================
    $stats = [
        'total_missions' => 0,
        'total_earned' => 0.0,
        'total_withdrawn' => 0.0,
        'total_staked' => 0.0,
        'total_referral' => 0.0
    ];

    if (tableExists($pdo, 'game_sessions')) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(earnings_usdt), 0) as total
            FROM game_sessions
            WHERE wallet_address = ? AND status = 'completed'
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_missions'] = (int)($result['count'] ?? 0);
        $stats['total_earned'] = (float)($result['total'] ?? 0);
    }

    if (tableExists($pdo, 'transactions')) {
        $txCols = getColumns($pdo, 'transactions');
        $amountCol = pickFirstExisting($txCols, ['amount_usdt', 'amount']);

        if ($amountCol) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM({$amountCol}), 0) as total
                FROM transactions
                WHERE wallet_address = ? AND type = 'referral_commission'
            ");
            $stmt->execute([$walletLower]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_referral'] = (float)($result['total'] ?? 0);
            $stats['total_earned'] += $stats['total_referral'];
        }
    }

    if (tableExists($pdo, 'withdrawals')) {
        $wCols = getColumns($pdo, 'withdrawals');
        $wAmountCol = pickFirstExisting($wCols, ['amount_usdt', 'amount']);

        if ($wAmountCol) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM({$wAmountCol}), 0) as total
                FROM withdrawals
                WHERE wallet_address = ? AND status IN ('approved', 'completed')
            ");
            $stmt->execute([$walletLower]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_withdrawn'] = (float)($result['total'] ?? 0);
        }
    }

    if (tableExists($pdo, 'stakes')) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM stakes
            WHERE wallet_address = ? AND status = 'active'
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_staked'] = (float)($result['total'] ?? 0) / 100000000;
    }

    echo json_encode([
        'success' => true,
        'transactions' => $uniqueTransactions,
        'stats' => $stats,
        'count' => count($uniqueTransactions)
    ]);

} catch (Exception $e) {
    error_log("Erro em transactions.php: " . $e->getMessage());
    $debug = (isset($_GET['debug']) && $_GET['debug'] == '1');

    $resp = [
        'success' => false,
        'transactions' => [],
        'error' => 'Database error'
    ];
    if ($debug) {
        $resp['debug_error'] = $e->getMessage();
    }
    echo json_encode($resp);
}





