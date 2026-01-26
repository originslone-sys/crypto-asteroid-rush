<?php
// ============================================
// CRYPTO ASTEROID RUSH - Logs do Sistema
// Arquivo: admin/pages/logs.php
// ============================================

$pageTitle = 'Logs';

$logType = $_GET['type'] ?? 'security';

$logs = [];
$logContent = '';

try {
    switch ($logType) {
        case 'security':
            $logFile = '../../api/game_security.log';
            break;
        case 'maintenance':
            $logFile = '../../api/maintenance.log';
            break;
        case 'error':
            $logFile = '../../api/error_log';
            break;
        default:
            $logFile = '../../api/game_security.log';
    }
    
    if (file_exists($logFile)) {
        $logContent = file_get_contents($logFile);
        // Pegar últimas 200 linhas
        $lines = explode("\n", $logContent);
        $lines = array_slice($lines, -200);
        $logContent = implode("\n", array_reverse($lines));
    }
    
    // Logs do banco de dados
    $securityLogs = $pdo->query("SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 100")->fetchAll();
    
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
                <a href="?page=logs&type=security" class="tab <?php echo $logType === 'security' ? 'active' : ''; ?>">
                    <i class="fas fa-shield-alt"></i> Segurança
                </a>
                <a href="?page=logs&type=maintenance" class="tab <?php echo $logType === 'maintenance' ? 'active' : ''; ?>">
                    <i class="fas fa-tools"></i> Manutenção
                </a>
                <a href="?page=logs&type=error" class="tab <?php echo $logType === 'error' ? 'active' : ''; ?>">
                    <i class="fas fa-exclamation-circle"></i> Erros
                </a>
                <a href="?page=logs&type=database" class="tab <?php echo $logType === 'database' ? 'active' : ''; ?>">
                    <i class="fas fa-database"></i> Banco de Dados
                </a>
            </div>
        </div>
    </div>
    
    <?php if ($logType === 'database'): ?>
    <!-- Logs do Banco de Dados -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-database"></i> Logs de Segurança (Banco)</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($securityLogs)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><h3>Nenhum log encontrado</h3></div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Wallet</th>
                                <th>Sessão</th>
                                <th>Tipo</th>
                                <th>Dados</th>
                                <th>IP</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($securityLogs as $log): ?>
                        <tr>
                            <td>#<?php echo $log['id']; ?></td>
                            <td><span class="wallet-addr"><?php echo substr($log['wallet_address'] ?? '-', 0, 10); ?>...</span></td>
                            <td><?php echo $log['session_id'] ?? '-'; ?></td>
                            <td><span class="badge badge-warning"><?php echo $log['event_type']; ?></span></td>
                            <td style="max-width: 200px; overflow: hidden;"><small><?php echo htmlspecialchars(substr($log['event_data'] ?? '', 0, 80)); ?></small></td>
                            <td><code><?php echo $log['ip_address'] ?? '-'; ?></code></td>
                            <td><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Logs de Arquivo -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-file-alt"></i> Conteúdo do Log</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($logContent)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><h3>Log vazio ou não encontrado</h3></div>
            <?php else: ?>
                <pre style="background: rgba(0,0,0,0.3); padding: 20px; border-radius: 10px; max-height: 600px; overflow: auto; font-size: 0.85rem; color: var(--text-dim); white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($logContent); ?></pre>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
