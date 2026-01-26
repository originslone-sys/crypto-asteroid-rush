<?php
// ============================================
// CRYPTO ASTEROID RUSH - Admin Header
// Arquivo: admin/includes/header.php
// ============================================

// Verificar sessão
if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

// Página atual - usar variável já definida ou detectar
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
    if ($currentPage === 'index') {
        $currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' | ' : ''; ?>Admin - Crypto Asteroid Rush</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700;800;900&family=Exo+2:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="admin-wrapper">
