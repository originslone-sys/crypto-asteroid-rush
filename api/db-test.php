<?php
// ============================================
// UNOBIX - Teste de Conexão MySQL
// api/db-test.php
// REMOVA ESTE ARQUIVO APÓS TESTAR!
// ============================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Informações de debug
$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'environment_variables' => []
];

// Verificar variáveis de ambiente
$envVars = ['MYSQLHOST', 'MYSQLPORT', 'MYSQLDATABASE', 'MYSQLUSER', 'MYSQL_PUBLIC_URL'];
foreach ($envVars as $var) {
    $value = getenv($var);
    $debug['environment_variables'][$var] = $value ? (strlen($value) > 20 ? substr($value, 0, 20) . '...' : $value) : '(não definido)';
}

// Tentar carregar config
try {
    require_once __DIR__ . '/config.php';
    
    $debug['config_loaded'] = true;
    $debug['db_host'] = defined('DB_HOST') ? DB_HOST : '(não definido)';
    $debug['db_port'] = defined('DB_PORT') ? DB_PORT : '(não definido)';
    $debug['db_name'] = defined('DB_NAME') ? DB_NAME : '(não definido)';
    $debug['db_user'] = defined('DB_USER') ? DB_USER : '(não definido)';
    $debug['db_pass'] = defined('DB_PASS') ? (DB_PASS ? '****' : '(vazio)') : '(não definido)';
    
} catch (Throwable $e) {
    $debug['config_loaded'] = false;
    $debug['config_error'] = $e->getMessage();
}

// Tentar conectar ao banco
$debug['connection_test'] = [];

try {
    $startTime = microtime(true);
    
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    $connectTime = round((microtime(true) - $startTime) * 1000, 2);
    
    $debug['connection_test']['success'] = true;
    $debug['connection_test']['connect_time_ms'] = $connectTime;
    
    // Testar query
    $stmt = $pdo->query("SELECT VERSION() as version, NOW() as server_time");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $debug['connection_test']['mysql_version'] = $result['version'];
    $debug['connection_test']['server_time'] = $result['server_time'];
    
    // Verificar tabela users
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $debug['connection_test']['users_table_exists'] = $stmt->rowCount() > 0;
    
    if ($debug['connection_test']['users_table_exists']) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $debug['connection_test']['users_count'] = $stmt->fetch()['count'];
    }
    
    // Verificar outras tabelas
    $tables = ['game_sessions', 'game_events', 'transactions', 'withdrawals'];
    $debug['tables'] = [];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $debug['tables'][$table] = $stmt->rowCount() > 0 ? 'exists' : 'missing';
    }
    
} catch (PDOException $e) {
    $debug['connection_test']['success'] = false;
    $debug['connection_test']['error'] = $e->getMessage();
    $debug['connection_test']['error_code'] = $e->getCode();
}

// Resposta
echo json_encode([
    'status' => $debug['connection_test']['success'] ?? false ? 'OK' : 'ERROR',
    'message' => $debug['connection_test']['success'] ?? false 
        ? 'Conexão com banco de dados OK!' 
        : 'Falha na conexão: ' . ($debug['connection_test']['error'] ?? 'Desconhecido'),
    'debug' => $debug
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
