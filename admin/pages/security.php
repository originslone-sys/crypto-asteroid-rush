<?php
// ============================================
// UNOBIX - Segurança
// Arquivo: admin/pages/security.php
// v7.0 - Ações de alerta, blacklist, filtros
// ============================================

$pageTitle = 'Segurança';

try {
    $flaggedSessions = $pdo->query("
        SELECT gs.*, u.display_name, u.is_banned 
        FROM game_sessions gs
        LEFT JOIN users u ON gs.google_uid = u.google_uid
        WHERE gs.status = 'flagged'
        ORDER BY gs.created_at DESC LIMIT 50
    ")->fetchAll();
    
    $bannedPlayers = $pdo->query("
        SELECT id, google_uid, email, display_name, ban_reason, updated_at 
        FROM users WHERE is_banned = 1 ORDER BY updated_at DESC LIMIT 20
    ")->fetchAll();
    
    $suspiciousLogs = [];
    try {
        $suspiciousLogs = $pdo->query("
            SELECT sa.*, u.display_name, u.email
            FROM suspicious_activity sa
            LEFT JOIN users u ON sa.user_id = u.id
            ORDER BY sa.created_at DESC LIMIT 50
        ")->fetchAll();
    } catch (Exception $e) {}
    
    $stats = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged') as flagged,
            (SELECT COUNT(*) FROM users WHERE is_banned = 1) as banned
    ")->fetch();
    
    $alerts24h = 0;
    try { $alerts24h = $pdo->query("SELECT COUNT(*) FROM suspicious_activity WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(); } catch (Exception $e) {}
    
    $blacklistedIPs = [];
    $blacklistCount = 0;
    try {
        $blacklistedIPs = $pdo->query("SELECT * FROM ip_blacklist WHERE expires_at IS NULL OR expires_at > NOW() ORDER BY created_at DESC LIMIT 50")->fetchAll();
        $blacklistCount = count($blacklistedIPs);
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
        <div class="stat-card"><div class="icon danger"><i class="fas fa-flag"></i></div><div class="value"><?php echo $stats['flagged'] ?? 0; ?></div><div class="label">Sessões Flagged</div></div>
        <div class="stat-card"><div class="icon warning"><i class="fas fa-ban"></i></div><div class="value"><?php echo $stats['banned'] ?? 0; ?></div><div class="label">Jogadores Banidos</div></div>
        <div class="stat-card"><div class="icon primary"><i class="fas fa-bell"></i></div><div class="value"><?php echo $alerts24h; ?></div><div class="label">Alertas (24h)</div></div>
        <div class="stat-card"><div class="icon danger"><i class="fas fa-globe"></i></div><div class="value"><?php echo $blacklistCount; ?></div><div class="label">IPs Bloqueados</div></div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
        <!-- Sessões Flagged -->
        <div class="panel">
            <div class="panel-header"><h3 class="panel-title"><i class="fas fa-flag"></i> Sessões Suspeitas</h3></div>
            <div class="panel-body">
                <?php if (empty($flaggedSessions)): ?>
                    <div class="empty-state"><i class="fas fa-check"></i><p>Nenhuma sessão suspeita</p></div>
                <?php else: ?>
                <div class="table-container" style="max-height: 400px; overflow-y: auto;">
                    <table><thead><tr><th>ID</th><th>Jogador</th><th>Ganho</th><th>Data</th></tr></thead><tbody>
                    <?php foreach ($flaggedSessions as $s): ?>
                    <tr>
                        <td>#<?php echo $s['id']; ?></td>
                        <td><?php echo htmlspecialchars($s['display_name'] ?? 'Usuário'); ?><?php if ($s['is_hard_mode'] ?? false): ?> <span class="badge badge-warning">HARD</span><?php endif; ?></td>
                        <td style="color: var(--warning);">R$ <?php echo number_format($s['earnings_brl'] ?? 0, 6, ',', '.'); ?></td>
                        <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Jogadores Banidos -->
        <div class="panel">
            <div class="panel-header"><h3 class="panel-title"><i class="fas fa-ban"></i> Banidos</h3></div>
            <div class="panel-body">
                <?php if (empty($bannedPlayers)): ?>
                    <div class="empty-state"><i class="fas fa-check"></i><p>Nenhum banido</p></div>
                <?php else: ?>
                <div class="table-container" style="max-height: 400px; overflow-y: auto;">
                    <table><thead><tr><th>Jogador</th><th>Motivo</th><th>Data</th><th>Ação</th></tr></thead><tbody>
                    <?php foreach ($bannedPlayers as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['display_name'] ?? 'Usuário'); ?><div style="font-size: 0.7rem; color: var(--text-dim);"><?php echo htmlspecialchars($p['email'] ?? ''); ?></div></td>
                        <td><small><?php echo htmlspecialchars($p['ban_reason'] ?? 'N/A'); ?></small></td>
                        <td><?php echo date('d/m/Y', strtotime($p['updated_at'])); ?></td>
                        <td><button class="btn btn-sm btn-success" onclick="unbanPlayer('<?php echo htmlspecialchars($p['google_uid']); ?>')" title="Desbanir"><i class="fas fa-unlock"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- IP Blacklist -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="panel-title"><i class="fas fa-globe"></i> IP Blacklist</h3>
            <button class="btn btn-sm btn-danger" onclick="blacklistIP()"><i class="fas fa-plus"></i> Bloquear IP</button>
        </div>
        <div class="panel-body">
            <?php if (empty($blacklistedIPs)): ?>
                <div class="empty-state"><i class="fas fa-check"></i><p>Nenhum IP bloqueado</p></div>
            <?php else: ?>
            <div class="table-container">
                <table><thead><tr><th>IP</th><th>Motivo</th><th>Criado</th><th>Expira</th><th>Ação</th></tr></thead><tbody>
                <?php foreach ($blacklistedIPs as $bl): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars($bl['ip_address']); ?></code></td>
                    <td><small><?php echo htmlspecialchars($bl['reason'] ?? '-'); ?></small></td>
                    <td><?php echo date('d/m H:i', strtotime($bl['created_at'])); ?></td>
                    <td><?php echo $bl['expires_at'] ? date('d/m H:i', strtotime($bl['expires_at'])) : '<span class="badge badge-danger">Permanente</span>'; ?></td>
                    <td><button class="btn btn-sm btn-success" onclick="unblacklistIP('<?php echo htmlspecialchars($bl['ip_address']); ?>')" title="Desbloquear"><i class="fas fa-unlock"></i></button></td>
                </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Atividades Suspeitas com Ações -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <h3 class="panel-title"><i class="fas fa-exclamation-triangle"></i> Atividades Suspeitas</h3>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <button class="btn btn-sm btn-primary" onclick="bulkReviewAlerts()" title="Revisar selecionados"><i class="fas fa-check-double"></i> Revisar</button>
                <button class="btn btn-sm btn-danger" onclick="bulkDeleteAlerts()" title="Excluir selecionados"><i class="fas fa-trash"></i> Excluir</button>
                <button class="btn btn-sm btn-warning" onclick="deleteReviewedAlerts(30)" title="Limpar revisados com mais de 30 dias"><i class="fas fa-broom"></i> Limpar Antigos</button>
            </div>
        </div>
        <div class="panel-body">
            <?php if (empty($suspiciousLogs)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>Nenhuma atividade suspeita registrada</p></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 30px;"><input type="checkbox" onchange="toggleSelectAllAlerts(this)" title="Selecionar todos"></th>
                            <th>ID</th><th>Jogador</th><th>Tipo</th><th>Severidade</th><th>IP</th><th>Data</th><th>Status</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($suspiciousLogs as $log): 
                        $isReviewed = !empty($log['reviewed']);
                        $sevClass = ['low'=>'info','medium'=>'warning','high'=>'danger','critical'=>'danger'][$log['severity'] ?? 'low'] ?? 'info';
                    ?>
                    <tr data-alert-id="<?php echo $log['id']; ?>" style="<?php echo $isReviewed ? 'opacity: 0.6;' : ''; ?>">
                        <td><input type="checkbox" class="alert-checkbox" value="<?php echo $log['id']; ?>"></td>
                        <td>#<?php echo $log['id']; ?></td>
                        <td>
                            <?php echo htmlspecialchars($log['display_name'] ?? 'Usuário'); ?>
                            <?php if (!empty($log['email'])): ?><div style="font-size: 0.65rem; color: var(--text-dim);"><?php echo htmlspecialchars($log['email']); ?></div><?php endif; ?>
                        </td>
                        <td><span class="badge badge-warning"><?php echo htmlspecialchars($log['activity_type']); ?></span></td>
                        <td><span class="badge badge-<?php echo $sevClass; ?>"><?php echo ucfirst($log['severity'] ?? 'low'); ?></span></td>
                        <td><code style="font-size: 0.75rem;"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></code></td>
                        <td style="white-space: nowrap;"><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                        <td>
                            <?php if ($isReviewed): ?>
                                <span class="badge badge-success" title="Por <?php echo htmlspecialchars($log['reviewed_by'] ?? ''); ?> em <?php echo $log['reviewed_at'] ? date('d/m H:i', strtotime($log['reviewed_at'])) : ''; ?>"><i class="fas fa-check"></i></span>
                            <?php else: ?>
                                <span class="badge badge-info">Pendente</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space: nowrap;">
                            <?php if (!$isReviewed): ?>
                                <button class="btn btn-sm btn-primary btn-review" onclick="reviewAlert(<?php echo $log['id']; ?>)" title="Marcar como revisado"><i class="fas fa-check"></i></button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-danger" onclick="deleteAlert(<?php echo $log['id']; ?>)" title="Excluir"><i class="fas fa-trash"></i></button>
                            <?php if (!empty($log['ip_address']) && $log['ip_address'] !== '-'): ?>
                                <button class="btn btn-sm btn-warning" onclick="blacklistIP('<?php echo htmlspecialchars($log['ip_address']); ?>', 'Bloqueado via alerta #<?php echo $log['id']; ?>')" title="Bloquear IP"><i class="fas fa-ban"></i></button>
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
