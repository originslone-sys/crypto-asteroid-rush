<?php
// ============================================
// DEBUG PARA CONFIG-CLOUDRUN.PHP
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$result = [
    'status' => 'debug-cloudrun',
    'timestamp' => date('Y-m-d H:i:s'),
    'config_loaded' => '✅ config-cloudrun.php',
    'environment' => [
        'APP_ENV' => getenv('APP_ENV') ?: 'not set',
        'CLOUDSQL_INSTANCE' => getenv('CLOUDSQL_INSTANCE') ?: 'not set',
        'MYSQLHOST' => getenv('MYSQLHOST') ?: 'not set',
        'MYSQLPORT' => getenv('MYSQLPORT') ?: 'not set',
        'MYSQLDATABASE' => getenv('MYSQLDATABASE') ?: 'not set',
        'MYSQLUSER' => getenv('MYSQLUSER') ?: 'not set',
        'MYSQLPASSWORD' => getenv('MYSQLPASSWORD') ? '***' . substr(getenv('MYSQLPASSWORD'), -3) : 'not set',
        'GAME_SECRET_KEY' => getenv('GAME_SECRET_KEY') ? '***' . substr(getenv('GAME_SECRET_KEY'), -5) : 'not set',
        'ADMIN_PASSWORD' => getenv('ADMIN_PASSWORD') ? '***' . substr(getenv('ADMIN_PASSWORD'), -3) : 'not set',
        'FIREBASE_PROJECT_ID' => getenv('FIREBASE_PROJECT_ID') ?: 'not set',
        'FIREBASE_API_KEY' => getenv('FIREBASE_API_KEY') ? '***' . substr(getenv('FIREBASE_API_KEY'), -5) : 'not set',
    ],
    'defined_constants' => [
        'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'not defined',
        'DB_PORT' => defined('DB_PORT') ? DB_PORT : 'not defined',
        'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'not defined',
        'DB_USER' => defined('DB_USER') ? DB_USER : 'not defined',
        'DB_PASS' => defined('DB_PASS') ? '***' . substr(DB_PASS, -3) : 'not defined',
        'GAME_SECRET_KEY' => defined('GAME_SECRET_KEY') ? '***' . substr(GAME_SECRET_KEY, -5) : 'not defined',
        'ADMIN_PASSWORD' => defined('ADMIN_PASSWORD') ? '***' . substr(ADMIN_PASSWORD, -3) : 'not defined',
        'FIREBASE_PROJECT_ID' => defined('FIREBASE_PROJECT_ID') ? FIREBASE_PROJECT_ID : 'not defined',
        'FIREBASE_API_KEY' => defined('FIREBASE_API_KEY') ? '***' . substr(FIREBASE_API_KEY, -5) : 'not defined',
        'SITE_URL' => defined('SITE_URL') ? SITE_URL : 'not defined',
        'GAME_NAME' => defined('GAME_NAME') ? GAME_NAME : 'not defined',
    ],
    'functions_available' => [
        'getDatabaseConnection' => function_exists('getDatabaseConnection'),
        'validateGoogleUid' => function_exists('validateGoogleUid'),
        'validateWallet' => function_exists('validateWallet'),
        'getClientIP' => function_exists('getClientIP'),
        'setCorsHeaders' => function_exists('setCorsHeaders'),
        'secureLog' => function_exists('secureLog'),
        'generateSecureToken' => function_exists('generateSecureToken'),
        'sanitizeInput' => function_exists('sanitizeInput'),
        'isValidEmail' => function_exists('isValidEmail'),
        'formatCurrency' => function_exists('formatCurrency'),
    ],
    'database_test' => testDatabaseConnection(),
    'function_tests' => runFunctionTests(),
];

echo json_encode($result, JSON_PRETTY_PRINT);

function testDatabaseConnection() {
    try {
        $pdo = getDatabaseConnection();
        if (!$pdo) {
            return ['connected' => false, 'error' => 'getDatabaseConnection returned null'];
        }
        
        // Testar consulta
        $stmt = $pdo->query("SELECT 1 as test, NOW() as time, DATABASE() as db, USER() as user");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar tabelas
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // Verificar estrutura da tabela users
        $usersColumns = [];
        if (in_array('users', $tables)) {
            $stmt = $pdo->query("DESCRIBE users");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $usersColumns[] = $row['Field'] . ' (' . $row['Type'] . ')';
            }
        }
        
        return [
            'connected' => true,
            'test_query' => $result,
            'tables' => $tables,
            'total_tables' => count($tables),
            'users_table_exists' => in_array('users', $tables),
            'users_columns_count' => count($usersColumns),
            'users_sample_columns' => array_slice($usersColumns, 0, 5),
        ];
        
    } catch (Exception $e) {
        return [
            'connected' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->getCode(),
        ];
    }
}

function runFunctionTests() {
    $tests = [];
    
    // Testar validateGoogleUid
    $tests['validateGoogleUid_valid'] = validateGoogleUid('test123456');
    $tests['validateGoogleUid_invalid'] = validateGoogleUid('');
    
    // Testar getClientIP
    $tests['getClientIP'] = getClientIP();
    
    // Testar generateSecureToken
    $tests['generateSecureToken'] = strlen(generateSecureToken(16)) === 32; // 16 bytes = 32 hex chars
    
    // Testar sanitizeInput
    $tests['sanitizeInput'] = sanitizeInput('<script>alert("xss")</script>') === '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;';
    
    // Testar isValidEmail
    $tests['isValidEmail_valid'] = isValidEmail('test@example.com');
    $tests['isValidEmail_invalid'] = isValidEmail('not-an-email');
    
    // Testar formatCurrency
    $tests['formatCurrency_brl'] = formatCurrency(1234.56, 'BRL');
    $tests['formatCurrency_usd'] = formatCurrency(1234.56, 'USD');
    
    return $tests;
}