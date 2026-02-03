<?php
// Simulando ambiente Cloud Run
putenv('MYSQLHOST=34.168.76.127');
putenv('MYSQLDATABASE=unobix_db');
putenv('MYSQLUSER=unobix_user');
putenv('MYSQLPASSWORD=YyZD3H)dndSo*A/N');
putenv('FIREBASE_PROJECT_ID=unobix-oauth-a69cd');
putenv('FIREBASE_API_KEY=AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U');
putenv('GAME_SECRET_KEY=test_key');
putenv('ADMIN_PASSWORD=test_pass');
putenv('APP_ENV=production');

require_once 'api/config.php';

echo "✅ Config carregada localmente\n";
echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "FIREBASE_API_KEY: " . substr(FIREBASE_API_KEY, 0, 10) . "...\n";

// Testar conexão
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    echo "✅ Conexão banco OK\n";
} catch (PDOException $e) {
    echo "❌ Erro conexão: " . $e->getMessage() . "\n";
}
