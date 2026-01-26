<?php
// ============================================
// CRYPTO ASTEROID RUSH - Staking
// Arquivo: admin/pages/stakes.php
// CORRIGIDO: Conversao de unidades para USDT
// Os valores sao armazenados multiplicados por 100000000
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

// Fator de conversao (valores armazenados em unidades inteiras)
define('UNITS_DIVISOR', 100000000);

try {
    // Buscar stakes
    $stakes = $pdo->query("
        SELECT 
            s.id,
            s.wallet_address,
            s.amount,
            s.apy,
            s.total_earned,
            s.status,
            s.created_at,
            s.updated_at,
            p.balance_usdt 
        FROM stakes s 
        LEFT JOIN players p ON s.wallet_address = p.wallet_address 
        ORDER BY s.created_at DESC 
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Estatisticas - converter na query
    $statsQuery = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            COALESCE(SUM(CASE WHEN status = 'active' THEN amount ELSE 0 END), 0) as total_staked,
            COALESCE(SUM(total_earned), 0) as total_earned
        FROM stakes
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($statsQuery) {
        $stats['total'] = (int)$statsQuery['total'];
        $stats['active'] = (int)$statsQuery['active'];
        // Converter de unidades para USDT
        $stats['total_staked'] = (float)$statsQuery['total_staked'] / UNITS_DIVISOR;
        $stats['total_earned'] = (float)$statsQuery['total_earned'] / UNITS_DIVISOR;
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

/**
 * Converte unidades para USDT e formata
 */
function formatUsdt($units, $decimals = 6) {
    $usdt = (float)$units / UNITS_DIVISOR;
    return number_format($usdt, $decimals, '.', ',');
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-coins"></i> Gerenciamento de Staking</h1>
        <p class="page-subtitle">Controle de stakes e rendimentos</p>
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
            <div class="value">$<?php echo number_format($stats['total_staked'], 2, '.', ','); ?></div>
            <div class="label">Total em Stake</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-gift"></i></div>
            <div class="value">$<?php echo number_format($stats['total_earned'], 6, '.', ','); ?></div>
            <div class="label">Rendimentos Pagos</div>
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
                    <p>Os stakes dos usuarios aparecerao aqui.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Wallet</th>
                                <th>Valor</th>
                                <th>APY</th>
                                <th>Rendimento</th>
                                <th>Status</th>
                                <th>Inicio</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stakes as $s): ?>
                        <?php 
                            // Converter de unidades para USDT
                            $amountUsdt = (float)$s['amount'] / UNITS_DIVISOR;
                            $earnedUsdt = (float)$s['total_earned'] / UNITS_DIVISOR;
                            $apy = floatval($s['apy'] ?? 0.12);
                        ?>
                        <tr>
                            <td>#<?php echo (int)$s['id']; ?></td>
                            <td>
                                <span class="wallet-addr" onclick="copyToClipboard('<?php echo htmlspecialchars($s['wallet_address']); ?>')" title="<?php echo htmlspecialchars($s['wallet_address']); ?>">
                                    <?php echo substr($s['wallet_address'], 0, 6) . '...' . substr($s['wallet_address'], -4); ?>
                                </span>
                            </td>
                            <td style="color: var(--success); font-weight: 600;">
                                $<?php echo number_format($amountUsdt, 6, '.', ','); ?>
                            </td>
                            <td><?php echo number_format($apy * 100, 1); ?>%</td>
                            <td style="color: var(--warning);">
                                $<?php echo number_format($earnedUsdt, 6, '.', ','); ?>
                            </td>
                            <td>
                                <?php 
                                $statusClass = $s['status'] === 'active' ? 'success' : ($s['status'] === 'completed' ? 'primary' : 'warning');
                                ?>
                                <span class="badge badge-<?php echo $statusClass; ?>">
                                    <?php echo ucfirst($s['status']); ?>
                                </span>
                            </td>
                            <td style="color: var(--text-dim);">
                                <?php echo date('d/m/Y H:i', strtotime($s['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Info sobre armazenamento -->
                <div style="margin-top: 15px; padding: 10px 15px; background: rgba(0,240,255,0.05); border-radius: 8px; font-size: 0.8rem; color: var(--text-dim);">
                    <i class="fas fa-info-circle"></i> 
                    Valores armazenados em unidades (x100000000) e convertidos para exibicao.
                </div>
                
                <!-- Debug mode -->
                <?php if (isset($_GET['debug'])): ?>
                <div style="margin-top: 20px; padding: 15px; background: rgba(255,100,100,0.1); border: 1px solid rgba(255,100,100,0.3); border-radius: 10px; font-family: monospace; font-size: 11px;">
                    <strong>Debug - Valores brutos do banco:</strong><br><br>
                    <?php foreach ($stakes as $s): ?>
                        ID: <?php echo $s['id']; ?> | 
                        Raw amount: <?php echo $s['amount']; ?> | 
                        Converted: $<?php echo number_format((float)$s['amount'] / UNITS_DIVISOR, 8); ?> | 
                        Raw earned: <?php echo $s['total_earned']; ?><br>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Feedback visual opcional
    });
}
</script>

<style>
.wallet-addr {
    cursor: pointer;
    font-family: monospace;
    color: var(--primary);
    padding: 2px 6px;
    background: rgba(0, 240, 255, 0.1);
    border-radius: 4px;
}
.wallet-addr:hover {
    background: rgba(0, 240, 255, 0.2);
}
</style>