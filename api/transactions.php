<?php
// api/transactions.php - Historico de transacoes COMPLETO
// v2.3 - Corrigido duplicatas stake detalhados (common, rare, epic, legendary)

require_once "../config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$wallet = isset($_GET['wallet']) ? $_GET['wallet'] : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$limit = max(1, min($limit, 50));

if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    echo json_encode(['success' => false, 'transactions' => [], 'error' => 'Invalid wallet']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $walletLower = strtolower($wallet);
    $transactions = [];
    
    // ============================================
    // 1. BUSCAR MISSOES (APENAS de game_sessions)
    // ============================================
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'game_sessions'")->fetch();
    if ($tableCheck) {
        // Verificar quais colunas existem
        $columns = $pdo->query("SHOW COLUMNS FROM game_sessions")->fetchAll(PDO::FETCH_COLUMN);
        
        $hasCommon = in_array('common_asteroids', $columns);
        $hasLegendary = in_array('legendary_asteroids', $columns);
        
        $selectFields = "
            id,
            mission_number,
            asteroids_destroyed,
            rare_asteroids,
            epic_asteroids,
            earnings_usdt,
            status,
            created_at
        ";
        
        if ($hasCommon) {
            $selectFields = str_replace('rare_asteroids,', 'common_asteroids, rare_asteroids,', $selectFields);
        }
        if ($hasLegendary) {
            $selectFields = str_replace('epic_asteroids,', 'epic_asteroids, legendary_asteroids,', $selectFields);
        }
        
        $stmt = $pdo->prepare("
            SELECT {$selectFields}
            FROM game_sessions 
            WHERE wallet_address = ? AND status = 'completed'
            ORDER BY created_at DESC
            LIMIT " . $limit
        );
        $stmt->execute([$walletLower]);
        $missions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($missions as $mission) {
            $missionNum = isset($mission['mission_number']) ? (int)$mission['mission_number'] : 0;
            $asteroidsDestroyed = isset($mission['asteroids_destroyed']) ? (int)$mission['asteroids_destroyed'] : 0;
            $commonAsteroids = isset($mission['common_asteroids']) ? (int)$mission['common_asteroids'] : 0;
            $rareAsteroids = isset($mission['rare_asteroids']) ? (int)$mission['rare_asteroids'] : 0;
            $epicAsteroids = isset($mission['epic_asteroids']) ? (int)$mission['epic_asteroids'] : 0;
            $legendaryAsteroids = isset($mission['legendary_asteroids']) ? (int)$mission['legendary_asteroids'] : 0;
            $earnings = isset($mission['earnings_usdt']) ? (float)$mission['earnings_usdt'] : 0;
            
            // Construir descrição com stats detalhados
            $desc = "Mission #" . $missionNum;
            $detailParts = [];
            
            if ($asteroidsDestroyed > 0) {
                $detailParts[] = $asteroidsDestroyed . " asteroids";
            }
            
            // Mostrar breakdown por tipo
            $typeParts = [];
            if ($legendaryAsteroids > 0) {
                $typeParts[] = $legendaryAsteroids . " legendary";
            }
            if ($epicAsteroids > 0) {
                $typeParts[] = $epicAsteroids . " epic";
            }
            if ($rareAsteroids > 0) {
                $typeParts[] = $rareAsteroids . " rare";
            }
            if ($commonAsteroids > 0) {
                $typeParts[] = $commonAsteroids . " common";
            }
            
            if (!empty($detailParts)) {
                $desc .= " | " . implode(", ", $detailParts);
            }
            if (!empty($typeParts)) {
                $desc .= " (" . implode(", ", $typeParts) . ")";
            }
            
            $transactions[] = [
                'id' => 'mission_' . $mission['id'],
                'type' => 'mission',
                'amount' => $earnings,
                'description' => $desc,
                'details' => [
                    'mission_number' => $missionNum,
                    'asteroids_destroyed' => $asteroidsDestroyed,
                    'common_asteroids' => $commonAsteroids,
                    'rare_asteroids' => $rareAsteroids,
                    'epic_asteroids' => $epicAsteroids,
                    'legendary_asteroids' => $legendaryAsteroids
                ],
                'status' => 'completed',
                'created_at' => $mission['created_at']
            ];
        }
    }
    
    // ============================================
    // 2. BUSCAR DA TABELA TRANSACTIONS
    // EXCLUINDO game_win e game_reward (já vem de game_sessions)
    // ============================================
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                type,
                amount,
                description,
                status,
                tx_hash,
                created_at
            FROM transactions 
            WHERE wallet_address = ?
            AND type NOT IN ('game_win', 'game_reward', 'stake', 'unstake')
            ORDER BY created_at DESC
            LIMIT " . $limit
        );
        $stmt->execute([$walletLower]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($txs as $tx) {
            $txType = isset($tx['type']) ? $tx['type'] : '';
            $txAmount = isset($tx['amount']) ? (float)$tx['amount'] : 0;
            $txStatus = isset($tx['status']) ? $tx['status'] : 'completed';
            $txHash = isset($tx['tx_hash']) ? $tx['tx_hash'] : null;
            $txDesc = isset($tx['description']) ? $tx['description'] : '';
            
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
                    if ($txStatus === 'pending') {
                        $title = 'Withdrawal Pending';
                    } elseif ($txStatus === 'completed') {
                        $title = 'Withdrawal Completed';
                    } elseif ($txStatus === 'failed') {
                        $title = 'Withdrawal Failed';
                    }
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
    
    // ============================================
    // 3. BUSCAR SAQUES (withdrawals)
    // ============================================
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'withdrawals'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                amount_usdt as amount,
                status,
                created_at,
                tx_hash
            FROM withdrawals 
            WHERE wallet_address = ?
            ORDER BY created_at DESC
            LIMIT " . $limit
        );
        $stmt->execute([$walletLower]);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($withdrawals as $withdrawal) {
            $status = isset($withdrawal['status']) ? $withdrawal['status'] : '';
            
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
            
            $txHash = isset($withdrawal['tx_hash']) ? $withdrawal['tx_hash'] : null;
            
            $transactions[] = [
                'id' => 'withdrawal_' . $withdrawal['id'],
                'type' => 'withdrawal',
                'amount' => -(float)$withdrawal['amount'],
                'description' => $statusText,
                'details' => [
                    'tx_hash' => $txHash
                ],
                'status' => $status,
                'created_at' => $withdrawal['created_at']
            ];
        }
    }
    
    // ============================================
    // 4. BUSCAR STAKING (stakes)
    // ============================================
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'stakes'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                amount,
                status,
                created_at
            FROM stakes 
            WHERE wallet_address = ?
            ORDER BY created_at DESC
            LIMIT " . $limit
        );
        $stmt->execute([$walletLower]);
        $stakes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($stakes as $stake) {
            $stakeAmount = (float)$stake['amount'] / 100000000;
            $stakeStatus = isset($stake['status']) ? $stake['status'] : 'active';
            
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
    
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'game_sessions'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(earnings_usdt), 0) as total 
            FROM game_sessions 
            WHERE wallet_address = ? AND status = 'completed'
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_missions'] = (int)$result['count'];
        $stats['total_earned'] = (float)$result['total'];
    }
    
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM transactions 
            WHERE wallet_address = ? AND type = 'referral_commission'
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_referral'] = (float)$result['total'];
        $stats['total_earned'] += $stats['total_referral'];
    }
    
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'withdrawals'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_usdt), 0) as total 
            FROM withdrawals 
            WHERE wallet_address = ? AND status IN ('approved', 'completed')
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_withdrawn'] = (float)$result['total'];
    }
    
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'stakes'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM stakes 
            WHERE wallet_address = ? AND status = 'active'
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_staked'] = (float)$result['total'] / 100000000;
    }
    
    echo json_encode([
        'success' => true,
        'transactions' => $uniqueTransactions,
        'stats' => $stats,
        'count' => count($uniqueTransactions)
    ]);
    
} catch (Exception $e) {
    error_log("Erro em transactions.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'transactions' => [], 
        'error' => 'Database error'
    ]);
}
?>