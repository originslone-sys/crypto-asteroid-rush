<?php
// ============================================
// UNOBIX - Configuração Principal
// api/config.php v5.0 - Arquitetura Segura
// ============================================

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// ============================================
// BANCO DE DADOS - CLOUD SQL
// ============================================
if (!defined('DB_HOST')) {
    $cloudSqlSocket = '/cloudsql/project-7be1cae5-5f08-45fb-aca:us-west1:unobix';
    $isCloudRun = file_exists('/cloudsql') || getenv('K_SERVICE');
    
    if ($isCloudRun) {
        define('DB_SOCKET', $cloudSqlSocket);
        define('DB_HOST', null);
        define('DB_PORT', null);
    } else {
        define('DB_SOCKET', null);
        define('DB_HOST', getenv('MYSQLHOST') ?: '127.0.0.1');
        define('DB_PORT', getenv('MYSQLPORT') ?: '3306');
    }
    
    define('DB_NAME', getenv('MYSQLDATABASE') ?: 'unobix_db');
    define('DB_USER', getenv('MYSQLUSER') ?: 'unobix_user');
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
}

// ============================================
// FIREBASE
// ============================================
if (!defined('FIREBASE_PROJECT_ID')) {
    define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: 'unobix-oauth-a69cd');
    define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: '');
}

// ============================================
// CAPTCHA
// ============================================
if (!defined('CAPTCHA_ENABLED')) {
    define('CAPTCHA_ENABLED', true);
    define('CAPTCHA_TYPE', 'math');
    define('HCAPTCHA_SECRET_KEY', getenv('HCAPTCHA_SECRET_KEY') ?: '');
    define('CAPTCHA_REQUIRED_ON_VICTORY', true);
}

// ============================================
// JOGO - CONFIGURAÇÕES BÁSICAS
// ============================================
if (!defined('GAME_DURATION')) {
    define('GAME_DURATION', 180);           // 3 minutos
    define('GAME_TOLERANCE', 30);           // 30 segundos tolerância (reduzido de 300!)
    define('INITIAL_LIVES', 6);
    define('MAX_MISSIONS_PER_HOUR', 5);
    define('HARD_MODE_PERCENTAGE', 40);
}

// ============================================
// RECOMPENSAS BRL - VALORES
// ============================================
if (!defined('REWARD_COMMON')) {
    define('REWARD_COMMON', 0);
    define('REWARD_RARE', 0.0002);
    define('REWARD_EPIC', 0.0004);
    define('REWARD_LEGENDARY', 0.001);

    define('ASTEROID_REWARDS_BRL', [
        'none'      => 0,
        'common'    => 0,
        'rare'      => 0.0002,
        'epic'      => 0.0004,
        'legendary' => 0.001
    ]);
}

// ============================================
// LIMITES DE SEGURANÇA - ANTI-CHEAT
// ============================================
if (!defined('EARNINGS_ALERT_BRL')) {
    // Limites de ganhos
    define('EARNINGS_ALERT_BRL', 0.03);     // Alerta se > R$0.03
    define('EARNINGS_SUSPECT_BRL', 0.05);   // Suspeito se > R$0.05
    define('EARNINGS_BLOCK_BRL', 0.08);     // Bloqueia se > R$0.08
    
    // Limites absolutos por partida
    define('MAX_ASTEROIDS_PER_GAME', 400);
    define('MAX_LEGENDARY_PER_GAME', 5);
    define('MAX_EPIC_PER_GAME', 20);
    define('MAX_RARE_PER_GAME', 80);
    
    // Proporções máximas (anti-cheat)
    define('MAX_LEGENDARY_PERCENT', 0.02);  // Máximo 2% lendários
    define('MAX_EPIC_PERCENT', 0.08);       // Máximo 8% épicos
    define('MAX_RARE_PERCENT', 0.25);       // Máximo 25% raros
    
    // Velocidade de jogo
    define('MAX_ASTEROIDS_PER_SECOND', 3);
    define('MIN_GAME_DURATION_SECONDS', 120); // Mínimo 2 minutos para ser válido
}

// ============================================
// SAQUES
// ============================================
if (!defined('MIN_WITHDRAW_BRL')) {
    define('MIN_WITHDRAW_BRL', 1.00);
    define('WEEKLY_WITHDRAW_LIMIT', 1);
    define('WITHDRAW_METHODS', ['pix']);
}

// ============================================
// STAKING
// ============================================
if (!defined('STAKE_APY')) {
    define('STAKE_APY', 0.05);
    define('MIN_STAKE_BRL', 0.10);
}

// ============================================
// CONEXÃO COM BANCO DE DADOS
// ============================================

if (!function_exists('getDatabaseConnection')) {
    function getDatabaseConnection() {
        static $pdo = null;
        if ($pdo === null) {
            try {
                if (defined('DB_SOCKET') && DB_SOCKET) {
                    $dsn = "mysql:unix_socket=" . DB_SOCKET . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                } else {
                    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                }
                
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 10
                ]);
            } catch (PDOException $e) {
                error_log("❌ DB Error: " . $e->getMessage());
                return null;
            }
        }
        return $pdo;
    }
}

