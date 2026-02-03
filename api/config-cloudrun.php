<?php
// ============================================
// UNOBIX - Configuração Principal para Cloud Run
// Arquivo: api/config-cloudrun.php
// v2.1 - Cloud Run + Cloud SQL + Funcionalidades completas
// ============================================

// ============================================
// AJUSTES TÉCNICOS
// ============================================
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// Log inicial
error_log('🚀 config-cloudrun.php carregado - Ambiente Cloud Run');

// ============================================
// CONFIGURAÇÕES DE BANCO DE DADOS (Cloud Run + Cloud SQL)
// ============================================
// Prioridade: Socket Unix Cloud SQL > TCP/IP Cloud SQL Proxy
$cloudsqlInstance = getenv('CLOUDSQL_INSTANCE') ?: 'project-7be1cae5-5f08-45fb-aca:us-west1:unobix';

if ($cloudsqlInstance && file_exists('/cloudsql/' . $cloudsqlInstance)) {
    // Usar socket Unix do Cloud SQL (recomendado)
    $dbHost = "/cloudsql/" . $cloudsqlInstance;
    $dbPort = null; // Socket não usa porta
    error_log('🔧 Usando socket Cloud SQL: ' . $dbHost);
} else {
    // Usar TCP/IP (Cloud SQL Proxy ou variáveis de ambiente)
    $dbHost = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
    $dbPort = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';
    error_log('🔧 Usando TCP/IP: ' . $dbHost . ':' . $dbPort);
}

// Credenciais com fallback para desenvolvimento
$dbName = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'unobix_db';
$dbUser = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'unobix_user';
$dbPass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: 'YyZD3H)dndSo*A/N';

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);

// ============================================
// CONFIGURAÇÕES DE SEGURANÇA
// ============================================
$gameSecretKey = getenv('GAME_SECRET_KEY') ?: 'unobix_production_secret_key_2024_change_me';
$adminPassword = getenv('ADMIN_PASSWORD') ?: 'Admin@Unobix2024!';

// Log para debug (não em produção)
if (getenv('APP_ENV') !== 'production') {
    error_log('🔐 Configurações de segurança carregadas');
}

define('GAME_SECRET_KEY', $gameSecretKey);
define('ADMIN_PASSWORD', $adminPassword);

// ============================================
// FIREBASE / GOOGLE AUTH
// ============================================
$firebaseProjectId = getenv('FIREBASE_PROJECT_ID');
$firebaseApiKey = getenv('FIREBASE_API_KEY');

// Usar valores reais do Firebase (mesmos do frontend) se variáveis não estiverem definidas
if (!$firebaseProjectId || !$firebaseApiKey) {
    $firebaseProjectId = 'unobix-oauth-a69cd';
    $firebaseApiKey = 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U';
    error_log('⚠️ Firebase config usando valores hardcoded (Cloud Run sem variáveis)');
} else {
    error_log('✅ Firebase config carregada das variáveis de ambiente');
}

define('FIREBASE_PROJECT_ID', $firebaseProjectId);
define('FIREBASE_API_KEY', $firebaseApiKey);

// ============================================
// CONFIGURAÇÕES DO JOGO
// ============================================
define('SITE_URL', getenv('SITE_URL') ?: 'https://crypto-asteroid-234282032979.us-west1.run.app');
define('GAME_NAME', 'UNOBIX');
define('CURRENCY_SYMBOL', 'R$');
define('DEFAULT_LANGUAGE', 'pt_BR');

// ============================================
// FUNÇÕES PRINCIPAIS
// ============================================

/**
 * Obtém conexão com o banco de dados (Cloud Run compatível)
 */
function getDatabaseConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            // Construir DSN baseado no tipo de conexão
            if (DB_PORT === null) {
                // Socket Unix (Cloud SQL)
                $dsn = "mysql:unix_socket=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            } else {
                // TCP/IP
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            }
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
            
            error_log('✅ Conexão com banco estabelecida: ' . DB_NAME);
            
        } catch (PDOException $e) {
            error_log('❌ Erro de conexão DB: ' . $e->getMessage());
            error_log('❌ DSN: ' . ($dsn ?? 'não definida'));
            error_log('❌ Usuário: ' . DB_USER);
            
            // Tentar fallback para desenvolvimento local
            if (getenv('APP_ENV') === 'development') {
                try {
                    $pdo = new PDO("mysql:host=localhost;dbname=unobix", "root", "", [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    error_log('⚠️ Usando fallback local (desenvolvimento)');
                } catch (Exception $e2) {
                    error_log('❌ Fallback também falhou: ' . $e2->getMessage());
                }
            }
            
            return null;
        }
    }

    return $pdo;
}

/**
 * Valida Google UID
 */
function validateGoogleUid($uid) {
    if (empty($uid)) return false;
    // Firebase/Google UIDs: 10-128 caracteres, letras, números, _, -
    return preg_match('/^[a-zA-Z0-9_-]{10,128}$/', $uid);
}

/**
 * Valida wallet Ethereum (para compatibilidade)
 */
function validateWallet($wallet) {
    return preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet);
}

/**
 * Obtém IP do cliente
 */
function getClientIP() {
    $headers = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR', 
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(current(explode(',', $_SERVER[$header])));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}

/**
 * Configura headers CORS
 */
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // 24 horas
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Log seguro (para produção)
 */
function secureLog($message, $level = 'INFO') {
    $logEntry = date('Y-m-d H:i:s') . " [$level] " . $message;
    
    // Em produção, log para stderr (capturado pelo Cloud Run)
    if (getenv('APP_ENV') === 'production') {
        error_log($logEntry);
    } else {
        // Em desenvolvimento, log para arquivo
        $logFile = __DIR__ . '/../logs/app-' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);
    }
}

/**
 * Gera token seguro
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Sanitiza input
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Valida email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Formata valor monetário
 */
function formatCurrency($value, $currency = 'BRL') {
    if ($currency === 'BRL') {
        return 'R$ ' . number_format($value, 2, ',', '.');
    } else {
        return '$ ' . number_format($value, 2, '.', ',');
    }
}

// ============================================
// INICIALIZAÇÃO
// ============================================
// Verificar se estamos em ambiente de produção
if (getenv('APP_ENV') === 'production') {
    // Em produção, garantir que conexão funciona
    $testConnection = getDatabaseConnection();
    if (!$testConnection) {
        error_log('❌ CRÍTICO: Não foi possível conectar ao banco em produção');
        // Não morrer imediatamente, tentar reconexão mais tarde
    }
}

error_log('✅ config-cloudrun.php inicializado com sucesso');