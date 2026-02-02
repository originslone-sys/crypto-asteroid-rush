<?php
// ============================================
// DEBUG SIMPLIFICADO - USA CONFIG SIMPLE
// ============================================

require_once 'config-simple.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$result = [
    'status' => 'debug-simple',
    'timestamp' => date('Y-m-d H:i:s'),
    'config_loaded' => '✅ config-simple.php',
    'environment' => [
        'MYSQLHOST' => getenv('MYSQLHOST') ?: 'not set',
        'MYSQLPORT' => getenv('MYSQLPORT') ?: 'not set',
        'MYSQLDATABASE' => getenv('MYSQLDATABASE') ?: 'not set',
        'MYSQLUSER' => getenv('MYSQLUSER') ?: 'not set',
        'MYSQLPASSWORD' => getenv('MYSQLPASSWORD') ? '***' . substr(getenv('MYSQLPASSWORD'), -3) : 'not set',
    ],
    'defined_constants' => [
        'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'not defined',
        'DB_PORT' => defined('DB_PORT') ? DB_PORT : 'not defined',
        'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'not defined',
        'DB_USER' => defined('DB_USER') ? DB_USER : 'not defined',
        'DB_PASS' => defined('DB_PASS') ? '***' . substr(DB_PASS, -3) : 'not defined',
        'GAME_SECRET_KEY' => defined('GAME_SECRET_KEY') ? '***' . substr(GAME_SECRET_KEY, -5) : 'not defined',
        'ADMIN_PASSWORD' => defined('ADMIN_PASSWORD') ? '***' . substr(ADMIN_PASSWORD, -3) : 'not defined',
    ],
    'database_test' => testDatabaseConnection(),
];

echo json_encode($result, JSON_PRETTY_PRINT);

function testDatabaseConnection() {
    try {
        $pdo = getDatabaseConnection();
        if (!$pdo) {
            return ['connected' => false, 'error' => 'getDatabaseConnection returned null'];
        }
        
        // Testar consulta simples
        $stmt = $pdo->query("SELECT 1 as test, NOW() as time");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar tabelas
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        return [
            'connected' => true,
            'test_query' => $result,
            'tables' => $tables,
            'total_tables' => count($tables),
            'users_table_exists' => in_array('users', $tables),
        ];
        
    } catch (Exception $e) {
        return [
            'connected' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'dsn' => "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME,
        ];
    }
}