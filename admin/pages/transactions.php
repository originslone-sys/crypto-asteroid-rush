<?php
// ============================================
// CRYPTO ASTEROID RUSH - Transações
// Arquivo: admin/pages/transactions.php
// v3.0 - Com paginação
// ============================================

$pageTitle = 'Transações';

$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

function buildMissionDescription($m) {
    $parts = ["Mission #{$m['mission_number']}"];
    
    $asteroidParts = [];
    if (!empty($m['legendary_asteroids']) && $m['legendary_asteroids'] > 0) $asteroidParts[] = "{$m['legendary_asteroids']} legendary";
    if (!empty($m['epic_asteroids']) && $m['epic_asteroids'] > 0) $asteroidParts[] = "{$m['epic_asteroids']} epic";
    if (!empty($m['rare_asteroids']) && $m['rare_asteroids'] > 0) $asteroidParts[] = "{$m['rare_asteroids']} rare";
    if (!empty($m['common_asteroids']) && $m['common_asteroids'] > 0) $asteroidParts[] = "{$m['common_asteroids']} common";
    
    if (!empty($asteroidParts)) {
        $parts[] = $m['asteroids_destroyed'] . " asteroids (" . implode(", ", $asteroidParts) . ")";
    } else {
        $parts[] = $m['asteroids_destroyed'] . " asteroids";
    }
    
    return implode(" | ", $parts);
}