if (!function_exists('getDBConnection')) {
    function getDBConnection() { 
        return getDatabaseConnection(); 
    }
}

// ============================================
// FUNÇÕES DE VALIDAÇÃO
// ============================================

if (!function_exists('validateGoogleUid')) {
    function validateGoogleUid($uid) {
        if (empty($uid) || !is_string($uid)) return false;
        $uid = trim($uid);
        if (strpos($uid, '...') !== false) return strlen($uid) >= 10;
        return strlen($uid) >= 21 && strlen($uid) <= 128 && preg_match('/^[a-zA-Z0-9_-]+$/', $uid);
    }
}

if (!function_exists('validateWallet')) {
    function validateWallet($wallet) {
        if (empty($wallet) || !is_string($wallet)) return false;
        return preg_match('/^0x[a-f0-9]{40}$/', strtolower(trim($wallet)));
    }
}

if (!function_exists('validatePixKey')) {
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
            default:
                return strlen($key) >= 5 && strlen($key) <= 100;
        }
    }
}

// ============================================
// FUNÇÕES UTILITÁRIAS
// ============================================

if (!function_exists('getClientIP')) {
    function getClientIP() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = $_SERVER[$h];
                if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }
}

if (!function_exists('secureLog')) {
    function secureLog($msg) {
        error_log("[" . date('Y-m-d H:i:s') . "] " . getClientIP() . " | $msg");
    }
}

if (!function_exists('getRewardByType')) {
    function getRewardByType($type) {
        $rewards = ASTEROID_REWARDS_BRL;
        return $rewards[strtolower($type)] ?? 0;
    }
}

if (!function_exists('isHardModeMission')) {
    function isHardModeMission() {
        return mt_rand(1, 100) <= HARD_MODE_PERCENTAGE;
    }
}

if (!function_exists('verifyCaptcha')) {
    function verifyCaptcha($token, $ip = null) {
        if (!CAPTCHA_ENABLED) return ['success' => true];
        if (empty($token)) return ['success' => false, 'message' => 'Token CAPTCHA ausente'];
        
        if (CAPTCHA_TYPE === 'math') {
            try {
                $decoded = base64_decode($token);
                if (strpos($decoded, 'math_') === 0) {
                    $parts = explode('_', $decoded);
                    if (count($parts) >= 3) {
                        $timestamp = (int)end($parts);
                        if (time() - ($timestamp / 1000) < 600) return ['success' => true];
                        return ['success' => false, 'message' => 'Token expirado'];
                    }
                }
                return ['success' => false, 'message' => 'Token inválido'];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Erro ao validar token'];
            }
        }
        
        return ['success' => true];
    }
}

if (!function_exists('findPlayer')) {
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
}

if (!function_exists('findPlayerById')) {
    function findPlayerById($pdo, $id) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch();
    }
}

// ============================================
// VALIDAÇÃO ANTI-CHEAT - NOVA!
// ============================================

