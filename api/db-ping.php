<?php
// ============================================
// UNOBIX - DB Ping (diagnóstico Cloud SQL)
// Arquivo: api/db-ping.php
// Objetivo: validar conexão PDO + latência + modo (socket/TCP)
// ============================================

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . "/config.php";

// CORS/Headers: usa o seu helper se existir
if (function_exists('setCorsHeaders')) {
    setCorsHeaders();
}
header('Content-Type: application/json; charset=utf-8');

function json_out($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Evitar vazar info em prod: opcionalmente exigir um token simples
// Configure DB_PING_TOKEN no env/Secret Manager se quiser
$requiredToken = getenv('DB_PING_TOKEN') ?: '';
if ($requiredToken !== '') {
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_DB_PING_TOKEN'] ?? '');
    if (!hash_equals($requiredToken, (string)$token)) {
        json_out(["success" => false, "error" => "Unauthorized"], 401);
    }
}

$startTotal = microtime(true);

try {
    // Tenta reutilizar a mesma estratégia do seu config.php
    // Você já implementou suporte a /cloudsql/<instance> em api/config.php via CLOUDSQL_INSTANCE
    // Aqui só reconstruímos DSN de forma compatível.
    $cloudsqlInstance = getenv('CLOUDSQL_INSTANCE') ?: '';
    $dbHost = getenv('MYSQLHOST') ?: '';
    $dbPort = (int)(getenv('MYSQLPORT') ?: 3306);
    $dbName = getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: '');
    $dbUser = getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: '');
    $dbPass = getenv('MYSQLPASSWORD') ?: (getenv('DB_PASS') ?: '');

    $mode = 'tcp';
    if ($cloudsqlInstance) {
        $dbHost = "/cloudsql/" . $cloudsqlInstance;
        $mode = 'cloudsql_socket';
    }

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        json_out([
            "success" => false,
            "error" => "Missing DB env vars",
            "details" => [
                "MYSQLHOST" => $dbHost !== '' ? "set" : "missing",
                "MYSQLDATABASE" => $dbName !== '' ? "set" : "missing",
                "MYSQLUSER" => $dbUser !== '' ? "set" : "missing",
                "MYSQLPASSWORD" => ($dbPass !== '') ? "set" : "missing",
                "CLOUDSQL_INSTANCE" => $cloudsqlInstance !== '' ? "set" : "missing",
            ]
        ], 500);
    }

    if ($mode === 'cloudsql_socket') {
        // DSN via unix_socket
        $dsn = "mysql:unix_socket={$dbHost};dbname={$dbName};charset=utf8mb4";
    } else {
        // DSN via TCP
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    }

    $startPdo = microtime(true);
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Tempo de conexão: ajuste se precisar
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdoMs = (microtime(true) - $startPdo) * 1000.0;

    $startQ = microtime(true);
    $ok = $pdo->query("SELECT 1 AS ok")->fetch();
    $qMs = (microtime(true) - $startQ) * 1000.0;

    $totalMs = (microtime(true) - $startTotal) * 1000.0;

    json_out([
        "success" => true,
        "mode" => $mode,
        "db" => [
            "name" => $dbName,
            // nunca retorne senha; host pode retornar, mas sem detalhes sensíveis
            "host_hint" => ($mode === 'cloudsql_socket') ? "unix_socket:/cloudsql/..." : "tcp:" . $dbHost,
            "port" => ($mode === 'tcp') ? $dbPort : null,
        ],
        "timing_ms" => [
            "pdo_connect" => round($pdoMs, 2),
            "query" => round($qMs, 2),
            "total" => round($totalMs, 2),
        ],
        "result" => $ok,
        "ts" => gmdate('c'),
    ]);

} catch (Throwable $e) {
    // Não vazar credenciais/DSN completo
    json_out([
        "success" => false,
        "error" => "DB connection failed",
        "exception" => [
            "type" => get_class($e),
            "message" => $e->getMessage(),
            "code" => $e->getCode(),
        ],
        "ts" => gmdate('c'),
    ], 500);
}
