<?php
// ============================================
// phpMyAdmin Configuration for Cloud SQL
// PROTEGIDO por senha do admin
// ============================================

// Bloquear acesso direto sem autenticação
session_start();

// Senha do admin (mesma do painel admin)
$admin_password = getenv('ADMIN_PASSWORD') ?: 'Admin@Unobix2024!';

// Verificar autenticação
if (!isset($_SESSION['phpmyadmin_auth']) || $_SESSION['phpmyadmin_auth'] !== true) {
    if (isset($_POST['password']) && $_POST['password'] === $admin_password) {
        $_SESSION['phpmyadmin_auth'] = true;
    } else {
        // Mostrar formulário de login
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>phpMyAdmin - Login</title>
            <style>
                body { font-family: Arial; background: #1a1a1a; color: white; padding: 50px; }
                .login-box { max-width: 400px; margin: 0 auto; background: #2a2a2a; padding: 30px; border-radius: 10px; }
                input { width: 100%; padding: 10px; margin: 10px 0; border-radius: 5px; border: 1px solid #444; }
                button { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2>🔐 phpMyAdmin - Cloud SQL</h2>
                <p>Acesso restrito ao administrador</p>
                <form method="POST">
                    <input type="password" name="password" placeholder="Senha de administração" required>
                    <button type="submit">Entrar</button>
                </form>
            </div>
        </body>
        </html>';
        exit;
    }
}

// Configuração do phpMyAdmin
$cfg['Servers'][1]['host'] = getenv('MYSQLHOST') ?: '127.0.0.1';
$cfg['Servers'][1]['port'] = getenv('MYSQLPORT') ?: '3306';
$cfg['Servers'][1]['user'] = getenv('MYSQLUSER') ?: 'unobix_user';
$cfg['Servers'][1]['password'] = getenv('MYSQLPASSWORD') ?: '';
$cfg['Servers'][1]['auth_type'] = 'config';
$cfg['Servers'][1]['AllowNoPassword'] = false;
$cfg['Servers'][1]['compress'] = false;
$cfg['Servers'][1]['AllowRoot'] = false;

// Configurações gerais
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
$cfg['MaxRows'] = 100;
$cfg['ExecTimeLimit'] = 300;
$cfg['MemoryLimit'] = '256M';

// Idioma
$cfg['DefaultLang'] = 'pt_BR';
$cfg['ServerDefault'] = 1;

// Segurança
$cfg['blowfish_secret'] = md5(getenv('GAME_SECRET_KEY') ?: 'unobix_secret');
?>