if (!function_exists('validateGameStats')) {
    /**
     * Valida estatísticas do jogo para detectar trapaças
     * @param array $stats [common, rare, epic, legendary]
     * @param int $gameDuration Duração em segundos
     * @param bool $isHardMode Se estava em hard mode
     * @return array [valid, warnings, errors]
     */
    function validateGameStats($stats, $gameDuration, $isHardMode = false) {
        $result = [
            'valid' => true,
            'warnings' => [],
            'errors' => [],
            'flags' => []
        ];
        
        $common = (int)($stats['common'] ?? 0);
        $rare = (int)($stats['rare'] ?? 0);
        $epic = (int)($stats['epic'] ?? 0);
        $legendary = (int)($stats['legendary'] ?? 0);
        $total = $common + $rare + $epic + $legendary;
        
        // 1. Verificar limites absolutos
        if ($legendary > MAX_LEGENDARY_PER_GAME) {
            $result['errors'][] = "Lendários excede máximo: $legendary > " . MAX_LEGENDARY_PER_GAME;
            $result['flags'][] = 'LEGENDARY_OVERFLOW';
            $result['valid'] = false;
        }
        
        if ($epic > MAX_EPIC_PER_GAME) {
            $result['errors'][] = "Épicos excede máximo: $epic > " . MAX_EPIC_PER_GAME;
            $result['flags'][] = 'EPIC_OVERFLOW';
            $result['valid'] = false;
        }
        
        if ($rare > MAX_RARE_PER_GAME) {
            $result['errors'][] = "Raros excede máximo: $rare > " . MAX_RARE_PER_GAME;
            $result['flags'][] = 'RARE_OVERFLOW';
            $result['valid'] = false;
        }
        
        if ($total > MAX_ASTEROIDS_PER_GAME) {
            $result['errors'][] = "Total de asteroides excede máximo: $total > " . MAX_ASTEROIDS_PER_GAME;
            $result['flags'][] = 'TOTAL_OVERFLOW';
            $result['valid'] = false;
        }
        
        // 2. Verificar proporções (só se tiver asteroides suficientes)
        if ($total >= 50) {
            $legendaryPercent = $legendary / $total;
            $epicPercent = $epic / $total;
            $rarePercent = $rare / $total;
            
            if ($legendaryPercent > MAX_LEGENDARY_PERCENT) {
                $result['warnings'][] = sprintf("Proporção de lendários alta: %.1f%% > %.1f%%", 
                    $legendaryPercent * 100, MAX_LEGENDARY_PERCENT * 100);
                $result['flags'][] = 'HIGH_LEGENDARY_RATIO';
            }
            
            if ($epicPercent > MAX_EPIC_PERCENT) {
                $result['warnings'][] = sprintf("Proporção de épicos alta: %.1f%% > %.1f%%", 
                    $epicPercent * 100, MAX_EPIC_PERCENT * 100);
                $result['flags'][] = 'HIGH_EPIC_RATIO';
            }
            
            if ($rarePercent > MAX_RARE_PERCENT) {
                $result['warnings'][] = sprintf("Proporção de raros alta: %.1f%% > %.1f%%", 
                    $rarePercent * 100, MAX_RARE_PERCENT * 100);
                $result['flags'][] = 'HIGH_RARE_RATIO';
            }
        }
        
        // 3. Verificar velocidade de destruição
        if ($gameDuration > 0) {
            $asteroidsPerSecond = $total / $gameDuration;
            if ($asteroidsPerSecond > MAX_ASTEROIDS_PER_SECOND) {
                $result['warnings'][] = sprintf("Velocidade suspeita: %.1f/s > %d/s", 
                    $asteroidsPerSecond, MAX_ASTEROIDS_PER_SECOND);
                $result['flags'][] = 'HIGH_SPEED';
            }
        }
        
        // 4. Verificar duração mínima
        if ($gameDuration < MIN_GAME_DURATION_SECONDS && $total > 20) {
            $result['warnings'][] = sprintf("Jogo muito curto: %ds < %ds", 
                $gameDuration, MIN_GAME_DURATION_SECONDS);
            $result['flags'][] = 'SHORT_GAME';
        }
        
        return $result;
    }
}

if (!function_exists('calculateServerEarnings')) {
    /**
     * Calcula ganhos no servidor baseado nos contadores
     * Esta é a fonte da verdade - não confia no cliente
     */
    function calculateServerEarnings($stats) {
        $rare = (int)($stats['rare'] ?? 0);
        $epic = (int)($stats['epic'] ?? 0);
        $legendary = (int)($stats['legendary'] ?? 0);
        
        // Aplicar limites de segurança
        $rare = min($rare, MAX_RARE_PER_GAME);
        $epic = min($epic, MAX_EPIC_PER_GAME);
        $legendary = min($legendary, MAX_LEGENDARY_PER_GAME);
        
        return ($rare * REWARD_RARE) + ($epic * REWARD_EPIC) + ($legendary * REWARD_LEGENDARY);
    }
}

if (!function_exists('generateSessionSeed')) {
    /**
     * Gera seed para verificação de integridade
     */
    function generateSessionSeed() {
        return bin2hex(random_bytes(16));
    }
}

if (!function_exists('verifyGameHash')) {
    /**
     * Verifica hash de integridade do jogo
     */
    function verifyGameHash($sessionToken, $seed, $stats, $clientHash) {
        $data = json_encode([
            'token' => $sessionToken,
            'seed' => $seed,
            'stats' => $stats
        ], JSON_UNESCAPED_UNICODE);
        
        $expectedHash = hash('sha256', $data);
        return hash_equals($expectedHash, $clientHash);
    }
}

// ============================================
// CORS E HEADERS
// ============================================

if (!function_exists('setCorsHeaders')) {
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
}

if (!function_exists('setCORSHeaders')) {
    function setCORSHeaders() { 
        setCorsHeaders(); 
    }
}

// ============================================
// FUNÇÕES DE INPUT
// ============================================

if (!function_exists('getRequestInput')) {
    function getRequestInput() {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        return is_array($json) ? array_merge($_GET, $_POST, $json) : array_merge($_GET, $_POST);
    }
}

if (!function_exists('getUserIdentifier')) {
    function getUserIdentifier($input = null) {
        if ($input === null) $input = getRequestInput();
        
        $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '';
        $googleUid = trim($googleUid);
        
        if (!empty($googleUid) && validateGoogleUid($googleUid)) {
            return ['type' => 'google_uid', 'value' => $googleUid];
        }
        
        return null;
    }
}

// ============================================
// FORMATAR VALORES
// ============================================

if (!function_exists('formatBRL')) {
    function formatBRL($value) {
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }
}

if (!function_exists('formatEarnings')) {
    function formatEarnings($value) {
        return 'R$ ' . number_format((float)$value, 4, ',', '.');
    }
}
