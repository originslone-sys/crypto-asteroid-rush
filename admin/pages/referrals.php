<?php
// ============================================
// UNOBIX - Afiliados
// Arquivo: admin/pages/referrals.php
// ============================================

$pageTitle = 'Afiliados';

try {
    $referrals = $pdo->query("
        SELECT r.*, 
               p1.display_name as referrer_name, p1.email as referrer_email,
               p2.display_name as referred_name, p2.email as referred_email, p2.total_played
        FROM referrals r
        LEFT JOIN players p1 ON r.referrer_google_uid = p1.google_uid
        LEFT JOIN players p2 ON r.referred_google_uid = p2.google_uid
        ORDER BY r.created_at DESC LIMIT 100
    ")->fetchAll();
    
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) as claimed,
            SUM(CASE WHEN status = 'claimed' THEN commission_brl ELSE 0 END) as total_paid
        FROM referrals
    ")->fetch();
} catch (Exception $e) {
    $error = $e->getMessage();
}

function formatBRL($v) { return 'R$ ' . number_format($v ?? 0, 2, ',', '.'); }
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-user-friends"></i> Sistema de Afiliados</h1>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="label">Total Indicações</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-clock"></i></div>
            <div class="value"><?php echo $stats['pending'] ?? 0; ?></div>
            <div class="label">Em Progresso</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check"></i></div>
            <div class="value"><?php echo ($stats['completed'] ?? 0) + ($stats['claimed'] ?? 0); ?></div>
            <div class="label">Completadas</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-coins"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_paid']); ?></div>
            <div class="label">Comissões Pagas</div>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Indicações</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($referrals)): ?>
                <div class="empty-state"><i class="fas fa-user-friends"></i><h3>Nenhuma indicação</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Indicador</th><th>Indicado</th><th>Progresso</th><th>Comissão</th><th>Status</th><th>Data</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($referrals as $r): ?>
                    <?php 
                        $progress = min(100, ($r['total_played'] ?? 0));
                        $statusLabels = ['pending' => ['Em Progresso', 'warning'], 'completed' => ['Completado', 'success'], 'claimed' => ['Resgatado', 'primary']];
                        $label = $statusLabels[$r['status']] ?? [$r['status'], 'info'];
                    ?>
                    <tr>
                        <td>#<?php echo $r['id']; ?></td>
                        <td>
                            <div><?php echo htmlspecialchars($r['referrer_name'] ?? 'Usuário'); ?></div>
                            <small style="color: var(--text-dim);"><?php echo htmlspecialchars($r['referrer_email'] ?? ''); ?></small>
                        </td>
                        <td>
                            <div><?php echo htmlspecialchars($r['referred_name'] ?? 'Usuário'); ?></div>
                            <small style="color: var(--text-dim);"><?php echo htmlspecialchars($r['referred_email'] ?? ''); ?></small>
                        </td>
                        <td>
                            <div><?php echo $r['missions_completed'] ?? 0; ?>/100</div>
                            <div style="background: rgba(255,255,255,0.1); border-radius: 5px; height: 6px; margin-top: 5px;">
                                <div style="background: var(--primary); width: <?php echo $progress; ?>%; height: 100%; border-radius: 5px;"></div>
                            </div>
                        </td>
                        <td style="color: var(--success);"><?php echo formatBRL($r['commission_brl']); ?></td>
                        <td><span class="badge badge-<?php echo $label[1]; ?>"><?php echo $label[0]; ?></span></td>
                        <td><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
