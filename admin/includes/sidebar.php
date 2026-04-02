<?php
// ============================================
// UNOBIX - Admin Sidebar
// Arquivo: admin/includes/sidebar.php
// v6.0 - Tabela users, game_settings, staking
// ============================================

$pendingWithdrawals = 0;
$flaggedSessions = 0;
$pendingReferrals = 0;
$totalPlayers = 0;
$openTickets = 0;

try {
    if (isset($pdo)) {
        // Saques pendentes
        $stmt = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'");
        $pendingWithdrawals = $stmt->fetchColumn();

        // Sessões flagged
        $stmt = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged'");
        $flaggedSessions = $stmt->fetchColumn();

        // Referrals qualificados (prontos para claim)
        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if ($tableExists) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM referrals WHERE status = 'qualified'");
            $pendingReferrals = $stmt->fetchColumn();
        }

        // Total de usuários
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $totalPlayers = $stmt->fetchColumn();

        // Tickets de suporte abertos
        $ticketTable = $pdo->query("SHOW TABLES LIKE 'support_tickets'")->fetch();
        if ($ticketTable) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')");
            $openTickets = $stmt->fetchColumn();
        }
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
    
    <nav class="sidebar-nav" onclick="if(window.innerWidth<=992)closeSidebar()">
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

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=referrals" class="nav-item <?php echo $currentPage === 'referrals' ? 'active' : ''; ?>">
                <i class="fas fa-user-friends"></i>
                <span>Afiliados</span>
                <?php if ($pendingReferrals > 0): ?>
                    <span class="badge"><?php echo $pendingReferrals; ?></span>
                <?php endif; ?>
            </a>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=support" class="nav-item <?php echo $currentPage === 'support' ? 'active' : ''; ?>">
                <i class="fas fa-headset"></i>
                <span>Suporte</span>
                <?php if ($openTickets > 0): ?>
                    <span class="badge"><?php echo $openTickets; ?></span>
                <?php endif; ?>
            </a>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=exploration" class="nav-item <?php echo $currentPage === 'exploration' ? 'active' : ''; ?>">
                <i class="fas fa-compass"></i>
                <span>Exploração</span>
            </a>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=notifications" class="nav-item <?php echo $currentPage === 'notifications' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notificações</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Monetizacao</div>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=credits" class="nav-item <?php echo $currentPage === 'credits' ? 'active' : ''; ?>">
                <i class="fas fa-ticket-alt"></i>
                <span>Creditos</span>
            </a>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=premium" class="nav-item <?php echo $currentPage === 'premium' ? 'active' : ''; ?>">
                <i class="fas fa-crown" style="color: #ffd700;"></i>
                <span>Premium</span>
            </a>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=ads" class="nav-item <?php echo $currentPage === 'ads' ? 'active' : ''; ?>">
                <i class="fas fa-ad"></i>
                <span>Anuncios</span>
            </a>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=whatsapp" class="nav-item <?php echo $currentPage === 'whatsapp' ? 'active' : ''; ?>">
                <i class="fab fa-whatsapp"></i>
                <span>WhatsApp</span>
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">Sistema</div>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=metrics" class="nav-item <?php echo $currentPage === 'metrics' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Metricas</span>
            </a>

            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=suspect-analysis" class="nav-item <?php echo $currentPage === 'suspect-analysis' ? 'active' : ''; ?>">
                <i class="fas fa-robot"></i>
                <span>Análise de Suspeitas</span>
            </a>

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
        <small style="color: var(--text-dim);">v6.0 - BRL</small>
    </div>
</aside>
