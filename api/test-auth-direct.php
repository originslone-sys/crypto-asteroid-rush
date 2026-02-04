<?php
// ============================================
// TEST AUTH DIRECT - SEM CONFIG.PHP
// Salvar como: api/test-auth-direct.php
// ============================================

// Headers primeiro
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Função local para evitar conflito
function sendResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Input
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? 'test';
    
    // Testar conexão direta
    $dsn = "mysql:host=34.168.76.127;port=3306;dbname=unobix_db;charset=utf8mb4";
    $pdo = new PDO($dsn, 'unobix_user', 'YyZD3H)dndSo*A/N', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    if ($action === 'test') {
        sendResponse([
            'success' => true,
            'message' => 'Conexão OK - teste direto sem config.php',
            'server_time' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION
        ]);
    }
    
    if ($action === 'login') {
        $googleUid = trim($input['google_uid'] ?? '');
        $email = trim($input['email'] ?? '');
        $displayName = trim($input['display_name'] ?? '');
        
        if (empty($googleUid)) {
            sendResponse(['success' => false, 'error' => 'google_uid obrigatório'], 400);
        }
        
        // UPSERT
        $stmt = $pdo->prepare("
            INSERT INTO users (
                google_uid, email, display_name, 
                balance_brl, total_played, total_earned_brl,
                created_at, updated_at, last_login
            ) VALUES (?, ?, ?, 0, 0, 0, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                email = COALESCE(VALUES(email), email),
                display_name = COALESCE(VALUES(display_name), display_name),
                last_login = NOW(),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            substr($googleUid, 0, 128),
            substr($email, 0, 255),
            substr($displayName, 0, 100)
        ]);
        
        // Buscar
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $user = $stmt->fetch();
        
        if (!$user) {
            sendResponse(['success' => false, 'error' => 'Usuário não encontrado'], 500);
        }
        
        // Session token
        $sessionToken = hash('sha256', $googleUid . '|' . $user['id'] . '|' . microtime(true) . '|' . bin2hex(random_bytes(16)));
        
        sendResponse([
            'success' => true,
            'message' => 'Login OK - via teste direto',
            'session_token' => $sessionToken,
            'user' => [
                'id' => (int)$user['id'],
                'google_uid' => $user['google_uid'],
                'email' => $user['email'] ?? '',
                'display_name' => $user['display_name'] ?? '',
                'wallet_address' => $user['wallet_address'] ?? null,
                'balance_brl' => (float)($user['balance_brl'] ?? 0),
                'total_played' => (int)($user['total_played'] ?? 0),
                'total_earned_brl' => (float)($user['total_earned_brl'] ?? 0),
                'is_admin' => (bool)($user['is_admin'] ?? false),
                'referral_code' => $user['referral_code'] ?? null
            ]
        ]);
    }
    
} catch (Exception $e) {
    sendResponse([
        'success' => false,
        'error' => 'Erro: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}
