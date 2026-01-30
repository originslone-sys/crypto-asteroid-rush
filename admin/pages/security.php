<?php
// ============================================
// UNOBIX - Segurança
// Arquivo: admin/pages/security.php
// ============================================

$pageTitle = 'Segurança';

try {
    // Sessões flagged recentes
    $flaggedSessions = $pdo->query("
        SELECT gs.*, p.display_name, p.is_banned 
        FROM game_sessions gs
        LEFT JOIN players p ON gs.google_uid = p.google_uid
        WHERE gs.status = 'flagged'
        ORDER BY gs.created_at DESC LIMIT 50
    ")->fetchAll();
    
    // Jogadores banidos
    $bannedPlayers = $pdo->query("
        SELECT * FROM players WHERE is_banned = 1 ORDER BY updated_at DESC LIMIT 20
    ")->fetchAll();
    
    // Logs de segurança
    $securityLogs = $pdo->query("
        SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 50
    ")->fetchAll();
    
    // Stats
    $stats = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged') as flagged,
            (SELECT COUNT(*) FROM players WHERE is_banned = 1) as banned,
            (SELECT COUNT(*) FROM security_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as alerts_24h
    ")->fetch();
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-shield-alt"></i> Segurança</h1>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-flag"></i></div>
            <div class="value"><?php echo $stats['flagged'] ?? 0; ?></div>
            <div class="label">Sessões Flagged</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-ban"></i></div>
            <div class="value"><?php echo $stats['banned'] ?? 0; ?></div>
            <div class="label">Jogadores Banidos</div>
        </div>
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-bell"></i></div>
            <div class="value"><?php echo $stats['alerts_24h'] ?? 0; ?></div>
            <div class="label">Alertas (24h)</div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
        <!-- Sessões Flagged -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-flag"></i> Sessões Suspeitas</h3>
            </div>
            <div class="panel-body">
                <?php if (empty($flaggedSessions)): ?>
                    <div class="empty-state"><i class="fas fa-check"></i><p>Nenhuma sessão suspeita</p></div>
                <?php else: ?>
                <div class="table-container" style="max-height: 400px; overflow-y: auto;">
                    <table>
                        <thead><tr><th>ID</th><th>Jogador</th><th>Motivo</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($flaggedSessions as $s): ?>
                        <tr>
                            <td>#<?php echo $s['id']; ?></td>
                            <td><?php echo htmlspecialchars($s['display_name'] ?? 'Usuário'); ?></td>
                            <td><small><?php echo htmlspecialchars($s['flag_reason'] ?? 'N/A'); ?></small></td>
                            <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Jogadores Banidos -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-ban"></i> Banidos</h3>
            </div>
            <div class="panel-body">
                <?php if (empty($bannedPlayers)): ?>
                    <div class="empty-state"><i class="fas fa-check"></i><p>Nenhum banido</p></div>
                <?php else: ?>
                <div class="table-container" style="max-height: 400px; overflow-y: auto;">
                    <table>
                        <thead><tr><th>Jogador</th><th>Motivo</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($bannedPlayers as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['display_name'] ?? 'Usuário'); ?></td>
                            <td><small><?php echo htmlspecialchars($p['ban_reason'] ?? 'N/A'); ?></small></td>
                            <td><?php echo date('d/m/Y', strtotime($p['updated_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Logs de Segurança -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-clipboard-list"></i> Logs de Segurança</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($securityLogs)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>Nenhum log</p></div>
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
</div>