try {
    $hasTransactions = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    $hasGameSessions = $pdo->query("SHOW TABLES LIKE 'game_sessions'")->fetch();
    
    $gsColumns = [];
    if ($hasGameSessions) {
        $cols = $pdo->query("SHOW COLUMNS FROM game_sessions")->fetchAll(PDO::FETCH_COLUMN);
        $gsColumns = $cols;
    }
    
    $hasCommonAsteroids = in_array('common_asteroids', $gsColumns);
    $hasLegendaryAsteroids = in_array('legendary_asteroids', $gsColumns);
    $hasAlertLevel = in_array('alert_level', $gsColumns);
    
    // ============================================
    // CONTAR TOTAL PARA PAGINAÇÃO
    // ============================================
    $totalRecords = 0;
    
    if ($hasGameSessions && ($filter === 'all' || $filter === 'mission')) {
        $countSql = "SELECT COUNT(*) FROM game_sessions WHERE status IN ('completed', 'flagged')";
        $countParams = [];
        if ($search) {
            $countSql .= " AND wallet_address LIKE ?";
            $countParams[] = "%$search%";
        }
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($countParams);
        $totalRecords += (int)$stmt->fetchColumn();
    }
    
    if ($hasTransactions && $filter !== 'mission') {
        $countSql = "SELECT COUNT(*) FROM transactions WHERE 1=1";
        $countParams = [];
        if ($filter === 'all') {
            $countSql .= " AND type NOT IN ('game_win', 'game_reward')";
        } elseif ($filter !== 'all') {
            $countSql .= " AND type = ?";
            $countParams[] = $filter;
        }
        if ($search) {
            $countSql .= " AND wallet_address LIKE ?";
            $countParams[] = "%$search%";
        }
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($countParams);
        $totalRecords += (int)$stmt->fetchColumn();
    }
    
    $totalPages = max(1, ceil($totalRecords / $perPage));
    $page = min($page, $totalPages);
    
    // ============================================
    // BUSCAR DADOS COM PAGINAÇÃO
    // ============================================
    $allTransactions = [];
    
    // Usar UNION para combinar e paginar corretamente
    $unionParts = [];
    $unionParams = [];
    
    if ($hasGameSessions && ($filter === 'all' || $filter === 'mission')) {
        $missionSelect = "
            SELECT 
                gs.id,
                gs.wallet_address,
                'mission' as type,
                gs.earnings_usdt as amount,
                gs.status,
                gs.created_at,
                gs.mission_number,
                gs.asteroids_destroyed,
                " . ($hasCommonAsteroids ? "gs.common_asteroids" : "0") . " as common_asteroids,
                gs.rare_asteroids,
                gs.epic_asteroids,
                " . ($hasLegendaryAsteroids ? "gs.legendary_asteroids" : "0") . " as legendary_asteroids,
                " . ($hasAlertLevel ? "gs.alert_level" : "NULL") . " as alert_level,
                gs.session_duration,
                NULL as description
            FROM game_sessions gs
            WHERE gs.status IN ('completed', 'flagged')
        ";
        
        if ($search) {
            $missionSelect .= " AND gs.wallet_address LIKE ?";
            $unionParams[] = "%$search%";
        }
        
        $unionParts[] = "($missionSelect)";
    }
    
    if ($hasTransactions && $filter !== 'mission') {
        $txSelect = "
            SELECT 
                t.id,
                t.wallet_address,
                t.type,
                t.amount,
                t.status,
                t.created_at,
                NULL as mission_number,
                NULL as asteroids_destroyed,
                NULL as common_asteroids,
                NULL as rare_asteroids,
                NULL as epic_asteroids,
                NULL as legendary_asteroids,
                NULL as alert_level,
                NULL as session_duration,
                t.description
            FROM transactions t
            WHERE 1=1
        ";
        
        if ($filter === 'all') {
            $txSelect .= " AND t.type NOT IN ('game_win', 'game_reward')";
        } elseif ($filter !== 'all') {
            $txSelect .= " AND t.type = ?";
            $unionParams[] = $filter;
        }
        
        if ($search) {
            $txSelect .= " AND t.wallet_address LIKE ?";
            $unionParams[] = "%$search%";
        }
        
        $unionParts[] = "($txSelect)";
    }
    
    if (!empty($unionParts)) {
        $finalSql = implode(" UNION ALL ", $unionParts);
        $finalSql .= " ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
        
        $stmt = $pdo->prepare($finalSql);
        $stmt->execute($unionParams);
        $results = $stmt->fetchAll();
        
        foreach ($results as $r) {
            $allTransactions[] = [
                'id' => $r['id'],
                'wallet_address' => $r['wallet_address'],
                'type' => $r['type'],
                'amount' => (float)$r['amount'],
                'status' => $r['status'],
                'created_at' => $r['created_at'],
                'description' => $r['type'] === 'mission' ? buildMissionDescription($r) : ($r['description'] ?? '-'),
                'details' => $r['type'] === 'mission' ? [
                    'mission_number' => $r['mission_number'],
                    'asteroids_destroyed' => $r['asteroids_destroyed'],
                    'common' => $r['common_asteroids'],
                    'rare' => $r['rare_asteroids'],
                    'epic' => $r['epic_asteroids'],
                    'legendary' => $r['legendary_asteroids'],
                    'duration' => $r['session_duration'],
                    'alert_level' => $r['alert_level']
                ] : null
            ];
        }
    }
    
    // ============================================
    // ESTATÍSTICAS
    // ============================================
    $stats = [
        'total' => $totalRecords,
        'missions_total' => 0,
        'missions_earnings' => 0,
        'withdrawal_total' => 0,
        'stake_total' => 0
    ];
    
    if ($hasGameSessions) {
        $result = $pdo->query("SELECT COUNT(*) as c, COALESCE(SUM(earnings_usdt), 0) as s FROM game_sessions WHERE status = 'completed'");
        $row = $result->fetch();
        $stats['missions_total'] = (int)$row['c'];
        $stats['missions_earnings'] = (float)$row['s'];
    }
    
    if ($hasTransactions) {
        $result = $pdo->query("
            SELECT 
                SUM(CASE WHEN type = 'withdrawal_paid' THEN amount ELSE 0 END) as withdrawal_total,
                SUM(CASE WHEN type = 'stake' THEN amount ELSE 0 END) as stake_total
            FROM transactions
        ");
        $row = $result->fetch();
        $stats['withdrawal_total'] = (float)$row['withdrawal_total'];
        $stats['stake_total'] = (float)$row['stake_total'];
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    $allTransactions = [];
    $stats = ['total' => 0, 'missions_total' => 0, 'missions_earnings' => 0, 'withdrawal_total' => 0, 'stake_total' => 0];
    $totalPages = 1;
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-exchange-alt"></i> Histórico de Transações</h1>
        <p class="page-subtitle">Missões, saques e movimentações</p>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-list"></i></div>
            <div class="value"><?php echo number_format($stats['total']); ?></div>
            <div class="label">Total de Registros</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-gamepad"></i></div>
            <div class="value"><?php echo number_format($stats['missions_total']); ?></div>
            <div class="label">Missões Completas</div>
            <div class="change">$<?php echo number_format($stats['missions_earnings'], 4); ?> pagos</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-money-bill-wave"></i></div>
            <div class="value">$<?php echo number_format($stats['withdrawal_total'], 2); ?></div>
            <div class="label">Saques Pagos</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-coins"></i></div>
            <div class="value">$<?php echo number_format($stats['stake_total'], 2); ?></div>
            <div class="label">Staking</div>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="transactions">
                <div class="filter-group">
                    <label>Buscar:</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Wallet..." class="form-control">
                </div>
                <div class="filter-group">
                    <label>Tipo:</label>
                    <select name="filter" class="form-control">
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Todos</option>
                        <option value="mission" <?php echo $filter === 'mission' ? 'selected' : ''; ?>>🎮 Missões</option>
                        <option value="withdrawal_paid" <?php echo $filter === 'withdrawal_paid' ? 'selected' : ''; ?>>💸 Saques</option>
                        <option value="stake" <?php echo $filter === 'stake' ? 'selected' : ''; ?>>🔒 Stake</option>
                        <option value="referral_bonus" <?php echo $filter === 'referral_bonus' ? 'selected' : ''; ?>>👥 Referral</option>
                        <option value="admin_adjust" <?php echo $filter === 'admin_adjust' ? 'selected' : ''; ?>>⚙️ Ajustes</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrar</button>
            </form>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="panel-title">
                Mostrando <?php echo count($allTransactions); ?> de <?php echo number_format($totalRecords); ?> registros
            </h3>
            <div class="pagination-info">
                Página <?php echo $page; ?> de <?php echo $totalPages; ?>
            </div>
        </div>
        <div class="panel-body">
            <?php if (empty($allTransactions)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>Nenhuma transação encontrada</h3>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Wallet</th>
                                <th>Tipo</th>
                                <th>Valor</th>
                                <th>Descrição</th>
                                <th>Status</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allTransactions as $t): ?>
                        <tr>
                            <td>#<?php echo $t['id']; ?></td>
                            <td>
                                <span class="wallet-addr" title="<?php echo $t['wallet_address']; ?>">
                                    <?php echo substr($t['wallet_address'], 0, 6) . '...' . substr($t['wallet_address'], -4); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                $typeLabels = [
                                    'mission' => ['🎮 Missão', 'primary'],
                                    'withdrawal_paid' => ['💸 Saque', 'warning'],
                                    'withdrawal' => ['💸 Saque', 'warning'],
                                    'stake' => ['🔒 Stake', 'info'],
                                    'unstake' => ['🔓 Unstake', 'info'],
                                    'referral_bonus' => ['👥 Referral', 'success'],
                                    'admin_adjust' => ['⚙️ Admin', 'danger'],
                                    'game_win' => ['🎮 Jogo', 'primary'],
                                    'game_reward' => ['🎮 Jogo', 'primary']
                                ];
                                $label = $typeLabels[$t['type']] ?? [$t['type'], 'secondary'];
                                ?>
                                <span class="badge badge-<?php echo $label[1]; ?>"><?php echo $label[0]; ?></span>
                                <?php if ($t['type'] === 'mission' && !empty($t['details']['alert_level'])): ?>
                                    <?php if ($t['details']['alert_level'] === 'critical'): ?>
                                        <span class="badge badge-danger" title="Alerta crítico">⚠️</span>
                                    <?php elseif ($t['details']['alert_level'] === 'suspect'): ?>
                                        <span class="badge badge-warning" title="Suspeito">⚠️</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="color: <?php echo $t['amount'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: bold;">
                                <?php echo $t['amount'] >= 0 ? '+' : ''; ?>$<?php echo number_format($t['amount'], 6); ?>
                            </td>
                            <td>
                                <?php if ($t['type'] === 'mission' && $t['details']): ?>
                                    <small>
                                        Mission #<?php echo $t['details']['mission_number']; ?> |
                                        <?php echo $t['details']['asteroids_destroyed']; ?> asteroids
                                        <?php if (!empty($t['details']['legendary']) && $t['details']['legendary'] > 0): ?>
                                            <span style="color: gold;" title="Legendary">★<?php echo $t['details']['legendary']; ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($t['details']['epic']) && $t['details']['epic'] > 0): ?>
                                            <span style="color: #9932CC;" title="Epic">◆<?php echo $t['details']['epic']; ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($t['details']['rare']) && $t['details']['rare'] > 0): ?>
                                            <span style="color: #4169E1;" title="Rare">●<?php echo $t['details']['rare']; ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($t['details']['common']) && $t['details']['common'] > 0): ?>
                                            <span style="color: #888;" title="Common">○<?php echo $t['details']['common']; ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($t['details']['duration'])): ?>
                                            | <?php echo $t['details']['duration']; ?>s
                                        <?php endif; ?>
                                    </small>
                                <?php else: ?>
                                    <small><?php echo htmlspecialchars($t['description'] ?? '-'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $statusColors = [
                                    'completed' => 'success',
                                    'approved' => 'success',
                                    'pending' => 'warning',
                                    'active' => 'warning',
                                    'flagged' => 'danger',
                                    'rejected' => 'danger',
                                    'cancelled' => 'secondary'
                                ];
                                $statusColor = $statusColors[$t['status']] ?? 'primary';
                                ?>
                                <span class="badge badge-<?php echo $statusColor; ?>">
                                    <?php echo ucfirst($t['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- PAGINAÇÃO -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $baseUrl = "?page=transactions&filter=" . urlencode($filter) . "&search=" . urlencode($search);
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl; ?>&p=1" class="pagination-btn" title="Primeira">«</a>
                        <a href="<?php echo $baseUrl; ?>&p=<?php echo $page - 1; ?>" class="pagination-btn" title="Anterior">‹</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1) echo '<span class="pagination-ellipsis">...</span>';
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a href="<?php echo $baseUrl; ?>&p=<?php echo $i; ?>" 
                           class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($endPage < $totalPages) echo '<span class="pagination-ellipsis">...</span>'; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl; ?>&p=<?php echo $page + 1; ?>" class="pagination-btn" title="Próxima">›</a>
                        <a href="<?php echo $baseUrl; ?>&p=<?php echo $totalPages; ?>" class="pagination-btn" title="Última">»</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.filters {
    display: flex;
    gap: 15px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.filter-group label {
    font-size: 12px;
    color: var(--text-secondary);
}
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 5px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid rgba(255,255,255,0.1);
}
.pagination-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 6px;
    color: var(--text-primary);
    text-decoration: none;
    font-size: 14px;
    transition: all 0.2s;
}
.pagination-btn:hover {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}
.pagination-btn.active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
    font-weight: bold;
}
.pagination-ellipsis {
    color: var(--text-secondary);
    padding: 0 5px;
}
.pagination-info {
    font-size: 13px;
    color: var(--text-secondary);
}
</style>