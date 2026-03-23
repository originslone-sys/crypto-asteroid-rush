<?php
// ============================================
// UNOBIX - Transações
// Arquivo: admin/pages/transactions.php
// v7.0 - Paginação, tipos corretos
// ============================================

$pageTitle = 'Transações';
$typeFilter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

try {
    $baseSql = "FROM transactions t
            LEFT JOIN users p ON t.google_uid = p.google_uid WHERE 1=1";
    $params = [];
    $filterSql = '';

    if ($typeFilter !== 'all') {
        $filterSql .= " AND t.type = ?";
        $params[] = $typeFilter;
    }
    if ($search) {
        $filterSql .= " AND (t.google_uid LIKE ? OR p.display_name LIKE ? OR t.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Contagem para paginação
    $stmtCount = $pdo->prepare("SELECT COUNT(*) " . $baseSql . $filterSql);
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));

    $sql = "SELECT t.*, p.display_name " . $baseSql . $filterSql;
    $sql .= " ORDER BY t.created_at DESC LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    $stats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN amount_brl > 0 THEN amount_brl ELSE 0 END) as total_credit,
            SUM(CASE WHEN amount_brl < 0 THEN ABS(amount_brl) ELSE 0 END) as total_debit
        FROM transactions WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch();
} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php

// Mapeamento completo de tipos de transação
$typeLabels = [
    'game_reward'          => ['Ganho Jogo', 'success'],
    'game_earning'         => ['Ganho Jogo', 'success'],
    'withdraw'             => ['Saque', 'danger'],
    'withdrawal'           => ['Saque', 'danger'],
    'withdraw_reject'      => ['Saque Devolvido', 'warning'],
    'deposit'              => ['Depósito', 'success'],
    'credit_purchase'      => ['Compra Créditos', 'primary'],
    'stake'                => ['Stake', 'primary'],
    'unstake'              => ['Unstake', 'info'],
    'stake_reward'         => ['Rendimento', 'success'],
    'referral_bonus'       => ['Bônus Indicação', 'success'],
    'referral_commission'  => ['Comissão Indicação', 'success'],
    'welcome_bonus'        => ['Bônus Boas-vindas', 'success'],
    'admin_adjust'         => ['Ajuste Admin', 'warning']
];

// Tipos que são saída de dinheiro (devem aparecer como negativo)
$debitTypes = ['withdraw', 'withdrawal', 'stake'];
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-exchange-alt"></i> Transações</h1>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-list"></i></div>
            <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="label">Total (30d)</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-arrow-down"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_credit']); ?></div>
            <div class="label">Entradas</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-arrow-up"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_debit']); ?></div>
            <div class="label">Saídas</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="transactions">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar jogador ou descrição..." class="form-control" style="width: 250px;">
                <select name="type" class="form-control">
                    <option value="all">Todos</option>
                    <option value="game_reward" <?php echo $typeFilter === 'game_reward' ? 'selected' : ''; ?>>Ganhos Jogo</option>
                    <option value="withdraw" <?php echo $typeFilter === 'withdraw' ? 'selected' : ''; ?>>Saques</option>
                    <option value="deposit" <?php echo $typeFilter === 'deposit' ? 'selected' : ''; ?>>Depósitos</option>
                    <option value="credit_purchase" <?php echo $typeFilter === 'credit_purchase' ? 'selected' : ''; ?>>Compra Créditos</option>
                    <option value="referral_commission" <?php echo $typeFilter === 'referral_commission' ? 'selected' : ''; ?>>Indicações</option>
                    <option value="withdraw_reject" <?php echo $typeFilter === 'withdraw_reject' ? 'selected' : ''; ?>>Saques Devolvidos</option>
                    <option value="admin_adjust" <?php echo $typeFilter === 'admin_adjust' ? 'selected' : ''; ?>>Ajustes Admin</option>
                    <option value="stake" <?php echo $typeFilter === 'stake' ? 'selected' : ''; ?>>Stakes</option>
                    <option value="unstake" <?php echo $typeFilter === 'unstake' ? 'selected' : ''; ?>>Unstakes</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <?php if (empty($transactions)): ?>
                <div class="empty-state"><i class="fas fa-exchange-alt"></i><h3>Nenhuma transação</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table class="table-compact">
                    <thead>
                        <tr><th>ID</th><th>Jogador</th><th>Tipo</th><th>Valor</th><th>Descrição</th><th>Status</th><th>Data</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transactions as $t):
                        $label = $typeLabels[$t['type']] ?? [$t['type'], 'primary'];
                        $amountBrl = (float)($t['amount_brl'] ?? 0);
                        // Corrigir exibição: saques que foram armazenados positivos por engano
                        if (in_array($t['type'], $debitTypes) && $amountBrl > 0) {
                            $amountBrl = -$amountBrl;
                        }
                        $isPositive = $amountBrl >= 0;
                    ?>
                    <tr>
                        <td style="color: var(--text-dim);">#<?php echo $t['id']; ?></td>
                        <td><?php echo htmlspecialchars($t['display_name'] ?? 'Usuário'); ?></td>
                        <td><span class="badge badge-<?php echo $label[1]; ?>"><?php echo $label[0]; ?></span></td>
                        <td style="color: <?php echo $isPositive ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: 600; white-space: nowrap;">
                            <?php echo $isPositive ? '+' : ''; ?><?php echo formatBRL($amountBrl); ?>
                        </td>
                        <td style="color: var(--text-dim); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($t['description'] ?? '-'); ?></td>
                        <td><span class="badge badge-<?php echo $t['status'] === 'completed' ? 'success' : ($t['status'] === 'failed' ? 'danger' : 'warning'); ?>"><?php echo $t['status']; ?></span></td>
                        <td style="white-space: nowrap; color: var(--text-dim);"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
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
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl . '&p=' . ($page - 1); ?>" class="btn btn-outline btn-sm">&laquo; Anterior</a>
                    <?php endif; ?>

                    <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1) echo '<span class="pagination-dots">...</span>';
                        for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="<?php echo $baseUrl . '&p=' . $i; ?>" class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $i; ?></a>
                    <?php
                        endfor;
                        if ($end < $totalPages) echo '<span class="pagination-dots">...</span>';
                    ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl . '&p=' . ($page + 1); ?>" class="btn btn-outline btn-sm">Próxima &raquo;</a>
                    <?php endif; ?>

                    <span style="color: var(--text-dim); font-size: 0.8rem; margin-left: 10px;">
                        <?php echo number_format($totalRows); ?> transações | Página <?php echo $page; ?>/<?php echo $totalPages; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.table-compact th,
.table-compact td {
    padding: 8px 10px;
    font-size: 0.85rem;
}
.pagination {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid var(--border-color);
    flex-wrap: wrap;
}
.pagination .btn { min-width: 36px; text-align: center; text-decoration: none; }
.pagination-dots { color: var(--text-dim); padding: 0 4px; }
</style>
