<?php
// ============================================
// UNOBIX - Segurança
// Arquivo: admin/pages/security.php
// v6.0 - Tabela users, suspicious_activity (doc 3.8)
// ============================================

$pageTitle = 'Segurança';

try {
    // Sessões flagged recentes — JOIN com users
    $flaggedSessions = $pdo->query("
        SELECT gs.*, u.display_name, u.is_banned 
        FROM game_sessions gs
        LEFT JOIN users u ON gs.google_uid = u.google_uid
        WHERE gs.status = 'flagged'
        ORDER BY gs.created_at DESC LIMIT 50
    ")->fetchAll();
    
    // Jogadores banidos — tabela: users (doc 3.1)
    $bannedPlayers = $pdo->query("
        SELECT id, google_uid, email, display_name, ban_reason, updated_at 
        FROM users WHERE is_banned = 1 ORDER BY updated_at DESC LIMIT 20
    ")->fetchAll();
    
    // Atividades suspeitas — tabela: suspicious_activity (doc 3.8)
    $suspiciousLogs = [];
    try {
        $suspiciousLogs = $pdo->query("
            SELECT sa.*, u.display_name, u.email
            FROM suspicious_activity sa
            LEFT JOIN users u ON sa.user_id = u.id
            ORDER BY sa.created_at DESC LIMIT 50
        ")->fetchAll();
    } catch (Exception $e) {
        // Tabela pode não existir ainda
    }
    
    // Stats
    $stats = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged') as flagged,
            (SELECT COUNT(*) FROM users WHERE is_banned = 1) as banned
    ")->fetch();
    
    // Alertas 24h (suspicious_activity)
    $alerts24h = 0;
    try {
        $alerts24h = $pdo->query("SELECT COUNT(*) FROM suspicious_activity WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    } catch (Exception $e) {}
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-shield-alt"></i> Segurança</h1>
    </div>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
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
            <div class="value"><?php echo $alerts24h; ?></div>
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
                        <thead><tr><th>ID</th><th>Jogador</th><th>Ganho</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($flaggedSessions as $s): ?>
                        <tr>
                            <td>#<?php echo $s['id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($s['display_name'] ?? 'Usuário'); ?>
                                <?php if ($s['is_hard_mode'] ?? false): ?><span class="badge badge-warning">HARD</span><?php endif; ?>
                            </td>
                            <td style="color: var(--warning);">R$ <?php echo number_format($s['earnings_brl'] ?? 0, 6, ',', '.'); ?></td>
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
                            <td>
                                <?php echo htmlspecialchars($p['display_name'] ?? 'Usuário'); ?>
                                <div style="font-size: 0.7rem; color: var(--text-dim);"><?php echo htmlspecialchars($p['email'] ?? ''); ?></div>
                            </td>
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
    
    <!-- Atividades Suspeitas (suspicious_activity - doc 3.8) -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-exclamation-triangle"></i> Atividades Suspeitas</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($suspiciousLogs)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>Nenhuma atividade suspeita registrada</p></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead><tr><th>ID</th><th>Jogador</th><th>Tipo</th><th>Severidade</th><th>IP</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php foreach ($suspiciousLogs as $log): ?>
                    <tr>
                        <td>#<?php echo $log['id']; ?></td>
                        <td><?php echo htmlspecialchars($log['display_name'] ?? 'Usuário'); ?></td>
                        <td><span class="badge badge-warning"><?php echo htmlspecialchars($log['activity_type']); ?></span></td>
                        <td>
                            <?php
                            $sevClass = ['low' => 'info', 'medium' => 'warning', 'high' => 'danger', 'critical' => 'danger'][$log['severity'] ?? 'low'] ?? 'info';
                            ?>
                            <span class="badge badge-<?php echo $sevClass; ?>"><?php echo ucfirst($log['severity'] ?? 'low'); ?></span>
                        </td>
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
