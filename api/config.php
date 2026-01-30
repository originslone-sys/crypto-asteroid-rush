<?php
// ============================================
// UNOBIX - Configuração Principal
// Arquivo: api/config.php
// v4.0 - Google Auth + BRL + Novos Sistemas
// ============================================

// ============================================
// CONFIGURAÇÕES DO BANCO DE DADOS
// Railway usa variáveis de ambiente
// cPanel usa valores hardcoded como fallback
// ============================================

if (getenv('MYSQLHOST') || getenv('DB_HOST')) {
    // Railway environment
    define('DB_HOST', getenv('MYSQLHOST') ?: getenv('DB_HOST'));
    define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('DB_NAME'));
    define('DB_USER', getenv('MYSQLUSER') ?: getenv('DB_USER'));
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS'));
    define('DB_PORT', getenv('MYSQLPORT') ?: '3306');
} else {
    // cPanel fallback - ALTERE PARA SEUS DADOS
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'seu_banco');
    define('DB_USER', 'seu_usuario');
    define('DB_PASS', 'sua_senha');
    define('DB_PORT', '3306');
}

// ============================================
// CONFIGURAÇÕES FIREBASE (Google Auth)
// ============================================
define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: '
unobix-oauth-a69cd');
define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U');

// ============================================
// CONFIGURAÇÕES DE SEGURANÇA DO JOGO
// ============================================
define('GAME_SECRET_KEY', getenv('GAME_SECRET_KEY') ?: 'UNOBIX_2026_S3CR3T_K3Y_X9Z2M4');
define('GAME_DURATION', 180); // Duração do jogo em segundos
define('GAME_TOLERANCE', 15); // Tolerância em segundos (para lag de rede)
define('MAX_ASTEROIDS_PER_SECOND', 3); // Máximo de asteroides destruídos por segundo

// ============================================
// CONFIGURAÇÕES DE STAKE (5% APY)
// ============================================
define('STAKE_APY', 0.05); // 5% ao ano
define('MIN_STAKE_AMOUNT_BRL', 0.01); // Mínimo R$ 0,01
define('MAX_STAKE_AMOUNT_BRL', 10000.00); // Máximo R$ 10.000

// ============================================
// RECOMPENSAS EM BRL
// COMUM = R$ 0 (asteroides comuns não valem nada)
// ============================================
define('REWARD_NONE', 0);
define('REWARD_COMMON_BRL', 0);       // R$ 0,00 - Sem valor!
define('REWARD_RARE_BRL', 0.001);     // R$ 0,001
define('REWARD_EPIC_BRL', 0.005);     // R$ 0,005
define('REWARD_LEGENDARY_BRL', 0.02); // R$ 0,02

// ============================================
// HARD MODE (40% das missões) - SECRETO
// Não expor no frontend!
// ============================================
define('HARD_MODE_PERCENTAGE', 40); // 40% das missões serão hard
define('HARD_MODE_SPEED_MULTIPLIER', 1.4);
define('HARD_MODE_SPAWN_MULTIPLIER', 0.7);

// ============================================
// SPAWN RATES - SECRETO
// Não expor no frontend!
// ============================================
define('SPAWN_RATE_COMMON', 0.95);    // 95%
define('SPAWN_RATE_RARE', 0.03);      // 3%
define('SPAWN_RATE_EPIC', 0.015);     // 1.5%
define('SPAWN_RATE_LEGENDARY', 0.005); // 0.5%

// ============================================
// CONFIGURAÇÕES DE SAQUE
// ============================================
define('MIN_WITHDRAW_BRL', 1.00);     // Mínimo R$ 1,00
define('MAX_WITHDRAW_BRL', 10000.00); // Máximo R$ 10.000
define('WITHDRAW_WEEKLY_LIMIT', 1);   // 1 saque por semana
define('WITHDRAW_PROCESSING_START', 20); // Dia 20
define('WITHDRAW_PROCESSING_END', 25);   // Dia 25

// ============================================
// LIMITES DE IP (Anti-abuse)
// ============================================
define('MAX_MISSIONS_PER_HOUR', 5);    // Máximo 5 missões por hora por IP
define('MAX_CONCURRENT_MISSIONS', 1);  // Apenas 1 missão simultânea por IP

// ============================================
// CAPTCHA (hCaptcha)
// ============================================
define('HCAPTCHA_SITE_KEY', getenv('HCAPTCHA_SITE_KEY') ?: '6bc4cc76-b924-4d92-9c76-0648a9000436');
define('HCAPTCHA_SECRET_KEY', getenv('HCAPTCHA_SECRET_KEY') ?: 'ES_6d5ff3999fb84de99004187ea2f74512');
define('CAPTCHA_REQUIRED_ON_VICTORY', true);
define('CAPTCHA_REQUIRED_ON_GAMEOVER', false);

