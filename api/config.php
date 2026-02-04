<?php
// ============================================
// UNOBIX - Configuração Principal
// api/config.php v4.0 - CORRIGIDO
// Moeda: BRL | Sem MetaMask/USDT
// ============================================

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// ============================================
// BANCO DE DADOS
// ============================================
$mysqlPublicUrl = getenv('MYSQL_PUBLIC_URL');
if ($mysqlPublicUrl && preg_match('/mysql:\/\/([^:]+):([^@]+)@([^:]+):(\d+)\/(.+)/', $mysqlPublicUrl, $matches)) {
    define('DB_HOST', $matches[3]);
    define('DB_PORT', $matches[4]);
    define('DB_USER', $matches[1]);
    define('DB_PASS', $matches[2]);
    define('DB_NAME', $matches[5]);
} else {
    define('DB_HOST', getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '34.168.76.127');
    define('DB_PORT', getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306');
    define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'unobix_db');
    define('DB_USER', getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'unobix_user');
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '');
}

// ============================================
// FIREBASE
// ============================================
define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: 'unobix-oauth-a69cd');
define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U');

// ============================================
// CAPTCHA - Matemático simples (sem hCaptcha)
// ============================================
define('CAPTCHA_ENABLED', true);
define('CAPTCHA_TYPE', 'math'); // 'math' ou 'hcaptcha'
define('HCAPTCHA_SECRET_KEY', getenv('HCAPTCHA_SECRET_KEY') ?: '');
define('CAPTCHA_REQUIRED_ON_VICTORY', true);

// ============================================
// JOGO
// ============================================
define('GAME_DURATION', 180);           // 3 minutos
define('GAME_TOLERANCE', 300);          // 5 minutos de tolerância
define('INITIAL_LIVES', 6);
define('MAX_MISSIONS_PER_HOUR', 5);
define('MAX_EVENTS_PER_SECOND', 10);
define('HARD_MODE_PERCENTAGE', 40);     // 40% das missões são difíceis

// ============================================
// RECOMPENSAS BRL - VALORES CORRETOS
// ============================================
define('REWARD_COMMON', 0);             // R$ 0,00
define('REWARD_RARE', 0.0002);          // R$ 0,0002
define('REWARD_EPIC', 0.0004);          // R$ 0,0004
define('REWARD_LEGENDARY', 0.001);      // R$ 0,001

define('ASTEROID_REWARDS_BRL', [
    'none'      => 0,
    'common'    => 0,
    'rare'      => 0.0002,
    'epic'      => 0.0004,
    'legendary' => 0.001
]);

// ============================================
// LIMITES DE SEGURANÇA
// ============================================
define('EARNINGS_ALERT_BRL', 0.05);     // Alerta se ganhar mais que isso
define('EARNINGS_SUSPECT_BRL', 0.08);   // Suspeito se ganhar mais que isso
define('EARNINGS_BLOCK_BRL', 0.10);     // Bloquear se ganhar mais que isso

// ============================================
// SAQUES
// ============================================
define('MIN_WITHDRAW_BRL', 1.00);       // Mínimo R$ 1,00
define('WEEKLY_WITHDRAW_LIMIT', 1);     // 1 saque por semana
define('WITHDRAW_METHODS', ['pix']);    // Apenas PIX

// ============================================
// STAKING
// ============================================
define('STAKE_APY', 0.05);              // 5% ao ano
define('MIN_STAKE_BRL', 0.10);          // Mínimo R$ 0,10 para stake

// ============================================
// FUNÇÕES DE BANCO DE DADOS
// ============================================

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
            error_log("❌ DB Error: " . $e->getMessage());
            return null;
        }
    }
    return $pdo;
}

// Alias para compatibilidade
function getDBConnection() { 
    return getDatabaseConnection(); 
}

// ============================================
// FUNÇÕES DE VALIDAÇÃO
// ============================================

/**
 * Validar Google UID
 */
function validateGoogleUid($uid) {
    if (empty($uid) || !is_string($uid)) return false;
    $uid = trim($uid);
    
    // Permitir UID truncado (com ...)
    if (strpos($uid, '...') !== false) {
        return strlen($uid) >= 10;
    }
    
    // UID completo: 21-128 caracteres, alfanumérico com _ e -
    return strlen($uid) >= 21 && strlen($uid) <= 128 && preg_match('/^[a-zA-Z0-9_-]+$/', $uid);
}

/**
 * Validar endereço de wallet (BSC/ETH) - para compatibilidade legado
 */
function validateWallet($wallet) {
    if (empty($wallet) || !is_string($wallet)) return false;
    $wallet = trim(strtolower($wallet));
    return preg_match('/^0x[a-f0-9]{40}$/', $wallet);
}

/**
 * Validar chave PIX
 */
function validatePixKey($key, $type = 'cpf') {
    if (empty($key)) return false;
    $key = trim($key);
    
    switch (strtolower($type)) {
        case 'cpf':
            return preg_match('/^\d{11}$/', preg_replace('/\D/', '', $key));
        case 'cnpj':
            return preg_match('/^\d{14}$/', preg_replace('/\D/', '', $key));
        case 'email':
            return filter_var($key, FILTER_VALIDATE_EMAIL) !== false;
        case 'phone':
        case 'celular':
            return preg_match('/^\+?\d{10,13}$/', preg_replace('/\D/', '', $key));
        case 'random':
        case 'aleatoria':
            return strlen($key) >= 20 && strlen($key) <= 36;
        default:
            // Aceitar qualquer formato se tipo não especificado
            return strlen($key) >= 5 && strlen($key) <= 100;
    }
}

