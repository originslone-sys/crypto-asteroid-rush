<?php
// ============================================
// CRYPTO ASTEROID RUSH - Configuração
// Arquivo: api/config.php
// ============================================

// ===============================
// CONFIGURAÇÕES DO BANCO (Railway)
// ===============================
define('DB_HOST', getenv('MYSQLHOST'));
define('DB_NAME', getenv('MYSQLDATABASE'));
define('DB_USER', getenv('MYSQLUSER'));
define('DB_PASS', getenv('MYSQLPASSWORD'));
define('DB_PORT', getenv('MYSQLPORT'));

// ===============================
// CONFIGURAÇÕES DO SISTEMA
// ===============================
define('STAKE_APY', 0.12);
define('MIN_STAKE_AMOUNT', 0.0001);

// ===============================
// SEGURANÇA DO JOGO
// ===============================
define('GAME_SECRET_KEY', getenv('GAME_SECRET_KEY')); // Defina no Railway Variables
define('GAME_DURATION', 180); 
define('GAME_TOLERANCE', 15); 
define('MAX_ASTEROIDS_PER_SECOND', 3); 
define('ENTRY_FEE_BNB', 0.00001); 

// ===============================
// RECOMPENSAS
// ===============================
define('REWARD_NONE', 0);
define('REWARD_COMMON', 0.0001);
define('REWARD_RARE', 0.01);
define('REWARD_EPIC', 0.10);

// ===============================
// LIMITES POR MISSÃO
// ===============================
define('MAX_RARE_PER_MISSION', 2);
define('MAX_EPIC_PER_MISSION', 1);
define('EPIC_MIN_MISSIONS_INTERVAL', 15);

// ===============================
// FUNÇÃO DE CONEXÃO COM O BANCO
// ===============================
function getDatabaseConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false
        ]);
        return $pdo;
    } catch(PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

// ===============================
// FUNÇÕES AUXILIARES
// ===============================
function validateWallet($wallet) {
    return preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet);
}

function generateSessionToken($wallet, $sessionId) {
    $data = $wallet . '|' . $sessionId . '|' . time() . '|' . GAME_SECRET_KEY;
    return hash('sha256', $data);
}

function validateSessionToken($token, $wallet, $sessionId, $createdAt) {
    $data = $wallet . '|' . $sessionId . '|' . strtotime($createdAt) . '|' . GAME_SECRET_KEY;
    $expectedToken = hash('sha256', $data);
    return hash_equals($expectedToken, $token);
}

function secureLog($message, $file = 'game_security.log') {
    $logEntry = date('Y-m-d H:i:s') . ' | ' . $message . PHP_EOL;
    file_put_contents(__DIR__ . '/' . $file, $logEntry, FILE_APPEND);
}
?>
