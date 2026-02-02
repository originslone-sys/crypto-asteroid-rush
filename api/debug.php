<?php
// ============================================
// DEBUG ENDPOINT - Para verificar erros
// ============================================

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'status' => 'debug',
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => [
        'APP_ENV' => getenv('APP_ENV') ?: 'not set',
        'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'not defined',
        'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'not defined',
        'DB_USER' => defined('DB_USER') ? DB_USER : 'not defined',
        'DB_PORT' => defined('DB_PORT') ? DB_PORT : 'not defined',
        'GAME_SECRET_KEY' => defined('GAME_SECRET_KEY') ? substr(GAME_SECRET_KEY, 0, 10) . '...' : 'not defined',
        'ADMIN_PASSWORD' => defined('ADMIN_PASSWORD') ? substr(ADMIN_PASSWORD, 0, 3) . '...' : 'not defined',
    ],
    'server' => [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ],
    'database_test' => testDatabaseConnection(),
], JSON_PRETTY_PRINT);

function testDatabaseConnection() {
    try {
        $pdo = getDatabaseConnection();
        if (!$pdo) {
            return ['connected' => false, 'error' => 'getDatabaseConnection returned null'];
        }
        
        // Testar consulta simples
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar tabelas
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // Verificar tabela users
        $usersTableExists = in_array('users', $tables);
        $usersColumns = [];
        
        if ($usersTableExists) {
            $stmt = $pdo->query("DESCRIBE users");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $usersColumns[] = $row['Field'];
            }
        }
        
        return [
            'connected' => true,
            'test_query' => $result['test'] ?? 'failed',
            'tables' => $tables,
            'users_table_exists' => $usersTableExists,
            'users_columns' => $usersColumns,
            'total_tables' => count($tables),
        ];
        
    } catch (Exception $e) {
        return [
            'connected' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
        ];
    }
}