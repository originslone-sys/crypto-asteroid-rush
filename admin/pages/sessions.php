<?php
// ============================================
// UNOBIX - Sessões de Jogo
// Arquivo: admin/pages/sessions.php
// v7.0 - Paginação
// ============================================

$pageTitle = 'Sessões';
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

try {
    $baseSql = "FROM game_sessions gs
            LEFT JOIN users p ON gs.google_uid = p.google_uid WHERE 1=1";
    $params = [];
    $filterSql = '';

    if ($filter !== 'all') {
        $filterSql .= " AND gs.status = ?";
        $params[] = $filter;
    }
    if ($search) {
        $filterSql .= " AND (gs.google_uid LIKE ? OR p.display_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Contagem
    $stmtCount = $pdo->prepare("SELECT COUNT(*) " . $baseSql . $filterSql);
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));

    $sql = "SELECT gs.*, p.display_name, p.is_banned " . $baseSql . $filterSql;
    $sql .= " ORDER BY gs.created_at DESC LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();

    $stats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'flagged' THEN 1 ELSE 0 END) as flagged,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            COALESCE(SUM(earnings_brl), 0) as total_earnings,
            COALESCE(SUM(asteroids_destroyed), 0) as total_asteroids
        FROM game_sessions WHERE DATE(created_at) = CURDATE()
    ")->fetch();
} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-gamepad"></i> Sessões de Jogo</h1>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-play"></i></div>
            <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="label">Hoje</div>
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
            <div class="icon warning"><i class="fas fa-coins"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_earnings']); ?></div>
            <div class="label">Ganhos Hoje</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="sessions">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar jogador..." class="form-control" style="width: 200px;">
                <select name="filter" class="form-control">
                    <option value="all">Todos</option>
                    <option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>Completadas</option>
                    <option value="flagged" <?php echo $filter === 'flagged' ? 'selected' : ''; ?>>Flagged</option>
                    <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Ativas</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <?php if (empty($sessions)): ?>
                <div class="empty-state"><i class="fas fa-gamepad"></i><h3>Nenhuma sessão</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Jogador</th><th>Missão</th><th>Asteroides</th><th>Ganho</th><th>Duração</th><th>Status</th><th>Data</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td>#<?php echo $s['id']; ?></td>
                        <td>
                            <?php echo htmlspecialchars($s['display_name'] ?? 'Usuário'); ?>
                            <?php if ($s['is_banned']): ?><span class="badge badge-danger">BAN</span><?php endif; ?>
                            <?php if ($s['is_hard_mode']): ?><span class="badge badge-warning">HARD</span><?php endif; ?>
                        </td>
                        <td>#<?php echo $s['mission_number']; ?></td>
                        <td><?php echo $s['asteroids_destroyed']; ?> <small style="color: var(--warning);">(<?php echo $s['rare_asteroids']; ?>R/<?php echo $s['epic_asteroids']; ?>E)</small></td>
                        <td style="color: var(--success);"><?php echo formatBRL($s['earnings_brl']); ?></td>
                        <td><?php echo $s['game_duration'] ? $s['game_duration'] . 's' : '-'; ?></td>
                        <td>
                            <?php $sc = ['completed'=>'success','flagged'=>'danger','active'=>'warning'][$s['status']] ?? 'primary'; ?>
                            <span class="badge badge-<?php echo $sc; ?>"><?php echo $s['status']; ?></span>
                        </td>
                        <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <?php
                    $queryParams = $_GET;
                    unset($queryParams['p']);
                    $baseUrl = '?' . http_build_query($queryParams);
                ?>
                <div style="display: flex; align-items: center; gap: 6px; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color); flex-wrap: wrap;">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl . '&p=' . ($page - 1); ?>" class="btn btn-outline btn-sm">&laquo; Anterior</a>
                    <?php endif; ?>
                    <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1) echo '<span style="color: var(--text-dim); padding: 0 4px;">...</span>';
                        for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="<?php echo $baseUrl . '&p=' . $i; ?>" class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>" style="min-width: 36px; text-align: center; text-decoration: none;"><?php echo $i; ?></a>
                    <?php
                        endfor;
                        if ($end < $totalPages) echo '<span style="color: var(--text-dim); padding: 0 4px;">...</span>';
                    ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl . '&p=' . ($page + 1); ?>" class="btn btn-outline btn-sm">Próxima &raquo;</a>
                    <?php endif; ?>
                    <span style="color: var(--text-dim); font-size: 0.8rem; margin-left: 10px;">
                        <?php echo number_format($totalRows); ?> sessões | Página <?php echo $page; ?>/<?php echo $totalPages; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>
