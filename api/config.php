<?php
// ============================================
// UNOBIX - Configuração Principal
// api/config.php v5.0 - Arquitetura Segura
// ============================================

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

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
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'YyZD3H)dndSo*A/N');
}

// ============================================
// FIREBASE
// ============================================
if (!defined('FIREBASE_PROJECT_ID')) {
    define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: 'unobix-oauth-a69cd');
    define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U');
}

// ============================================
// CAPTCHA - Google reCAPTCHA v2 (checkbox)
// ============================================
if (!defined('CAPTCHA_ENABLED')) {
    define('CAPTCHA_ENABLED', true);
    define('CAPTCHA_TYPE', 'recaptcha_v2');
    define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '6LcVtmgsAAAAAIoCvMa0Ou4Y72WchB0mSdZsmBbs');
    define('RECAPTCHA_SECRET_KEY', getenv('RECAPTCHA_SECRET_KEY') ?: '6LcVtmgsAAAAAOz5dJgWRjQfEowN-iZMyiD4jTGc');
    define('CAPTCHA_REQUIRED_ON_VICTORY', true);
}

// ============================================
// JOGO - CONFIGURAÇÕES BÁSICAS
// ============================================
if (!defined('GAME_DURATION')) {
    define('GAME_DURATION', 180);           // 3 minutos
    define('GAME_TOLERANCE', 90);           // 90 segundos tolerância para resolver CAPTCHA
    define('CAPTCHA_RESEND_TOLERANCE', 60); // Tolerância extra para reenvio com CAPTCHA (ads + verificação)
    define('INITIAL_LIVES', 3);             // v2: 3 vidas para todos os modos
    define('HARD_MODE_PERCENTAGE', 90);     // Legacy: mantido para game.html original

    // v2: Configuração de modos de jogo
    define('GAME_MODES', [
        'normal'  => ['credits' => 1, 'label' => 'Normal'],
        'hard'    => ['credits' => 2, 'label' => 'Difícil'],
        'extreme' => ['credits' => 3, 'label' => 'Extreme'],
    ]);

    // v2: Modos válidos (incluindo treino para validação)
    define('VALID_GAME_MODES', ['normal', 'hard', 'extreme', 'training']);
}

// ============================================
// RECOMPENSAS BRL - VALORES
// ============================================
if (!defined('REWARD_COMMON')) {
    define('REWARD_COMMON', 0);
    define('REWARD_RARE', 0.02);
    define('REWARD_EPIC', 0.05);
    define('REWARD_LEGENDARY', 0.20);

    define('ASTEROID_REWARDS_BRL', [
        'none'      => 0,
        'common'    => 0,
        'rare'      => 0.02,
        'epic'      => 0.05,
        'legendary' => 0.20
    ]);
}

// ============================================
// LIMITES DE SEGURANÇA - ANTI-CHEAT
// ============================================
if (!defined('EARNINGS_ALERT_BRL')) {
    // v2: Apenas alerta de earnings (sem bloqueio)
    define('EARNINGS_ALERT_BRL', 10.00);    // Alerta no admin se > R$10.00
    define('EARNINGS_SUSPECT_BRL', 999.00); // Desativado
    define('EARNINGS_BLOCK_BRL', 999.00);   // Desativado

    // Limites absolutos por partida (permissivos - sem anti-cheat rígido)
    define('MAX_ASTEROIDS_PER_GAME', 9999);
    define('MAX_LEGENDARY_PER_GAME', 9999);
    define('MAX_EPIC_PER_GAME', 9999);
    define('MAX_RARE_PER_GAME', 9999);

    // Proporções máximas (desativadas)
    define('MAX_LEGENDARY_PERCENT', 1.0);
    define('MAX_EPIC_PERCENT', 1.0);
    define('MAX_RARE_PERCENT', 1.0);


    // Velocidade de jogo
    define('MAX_ASTEROIDS_PER_SECOND', 999);
    define('MIN_GAME_DURATION_SECONDS', 60);
}

// ============================================
// SAQUES
// ============================================
if (!defined('MIN_WITHDRAW_BRL')) {
    define('MIN_WITHDRAW_BRL', 20.00);
    define('WITHDRAW_METHODS', ['pix']);
    define('WITHDRAW_COOLDOWN_HOURS', 24); // 1 saque por dia
}

