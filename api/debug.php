<?php
// Debug endpoint temporário
require_once __DIR__ . "/config.php";

header('Content-Type: text/plain');
echo "=== DEBUG UNOBIX ===\n\n";

// 1. Mostrar variáveis de ambiente
echo "1. Variáveis de ambiente:\n";
$envVars = ['MYSQL_PUBLIC_URL', 'MYSQLHOST', 'MYSQLPORT', 'MYSQLUSER', 'MYSQLPASSWORD', 'MYSQLDATABASE'];
foreach ($envVars as $var) {
    $value = getenv($var);
    echo "   $var: " . ($value ? $value : '(não definida)') . "\n";
}
echo "\n";

// 2. Mostrar constantes definidas
echo "2. Constantes config.php:\n";
echo "   DB_HOST: " . DB_HOST . "\n";
echo "   DB_PORT: " . DB_PORT . "\n";
echo "   DB_NAME: " . DB_NAME . "\n";
echo "   DB_USER: " . DB_USER . "\n";
echo "   DB_PASS: " . (strlen(DB_PASS) > 0 ? '***' . substr(DB_PASS, -4) : 'vazia') . "\n\n";

// 3. Testar conexão
echo "3. Testando conexão MySQL...\n";
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    echo "   ✅ Conexão OK\n";
    echo "   DSN: $dsn\n";
    
    // Testar query
    $stmt = $pdo->query("SELECT DATABASE() as db, USER() as user, VERSION() as version");
    $result = $stmt->fetch();
    echo "   Banco: " . $result['db'] . "\n";
    echo "   Usuário: " . $result['user'] . "\n";
    echo "   MySQL: " . $result['version'] . "\n\n";
    
    // Testar tabela players
    echo "4. Testando tabela players...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM players");
    $players = $stmt->fetch();
    echo "   Total players: " . $players['total'] . "\n";
    
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ?");
    $stmt->execute(['DqnexVtvrtdG3fe8fSGzHk8NA713']);
    $player = $stmt->fetch();
    
    if ($player) {
        echo "   ✅ Player encontrado: " . $player['google_uid'] . "\n";
        echo "   ID: " . $player['id'] . "\n";
        echo "   Email: " . ($player['email'] ?? 'N/A') . "\n";
    } else {
        echo "   ❌ Player não encontrado\n";
    }
    
} catch (PDOException $e) {
    echo "   ❌ Erro PDO: " . $e->getMessage() . "\n";
    echo "   Código: " . $e->getCode() . "\n";
    echo "   SQL State: " . $e->errorInfo[0] . "\n";
}

echo "\n=== FIM DEBUG ===\n";
?>