<?php
// ============================================
// UNOBIX - Logs do Sistema
// Arquivo: admin/pages/logs.php
// ============================================

$pageTitle = 'Logs';
$logType = $_GET['type'] ?? 'database';

try {
    $securityLogs = $pdo->query("SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 100")->fetchAll();
    $transactionLogs = $pdo->query("SELECT t.*, p.display_name FROM transactions t LEFT JOIN players p ON t.google_uid = p.google_uid ORDER BY t.created_at DESC LIMIT 100")->fetchAll();
    $sessionLogs = $pdo->query("SELECT gs.*, p.display_name FROM game_sessions gs LEFT JOIN players p ON gs.google_uid = p.google_uid WHERE gs.status IN ('flagged', 'expired') ORDER BY gs.created_at DESC LIMIT 50")->fetchAll();
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-file-alt"></i> Logs do Sistema</h1>
    </div>
    
    <div class="panel">
        <div class="panel-body">
            <div class="tabs">
                <a href="?page=logs&type=database" class="tab <?php echo $logType === 'database' ? 'active' : ''; ?>"><i class="fas fa-database"></i> Segurança</a>
                <a href="?page=logs&type=transactions" class="tab <?php echo $logType === 'transactions' ? 'active' : ''; ?>"><i class="fas fa-exchange-alt"></i> Transações</a>
                <a href="?page=logs&type=sessions" class="tab <?php echo $logType === 'sessions' ? 'active' : ''; ?>"><i class="fas fa-gamepad"></i> Sessões</a>
            </div>
        </div>
    </div>
    
    <?php if ($logType === 'database'): ?>
    <div class="panel">
        <div class="panel-header"><h3 class="panel-title"><i class="fas fa-shield-alt"></i> Logs de Segurança</h3></div>
        <div class="panel-body">
            <?php if (empty($securityLogs)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><h3>Nenhum log</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead><tr><th>ID</th><th>Tipo</th><th>Dados</th><th>IP</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php foreach ($securityLogs as $log): ?>
                    <tr>
                        <td>#<?php echo $log['id']; ?></td>
                        <td><span class="badge badge-warning"><?php echo htmlspecialchars($log['event_type']); ?></span></td>
                        <td style="max-width: 300px;"><small><?php echo htmlspecialchars(substr($log['event_data'] ?? '', 0, 100)); ?></small></td>
                        <td><code><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></code></td>
                        <td><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php elseif ($logType === 'transactions'): ?>
    <div class="panel">
        <div class="panel-header"><h3 class="panel-title"><i class="fas fa-exchange-alt"></i> Transações</h3></div>
        <div class="panel-body">
            <?php if (empty($transactionLogs)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><h3>Nenhuma</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead><tr><th>ID</th><th>Jogador</th><th>Tipo</th><th>Valor</th><th>Status</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php foreach ($transactionLogs as $t): ?>
                    <tr>
                        <td>#<?php echo $t['id']; ?></td>
                        <td><?php echo htmlspecialchars($t['display_name'] ?? 'Usuário'); ?></td>
                        <td><span class="badge badge-primary"><?php echo $t['type']; ?></span></td>
                        <td style="color: <?php echo $t['amount_brl'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">R$ <?php echo number_format($t['amount_brl'], 2, ',', '.'); ?></td>
                        <td><span class="badge badge-<?php echo $t['status'] === 'completed' ? 'success' : 'warning'; ?>"><?php echo $t['status']; ?></span></td>
                        <td><?php echo date('d/m H:i', strtotime($t['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php else: ?>
    <div class="panel">
        <div class="panel-header"><h3 class="panel-title"><i class="fas fa-gamepad"></i> Sessões Problemáticas</h3></div>
        <div class="panel-body">
            <?php if (empty($sessionLogs)): ?>
                <div class="empty-state"><i class="fas fa-check"></i><h3>Nenhuma</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead><tr><th>ID</th><th>Jogador</th><th>Status</th><th>Motivo</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php foreach ($sessionLogs as $s): ?>
                    <tr>
                        <td>#<?php echo $s['id']; ?></td>
                        <td><?php echo htmlspecialchars($s['display_name'] ?? 'Usuário'); ?></td>
                        <td><span class="badge badge-<?php echo $s['status'] === 'flagged' ? 'danger' : 'warning'; ?>"><?php echo $s['status']; ?></span></td>
                        <td><small><?php echo htmlspecialchars($s['flag_reason'] ?? '-'); ?></small></td>
                        <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
