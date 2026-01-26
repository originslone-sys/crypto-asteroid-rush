<?php
// ============================================
// CRYPTO ASTEROID RUSH - Admin Sidebar
// Arquivo: admin/includes/sidebar.php
// ATUALIZADO: Link para pagina de Afiliados
// ============================================

// Contar saques pendentes
$pendingWithdrawals = 0;
$flaggedSessions = 0;
$pendingReferrals = 0;

try {
    if (isset($pdo)) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'");
        $pendingWithdrawals = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged'");
        $flaggedSessions = $stmt->fetchColumn();
        
        // Contar referrals completados aguardando resgate
        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if ($tableExists) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM referrals WHERE status = 'completed'");
            $pendingReferrals = $stmt->fetchColumn();
        }
    }
} catch (Exception $e) {}
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <span class="icon">&#9732;&#65039;</span>
            <span class="text">ASTEROID RUSH</span>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Principal</div>
            
            <a href="index.php?page=dashboard" class="nav-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="index.php?page=withdrawals" class="nav-item <?php echo $currentPage === 'withdrawals' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span>Saques</span>
                <?php if ($pendingWithdrawals > 0): ?>
                    <span class="badge"><?php echo $pendingWithdrawals; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="index.php?page=players" class="nav-item <?php echo $currentPage === 'players' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>Jogadores</span>
            </a>
            
            <a href="index.php?page=transactions" class="nav-item <?php echo $currentPage === 'transactions' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i>
                <span>Transacoes</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Jogo</div>
            
            <a href="index.php?page=sessions" class="nav-item <?php echo $currentPage === 'sessions' ? 'active' : ''; ?>">
                <i class="fas fa-gamepad"></i>
                <span>Sessoes de Jogo</span>
            </a>
            
            <a href="index.php?page=stakes" class="nav-item <?php echo $currentPage === 'stakes' ? 'active' : ''; ?>">
                <i class="fas fa-coins"></i>
                <span>Staking</span>
            </a>
            
            <a href="index.php?page=referrals" class="nav-item <?php echo $currentPage === 'referrals' ? 'active' : ''; ?>">
                <i class="fas fa-user-friends"></i>
                <span>Afiliados</span>
                <?php if ($pendingReferrals > 0): ?>
                    <span class="badge badge-success"><?php echo $pendingReferrals; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Seguranca</div>
            
            <a href="index.php?page=security" class="nav-item <?php echo $currentPage === 'security' ? 'active' : ''; ?>">
                <i class="fas fa-shield-alt"></i>
                <span>Anti-Cheat</span>
                <?php if ($flaggedSessions > 0): ?>
                    <span class="badge"><?php echo $flaggedSessions; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="index.php?page=logs" class="nav-item <?php echo $currentPage === 'logs' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i>
                <span>Logs</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Sistema</div>
            
            <a href="index.php?page=settings" class="nav-item <?php echo $currentPage === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i>
                <span>Configuracoes</span>
            </a>
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <div class="admin-info">
            <div class="admin-avatar">
                <?php echo strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1)); ?>
            </div>
            <div>
                <div class="admin-name"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></div>
                <div class="admin-role">Administrador</div>
            </div>
        </div>
        <a href="index.php?logout=1" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Sair
        </a>
    </div>
</aside>

<style>
/* Badge verde para referrals */
.badge-success {
    background: var(--success) !important;
    color: var(--deep-space) !important;
}
</style>
