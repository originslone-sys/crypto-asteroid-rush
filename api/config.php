<?php
// ============================================
// CRYPTO ASTEROID RUSH - Configuração
// ============================================

// Banco de dados (Railway / Docker-safe)
define('DB_HOST', getenv('MYSQLHOST') ?: getenv('DB_HOST'));
define('DB_PORT', getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306;
define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('DB_NAME'));
define('DB_USER', getenv('MYSQLUSER') ?: getenv('DB_USER'));
define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS'));

// Segurança mínima
if (!DB_HOST || !DB_NAME || !DB_USER) {
    error_log("❌ Variáveis de banco ausentes no ambiente");
    http_response_code(500);
    exit('Erro de configuração do servidor');
}

// Força TCP (NUNCA socket)
if (DB_HOST === 'localhost') {
    define('DB_HOST_FIXED', '127.0.0.1');
} else {
    define('DB_HOST_FIXED', DB_HOST);
}

// Conexão PDO global
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST_FIXED . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5
        ]
    );
} catch (PDOException $e) {
    error_log("❌ Erro DB: " . $e->getMessage());
    http_response_code(500);
    exit('Erro ao conectar ao banco');
}
