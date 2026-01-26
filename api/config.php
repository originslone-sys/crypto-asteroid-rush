<?php
// ============================================
// CRYPTO ASTEROID RUSH - Configuração
// Arquivo: api/config.php
// ============================================

// Configurações do banco de dados (Railway / Docker)
// Preferência: variáveis MYSQL* do Railway. Fallback: DB_* (para ambiente local).
function getEnvAny(...$keys) {
    foreach ($keys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') return $v;
    }
    return null;
}

define('DB_HOST', getEnvAny('MYSQLHOST', 'DB_HOST'));
define('DB_PORT', (int)(getEnvAny('MYSQLPORT', 'DB_PORT') ?: 3306));
define('DB_NAME', getEnvAny('MYSQLDATABASE', 'DB_NAME'));
define('DB_USER', getEnvAny('MYSQLUSER', 'DB_USER'));
define('DB_PASS', getEnvAny('MYSQLPASSWORD', 'DB_PASS'));

// Configurações do sistema
define('STAKE_APY', 0.12);
define('MIN_STAKE_AMOUNT', 0.0001);

// ============================================
// CONFIGURAÇÕES DE SEGURANÇA DO JOGO
// ============================================
define('GAME_SECRET_KEY', 'CAR_2026_S3CR3T_K3Y_X9Z2M4'); // Chave secreta para hash
define('GAME_DURATION', 180); // Duração do jogo em segundos
define('GAME_TOLERANCE', 15); // Tolerância em segundos (para lag de rede)
define('MAX_ASTEROIDS_PER_SECOND', 3); // Máximo de asteroides destruídos por segundo
define('ENTRY_FEE_BNB', 0.00001); // Taxa de entrada

// Recompensas (devem ser iguais ao frontend)
define('REWARD_NONE', 0);
define('REWARD_COMMON', 0.0001);
define('REWARD_RARE', 0.01);
define('REWARD_EPIC', 0.10);

// Limites por missão
define('MAX_RARE_PER_MISSION', 2);
define('MAX_EPIC_PER_MISSION', 1);
define('EPIC_MIN_MISSIONS_INTERVAL', 15);

// Função para conexão com o banco
function getDatabaseConnection() {
    // Força TCP (evita tentativa de socket quando host = localhost)
    $host = (DB_HOST === 'localhost') ? '127.0.0.1' : DB_HOST;

    if (!$host || !DB_NAME || !DB_USER) {
        error_log("Database config missing (env vars). host={$host} db=" . (DB_NAME ?: 'null') . " user=" . (DB_USER ?: 'null'));
        return null;
    }

    try {
        $pdo = new PDO(
            "mysql:host={$host};port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5
            ]
        );
        return $pdo;
    } catch(PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

// Função para validar endereço de carteira
function validateWallet($wallet) {
    return preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet);
}

// Função para gerar token de sessão seguro
function generateSessionToken($wallet, $sessionId) {
    $data = $wallet . '|' . $sessionId . '|' . time() . '|' . GAME_SECRET_KEY;
    return hash('sha256', $data);
}

// Função para validar token de sessão
function validateSessionToken($token, $wallet, $sessionId, $createdAt) {
    // Token é válido por 5 minutos (300 segundos) após criação
    $data = $wallet . '|' . $sessionId . '|' . strtotime($createdAt) . '|' . GAME_SECRET_KEY;
    $expectedToken = hash('sha256', $data);
    return hash_equals($expectedToken, $token);
}

// Função para log seguro
function secureLog($message, $file = 'game_security.log') {
    $logEntry = date('Y-m-d H:i:s') . ' | ' . $message . "\n";
    file_put_contents(__DIR__ . '/' . $file, $logEntry, FILE_APPEND);
}
?>
