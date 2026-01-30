<?php
// ============================================
// UNOBIX - Admin Sidebar
// Arquivo: admin/includes/sidebar.php
// ATUALIZADO: Google Auth, BRL, Ads Manager
// ============================================

$pendingWithdrawals = 0;
$flaggedSessions = 0;
$pendingReferrals = 0;
$totalPlayers = 0;

try {
    if (isset($pdo)) {
        // Saques pendentes
        $stmt = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'");
        $pendingWithdrawals = $stmt->fetchColumn();
        
        // Sessões flagged
        $stmt = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged'");
        $flaggedSessions = $stmt->fetchColumn();
        
        // Referrals completados
        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if ($tableExists) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM referrals WHERE status = 'completed'");
            $pendingReferrals = $stmt->fetchColumn();
        }
        
        // Total de jogadores
        $stmt = $pdo->query("SELECT COUNT(*) FROM players");
        $totalPlayers = $stmt->fetchColumn();
    }
} catch (Exception $e) {}
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="<?php echo $ADMIN_BASE_URL; ?>/../img/logo-unobix.png" alt="Unobix" style="width: 36px; height: 36px; border-radius: 50%;">
            <span class="text">UNOBIX</span>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Principal</div>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=dashboard" class="nav-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=withdrawals" class="nav-item <?php echo $currentPage === 'withdrawals' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i>
                <span>Saques</span>
                <?php if ($pendingWithdrawals > 0): ?>
                    <span class="badge"><?php echo $pendingWithdrawals; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=players" class="nav-item <?php echo $currentPage === 'players' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>Jogadores</span>
                <span class="badge badge-info"><?php echo $totalPlayers; ?></span>
            </a>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=transactions" class="nav-item <?php echo $currentPage === 'transactions' ? 'active' : ''; ?>">
                <i class="fas fa-exchange-alt"></i>
                <span>Transações</span>
            </a>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=sessions" class="nav-item <?php echo $currentPage === 'sessions' ? 'active' : ''; ?>">
                <i class="fas fa-gamepad"></i>
                <span>Sessões</span>
                <?php if ($flaggedSessions > 0): ?>
                    <span class="badge badge-warning"><?php echo $flaggedSessions; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=stakes" class="nav-item <?php echo $currentPage === 'stakes' ? 'active' : ''; ?>">
                <i class="fas fa-coins"></i>
                <span>Staking</span>
            </a>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=referrals" class="nav-item <?php echo $currentPage === 'referrals' ? 'active' : ''; ?>">
                <i class="fas fa-user-friends"></i>
                <span>Afiliados</span>
                <?php if ($pendingReferrals > 0): ?>
                    <span class="badge"><?php echo $pendingReferrals; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Monetização</div>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=ads" class="nav-item <?php echo $currentPage === 'ads' ? 'active' : ''; ?>">
                <i class="fas fa-ad"></i>
                <span>Anúncios</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Sistema</div>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=security" class="nav-item <?php echo $currentPage === 'security' ? 'active' : ''; ?>">
                <i class="fas fa-shield-alt"></i>
                <span>Segurança</span>
            </a>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=logs" class="nav-item <?php echo $currentPage === 'logs' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i>
                <span>Logs</span>
            </a>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=settings" class="nav-item <?php echo $currentPage === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i>
                <span>Configurações</span>
            </a>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Conta</div>
            
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?logout=1" class="nav-item logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Sair</span>
            </a>
        </div>
    </nav>
    
    <div class="sidebar-footer">
        <small style="color: var(--text-dim);">v2.0 - BRL</small>
    </div>
</aside>
