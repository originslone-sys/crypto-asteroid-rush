<?php
// ===============================================================
// CRYPTO ASTEROID RUSH - ADMIN HEADER
// Compatível com Railway (PHP 8 + MySQL 8)
// ===============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crypto Asteroid Rush - Painel Administrativo</title>
    
    <!-- ============================= -->
    <!-- CSS PRINCIPAL (Railway compatível) -->
    <!-- ============================= -->
    <link rel="stylesheet" href="../css/admin.css">

    <!-- ============================= -->
    <!-- Font Awesome e Ícones -->
    <!-- ============================= -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- ============================= -->
    <!-- JavaScript global -->
    <!-- ============================= -->
    <script src="../js/admin.js" defer></script>
</head>

<body>
    <div id="admin-panel">
        <!-- HEADER SUPERIOR -->
        <header class="admin-header">
            <div class="header-left">
                <button class="sidebar-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="header-title">Painel Administrativo</h1>
            </div>
            <div class="header-right">
                <span class="admin-user">
                    <i class="fas fa-user-shield"></i>
                    <?php echo htmlspecialchars($_SESSION['admin'] ?? 'Administrador'); ?>
                </span>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </div>
        </header>

        <!-- CONTAINER PRINCIPAL -->
        <div class="admin-container">
