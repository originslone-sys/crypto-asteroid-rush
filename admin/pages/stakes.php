<?php
// ============================================
// UNOBIX - Gerenciamento de Staking
// Arquivo: admin/pages/stakes.php
// v6.0 - Tabela staking, google_uid, BRL 6 decimais
// ============================================

$pageTitle = 'Staking';
$error = null;
$stakes = [];
$stats = [
    'total' => 0,
    'active' => 0,
    'total_staked' => 0,
    'total_earned' => 0
];

try {
    // Buscar stakes — tabela: staking (doc 3.5)
    // JOIN com users via google_uid
    $stakes = $pdo->query("
        SELECT 
            s.id,
            s.google_uid,
            s.amount_brl,
            s.earned_brl,
            s.apy_rate,
            s.status,
            s.staked_at,
            s.min_unstake_at,
            s.last_compound_at,
            s.unstaked_at,
            s.created_at,
            u.display_name,
            u.email,
            u.balance_brl
        FROM staking s 
        LEFT JOIN users u ON s.google_uid = u.google_uid 
        ORDER BY s.created_at DESC 
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Estatísticas
    $statsQuery = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            COALESCE(SUM(CASE WHEN status = 'active' THEN amount_brl ELSE 0 END), 0) as total_staked,
            COALESCE(SUM(earned_brl), 0) as total_earned
        FROM staking
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($statsQuery) {
        $stats = [
            'total' => (int)$statsQuery['total'],
            'active' => (int)$statsQuery['active'],
            'total_staked' => (float)$statsQuery['total_staked'],
            'total_earned' => (float)$statsQuery['total_earned']
        ];
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php (Regra de Ouro: 6 decimais)

if (!function_exists('formatBRLShort')) {
    function formatBRLShort($value) {
        return 'R$ ' . number_format($value ?? 0, 2, ',', '.');
    }
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-coins"></i> Gerenciamento de Staking</h1>
        <p class="page-subtitle">Controle de stakes e rendimentos em BRL</p>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> Erro: <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-layer-group"></i></div>
            <div class="value"><?php echo $stats['total']; ?></div>
            <div class="label">Total de Stakes</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check-circle"></i></div>
            <div class="value"><?php echo $stats['active']; ?></div>
            <div class="label">Stakes Ativos</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-lock"></i></div>
            <div class="value"><?php echo formatBRLShort($stats['total_staked']); ?></div>
            <div class="label">Total em Stake</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-gift"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_earned']); ?></div>
            <div class="label">Rendimentos Acumulados</div>
        </div>
    </div>
    
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Lista de Stakes</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($stakes)): ?>
                <div class="empty-state">
                    <i class="fas fa-coins"></i>
                    <h3>Nenhum stake encontrado</h3>
                    <p>Os stakes dos usuários aparecerão aqui.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Jogador</th>
                                <th>Valor</th>
                                <th>APY</th>
                                <th>Rendimento</th>
                                <th>Status</th>
                                <th>Início</th>
                                <th>Resgate Mín.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stakes as $s): ?>
                        <tr>
                            <td>#<?php echo (int)$s['id']; ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($s['display_name'] ?? 'Usuário'); ?></div>
                                <small style="color: var(--text-dim);"><?php echo htmlspecialchars($s['email'] ?? ''); ?></small>
                            </td>
                            <td style="color: var(--success); font-weight: 600;">
                                <?php echo formatBRL($s['amount_brl']); ?>
                            </td>
                            <td><?php echo number_format($s['apy_rate'] ?? 0, 2); ?>%</td>
                            <td style="color: var(--warning);">
                                <?php echo formatBRL($s['earned_brl']); ?>
                            </td>
                            <td>
                                <?php 
                                $statusClass = ['active' => 'success', 'completed' => 'primary', 'cancelled' => 'warning'][$s['status']] ?? 'info';
                                $statusText = ['active' => 'Ativo', 'completed' => 'Resgatado', 'cancelled' => 'Cancelado'][$s['status']] ?? $s['status'];
                                ?>
                                <span class="badge badge-<?php echo $statusClass; ?>">
                                    <?php echo $statusText; ?>
                                </span>
                            </td>
                            <td style="color: var(--text-dim);">
                                <?php echo $s['staked_at'] ? date('d/m/Y H:i', strtotime($s['staked_at'])) : '-'; ?>
                            </td>
                            <td style="color: var(--text-dim);">
                                <?php echo $s['min_unstake_at'] ? date('d/m/Y', strtotime($s['min_unstake_at'])) : '-'; ?>
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
