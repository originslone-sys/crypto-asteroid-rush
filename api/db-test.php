<?php
// ============================================
// UNOBIX - Teste de Conexão MySQL
// api/db-test.php - Cloud Run Edition
// BLOQUEADO EM PRODUÇÃO
// ============================================

// Endpoint de desenvolvimento - bloqueado em produção
http_response_code(403);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'Endpoint desabilitado']);
exit;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'environment' => []
];

// Detectar Cloud Run
$debug['environment']['is_cloud_run'] = !empty(getenv('K_SERVICE'));
$debug['environment']['k_service'] = getenv('K_SERVICE') ?: '(não definido)';
$debug['environment']['k_revision'] = getenv('K_REVISION') ?: '(não definido)';
$debug['environment']['cloudsql_dir_exists'] = file_exists('/cloudsql');

// Verificar socket do Cloud SQL
$socketPath = '/cloudsql/project-7be1cae5-5f08-45fb-aca:us-west1:unobix';
$debug['environment']['socket_path'] = $socketPath;
$debug['environment']['socket_exists'] = file_exists($socketPath);

// Listar sockets disponíveis
if (file_exists('/cloudsql')) {
    $debug['environment']['available_sockets'] = scandir('/cloudsql');
}

// Variáveis de ambiente MySQL
$envVars = ['MYSQLHOST', 'MYSQLPORT', 'MYSQLDATABASE', 'MYSQLUSER'];
foreach ($envVars as $var) {
    $value = getenv($var);
    $debug['environment'][$var] = $value ?: '(não definido)';
}
$debug['environment']['MYSQLPASSWORD'] = getenv('MYSQLPASSWORD') ? '****' : '(não definido)';

// Carregar config
try {
    require_once __DIR__ . '/config.php';
    
    $debug['config_loaded'] = true;
    $debug['db_socket'] = defined('DB_SOCKET') ? DB_SOCKET : '(não definido)';
    $debug['db_host'] = defined('DB_HOST') ? (DB_HOST ?: '(null - usando socket)') : '(não definido)';
    $debug['db_name'] = defined('DB_NAME') ? DB_NAME : '(não definido)';
    $debug['db_user'] = defined('DB_USER') ? DB_USER : '(não definido)';
    
} catch (Throwable $e) {
    $debug['config_loaded'] = false;
    $debug['config_error'] = $e->getMessage();
}

// Testar conexão
$debug['connection_test'] = [];

try {
    $startTime = microtime(true);
    
    // Usar a função do config
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("getDatabaseConnection() retornou null");
    }
    
    $connectTime = round((microtime(true) - $startTime) * 1000, 2);
    
    $debug['connection_test']['success'] = true;
    $debug['connection_test']['connect_time_ms'] = $connectTime;
    
    // Testar query
    $stmt = $pdo->query("SELECT VERSION() as version, NOW() as server_time");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $debug['connection_test']['mysql_version'] = $result['version'];
    $debug['connection_test']['server_time'] = $result['server_time'];
    
    // Verificar tabelas
    $tables = ['users', 'game_sessions', 'game_events', 'transactions', 'withdrawals'];
    $debug['tables'] = [];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        $debug['tables'][$table] = $exists ? '✅ exists' : '❌ missing';
    }
    
    // Contar usuários
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $debug['connection_test']['users_count'] = $stmt->fetch()['count'];
    
} catch (PDOException $e) {
    $debug['connection_test']['success'] = false;
    $debug['connection_test']['error'] = $e->getMessage();
    $debug['connection_test']['error_code'] = $e->getCode();
} catch (Exception $e) {
    $debug['connection_test']['success'] = false;
    $debug['connection_test']['error'] = $e->getMessage();
}

// Resposta
$success = $debug['connection_test']['success'] ?? false;

echo json_encode([
    'status' => $success ? '✅ OK' : '❌ ERROR',
    'message' => $success 
        ? 'Conexão com Cloud SQL OK!' 
        : 'Falha: ' . ($debug['connection_test']['error'] ?? 'Erro desconhecido'),
    'debug' => $debug
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
