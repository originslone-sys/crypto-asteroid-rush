<?php
// ============================================
// CRYPTO ASTEROID RUSH - Painel Administrativo
// Arquivo: admin/index.php
// ATUALIZADO: Adicionado pagina referrals
// ============================================

session_start();

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

// Configuracao de autenticacao
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123'); // ALTERE ESTA SENHA!

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
            header('Location: ' . $ADMIN_INDEX_URL);
            exit;
        } else {
            $error = 'Credenciais invalidas!';
        }
    }
    
    // Exibir formulario de login
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login | Crypto Asteroid Rush</title>
        <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700;800;900&family=Exo+2:wght@300;400;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="<?php echo $ADMIN_BASE_URL; ?>/css/admin.css">
    </head>
    <body>
        <div class="login-wrapper">
            <div class="login-container">
                <div class="login-logo">
                    <span class="icon">&#9732;&#65039;</span>
                    <span class="text">CRYPTO ASTEROID RUSH</span>
                </div>
                <h1 class="login-title">Painel Administrativo</h1>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Usuario</label>
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
                    <p>Crypto Asteroid Rush &copy; <?php echo date('Y'); ?></p>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// CONFIGURACOES GERAIS
// ============================================

// Incluir config (do projeto)
require_once __DIR__ . '/../config.php';

// Configuracao do modo debug
$debugMode = false; // ATIVE APENAS PARA TESTES

// Conectar ao banco
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set timezone
    $pdo->exec("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    die('<div style="background:#0a0819;color:#ff2a6d;padding:50px;text-align:center;font-family:Arial;">
        <h1>ERRO DE CONEXAO</h1>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        <p style="color:#888;">Host: ' . DB_HOST . ' | DB: ' . DB_NAME . '</p>
    </div>');
}

// Determinar pagina atual
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// ============================================
// LISTA DE PAGINAS PERMITIDAS - REFERRALS ADICIONADO
// ============================================
$allowedPages = [
    'dashboard', 
    'withdrawals', 
    'players', 
    'transactions', 
    'sessions', 
    'stakes', 
    'security', 
    'logs', 
    'settings',
    'referrals'  // <-- ADICIONADO
];

if (!in_array($page, $allowedPages)) {
    $page = 'dashboard';
}

$currentPage = $page;

// Habilitar exibicao de erros em modo debug
if ($debugMode) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Incluir header
include __DIR__ . '/includes/header.php';

// Incluir sidebar
include __DIR__ . '/includes/sidebar.php';

// Incluir pagina
$pageFile = __DIR__ . "/pages/{$page}.php";

if ($debugMode) {
    echo '<div style="background:orange;color:black;padding:10px;margin:10px;">Tentando incluir: ' . $pageFile . ' - ' . (file_exists($pageFile) ? 'EXISTE' : 'NAO EXISTE') . '</div>';
}

if (file_exists($pageFile)) {
    try {
        include $pageFile;
    } catch (Error $e) {
        echo '<div class="main-content"><div class="alert alert-danger">Erro PHP: ' . htmlspecialchars($e->getMessage()) . '<br>Arquivo: ' . $e->getFile() . ':' . $e->getLine() . '</div></div>';
    } catch (Exception $e) {
        echo '<div class="main-content"><div class="alert alert-danger">Excecao: ' . htmlspecialchars($e->getMessage()) . '</div></div>';
    }
} else {
    echo '<div class="main-content"><div class="alert alert-danger">Pagina nao encontrada: ' . htmlspecialchars($pageFile) . '</div></div>';
}

// Incluir footer
include __DIR__ . '/includes/footer.php';
?>
