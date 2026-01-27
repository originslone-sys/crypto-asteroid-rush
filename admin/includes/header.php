<?php
// ============================================
// CRYPTO ASTEROID RUSH - Admin Header
// Arquivo: admin/includes/header.php
// ============================================

// Base URL do admin (corrige paths e redirects em diferentes DocumentRoots)
$__scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$__scriptDir  = rtrim(str_replace('\\', '/', dirname($__scriptName)), '/');
if ($__scriptDir === '/') $__scriptDir = '';
// Se estiver acessando algo dentro de /pages ou /includes diretamente, subir 1 nível
if (preg_match('~/(pages|includes)$~', $__scriptDir)) {
    $__scriptDir = rtrim(str_replace('\\', '/', dirname($__scriptDir)), '/');
    if ($__scriptDir === '/') $__scriptDir = '';
}
$ADMIN_BASE_URL  = $__scriptDir;            // ex: '' ou '/admin'
$ADMIN_INDEX_URL = $ADMIN_BASE_URL . '/index.php';

// Verificar sessão
if (!isset($_SESSION['admin'])) {
    header('Location: ' . $ADMIN_INDEX_URL);
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
    <link rel="stylesheet" href="<?php echo $ADMIN_BASE_URL; ?>/css/admin.css">
</head>
<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="admin-wrapper">
