<?php
// ===============================================================
// CRYPTO ASTEROID RUSH - ADMIN SIDEBAR
// Compatível com Railway (PHP 8 + MySQL 8)
// ===============================================================
?>
<aside id="sidebar" class="admin-sidebar">
    <div class="sidebar-logo">
        <i class="fas fa-rocket"></i>
        <span>Asteroid Rush</span>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li>
                <a href="../pages/dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li>
                <a href="../pages/withdrawals.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'withdrawals.php' ? 'active' : ''; ?>">
                    <i class="fas fa-wallet"></i> Saques
                </a>
            </li>
            <li>
                <a href="../pages/referrals.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'referrals.php' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Afiliados
                </a>
            </li>
            <li>
                <a href="../pages/staking.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'staking.php' ? 'active' : ''; ?>">
                    <i class="fas fa-coins"></i> Staking
                </a>
            </li>
            <li>
                <a href="../pages/security.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'security.php' ? 'active' : ''; ?>">
                    <i class="fas fa-shield-alt"></i> Segurança
                </a>
            </li>
            <li>
                <a href="../pages/logs.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'logs.php' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i> Logs
                </a>
            </li>
        </ul>
    </nav>
</aside>

<!-- CONTEÚDO PRINCIPAL -->
<main id="main-content" class="admin-main">
