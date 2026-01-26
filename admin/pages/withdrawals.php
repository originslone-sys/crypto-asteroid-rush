<?php
// ============================================
// CRYPTO ASTEROID RUSH - Gerenciamento de Saques
// Arquivo: admin/pages/withdrawals.php
// ============================================

$pageTitle = 'Saques';

// Filtros
$statusFilter = $_GET['status'] ?? 'pending';

try {
    // Buscar saques
    $sql = "SELECT w.*, p.balance_usdt as player_balance, p.total_played, p.is_banned
            FROM withdrawals w 
            LEFT JOIN players p ON w.wallet_address = p.wallet_address";
    
    if ($statusFilter !== 'all') {
        $sql .= " WHERE w.status = :status";
    }
    
    $sql .= " ORDER BY w.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    if ($statusFilter !== 'all') {
        $stmt->execute(['status' => $statusFilter]);
    } else {
        $stmt->execute();
    }
    $withdrawals = $stmt->fetchAll();
    
    // Estatísticas
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'pending' THEN amount_usdt ELSE 0 END) as pending_amount,
            SUM(CASE WHEN status = 'approved' THEN amount_usdt ELSE 0 END) as approved_amount
        FROM withdrawals
    ")->fetch();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-money-bill-wave"></i> Gerenciamento de Saques</h1>
        <p class="page-subtitle">Aprovar, rejeitar e acompanhar solicitações de saque</p>
    </div>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-clock"></i></div>
            <div class="value"><?php echo $stats['pending'] ?? 0; ?></div>
            <div class="label">Pendentes</div>
            <div class="change">$<?php echo number_format($stats['pending_amount'] ?? 0, 2); ?></div>
        </div>
        
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check"></i></div>
            <div class="value"><?php echo $stats['approved'] ?? 0; ?></div>
            <div class="label">Aprovados</div>
            <div class="change">$<?php echo number_format($stats['approved_amount'] ?? 0, 2); ?></div>
        </div>
        
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-times"></i></div>
            <div class="value"><?php echo $stats['rejected'] ?? 0; ?></div>
            <div class="label">Rejeitados</div>
        </div>
        
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-list"></i></div>
            <div class="value"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="label">Total</div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-filter"></i> Filtros</h3>
        </div>
        <div class="panel-body">
            <div class="tabs">
                <a href="?page=withdrawals&status=pending" class="tab <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Pendentes (<?php echo $stats['pending'] ?? 0; ?>)
                </a>
                <a href="?page=withdrawals&status=approved" class="tab <?php echo $statusFilter === 'approved' ? 'active' : ''; ?>">
                    <i class="fas fa-check"></i> Aprovados
                </a>
                <a href="?page=withdrawals&status=rejected" class="tab <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">
                    <i class="fas fa-times"></i> Rejeitados
                </a>
                <a href="?page=withdrawals&status=all" class="tab <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> Todos
                </a>
            </div>
        </div>
    </div>
    
    <!-- Lista de Saques -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Solicitações de Saque</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($withdrawals)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>Nenhum saque encontrado</h3>
                    <p>Não há saques com o filtro selecionado</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Carteira</th>
                                <th>Valor</th>
                                <th>Saldo Atual</th>
                                <th>Partidas</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($withdrawals as $w): ?>
                            <tr>
                                <td><strong>#<?php echo $w['id']; ?></strong></td>
                                <td>
                                    <span class="wallet-addr" onclick="copyToClipboard('<?php echo $w['wallet_address']; ?>')" title="Clique para copiar">
                                        <?php echo substr($w['wallet_address'], 0, 6) . '...' . substr($w['wallet_address'], -4); ?>
                                    </span>
                                    <?php if ($w['is_banned']): ?>
                                        <span class="badge badge-danger" style="margin-left: 5px;">BANIDO</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--success); font-weight: bold;">
                                    $<?php echo number_format($w['amount_usdt'], 6); ?>
                                </td>
                                <td>
                                    $<?php echo number_format($w['player_balance'] ?? 0, 6); ?>
                                </td>
                                <td><?php echo $w['total_played'] ?? 0; ?></td>
                                <td>
                                    <?php
                                    // Status class
                                    if ($w['status'] === 'approved') {
                                        $statusClass = 'success';
                                        $statusText = 'Aprovado';
                                    } elseif ($w['status'] === 'pending') {
                                        $statusClass = 'warning';
                                        $statusText = 'Pendente';
                                    } elseif ($w['status'] === 'rejected') {
                                        $statusClass = 'danger';
                                        $statusText = 'Rejeitado';
                                    } else {
                                        $statusClass = 'primary';
                                        $statusText = $w['status'];
                                    }
                                    ?>
                                    <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($w['created_at'])); ?></td>
                                <td>
                                    <?php if ($w['status'] === 'pending'): ?>
                                        <div class="btn-group">
                                            <button onclick="processWithdrawalPayment(<?php echo $w['id']; ?>, '<?php echo $w['wallet_address']; ?>', <?php echo $w['amount_usdt']; ?>)" 
                                                    class="btn btn-success btn-sm">
                                                <i class="fas fa-check"></i> Pagar
                                            </button>
                                            <button onclick="rejectWithdrawal(<?php echo $w['id']; ?>)" 
                                                    class="btn btn-danger btn-sm">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    <?php elseif ($w['status'] === 'approved' && $w['tx_hash']): ?>
                                        <a href="https://bscscan.com/tx/<?php echo $w['tx_hash']; ?>" target="_blank" class="btn btn-outline btn-sm">
                                            <i class="fas fa-external-link-alt"></i> Ver TX
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--text-dim);">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
