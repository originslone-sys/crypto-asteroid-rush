<?php
// ============================================
// UNOBIX - Configuração Principal
// Arquivo: api/config.php
// v2.0 - Google Auth + BRL + hCaptcha
// ============================================

// ============================================
// CONFIGURAÇÕES DE BANCO DE DADOS (Railway)
// ============================================
define('DB_HOST', getenv('MYSQLHOST') ?: 'mysql.railway.internal');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'AiaWPNyMBtRFnUWtFjJtkMVtNzDnflta');
define('DB_PORT', getenv('MYSQLPORT') ?: 3306);

// ============================================
// CONFIGURAÇÕES DE SEGURANÇA
// ============================================
define('GAME_SECRET_KEY', getenv('GAME_SECRET_KEY') ?: 'UNOBIX_2026_S3CR3T_K3Y_X9Z2M4');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'admin_muito_seguro_2026');

// ============================================
// FIREBASE / GOOGLE AUTH
// ============================================
define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: 'unobix-oauth-a69cd');
define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U');

// ============================================
// hCAPTCHA
// ============================================
define('HCAPTCHA_SITE_KEY', getenv('HCAPTCHA_SITE_KEY') ?: '');
define('HCAPTCHA_SECRET_KEY', getenv('HCAPTCHA_SECRET_KEY') ?: '');
define('CAPTCHA_ENABLED', true);
define('CAPTCHA_REQUIRED_ON_VICTORY', true);
define('CAPTCHA_REQUIRED_ON_GAMEOVER', false);

// ============================================
// CONFIGURAÇÕES DO JOGO
// ============================================
define('GAME_DURATION', 180);           // 3 minutos
define('GAME_TOLERANCE', 300);          // 5 minutos de tolerância
define('INITIAL_LIVES', 6);
define('MAX_MISSIONS_PER_HOUR', 5);
define('MAX_CONCURRENT_MISSIONS', 1);
define('COOLDOWN_MINUTES', 3);

// ============================================
// HARD MODE (40% das missões) - SECRETO!
// ============================================
define('HARD_MODE_PERCENTAGE', 40);
define('HARD_MODE_SPEED_MULTIPLIER', 1.4);
define('HARD_MODE_SPAWN_MULTIPLIER', 0.7);

// ============================================
// VALORES DE RECOMPENSA (BRL) - SECRETO!
// ============================================
define('REWARD_NONE', 0);
define('REWARD_COMMON', 0);             // R$ 0,00
define('REWARD_RARE', 0.001);           // R$ 0,001
define('REWARD_EPIC', 0.005);           // R$ 0,005
define('REWARD_LEGENDARY', 0.02);       // R$ 0,02

// Mapeamento de recompensas (usado internamente)
define('ASTEROID_REWARDS_BRL', [
    'none' => 0,
    'common' => 0,
    'rare' => 0.001,
    'epic' => 0.005,
    'legendary' => 0.02
]);

// ============================================
// SPAWN RATES - SECRETO! (nunca expor)
// ============================================
define('SPAWN_RATE_COMMON', 0.95);      // 95%
define('SPAWN_RATE_RARE', 0.03);        // 3%
define('SPAWN_RATE_EPIC', 0.015);       // 1.5%
define('SPAWN_RATE_LEGENDARY', 0.005);  // 0.5%

// ============================================
// STAKING (5% APY)
// ============================================
define('STAKE_APY', 0.05);              // 5% ao ano
define('MIN_STAKE_BRL', 0.01);
define('MAX_STAKE_BRL', 10000.00);

// ============================================
// SAQUES
// ============================================
define('MIN_WITHDRAW_BRL', 1.00);
define('MAX_WITHDRAW_BRL', 10000.00);
define('WEEKLY_WITHDRAW_LIMIT', 1);
define('PROCESSING_START_DAY', 20);
define('PROCESSING_END_DAY', 25);

// Métodos de saque disponíveis
define('WITHDRAW_METHODS', ['pix', 'paypal', 'usdt_bep20']);

// ============================================
// SEGURANÇA / ANTI-FRAUDE
// ============================================
define('EARNINGS_ALERT_BRL', 0.30);     // Alerta se > R$ 0.30 por missão
define('EARNINGS_BLOCK_BRL', 1.00);     // Bloquear se > R$ 1.00 por missão
define('EARNINGS_SUSPECT_BRL', 0.50);   // Suspeito se > R$ 0.50 por missão
define('AUTO_BAN_AFTER_ALERTS', 5);

// Rate limit de eventos por segundo
define('MAX_EVENTS_PER_SECOND', 10);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

/**
 * Obtém conexão com o banco de dados
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
        } catch (PDOException $e) {
            error_log("Erro de conexão DB: " . $e->getMessage());
            return null;
        }
    }
    
    return $pdo;
}

/**
 * Valida wallet Ethereum (para compatibilidade)
 */
function validateWallet($wallet) {
    return preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet);
}

/**
 * Valida Google UID
 * Firebase/Google UIDs podem conter letras, números, _ e -
 * Tamanho típico: 20–128 caracteres
 */
if (!function_exists('validateGoogleUid')) {
    function validateGoogleUid($uid) {
        if (empty($uid)) return false;
        return preg_match('/^[a-zA-Z0-9_-]{20,128}$/', $uid);
    }
}

/**
 * Alias para compatibilidade
 * OBS: PHP trata nomes de função como case-insensitive,
 * então ESTE alias só pode existir se a função ainda não existir.
 */
