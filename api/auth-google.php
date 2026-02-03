<?php
// ============================================
// UNOBIX - Autenticação Google
// api/auth-google.php v3.0
// ============================================

require_once __DIR__ . "/config.php";
setCorsHeaders();

$input = array_merge($_GET, $_POST, json_decode(file_get_contents('php://input'), true) ?: []);
$action = $input['action'] ?? 'login';

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }
    
    switch ($action) {
        case 'verify':
        case 'login':
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');
            $email = $input['email'] ?? null;
            $displayName = $input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? null;
            $photoUrl = $input['photo_url'] ?? $input['photoURL'] ?? null;
            
            if (empty($googleUid) || !validateGoogleUid($googleUid)) {
                echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
                exit;
            }
            
            // Upsert
            $pdo->prepare("
                INSERT INTO users (google_uid, email, display_name, balance_brl, total_played, total_earned_brl, created_at, updated_at, last_login)
                VALUES (?, ?, ?, 0, 0, 0, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    email = COALESCE(VALUES(email), email),
                    display_name = COALESCE(VALUES(display_name), display_name),
                    last_login = NOW(),
                    updated_at = NOW()
            ")->execute([$googleUid, $email, $displayName]);
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Falha ao carregar usuário']);
                exit;
            }
            
            if (!empty($user['is_banned'])) {
                echo json_encode(['success' => false, 'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? '')]);
                exit;
            }
            
            $sessionToken = hash('sha256', $googleUid . '|' . $user['id'] . '|' . microtime(true) . '|' . bin2hex(random_bytes(16)));
            
            secureLog("AUTH_LOGIN | User: " . $user['id'] . " | UID: " . substr($googleUid, 0, 15));
            
            echo json_encode([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'session_token' => $sessionToken,
                'user' => [
                    'id' => (int)$user['id'],
                    'google_uid' => $googleUid,
                    'email' => $user['email'] ?? '',
                    'display_name' => $user['display_name'] ?? '',
                    'balance_brl' => (float)($user['balance_brl'] ?? 0),
                    'total_played' => (int)($user['total_played'] ?? 0),
                    'total_earned_brl' => (float)($user['total_earned_brl'] ?? 0),
                    'created_at' => $user['created_at'] ?? '',
                    'last_login' => $user['last_login'] ?? ''
                ]
            ]);
            break;
            
        case 'logout':
            echo json_encode(['success' => true, 'message' => 'Logout realizado']);
            break;
            
        case 'profile':
        case 'balance':
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? '');
            
            if (empty($googleUid)) {
                echo json_encode(['success' => false, 'error' => 'google_uid necessário']);
                exit;
            }
            
            $user = findPlayer($pdo, $googleUid);
            
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
                    'balance_brl' => (float)($user['balance_brl'] ?? 0),
                    'total_played' => (int)($user['total_played'] ?? 0),
                    'total_earned_brl' => (float)($user['total_earned_brl'] ?? 0)
                ],
                'balance_brl' => (float)($user['balance_brl'] ?? 0)
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    
} catch (Throwable $e) {
    error_log("❌ AUTH-GOOGLE ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
