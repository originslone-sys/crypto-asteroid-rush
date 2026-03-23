<?php
// ============================================
// UNOBIX - Contatos WhatsApp
// Arquivo: admin/pages/whatsapp.php
// ============================================

$pageTitle = 'WhatsApp';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

try {
    // Garantir coluna existe
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'whatsapp'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp VARCHAR(20) DEFAULT NULL");
    }

    $params = [];
    $filterSql = " AND u.whatsapp IS NOT NULL AND u.whatsapp != ''";

    if ($search) {
        $filterSql .= " AND (u.display_name LIKE ? OR u.email LIKE ? OR u.whatsapp LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Contagem
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE 1=1" . $filterSql);
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));

    $sql = "SELECT u.id, u.display_name, u.email, u.whatsapp, u.created_at, u.last_login, u.total_played, u.balance_brl
            FROM users u WHERE 1=1" . $filterSql;
    $sql .= " ORDER BY u.created_at DESC LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $contacts = $stmt->fetchAll();

    // Stats
    $totalContacts = $pdo->query("SELECT COUNT(*) FROM users WHERE whatsapp IS NOT NULL AND whatsapp != ''")->fetchColumn();
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

} catch (Exception $e) {
    $error = $e->getMessage();
}

function formatPhone($number) {
    $n = preg_replace('/\D/', '', $number);
    if (strlen($n) === 11) {
        return '(' . substr($n, 0, 2) . ') ' . substr($n, 2, 5) . '-' . substr($n, 7);
    } elseif (strlen($n) === 13) {
        return '+' . substr($n, 0, 2) . ' (' . substr($n, 2, 2) . ') ' . substr($n, 4, 5) . '-' . substr($n, 9);
    }
    return $number;
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fab fa-whatsapp"></i> Contatos WhatsApp</h1>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon success"><i class="fab fa-whatsapp"></i></div>
            <div class="value"><?php echo number_format($totalContacts ?? 0); ?></div>
            <div class="label">Contatos Coletados</div>
        </div>
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo number_format($totalUsers ?? 0); ?></div>
            <div class="label">Total de Jogadores</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-percentage"></i></div>
            <div class="value"><?php echo ($totalUsers > 0) ? round(($totalContacts / $totalUsers) * 100) : 0; ?>%</div>
            <div class="label">Taxa de Coleta</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="whatsapp">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar nome, email ou número..." class="form-control" style="width: 300px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Contatos</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($contacts)): ?>
                <div class="empty-state"><i class="fab fa-whatsapp"></i><h3>Nenhum contato coletado</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Jogador</th>
                            <th>WhatsApp</th>
                            <th>Email</th>
                            <th>Jogos</th>
                            <th>Saldo</th>
                            <th>Cadastro</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contacts as $c): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($c['display_name'] ?? 'Sem nome'); ?></strong></td>
                        <td>
                            <a href="https://wa.me/55<?php echo preg_replace('/\D/', '', $c['whatsapp']); ?>" target="_blank"
                               style="color: #25D366; font-weight: 600; text-decoration: none;">
                                <i class="fab fa-whatsapp"></i> <?php echo formatPhone($c['whatsapp']); ?>
                            </a>
                        </td>
                        <td style="color: var(--text-dim);"><?php echo htmlspecialchars($c['email'] ?? '-'); ?></td>
                        <td><?php echo $c['total_played'] ?? 0; ?></td>
                        <td style="color: var(--success);"><?php echo formatBRL($c['balance_brl'] ?? 0); ?></td>
                        <td style="color: var(--text-dim);"><?php echo date('d/m/Y', strtotime($c['created_at'])); ?></td>
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
                        <?php echo number_format($totalRows); ?> contatos | Página <?php echo $page; ?>/<?php echo $totalPages; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>