// ============================================
// THRESHOLDS DE SEGURANÇA (Anti-fraude)
// ============================================
define('EARNINGS_ALERT_BRL', 0.30);   // Alerta se ganhar mais que R$ 0,30
define('EARNINGS_SUSPECT_BRL', 0.50); // Suspeito se ganhar mais que R$ 0,50
define('EARNINGS_BLOCK_BRL', 1.00);   // Bloqueia se ganhar mais que R$ 1,00

// ============================================
// FUNÇÕES UTILITÁRIAS
// ============================================

/**
 * Conexão com o banco de dados
 */
function getDatabaseConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            // Timezone Brasil
            $pdo->exec("SET time_zone = '-03:00'");
        } catch(PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    return $pdo;
}

/**
 * Validar endereço de carteira (legacy - manter para compatibilidade)
 */
function validateWallet($wallet) {
    return preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet);
}

/**
 * Validar Google UID
 */
function validateGoogleUID($uid) {
    // Google UID é uma string de 28 caracteres alfanuméricos
    return preg_match('/^[a-zA-Z0-9_-]{20,128}$/', $uid);
}

/**
 * Gerar token de sessão seguro
 */
function generateSessionToken($identifier, $sessionId) {
    $data = $identifier . '|' . $sessionId . '|' . time() . '|' . GAME_SECRET_KEY;
    return hash('sha256', $data);
}

/**
 * Validar token de sessão
 */
function validateSessionToken($token, $identifier, $sessionId, $createdAt) {
    $data = $identifier . '|' . $sessionId . '|' . strtotime($createdAt) . '|' . GAME_SECRET_KEY;
    $expectedToken = hash('sha256', $data);
    return hash_equals($expectedToken, $token);
}

/**
 * Obter IP real do cliente
 */
function getClientIP() {
    // Cloudflare
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    // Proxy
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    // Real IP header
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    // Direct connection
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Log seguro
 */
function secureLog($message, $file = 'game_security.log') {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logEntry = date('Y-m-d H:i:s') . ' | ' . getClientIP() . ' | ' . $message . "\n";
    file_put_contents($logDir . '/' . $file, $logEntry, FILE_APPEND);
}

/**
 * Resposta JSON padronizada
 */
function jsonResponse($success, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = ['success' => $success];
    
    if ($success) {
        $response = array_merge($response, $data);
    } else {
        $response['error'] = $data['error'] ?? 'Erro desconhecido';
        if (isset($data['message'])) {
            $response['message'] = $data['message'];
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Obter identificador do usuário (Google UID ou Wallet)
 */
function getUserIdentifier($input) {
    // Prioridade: google_uid > wallet_address
    $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;
    $wallet = $input['wallet'] ?? $input['wallet_address'] ?? $input['walletAddress'] ?? null;
    
    if ($googleUid && validateGoogleUID($googleUid)) {
        return ['type' => 'google', 'value' => $googleUid];
    }
    
    if ($wallet && validateWallet(strtolower($wallet))) {
        return ['type' => 'wallet', 'value' => strtolower($wallet)];
    }
    
    return null;
}

/**
 * Buscar jogador por identificador
 */
function getPlayerByIdentifier($pdo, $identifier) {
    if ($identifier['type'] === 'google') {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$identifier['value']]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE wallet_address = ? LIMIT 1");
        $stmt->execute([$identifier['value']]);
    }
    
    return $stmt->fetch();
}

/**
 * Determinar dificuldade da missão (40% hard mode)
 */
function getMissionDifficulty() {
    $random = mt_rand(1, 100);
    return $random <= HARD_MODE_PERCENTAGE;
}

/**
 * Calcular valor do asteroide baseado no tipo
 */
function getAsteroidValue($type) {
    switch (strtolower($type)) {
        case 'legendary':
            return REWARD_LEGENDARY_BRL;
        case 'epic':
            return REWARD_EPIC_BRL;
        case 'rare':
            return REWARD_RARE_BRL;
        case 'common':
        case 'none':
        default:
            return 0;
    }
}

/**
 * Obter configuração do system_config
 */
function getSystemConfig($pdo, $key, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT config_value, is_public FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        
        if ($row) {
            return json_decode($row['config_value'], true);
        }
    } catch (Exception $e) {
        error_log("getSystemConfig error: " . $e->getMessage());
    }
    
    return $default;
}

/**
 * Headers CORS padrão
 */
function setCORSHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Obter input da requisição (JSON, POST, GET)
 */
function getRequestInput() {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    
    if (!is_array($input)) {
        $input = [];
    }
    
    // Merge com POST e GET (prioridade: JSON > POST > GET)
    return array_merge($_GET, $_POST, $input);
}
?>
