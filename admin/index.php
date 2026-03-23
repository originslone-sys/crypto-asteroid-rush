<?php
// ============================================
// UNOBIX - Painel Administrativo
// Arquivo: admin/index.php
// v6.0 - Google Auth, BRL, Ads Manager
// ============================================

session_start();

// Base URL do admin
$__scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$__scriptDir  = rtrim(str_replace('\\', '/', dirname($__scriptName)), '/');
if ($__scriptDir === '/') $__scriptDir = '';
if (preg_match('~/(pages|includes)$~', $__scriptDir)) {
    $__scriptDir = rtrim(str_replace('\\', '/', dirname($__scriptDir)), '/');
    if ($__scriptDir === '/') $__scriptDir = '';
}
$ADMIN_BASE_URL  = $__scriptDir;
$ADMIN_INDEX_URL = $ADMIN_BASE_URL . '/index.php';

// ============================================
// CREDENCIAIS DO ADMIN — altere aqui diretamente
// ============================================
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123');

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $ADMIN_INDEX_URL);
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
            $_SESSION['admin_logged_in'] = true;
            header('Location: ' . $ADMIN_INDEX_URL);
            exit;
        } else {
            $error = 'Credenciais inválidas!';
        }
    }
    
    // Exibir formulário de login
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login | UNOBIX</title>
        <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700;800;900&family=Exo+2:wght@300;400;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="<?php echo $ADMIN_BASE_URL; ?>/css/admin.css">
    </head>
    <body>
        <div class="login-wrapper">
            <div class="login-container">
                <div class="login-logo">
                    <img src="../img/logo-unobix.png" alt="Unobix" style="width: 60px; height: 60px;">
                    <span class="text">UNOBIX</span>
                </div>
                <h1 class="login-title">Painel Administrativo</h1>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Usuário</label>
                        <input type="text" name="username" class="form-control" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Senha</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>
                
                <div class="login-footer">
                    <p>UNOBIX &copy; <?php echo date('Y'); ?></p>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// CONFIGURAÇÕES GERAIS
// ============================================

require_once __DIR__ . '/../api/config.php';

// ============================================
// SEMPRE exibir erros no admin (facilita debug)
// ============================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Conectar ao banco (usa getDatabaseConnection() do config.php — Regra de Ouro)
try {
    $pdo = getDatabaseConnection();
    $pdo->exec("SET time_zone = '-03:00'");
} catch (Exception $e) {
    die('<div style="background:#0a0a0f;color:#ff4757;padding:50px;text-align:center;font-family:Arial;">
        <h1>ERRO DE CONEXÃO</h1>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
    </div>');
}

// Determinar página atual
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// ============================================
// LISTA DE PÁGINAS PERMITIDAS
// ============================================
$allowedPages = [
    'dashboard',
    'withdrawals',
    'players',
    'transactions',
    'sessions',
    'stakes',
    'credits',
    'security',
    'logs',
    'settings',
    'referrals',
    'ads',
    'whatsapp'
];

if (!in_array($page, $allowedPages)) {
    $page = 'dashboard';
}

$currentPage = $page;

// Incluir header
include __DIR__ . '/includes/header.php';

// Incluir sidebar
include __DIR__ . '/includes/sidebar.php';

// Incluir página com tratamento de erros robusto
$pageFile = __DIR__ . "/pages/{$page}.php";

if (file_exists($pageFile)) {
    try {
        include $pageFile;
    } catch (Error $e) {
        echo '<div class="main-content"><div class="alert alert-danger" style="padding:20px;margin:20px;">
            <h3>⚠️ Erro PHP na página: ' . htmlspecialchars($page) . '</h3>
            <p><strong>Erro:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
            <p><strong>Arquivo:</strong> ' . htmlspecialchars($e->getFile()) . ' (linha ' . $e->getLine() . ')</p>
        </div></div>';
    } catch (Exception $e) {
        echo '<div class="main-content"><div class="alert alert-danger" style="padding:20px;margin:20px;">
            <h3>⚠️ Exceção na página: ' . htmlspecialchars($page) . '</h3>
            <p><strong>Erro:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
            <p><strong>Arquivo:</strong> ' . htmlspecialchars($e->getFile()) . ' (linha ' . $e->getLine() . ')</p>
        </div></div>';
    }
} else {
    echo '<div class="main-content"><div class="alert alert-danger" style="padding:20px;margin:20px;">
        <h3>❌ Página não encontrada</h3>
        <p>Arquivo: <code>' . htmlspecialchars($pageFile) . '</code></p>
        <p>Verifique se o arquivo <code>pages/' . htmlspecialchars($page) . '.php</code> existe na pasta admin.</p>
    </div></div>';
}

// Incluir footer
include __DIR__ . '/includes/footer.php';
?>

