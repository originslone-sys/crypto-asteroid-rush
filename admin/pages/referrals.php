<?php
// ============================================
// UNOBIX - Afiliados
// Arquivo: admin/pages/referrals.php
// v7.0 - Paginação
// ============================================

$pageTitle = 'Afiliados';
$statusFilter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

try {
    $baseSql = "FROM referrals r
        LEFT JOIN users p1 ON r.referrer_google_uid = p1.google_uid
        LEFT JOIN users p2 ON r.referred_google_uid = p2.google_uid
        WHERE 1=1";
    $params = [];
    $filterSql = '';

    if ($statusFilter !== 'all') {
        $filterSql .= " AND r.status = ?";
        $params[] = $statusFilter;
    }
    if ($search) {
        $filterSql .= " AND (p1.display_name LIKE ? OR p2.display_name LIKE ? OR p1.email LIKE ? OR p2.email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Contagem
    $stmtCount = $pdo->prepare("SELECT COUNT(*) " . $baseSql . $filterSql);
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));

    $sql = "SELECT r.*,
               p1.display_name as referrer_name, p1.email as referrer_email,
               p2.display_name as referred_name, p2.email as referred_email, p2.total_played
        " . $baseSql . $filterSql;
    $sql .= " ORDER BY r.created_at DESC LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $referrals = $stmt->fetchAll();

    $stats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) as qualified,
            SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) as paid,
            COALESCE(SUM(CASE WHEN status = 'claimed' THEN commission_brl ELSE 0 END), 0) as total_paid
        FROM referrals
    ")->fetch();
} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-user-friends"></i> Sistema de Afiliados</h1>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="label">Total Indicações</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-clock"></i></div>
            <div class="value"><?php echo $stats['pending'] ?? 0; ?></div>
            <div class="label">Em Progresso</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check"></i></div>
            <div class="value"><?php echo ($stats['qualified'] ?? 0) + ($stats['paid'] ?? 0); ?></div>
            <div class="label">Completadas</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-coins"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_paid'] ?? 0); ?></div>
            <div class="label">Comissões Pagas</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="referrals">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar jogador..." class="form-control" style="width: 200px;">
                <select name="status" class="form-control">
                    <option value="all">Todos</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Em Progresso</option>
                    <option value="qualified" <?php echo $statusFilter === 'qualified' ? 'selected' : ''; ?>>Qualificados</option>
                    <option value="claimed" <?php echo $statusFilter === 'claimed' ? 'selected' : ''; ?>>Resgatados</option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelados</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Indicações</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($referrals)): ?>
                <div class="empty-state"><i class="fas fa-user-friends"></i><h3>Nenhuma indicação</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Indicador</th><th>Indicado</th><th>Progresso</th><th>Comissão</th><th>Status</th><th>Data</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($referrals as $r): ?>
                    <?php
                        $required = (int)($r['missions_required'] ?? 100);
                        $completed = (int)($r['missions_completed'] ?? 0);
                        $progress = $required > 0 ? min(100, round(($completed / $required) * 100)) : 0;
                        $statusLabels = [
                            'pending'   => ['Em Progresso', 'warning'],
                            'qualified' => ['Qualificado', 'success'],
                            'claimed'   => ['Resgatado', 'primary'],
                            'cancelled' => ['Cancelado', 'danger']
                        ];
                        $label = $statusLabels[$r['status']] ?? [$r['status'], 'info'];
                    ?>
                    <tr>
                        <td>#<?php echo $r['id']; ?></td>
                        <td>
                            <div><?php echo htmlspecialchars($r['referrer_name'] ?? 'Usuário'); ?></div>
                            <small style="color: var(--text-dim);"><?php echo htmlspecialchars($r['referrer_email'] ?? ''); ?></small>
                        </td>
                        <td>
                            <div><?php echo htmlspecialchars($r['referred_name'] ?? 'Usuário'); ?></div>
                            <small style="color: var(--text-dim);"><?php echo htmlspecialchars($r['referred_email'] ?? ''); ?></small>
                        </td>
                        <td>
                            <div><?php echo $completed; ?>/<?php echo $required; ?></div>
                            <div style="background: rgba(255,255,255,0.1); border-radius: 5px; height: 6px; margin-top: 5px;">
                                <div style="background: var(--primary); width: <?php echo $progress; ?>%; height: 100%; border-radius: 5px;"></div>
                            </div>
                        </td>
                        <td style="color: var(--success);"><?php echo formatBRL($r['commission_brl']); ?></td>
                        <td><span class="badge badge-<?php echo $label[1]; ?>"><?php echo $label[0]; ?></span></td>
                        <td><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></td>
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
                        <?php echo number_format($totalRows); ?> indicações | Página <?php echo $page; ?>/<?php echo $totalPages; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>
