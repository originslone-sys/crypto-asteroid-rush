<?php
/**
 * Debug endpoint for current container (341cc66)
 * Helps diagnose why auth-google.php returns 500
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$debug = [];

// ============================================
// 1. Basic PHP Info
// ============================================
$debug['php_info'] = [
    'version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'display_errors' => ini_get('display_errors'),
    'error_reporting' => error_reporting(),
    'cwd' => getcwd(),
    'script_path' => __FILE__,
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A'
];

// ============================================
// 2. File Structure Analysis
// ============================================
$debug['file_structure'] = [];

// Check current directory
$debug['file_structure']['current_dir'] = getcwd();
$debug['file_structure']['listing'] = @scandir('.') ?: [];

// Check if api/ exists
$debug['file_structure']['api_dir_exists'] = is_dir('api');
if (is_dir('api')) {
    $debug['file_structure']['api_listing'] = @scandir('api') ?: [];
}

// Check critical files
$criticalFiles = [
    'api/config.php',
    'api/config-cloudrun.php', 
    'api/auth-google.php',
    'config.php',
    'config-cloudrun.php',
    'auth-google.php'
];

foreach ($criticalFiles as $file) {
    $debug['file_structure']['files'][$file] = [
        'exists' => file_exists($file),
        'is_readable' => file_exists($file) ? is_readable($file) : false,
        'size' => file_exists($file) ? filesize($file) : 0,
        'realpath' => file_exists($file) ? realpath($file) : null
    ];
}

// ============================================
// 3. Environment Variables
// ============================================
$requiredEnvVars = [
    'CLOUDSQL_INSTANCE',
    'MYSQLHOST',
    'MYSQLDATABASE', 
    'MYSQLUSER',
    'MYSQLPASSWORD',
    'FIREBASE_PROJECT_ID',
    'FIREBASE_API_KEY'
];

foreach ($requiredEnvVars as $var) {
    $value = getenv($var);
    $debug['environment'][$var] = [
        'set' => $value !== false,
        'value' => $value !== false ? (strpos($var, 'PASSWORD') !== false ? '***' . substr($value, -3) : substr($value, 0, 20)) : null
    ];
}

// ============================================
// 4. Test config.php inclusion
// ============================================
$debug['config_test'] = [];

// Try to include config.php
try {
    if (file_exists('api/config.php')) {
        // Capture any output/errors
        ob_start();
        require_once 'api/config.php';
        $output = ob_get_clean();
        
        $debug['config_test']['api_config'] = [
            'success' => true,
            'output' => $output ?: 'none',
            'constants_defined' => [
                'DB_HOST' => defined('DB_HOST'),
                'DB_NAME' => defined('DB_NAME'),
                'FIREBASE_API_KEY' => defined('FIREBASE_API_KEY')
            ]
        ];
    } else {
        $debug['config_test']['api_config'] = ['success' => false, 'error' => 'File not found'];
    }
} catch (Exception $e) {
    $debug['config_test']['api_config'] = ['success' => false, 'error' => $e->getMessage()];
}

// ============================================
// 5. Test database connection
// ============================================
$debug['database_test'] = [];

if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS')) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . (defined('DB_PORT') ? DB_PORT : '3306') . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        $debug['database_test'] = [
            'success' => true,
            'host' => DB_HOST,
            'tables' => $pdo->query("SHOW TABLES")->rowCount()
        ];
    } catch (PDOException $e) {
        $debug['database_test'] = [
            'success' => false,
            'error' => $e->getMessage(),
            'dsn' => $dsn ?? 'not attempted'
        ];
    }
} else {
    $debug['database_test'] = ['success' => false, 'error' => 'Database constants not defined'];
}

// ============================================
// 6. Simulate auth-google.php error
// ============================================
$debug['auth_simulation'] = [];

// Read first few lines of auth-google.php to see includes
if (file_exists('api/auth-google.php')) {
    $lines = file('api/auth-google.php', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $firstLines = array_slice($lines, 0, 20);
    
    $debug['auth_simulation']['file_exists'] = true;
    $debug['auth_simulation']['first_lines'] = $firstLines;
    
    // Check for config-cloudrun.php include
    foreach ($firstLines as $line) {
        if (strpos($line, 'config-cloudrun.php') !== false) {
            $debug['auth_simulation']['includes_config'] = trim($line);
            break;
        }
    }
} else {
    $debug['auth_simulation']['file_exists'] = false;
}

// ============================================
// 7. Recommendations
// ============================================
$debug['recommendations'] = [];

if (!$debug['file_structure']['api_dir_exists']) {
    $debug['recommendations'][] = '❌ api/ directory not found - check Dockerfile COPY command';
}

if (!$debug['file_structure']['files']['api/config.php']['exists']) {
    $debug['recommendations'][] = '❌ api/config.php not found - files not copied to container';
}

if (!$debug['config_test']['api_config']['success']) {
    $debug['recommendations'][] = '❌ config.php cannot be included - check PHP errors';
}

if (!$debug['database_test']['success']) {
    $debug['recommendations'][] = '❌ Database connection failed: ' . ($debug['database_test']['error'] ?? 'unknown');
}

// Output
echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