if (!function_exists('validateGoogleUID')) {
    function validateGoogleUID($uid) {
        return validateGoogleUid($uid);
    }
}

/**
 * Obtém identificador do usuário (google_uid ou wallet)
 */
function getUserIdentifier($input) {
    $googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
    $wallet    = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
    
    if ($googleUid !== '' && validateGoogleUid($googleUid)) {
        return [
            'type'  => 'google_uid',
            'value' => $googleUid
        ];
    }
    
    if ($wallet !== '' && validateWallet($wallet)) {
        return [
            'type'  => 'wallet',
            'value' => $wallet
        ];
    }
    
    return null;
}

/**
 * Gera token de sessão seguro
 */
function generateSessionToken($identifier, $sessionId) {
    $data = $identifier . '|' . $sessionId . '|' . time() . '|' . GAME_SECRET_KEY;
    return hash('sha256', $data);
}

/**
 * Obtém IP real do cliente
 */
function getClientIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}

/**
 * Log seguro para eventos do jogo
 */
function secureLog($message, $file = 'game_security.log') {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logEntry = date('Y-m-d H:i:s') . ' | ' . $message . "\n";
    @file_put_contents($logDir . '/' . $file, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Busca configuração do banco (system_config)
 */
function getSystemConfig($key, $default = null) {
    $pdo = getDatabaseConnection();
    if (!$pdo) return $default;
    
    try {
        $stmt = $pdo->prepare("SELECT config_value, is_public FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        if ($result) {
            return json_decode($result['config_value'], true);
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar config: " . $e->getMessage());
    }
    
    return $default;
}

/**
 * Determina se a missão será hard mode (40%)
 */
function isHardModeMission() {
    return mt_rand(1, 100) <= HARD_MODE_PERCENTAGE;
}

/**
 * Calcula recompensa baseada no tipo (BRL)
 */
function getRewardByType($type) {
    $rewards = ASTEROID_REWARDS_BRL;
    return isset($rewards[$type]) ? $rewards[$type] : 0;
}

/**
 * Verifica CAPTCHA via hCaptcha
 */
function verifyCaptcha($token, $ip = null) {
    if (!CAPTCHA_ENABLED || empty(HCAPTCHA_SECRET_KEY)) {
        return ['success' => true, 'message' => 'CAPTCHA desabilitado'];
    }
    
    if (empty($token)) {
        return ['success' => false, 'message' => 'Token CAPTCHA ausente'];
    }
    
    $data = [
        'secret' => HCAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $ip ?: getClientIP()
    ];
    
    $ch = curl_init('https://hcaptcha.com/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return ['success' => false, 'message' => 'Erro ao verificar CAPTCHA'];
    }
    
    $result = json_decode($response, true);
    
    return [
        'success' => isset($result['success']) && $result['success'] === true,
        'message' => isset($result['error-codes']) ? implode(', ', $result['error-codes']) : 'OK'
    ];
}

/**
 * Busca jogador por google_uid ou wallet
 */
function findPlayer($pdo, $identifier) {
    $userInfo = getUserIdentifier($identifier);
    if (!$userInfo) return null;
    
    if ($userInfo['type'] === 'google_uid') {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE wallet_address = ? LIMIT 1");
    }
    
    $stmt->execute([$userInfo['value']]);
    return $stmt->fetch();
}

/**
 * Cria ou atualiza jogador
 */
function getOrCreatePlayer($pdo, $input) {
    $googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
    $email = isset($input['email']) ? trim($input['email']) : '';
    $displayName = isset($input['display_name']) ? trim($input['display_name']) : '';
    $photoUrl = isset($input['photo_url']) ? trim($input['photo_url']) : '';
    $wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
    
    // Primeiro, buscar por google_uid
    if (!empty($googleUid) && validateGoogleUid($googleUid)) {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $player = $stmt->fetch();
        
        if ($player) {
            // Atualizar informações se necessário
            $stmt = $pdo->prepare("
                UPDATE players SET 
                    email = COALESCE(NULLIF(?, ''), email),
                    display_name = COALESCE(NULLIF(?, ''), display_name),
                    photo_url = COALESCE(NULLIF(?, ''), photo_url)
                WHERE google_uid = ?
            ");
            $stmt->execute([$email, $displayName, $photoUrl, $googleUid]);
            
            return findPlayer($pdo, $input);
        }
        
        // Criar novo jogador com Google UID
        // Gerar wallet temporária para compatibilidade
        $tempWallet = '0x' . substr(hash('sha256', $googleUid . time()), 0, 40);
        
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, email, display_name, photo_url, wallet_address, balance_brl, total_played)
            VALUES (?, ?, ?, ?, ?, 0, 0)
        ");
        $stmt->execute([$googleUid, $email, $displayName, $photoUrl, $tempWallet]);
        
        return findPlayer($pdo, $input);
    }
    
    // Fallback: buscar por wallet (compatibilidade com sistema antigo)
    if (!empty($wallet) && validateWallet($wallet)) {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE wallet_address = ? LIMIT 1");
        $stmt->execute([$wallet]);
        $player = $stmt->fetch();
        
        if (!$player) {
            $stmt = $pdo->prepare("
                INSERT INTO players (wallet_address, balance_brl, total_played)
                VALUES (?, 0, 0)
            ");
            $stmt->execute([$wallet]);
        }
        
        return findPlayer($pdo, $input);
    }
    
    return null;
}

// ============================================
// HEADERS PADRÃO (CORS)
// ============================================
function setCorsHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit(0);
    }
}
