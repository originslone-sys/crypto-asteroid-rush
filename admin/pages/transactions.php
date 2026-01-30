<?php
// ============================================
// UNOBIX - Transações
// Arquivo: admin/pages/transactions.php
// ============================================

$pageTitle = 'Transações';
$typeFilter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $sql = "SELECT t.*, p.display_name FROM transactions t 
            LEFT JOIN players p ON t.google_uid = p.google_uid WHERE 1=1";
    $params = [];
    
    if ($typeFilter !== 'all') {
        $sql .= " AND t.type = ?";
        $params[] = $typeFilter;
    }
    if ($search) {
        $sql .= " AND (t.google_uid LIKE ? OR p.display_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY t.created_at DESC LIMIT 200";
    
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

function formatBRL($v) { return 'R$ ' . number_format($v ?? 0, 2, ',', '.'); }

$typeLabels = [
    'game_earning' => ['Ganho Jogo', 'success'],
    'withdrawal' => ['Saque', 'warning'],
    'stake' => ['Stake', 'primary'],
    'unstake' => ['Unstake', 'info'],
    'stake_reward' => ['Rendimento', 'success'],
    'referral_bonus' => ['Bônus Indicação', 'success'],
    'admin_adjust' => ['Ajuste Admin', 'danger']
];
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-exchange-alt"></i> Transações</h1>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-list"></i></div>
            <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="label">Total (30d)</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-arrow-down"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_credit']); ?></div>
            <div class="label">Créditos</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-arrow-up"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_debit']); ?></div>
            <div class="label">Débitos</div>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="transactions">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar..." class="form-control" style="width: 200px;">
                <select name="type" class="form-control">
                    <option value="all">Todos</option>
                    <option value="game_earning" <?php echo $typeFilter === 'game_earning' ? 'selected' : ''; ?>>Ganhos</option>
                    <option value="withdrawal" <?php echo $typeFilter === 'withdrawal' ? 'selected' : ''; ?>>Saques</option>
                    <option value="stake" <?php echo $typeFilter === 'stake' ? 'selected' : ''; ?>>Stakes</option>
                    <option value="referral_bonus" <?php echo $typeFilter === 'referral_bonus' ? 'selected' : ''; ?>>Indicações</option>
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
                <table>
                    <thead>
                        <tr><th>ID</th><th>Jogador</th><th>Tipo</th><th>Valor</th><th>Descrição</th><th>Status</th><th>Data</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <?php $label = $typeLabels[$t['type']] ?? [$t['type'], 'primary']; ?>
                    <tr>
                        <td>#<?php echo $t['id']; ?></td>
                        <td><?php echo htmlspecialchars($t['display_name'] ?? 'Usuário'); ?></td>
                        <td><span class="badge badge-<?php echo $label[1]; ?>"><?php echo $label[0]; ?></span></td>
                        <td style="color: <?php echo $t['amount_brl'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: 600;">
                            <?php echo $t['amount_brl'] >= 0 ? '+' : ''; ?><?php echo formatBRL($t['amount_brl']); ?>
                        </td>
                        <td style="color: var(--text-dim); max-width: 200px;"><?php echo htmlspecialchars($t['description'] ?? '-'); ?></td>
                        <td><span class="badge badge-<?php echo $t['status'] === 'completed' ? 'success' : 'warning'; ?>"><?php echo $t['status']; ?></span></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