// ============================================
// CRÉDITOS - SISTEMA DE CRÉDITOS PARA JOGAR
// ============================================
if (!defined('CREDITS_PER_GAME')) {
    define('CREDITS_PER_GAME', 1); // 1 crédito por partida
    define('WELCOME_BONUS_CREDITS', 2); // Bônus de boas-vindas para novos usuários
}

// ============================================
// PVP - CONFIGURAÇÕES DO MODO PVP
// ============================================
if (!defined('PVP_ENTRY_FEE_CREDITS')) {
    define('PVP_ENTRY_FEE_CREDITS', 2);       // Custo para entrar numa partida PvP
    define('PVP_WINNER_PRIZE_CREDITS', 3);     // Prêmio do vencedor
    define('PVP_GAME_DURATION', 180);          // Duração da partida PvP (segundos)
    define('PVP_LIVES', 6);                    // Vidas por jogador
    define('PVP_MAX_BULLETS_PER_PLAYER', 5);   // Máximo de balas simultâneas por jogador
    define('PVP_FIRE_RATE_MS', 350);           // Intervalo mínimo entre tiros (ms)
    define('PVP_BULLET_SPEED', 15);            // Velocidade das balas (px/frame)
    define('PVP_SHIP_SPEED_X', 18);            // Velocidade horizontal da nave
    define('PVP_SHIP_SPEED_Y', 12);            // Velocidade vertical da nave
    define('PVP_ASTEROID_SPAWN_INTERVAL', 700);// Intervalo de spawn de asteroides (ms)
    define('PVP_MAX_ASTEROIDS', 10);           // Máximo de asteroides na tela
    define('PVP_MATCHMAKING_TIMEOUT', 60);     // Tempo máximo esperando oponente (segundos)
    define('PVP_COUNTDOWN_SECONDS', 3);        // Countdown antes da partida
    define('PVP_DISCONNECT_TIMEOUT', 10);      // Tempo para considerar desconexão (segundos)
    define('PVP_INVINCIBILITY_FRAMES', 60);    // Frames de invencibilidade pós-dano
    define('PVP_JWT_SECRET', getenv('PVP_JWT_SECRET') ?: 'pvp_secret_key_change_in_production_2025');
    define('PVP_GAME_SERVER_INTERNAL_URL', getenv('PVP_GAME_SERVER_URL') ?: 'http://10.0.0.2:3000'); // IP interno VPC
}

// ============================================
// ZETTPAY - GATEWAY PIX
// ============================================
if (!defined('ZETTPAY_BASE_URL')) {
    define('ZETTPAY_BASE_URL', getenv('ZETTPAY_BASE_URL') ?: 'https://api.zettpay.io/api');
    define('ZETTPAY_AUTH_URL', getenv('ZETTPAY_AUTH_URL') ?: 'https://api.zettpay.io/api/oauth/token');
    define('ZETTPAY_CLIENT_ID', getenv('ZETTPAY_CLIENT_ID') ?: 'clt_ev1pwgo5nhgy4yv5ydajnghf');
    define('ZETTPAY_CLIENT_SECRET', getenv('ZETTPAY_CLIENT_SECRET') ?: 'sec_SfcnrJKTF2TIDgcFBmNVXIPPfhJ6262Y6mV3tXBSWxax5Rav');
    define('MIN_DEPOSIT_BRL', 1.00);
    define('MAX_DEPOSIT_BRL', 500.00);
}

// Token para cron de reconciliação
if (!defined('RECONCILE_CRON_TOKEN')) {
    define('RECONCILE_CRON_TOKEN', getenv('RECONCILE_CRON_TOKEN') ?: 'rcn_unobix_7f3a9c2e1b5d8k4m');
}

// ============================================
// STAKING (DESCONTINUADO — constantes mantidas para compatibilidade com migração)
// ============================================
if (!defined('STAKE_APY')) {
    define('STAKE_APY', 0.05);
    define('MIN_STAKE_BRL', 0.01);
    define('MAX_STAKE_BRL', 1000.00);
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
                $pdo->exec("SET time_zone = '-03:00'");
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
// GARANTIR ÍNDICES CRÍTICOS (migrado para migrate.php)
// ============================================

if (!function_exists('ensureCriticalIndexes')) {
    function ensureCriticalIndexes($pdo) {
        // Índices agora são criados via migrate.php no deploy
        // Esta função é mantida para compatibilidade mas não faz mais DDL
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
                $phoneDigits = preg_replace('/\D/', '', $key);
                return preg_match('/^\d{10,13}$/', $phoneDigits);
            case 'evp':
            case 'aleatoria':
                return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key);
            default:
                return strlen($key) >= 5 && strlen($key) <= 100;
        }
    }
}

