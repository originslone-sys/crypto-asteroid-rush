<?php
// ============================================
// CRYPTO ASTEROID RUSH - Painel Administrativo
// Arquivo: admin/index.php
// ATUALIZADO: Adicionado pagina referrals
// ============================================

session_start();

// Configuracao de autenticacao
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123'); // ALTERE ESTA SENHA!

// Logout
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
            header('Location: index.php');
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
        <link rel="stylesheet" href="css/admin.css">
    </head>
    <body>
        <div class="login-wrapper">
            <div class="login-container">
                <div class="login-logo">
                    <span class="icon">&#9732;&#65039;</span>
                    <span class="text">CRYPTO ASTEROID RUSH</span>
                </div>
                <h1 class="login-title">PAINEL ADMINISTRATIVO</h1>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
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
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-sign-in-alt"></i> ACESSAR PAINEL
                    </button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// USUARIO LOGADO - CARREGAR PAINEL
// ============================================

// Debug mode - desative em producao
$debugMode = isset($_GET['debug']);

// Conexao com banco de dados
$configPaths = [
    __DIR__ . '/../api/config.php',
    __DIR__ . '/../../api/config.php',
    __DIR__ . '/../config.php',
    dirname(__DIR__) . '/api/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/api/config.php'
];

$configLoaded = false;
$configPath = '';

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $configLoaded = true;
        $configPath = $path;
        break;
    }
}

if ($debugMode) {
    echo '<div style="background:#1a1a2e;color:#fff;padding:20px;margin:20px;border-radius:10px;font-family:monospace;">';
    echo '<h3 style="color:#00f0ff;">Debug Info</h3>';
    echo '<p><strong>__DIR__:</strong> ' . __DIR__ . '</p>';
    echo '<p><strong>Config loaded:</strong> ' . ($configLoaded ? 'YES - ' . $configPath : 'NO') . '</p>';
    echo '<p><strong>Paths tried:</strong></p><ul>';
    foreach ($configPaths as $p) {
        echo '<li>' . $p . ' - ' . (file_exists($p) ? 'EXISTS' : 'NOT FOUND') . '</li>';
    }
    echo '</ul>';
    if ($configLoaded) {
        echo '<p><strong>DB_HOST:</strong> ' . (defined('DB_HOST') ? DB_HOST : 'NOT DEFINED') . '</p>';
        echo '<p><strong>DB_NAME:</strong> ' . (defined('DB_NAME') ? DB_NAME : 'NOT DEFINED') . '</p>';
    }
    echo '</div>';
}

if (!$configLoaded) {
    $pathList = '';
    foreach ($configPaths as $p) {
        $pathList .= "<li>$p</li>";
    }
    die('<div style="background:#0a0819;color:#ff2a6d;padding:50px;text-align:center;font-family:Arial;">
        <h1>ERRO</h1>
        <p>Arquivo config.php nao encontrado!</p>
        <p style="color:#888;font-size:14px;">Caminhos tentados:</p>
        <ul style="color:#888;font-size:12px;text-align:left;max-width:500px;margin:auto;">
        ' . $pathList . '
        </ul>
        <p style="margin-top:20px;"><a href="?debug=1" style="color:#00f0ff;">Clique aqui para debug</a></p>
    </div>');
}

// Conexao PDO
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    if ($debugMode) {
        echo '<div style="background:#1a1a2e;color:#0f0;padding:10px;margin:20px;border-radius:10px;font-family:monospace;">';
        echo 'Conexao com banco de dados OK!<br>';
        
        // Testar query
        try {
            $testPlayers = $pdo->query("SELECT COUNT(*) as total FROM players")->fetch();
            echo 'Tabela players: ' . ($testPlayers['total'] ?? 0) . ' registros<br>';
        } catch (Exception $e) {
            echo 'Erro tabela players: ' . $e->getMessage() . '<br>';
        }
        
        try {
            $testSessions = $pdo->query("SELECT COUNT(*) as total FROM game_sessions")->fetch();
            echo 'Tabela game_sessions: ' . ($testSessions['total'] ?? 0) . ' registros<br>';
        } catch (Exception $e) {
            echo 'Erro tabela game_sessions: ' . $e->getMessage() . '<br>';
        }
        
        try {
            $testWithdrawals = $pdo->query("SELECT COUNT(*) as total FROM withdrawals")->fetch();
            echo 'Tabela withdrawals: ' . ($testWithdrawals['total'] ?? 0) . ' registros<br>';
        } catch (Exception $e) {
            echo 'Erro tabela withdrawals: ' . $e->getMessage() . '<br>';
        }
        
        // Testar tabela referrals
        try {
            $testReferrals = $pdo->query("SELECT COUNT(*) as total FROM referrals")->fetch();
            echo 'Tabela referrals: ' . ($testReferrals['total'] ?? 0) . ' registros<br>';
        } catch (Exception $e) {
            echo 'Erro tabela referrals: ' . $e->getMessage() . '<br>';
        }
        
        echo '</div>';
    }
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