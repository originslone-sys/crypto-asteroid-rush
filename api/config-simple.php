<?php
// ============================================
// CONFIG SIMPLIFICADA - PARA TESTE RÁPIDO
// ============================================

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// ============================================
// CONFIGURAÇÕES DE BANCO DE DADOS (Cloud Run + Cloud SQL)
// ============================================
// Tentar socket Unix do Cloud SQL primeiro
$cloudsqlInstance = getenv('CLOUDSQL_INSTANCE') ?: 'project-7be1cae5-5f08-45fb-aca:us-central1:unobix';

if ($cloudsqlInstance && file_exists('/cloudsql/' . $cloudsqlInstance)) {
    // Usar socket Unix do Cloud SQL
    $dbHost = "/cloudsql/" . $cloudsqlInstance;
    $dbPort = null; // Socket não usa porta
    error_log('🔧 Usando socket Cloud SQL: ' . $dbHost);
} else {
    // Usar TCP/IP (Cloud SQL Proxy)
    $dbHost = getenv('MYSQLHOST') ?: '127.0.0.1';
    $dbPort = getenv('MYSQLPORT') ?: '3306';
    error_log('🔧 Usando TCP/IP: ' . $dbHost . ':' . $dbPort);
}

$dbName = getenv('MYSQLDATABASE') ?: 'unobix_db';
$dbUser = getenv('MYSQLUSER') ?: 'unobix_user';
$dbPass = getenv('MYSQLPASSWORD') ?: 'YyZD3H)dndSo*A/N';

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);

// ============================================
// CONFIGURAÇÕES DE SEGURANÇA (VALORES PADRÃO)
// ============================================
define('GAME_SECRET_KEY', 'unobix_production_secret_key_2024_change_me');
define('ADMIN_PASSWORD', 'Admin@Unobix2024!');

// ============================================
// FUNÇÃO DE CONEXÃO COM BANCO
// ============================================
function getDatabaseConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            // Construir DSN baseado no tipo de conexão
            if (DB_PORT === null) {
                // Socket Unix (Cloud SQL)
                $dsn = "mysql:unix_socket=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                error_log('🔧 Tentando conexão via socket Unix: ' . DB_HOST);
            } else {
                // TCP/IP
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                error_log('🔧 Tentando conexão TCP/IP: ' . DB_HOST . ':' . DB_PORT);
            }
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            error_log('✅ Conexão com banco estabelecida: ' . DB_NAME);
        } catch (PDOException $e) {
            error_log('❌ Erro de conexão DB: ' . $e->getMessage());
            error_log('❌ DSN tentada: ' . $dsn);
            error_log('❌ Usuário: ' . DB_USER);
            return null;
        }
    }

    return $pdo;
}

// ============================================
// FUNÇÕES ÚTEIS
// ============================================
function validateGoogleUid($uid) {
    if (empty($uid)) return false;
    return preg_match('/^[a-zA-Z0-9_-]{10,128}$/', $uid);
}

function getClientIP() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

echo "✅ config-simple.php carregado com sucesso!\n";