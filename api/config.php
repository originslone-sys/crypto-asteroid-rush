<?php
// ============================================
// UNOBIX - Configuração Principal
// api/config.php v3.0
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
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: 'YyZD3H)dndSo*A/N');
}

// FIREBASE
define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: 'unobix-oauth-a69cd');
define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U');

// CAPTCHA
$hcaptchaSecret = getenv('HCAPTCHA_SECRET_KEY');
define('CAPTCHA_ENABLED', !empty($hcaptchaSecret));
define('HCAPTCHA_SECRET_KEY', $hcaptchaSecret ?: '');
define('CAPTCHA_REQUIRED_ON_VICTORY', false);

// JOGO
define('GAME_DURATION', 180);
define('GAME_TOLERANCE', 300);
define('INITIAL_LIVES', 6);
define('MAX_MISSIONS_PER_HOUR', 5);
define('MAX_EVENTS_PER_SECOND', 10);
define('HARD_MODE_PERCENTAGE', 40);

// RECOMPENSAS BRL
define('REWARD_COMMON', 0);
define('REWARD_RARE', 0.001);
define('REWARD_EPIC', 0.005);
define('REWARD_LEGENDARY', 0.02);
define('ASTEROID_REWARDS_BRL', ['none'=>0,'common'=>0,'rare'=>0.001,'epic'=>0.005,'legendary'=>0.02]);

// SEGURANÇA
define('EARNINGS_ALERT_BRL', 0.10);
define('EARNINGS_SUSPECT_BRL', 0.15);
define('EARNINGS_BLOCK_BRL', 0.20);

// ============================================
// FUNÇÕES
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

function getDBConnection() { return getDatabaseConnection(); }

function validateGoogleUid($uid) {
    if (empty($uid) || !is_string($uid)) return false;
    $uid = trim($uid);
    if (strpos($uid, '...') !== false) return strlen($uid) >= 10;
    return strlen($uid) >= 21 && strlen($uid) <= 128 && preg_match('/^[a-zA-Z0-9_-]+$/', $uid);
}

function getClientIP() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = $_SERVER[$h];
            if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function secureLog($msg) {
    error_log("[" . date('Y-m-d H:i:s') . "] " . getClientIP() . " | $msg");
}

function getRewardByType($type) {
    $r = ASTEROID_REWARDS_BRL;
    return $r[strtolower($type)] ?? 0;
}

function isHardModeMission() {
    return mt_rand(1, 100) <= HARD_MODE_PERCENTAGE;
}

function verifyCaptcha($token, $ip = null) {
    if (!CAPTCHA_ENABLED) return ['success' => true];
    if (empty($token)) return ['success' => false, 'message' => 'Token ausente'];
    
    $ch = curl_init('https://hcaptcha.com/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['secret'=>HCAPTCHA_SECRET_KEY,'response'=>$token,'remoteip'=>$ip?:getClientIP()]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    
    $result = $resp ? json_decode($resp, true) : null;
    return ['success' => !empty($result['success'])];
}

function findPlayer($pdo, $identifier) {
    $uid = is_array($identifier) ? ($identifier['google_uid'] ?? $identifier['googleUid'] ?? '') : $identifier;
    $uid = trim($uid);
    if (empty($uid)) return null;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    
    if (!$user && strpos($uid, '...') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid LIKE ? LIMIT 1");
        $stmt->execute([str_replace('...', '%', $uid)]);
        $user = $stmt->fetch();
    }
    
    return $user;
}

function setCorsHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}
function setCORSHeaders() { setCorsHeaders(); }
