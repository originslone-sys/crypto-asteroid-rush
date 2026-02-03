<?php
// ============================================
// UNOBIX - Autenticação Google (Firebase)
// Arquivo: api/auth-google.php
// Unobix-only: google_uid como identidade, sem wallet placeholder
// ============================================

// Configuração completa para Cloud Run (mantém todas as funcionalidades)
require_once __DIR__ . "/config-cloudrun.php";

ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function readJsonInput(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
    return [];
}

$input = array_merge($_GET, $_POST, readJsonInput());
$action = $input['action'] ?? 'login';

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        error_log('❌ auth-google.php: getDatabaseConnection() retornou null');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Falha na conexão com o banco']);
        exit;
    }
    
    // Log para debug
    error_log('✅ auth-google.php: Conexão com banco estabelecida');
    error_log('   DB_HOST: ' . (defined('DB_HOST') ? DB_HOST : 'não definido'));
    error_log('   DB_NAME: ' . (defined('DB_NAME') ? DB_NAME : 'não definido'));
    error_log('   DB_USER: ' . (defined('DB_USER') ? DB_USER : 'não definido'));

    // user_sessions já existe no seu banco com schema diferente
    // Schema real: user_id (não google_uid), sem firebase_token, sem is_active
    // Não recriamos para evitar conflitos com schema existente
    error_log('ℹ️ Tabela user_sessions existe com schema: user_id, session_token, ip_address, user_agent, created_at, last_activity, expires_at');

    switch ($action) {
        case 'verify':
        case 'login': {
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? null;
            $email = $input['email'] ?? null;
            $displayName = $input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? null;
            // photo_url removido - não mais utilizado
            $firebaseToken = $input['firebase_token'] ?? $input['idToken'] ?? null;

            $googleUid = is_string($googleUid) ? trim($googleUid) : '';
            if (!$googleUid || !validateGoogleUid($googleUid)) {
                echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
                exit;
            }

            // upsert user (usando nossa tabela 'users' do schema - SEM photo_url)
            $stmt = $pdo->prepare("
                INSERT INTO users (google_uid, email, display_name, balance_brl, total_played, total_earned_brl, created_at, updated_at, last_login)
                VALUES (?, ?, ?, 0.00, 0, 0.00, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    email = COALESCE(VALUES(email), email),
                    display_name = COALESCE(VALUES(display_name), display_name),
                    last_login = NOW(),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $googleUid,
                $email ?: null,
                $displayName ?: null,
            ]);

            // buscar user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Falha ao carregar usuário']);
                exit;
            }

            // Verificar se usuário está ativo (nossa tabela users tem 'is_banned')
            if (isset($user['is_banned']) && $user['is_banned']) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Conta suspensa',
                    'message' => $user['ban_reason'] ?? 'Entre em contato com o suporte.'
                ]);
                exit;
            }

            // criar sessão (usando user_id conforme schema real)
            $sessionToken = hash('sha256', $googleUid . '|' . ($user['id'] ?? 0) . '|' . microtime(true) . '|' . bin2hex(random_bytes(16)));

            $stmt = $pdo->prepare("
                INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ");
            $stmt->execute([
                $user['id'],  // user_id em vez de google_uid
                $sessionToken,
                getClientIP(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'session_token' => $sessionToken,
                'user' => [
                    'id' => (int)$user['id'],
                    'google_uid' => $googleUid,
                    'email' => $user['email'] ?? '',
                    'display_name' => $user['display_name'] ?? '',
                    'balance_brl' => number_format((float)($user['balance_brl'] ?? 0), 2, '.', ''),
                    'total_played' => (int)($user['total_played'] ?? 0),
                    'total_earned_brl' => number_format((float)($user['total_earned_brl'] ?? 0), 2, '.', ''),
                    'total_withdrawn_brl' => number_format((float)($user['total_withdrawn_brl'] ?? 0), 2, '.', ''),
                    'created_at' => $user['created_at'] ?? '',
                    'last_login' => $user['last_login'] ?? ''
                ]
            ]);
            break;
        }

        case 'logout': {
            $sessionToken = $input['session_token'] ?? $input['sessionToken'] ?? null;
            $sessionToken = is_string($sessionToken) ? trim($sessionToken) : '';

            if ($sessionToken) {
                $pdo->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ?")->execute([$sessionToken]);
            }

            echo json_encode(['success' => true, 'message' => 'Logout realizado com sucesso']);
            break;
        }

        case 'profile': {
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;
            $googleUid = is_string($googleUid) ? trim($googleUid) : '';

            if (!$googleUid || !validateGoogleUid($googleUid)) {
                echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'user' => [
                    'google_uid' => $user['google_uid'],
                    'email' => $user['email'] ?? '',
                    'display_name' => $user['display_name'] ?? '',
                    'balance_brl' => number_format((float)($user['balance_brl'] ?? 0), 2, '.', ''),
                    'total_played' => (int)($user['total_played'] ?? 0),
                    'total_earned_brl' => number_format((float)($user['total_earned_brl'] ?? 0), 2, '.', ''),
                    'total_withdrawn_brl' => number_format((float)($user['total_withdrawn_brl'] ?? 0), 2, '.', ''),
                    'created_at' => $user['created_at'] ?? '',
                    'last_login' => $user['last_login'] ?? ''
                ]
            ]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }

} catch (Throwable $e) {
    error_log("❌ auth-google.php ERROR: " . $e->getMessage());
    error_log("❌ Stack trace: " . $e->getTraceAsString());
    error_log("❌ File: " . $e->getFile() . " Line: " . $e->getLine());
    
    if (function_exists('secureLog')) {
        secureLog("AUTH_GOOGLE_ERROR | " . $e->getMessage());
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Erro de configuração de autenticação',
        'debug' => (getenv('APP_ENV') !== 'production') ? $e->getMessage() : null
    ]);
}
