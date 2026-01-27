<?php
// ============================================
// CRYPTO ASTEROID RUSH - Painel Administrativo
// Arquivo: admin/index.php
// Ambiente: Railway (PHP 8 + MySQL 8)
// ============================================

session_start();
date_default_timezone_set('America/Sao_Paulo');

// ============================================
// Configuração de autenticação (preservada)
// ============================================
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123'); // ⚠️ ALTERE ESTA SENHA EM PRODUÇÃO!

// ============================================
// Logout
// ============================================
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ============================================
// LOGIN
// ============================================
if (!isset($_SESSION['admin'])) {
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($username === ADMIN_USER && $password === ADMIN_PASS) {
            $_SESSION['admin'] = true;
            $_SESSION['admin_name'] = $username;

            // ✅ Correção Railway:
            // Antes: header('Location: index.php');
            // Agora: caminho relativo correto para o painel
            header('Location: pages/dashboard.php');
            exit;
        } else {
            $error = 'Credenciais inválidas!';
        }
    }
    ?>

    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login | Crypto Asteroid Rush</title>
        <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700;800;900&family=Exo+2:wght@300;400;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="css/admin.css">
    </head>
    <body>
        <div class="login-wrapper">
            <div class="login-container">
                <div class="login-logo">
                    <span class="icon">🚀</span>
                    <span class="text">CRYPTO ASTEROID RUSH</span>
                </div>
                <h1 class="login-title">Painel Administrativo</h1>

                <?php if ($error): ?>
                    <p class="error-msg"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="username" class="form-label">Usuário</label>
                        <input type="text" name="username" id="username" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Senha</label>
                        <input type="password" name="password" id="password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>

                <footer class="login-footer">
                    <p>© <?php echo date('Y'); ?> Crypto Asteroid Rush</p>
                </footer>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// Sessão ativa → carregar painel principal
// ============================================
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin | Crypto Asteroid Rush</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700;800;900&family=Exo+2:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <?php include_once "includes/header.php"; ?>
    <?php include_once "includes/sidebar.php"; ?>

    <main class="admin-content">
        <section class="dashboard-section">
            <h1>Bem-vindo, <?php echo htmlspecialchars($_SESSION['admin_name']); ?> 👋</h1>
            <p>Selecione uma das opções no menu lateral para gerenciar o sistema.</p>
            <div class="dashboard-cards">
                <a href="pages/dashboard.php" class="card-link">
                    <div class="dashboard-card">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </div>
                </a>
                <a href="pages/withdrawals.php" class="card-link">
                    <div class="dashboard-card">
                        <i class="fas fa-wallet"></i>
                        <span>Saques</span>
                    </div>
                </a>
                <a href="pages/referrals.php" class="card-link">
                    <div class="dashboard-card">
                        <i class="fas fa-users"></i>
                        <span>Afiliados</span>
                    </div>
                </a>
                <a href="pages/staking.php" class="card-link">
                    <div class="dashboard-card">
                        <i class="fas fa-coins"></i>
                        <span>Staking</span>
                    </div>
                </a>
                <a href="pages/security.php" class="card-link">
                    <div class="dashboard-card">
                        <i class="fas fa-shield-alt"></i>
                        <span>Segurança</span>
                    </div>
                </a>
            </div>
        </section>
    </main>

    <?php include_once "includes/footer.php"; ?>
</body>
</html>
