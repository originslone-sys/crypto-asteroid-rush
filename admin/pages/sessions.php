<?php
// ============================================
// CRYPTO ASTEROID RUSH - Sessões de Jogo
// Arquivo: admin/pages/sessions.php
// ============================================

$pageTitle = 'Sessões de Jogo';

$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $sql = "SELECT gs.*, p.is_banned FROM game_sessions gs LEFT JOIN players p ON gs.wallet_address = p.wallet_address WHERE 1=1";
    $params = [];
    
    if ($filter !== 'all') {
        $sql .= " AND gs.status = ?";
        $params[] = $filter;
    }
    
    if ($search) {
        $sql .= " AND gs.wallet_address LIKE ?";
        $params[] = "%$search%";
    }
    
    $sql .= " ORDER BY gs.created_at DESC LIMIT 200";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();
    
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'flagged' THEN 1 ELSE 0 END) as flagged,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            COALESCE(SUM(earnings_usdt), 0) as total_earnings,
            COALESCE(SUM(asteroids_destroyed), 0) as total_asteroids
        FROM game_sessions
    ")->fetch();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-gamepad"></i> Sessões de Jogo</h1>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-play"></i></div>
            <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="label">Total de Sessões</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check"></i></div>
            <div class="value"><?php echo number_format($stats['completed'] ?? 0); ?></div>
            <div class="label">Completadas</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-flag"></i></div>
            <div class="value"><?php echo $stats['flagged'] ?? 0; ?></div>
            <div class="label">Flagged</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-meteor"></i></div>
            <div class="value"><?php echo number_format($stats['total_asteroids'] ?? 0); ?></div>
            <div class="label">Asteroides Destruídos</div>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="sessions">
                <div class="filter-group">
                    <label>Buscar:</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Wallet..." class="form-control">
                </div>
                <div class="filter-group">
                    <label>Status:</label>
                    <select name="filter" class="form-control">
                        <option value="all">Todos</option>
                        <option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>Completadas</option>
                        <option value="flagged" <?php echo $filter === 'flagged' ? 'selected' : ''; ?>>Flagged</option>
                        <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Ativas</option>
                        <option value="expired" <?php echo $filter === 'expired' ? 'selected' : ''; ?>>Expiradas</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filtrar</button>
            </form>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-body">
            <?php if (empty($sessions)): ?>
                <div class="empty-state"><i class="fas fa-gamepad"></i><h3>Nenhuma sessão encontrada</h3></div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Wallet</th>
                                <th>Missão</th>
                                <th>Asteroides</th>
                                <th>Raros</th>
                                <th>Épicos</th>
                                <th>Ganho</th>
                                <th>Duração</th>
                                <th>Status</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td>#<?php echo $s['id']; ?></td>
                            <td>
                                <span class="wallet-addr" onclick="copyToClipboard('<?php echo $s['wallet_address']; ?>')">
                                    <?php echo substr($s['wallet_address'],0,8); ?>...
                                </span>
                                <?php if ($s['is_banned']): ?><span class="badge badge-danger">BAN</span><?php endif; ?>
                            </td>
                            <td>#<?php echo $s['mission_number']; ?></td>
                            <td><?php echo $s['asteroids_destroyed']; ?></td>
                            <td style="color: var(--warning);"><?php echo $s['rare_asteroids']; ?></td>
                            <td style="color: var(--success);"><?php echo $s['epic_asteroids']; ?></td>
                            <td style="color: var(--success);">$<?php echo number_format($s['earnings_usdt'], 6); ?></td>
                            <td><?php echo $s['session_duration'] ? $s['session_duration'] . 's' : '-'; ?></td>
                            <td>
                                <?php
                                if ($s['status'] === 'completed') {
                                    $statusClass = 'success';
                                } elseif ($s['status'] === 'flagged') {
                                    $statusClass = 'danger';
                                } elseif ($s['status'] === 'active') {
                                    $statusClass = 'warning';
                                } else {
                                    $statusClass = 'primary';
                                }
                                ?>
                                <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $s['status']; ?></span>
                            </td>
                            <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
