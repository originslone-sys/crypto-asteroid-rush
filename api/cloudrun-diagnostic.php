<?php
/**
 * Cloud Run Diagnostic Endpoint
 * Tests all critical configurations for Cloud Run deployment
 * Based on 2025 best practices for Cloud Run + PHP + Cloud SQL + Firebase
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$diagnostic = [
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => 'cloud-run-diagnostic',
    'tests' => []
];

// ============================================
// TEST 1: PHP Environment
// ============================================
$diagnostic['tests']['php_environment'] = [
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'display_errors' => ini_get('display_errors'),
    'error_reporting' => error_reporting(),
    'status' => 'PASS'
];

// ============================================
// TEST 2: Environment Variables (Critical)
// ============================================
$requiredEnvVars = [
    'CLOUDSQL_INSTANCE',
    'MYSQLDATABASE',
    'MYSQLUSER',
    'MYSQLPASSWORD',
    'FIREBASE_PROJECT_ID',
    'FIREBASE_API_KEY',
    'GAME_SECRET_KEY',
    'ADMIN_PASSWORD'
];

$envTest = ['status' => 'PASS', 'missing' => [], 'present' => []];
foreach ($requiredEnvVars as $var) {
    $value = getenv($var);
    if ($value === false || $value === '') {
        $envTest['missing'][] = $var;
        $envTest['status'] = 'FAIL';
    } else {
        $envTest['present'][] = $var;
        // Mask sensitive values
        $envTest[$var] = $var === 'MYSQLPASSWORD' ? '***' . substr($value, -3) : substr($value, 0, 10) . '...';
    }
}
$diagnostic['tests']['environment_variables'] = $envTest;

// ============================================
// TEST 3: Database Connection
// ============================================
try {
    // Determine connection method (Cloud Run uses TCP/IP, not Unix sockets)
    $cloudsqlInstance = getenv('CLOUDSQL_INSTANCE');
    $dbHost = getenv('MYSQLHOST');
    
    if ($cloudsqlInstance && !$dbHost) {
        // Cloud Run with Cloud SQL - use public IP or Cloud SQL Proxy
        $dbHost = '34.168.76.127'; // Public IP fallback
        $diagnostic['tests']['database_connection']['method'] = 'cloudsql_public_ip';
    } elseif ($dbHost) {
        $diagnostic['tests']['database_connection']['method'] = 'custom_host';
    } else {
        $dbHost = '34.168.76.127'; // Default public IP
        $diagnostic['tests']['database_connection']['method'] = 'default_public_ip';
    }
    
    $dbName = getenv('MYSQLDATABASE') ?: 'unobix_db';
    $dbUser = getenv('MYSQLUSER') ?: 'unobix_user';
    $dbPass = getenv('MYSQLPASSWORD') ?: 'YyZD3H)dndSo*A/N';
    $dbPort = getenv('MYSQLPORT') ?: '3306';
    
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    $diagnostic['tests']['database_connection'] = [
        'status' => 'PASS',
        'host' => $dbHost,
        'database' => $dbName,
        'user' => $dbUser,
        'connection' => 'established',
        'tables_count' => $pdo->query("SHOW TABLES")->rowCount()
    ];
    
} catch (PDOException $e) {
    $diagnostic['tests']['database_connection'] = [
        'status' => 'FAIL',
        'error' => $e->getMessage(),
        'dsn' => $dsn ?? 'not_attempted'
    ];
}

// ============================================
// TEST 4: Firebase Configuration
// ============================================
$firebaseProjectId = getenv('FIREBASE_PROJECT_ID');
$firebaseApiKey = getenv('FIREBASE_API_KEY');

$diagnostic['tests']['firebase_config'] = [
    'status' => ($firebaseProjectId && $firebaseApiKey) ? 'PASS' : 'FAIL',
    'project_id' => $firebaseProjectId ?: 'NOT_SET',
    'api_key' => $firebaseApiKey ? '***' . substr($firebaseApiKey, -8) : 'NOT_SET',
    'verification_endpoint' => 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyIdToken'
];

// ============================================
// TEST 5: Critical PHP Files (Cloud Run container paths)
// ============================================
// In Cloud Run container, files may be in root or api/ directory
// We check both locations to be safe
$requiredFiles = [
    'config.php' => ['config.php', 'api/config.php'],
    'config-cloudrun.php' => ['config-cloudrun.php', 'api/config-cloudrun.php'],
    'auth-firebase.php' => ['auth-firebase.php', 'api/auth-firebase.php'],
    'game-start.php' => ['game-start.php', 'api/game-start.php']
];

$filesTest = ['status' => 'PASS', 'missing' => [], 'found' => []];
foreach ($requiredFiles as $file => $paths) {
    $found = false;
    $foundPath = null;
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $found = true;
            $foundPath = $path;
            break;
        }
    }
    
    if (!$found) {
        $filesTest['missing'][] = $file;
        $filesTest['status'] = 'FAIL';
    } else {
        $filesTest['found'][$file] = $foundPath;
    }
}
$diagnostic['tests']['required_files'] = $filesTest;

// ============================================
// FINAL DIAGNOSIS
// ============================================
$allPass = true;
foreach ($diagnostic['tests'] as $test) {
    if (isset($test['status']) && $test['status'] === 'FAIL') {
        $allPass = false;
        break;
    }
}

$diagnostic['overall_status'] = $allPass ? 'HEALTHY' : 'UNHEALTHY';
$diagnostic['recommendations'] = [];

if (!$allPass) {
    if (!empty($diagnostic['tests']['environment_variables']['missing'])) {
        $diagnostic['recommendations'][] = [
            'priority' => 'CRITICAL',
            'action' => 'Set missing environment variables in Cloud Run deployment',
            'variables' => $diagnostic['tests']['environment_variables']['missing']
        ];
    }
    
    if ($diagnostic['tests']['database_connection']['status'] === 'FAIL') {
        $diagnostic['recommendations'][] = [
            'priority' => 'CRITICAL',
            'action' => 'Fix database connection. Cloud Run uses TCP/IP, not Unix sockets.',
            'details' => 'Ensure MYSQLHOST points to Cloud SQL public IP (34.168.76.127)'
        ];
    }
}

// Output results
http_response_code($allPass ? 200 : 500);
echo json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