// ============================================
// FUNÇÕES UTILITÁRIAS
// ============================================

/**
 * Obter IP do cliente
 */
function getClientIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_X_FORWARDED_FOR',   // Proxy
        'HTTP_X_REAL_IP',         // Nginx
        'REMOTE_ADDR'             // Padrão
    ];
    
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = $_SERVER[$h];
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
 * Log seguro
 */
function secureLog($msg) {
    error_log("[" . date('Y-m-d H:i:s') . "] " . getClientIP() . " | $msg");
}

/**
 * Obter recompensa por tipo de asteroide
 */
function getRewardByType($type) {
    $rewards = ASTEROID_REWARDS_BRL;
    return $rewards[strtolower($type)] ?? 0;
}

/**
 * Determinar se missão é hard mode (40% de chance)
 */
function isHardModeMission() {
    return mt_rand(1, 100) <= HARD_MODE_PERCENTAGE;
}

/**
 * Verificar CAPTCHA matemático
 */
function verifyCaptcha($token, $ip = null) {
    // Se CAPTCHA desabilitado, sempre sucesso
    if (!CAPTCHA_ENABLED) {
        return ['success' => true];
    }
    
    if (empty($token)) {
        return ['success' => false, 'message' => 'Token CAPTCHA ausente'];
    }
    
    // CAPTCHA matemático: token é base64 de "math_{resposta}_{timestamp}"
    if (CAPTCHA_TYPE === 'math') {
        try {
            $decoded = base64_decode($token);
            if (strpos($decoded, 'math_') === 0) {
                // Token válido se foi gerado (validação real é no cliente)
                // Aqui apenas verificamos que o formato está correto
                $parts = explode('_', $decoded);
                if (count($parts) >= 3) {
                    $timestamp = (int)end($parts);
                    // Token válido por 10 minutos
                    if (time() - ($timestamp / 1000) < 600) {
                        return ['success' => true];
                    }
                    return ['success' => false, 'message' => 'Token expirado'];
                }
            }
            return ['success' => false, 'message' => 'Token inválido'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Erro ao validar token'];
        }
    }
    
    // hCaptcha (fallback se configurado)
    if (CAPTCHA_TYPE === 'hcaptcha' && !empty(HCAPTCHA_SECRET_KEY)) {
        $ch = curl_init('https://hcaptcha.com/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => HCAPTCHA_SECRET_KEY,
                'response' => $token,
                'remoteip' => $ip ?: getClientIP()
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        $result = $resp ? json_decode($resp, true) : null;
        return ['success' => !empty($result['success'])];
    }
    
    // Se nenhum método configurado, aceitar
    return ['success' => true];
}

/**
 * Buscar jogador por google_uid
 */
function findPlayer($pdo, $identifier) {
    $uid = is_array($identifier) 
        ? ($identifier['google_uid'] ?? $identifier['googleUid'] ?? '') 
        : $identifier;
    $uid = trim($uid);
    
    if (empty($uid)) return null;
    
    // Buscar na tabela users
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    
    // Se não encontrou e UID está truncado, tentar com LIKE
    if (!$user && strpos($uid, '...') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid LIKE ? LIMIT 1");
        $stmt->execute([str_replace('...', '%', $uid)]);
        $user = $stmt->fetch();
    }
    
    return $user;
}

/**
 * Buscar jogador por ID
 */
function findPlayerById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$id]);
    return $stmt->fetch();
}

// ============================================
// CORS E HEADERS
// ============================================

function setCorsHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
        http_response_code(204); 
        exit; 
    }
}

// Alias para compatibilidade
function setCORSHeaders() { 
    setCorsHeaders(); 
}

// ============================================
// FUNÇÕES DE INPUT
// ============================================

/**
 * Ler input JSON de forma segura
 */
function getRequestInput() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    
    if (is_array($json)) {
        return array_merge($_GET, $_POST, $json);
    }
    
    return array_merge($_GET, $_POST);
}

/**
 * Obter identificador do usuário do input
 */
function getUserIdentifier($input = null) {
    if ($input === null) {
        $input = getRequestInput();
    }
    
    // Tentar google_uid primeiro
    $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '';
    $googleUid = trim($googleUid);
    
    if (!empty($googleUid) && validateGoogleUid($googleUid)) {
        return ['type' => 'google_uid', 'value' => $googleUid];
    }
    
    // Fallback para wallet (legado)
    $wallet = $input['wallet'] ?? $input['wallet_address'] ?? '';
    $wallet = trim(strtolower($wallet));
    
    if (!empty($wallet) && validateWallet($wallet)) {
        return ['type' => 'wallet', 'value' => $wallet];
    }
    
    return null;
}

// ============================================
// FORMATAR VALORES
// ============================================

/**
 * Formatar valor em BRL
 */
function formatBRL($value) {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

/**
 * Formatar valor para exibição (4 casas decimais)
 */
function formatEarnings($value) {
    return 'R$ ' . number_format((float)$value, 4, ',', '.');
}