if (!function_exists('detectPixKeyType')) {
    /**
     * Detecta automaticamente o tipo de chave PIX
     * @param string $key Chave PIX
     * @return string cpf|cnpj|email|phone|evp
     */
    function detectPixKeyType($key) {
        $key = trim($key);
        $digits = preg_replace('/\D/', '', $key);

        // Email
        if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        // CPF (11 dígitos)
        if (preg_match('/^\d{11}$/', $digits) || preg_match('/^\d{3}\.\d{3}\.\d{3}-\d{2}$/', $key)) {
            return 'cpf';
        }
        // CNPJ (14 dígitos)
        if (preg_match('/^\d{14}$/', $digits) || preg_match('/^\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}$/', $key)) {
            return 'cnpj';
        }
        // Telefone (+55...)
        if (preg_match('/^\+?\d{10,13}$/', $digits)) {
            return 'phone';
        }
        // Chave aleatória (UUID format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return 'evp';
        }
        // Fallback
        return 'cpf';
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

if (!function_exists('getGameModeCredits')) {
    /**
     * v2: Retorna custo em créditos para um modo de jogo
     */
    function getGameModeCredits($mode) {
        $modes = GAME_MODES;
        return $modes[$mode]['credits'] ?? 1;
    }
}

if (!function_exists('isValidGameMode')) {
    /**
     * v2: Valida se o modo de jogo é válido
     */
    function isValidGameMode($mode) {
        return in_array($mode, VALID_GAME_MODES);
    }
}

if (!function_exists('verifyCaptcha')) {
    /**
     * Verificar token CAPTCHA
     * Suporta reCAPTCHA v2 (principal) e math (legacy fallback)
     */
    function verifyCaptcha($token, $ip = null) {
        if (!CAPTCHA_ENABLED) return ['success' => true];
        if (empty($token)) return ['success' => false, 'message' => 'Token CAPTCHA ausente'];

        if (CAPTCHA_TYPE === 'recaptcha_v2') {
            return verifyRecaptchaV2($token, $ip);
        }

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

if (!function_exists('verifyRecaptchaV2')) {
    /**
     * Verificar token reCAPTCHA v2 com API Google
     */
    function verifyRecaptchaV2($token, $ip = null) {
        if (empty(RECAPTCHA_SECRET_KEY)) {
            secureLog("RECAPTCHA_ERROR | Secret key não configurada");
            return ['success' => true, 'message' => 'reCAPTCHA não configurado'];
        }

        $postData = [
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $token
        ];
        if ($ip) $postData['remoteip'] = $ip;

        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($postData),
                'timeout' => 10
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);

        if ($result === false) {
            secureLog("RECAPTCHA_ERROR | Falha ao contatar API Google");
            return ['success' => true, 'message' => 'Erro de comunicação com reCAPTCHA'];
        }

        $response = json_decode($result, true);

        if (!$response || empty($response['success'])) {
            $errorCodes = $response['error-codes'] ?? [];
            secureLog("RECAPTCHA_FAIL | Errors: " . implode(', ', $errorCodes));
            return ['success' => false, 'message' => 'Verificação reCAPTCHA falhou', 'errors' => $errorCodes];
        }

        return ['success' => true, 'message' => 'OK'];
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
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Game-Token');
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

// Carregar sistema de métricas (auto-registra shutdown function)
if (!defined('METRICS_LOADED')) {
    define('METRICS_LOADED', true);
    require_once __DIR__ . '/metrics.php';
}

// Garantir índices críticos (1x por hora, após primeira conexão)
register_shutdown_function(function() {
    try {
        $pdo = getDatabaseConnection();
        if ($pdo) ensureCriticalIndexes($pdo);
    } catch (Exception $e) {}
});

if (!function_exists('formatBRL')) {
    function formatBRL($value) {
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }
}

if (!function_exists('formatEarnings')) {
    function formatEarnings($value) {
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }
}

// Constante de precisão decimal padrão (6 casas)
if (!defined('BRL_PRECISION')) {
    define('BRL_PRECISION', 6);
}
