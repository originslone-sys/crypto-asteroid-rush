<?php
// ============================================
// UNOBIX - Autenticação Firebase Server-Side
// Endpoint seguro para verificar tokens do Firebase
// ============================================

// Configuração completa para Cloud Run (mantém todas as funcionalidades)
require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Lidar com preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit();
}

// Ler input JSON
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['firebase_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Token do Firebase não fornecido.']);
    exit();
}

$firebaseToken = trim($input['firebase_token']);

// ============================================
// VERIFICAR TOKEN NO FIREBASE
// ============================================
function verifyFirebaseToken($idToken) {
    $url = "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=" . FIREBASE_API_KEY;
    
    $data = ['idToken' => $idToken];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("❌ Firebase token verification failed: HTTP $httpCode - $response");
        return null;
    }
    
    $result = json_decode($response, true);
    
    if (empty($result['users'][0])) {
        error_log("❌ Firebase token verification failed: No user data");
        return null;
    }
    
    $user = $result['users'][0];
    
    return [
        'uid' => $user['localId'],
        'email' => $user['email'] ?? '',
        'email_verified' => $user['emailVerified'] ?? false,
        'name' => $user['displayName'] ?? '',
        'photo_url' => $user['photoUrl'] ?? '',
        'provider' => $user['providerId'] ?? 'google.com'
    ];
}

// ============================================
// PROCESSAR AUTENTICAÇÃO
// ============================================
try {
    // Verificar token no Firebase
    $firebaseUser = verifyFirebaseToken($firebaseToken);
    
    if (!$firebaseUser) {
        http_response_code(401);
        echo json_encode(['error' => 'Token do Firebase inválido ou expirado.']);
        exit();
    }
    
    // Conectar ao banco de dados
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // Verificar se usuário já existe
    $stmt = $pdo->prepare("
        SELECT user_id, google_uid, email, name, photo_url, balance, created_at 
        FROM users 
        WHERE google_uid = ?
    ");
    $stmt->execute([$firebaseUser['uid']]);
    $existingUser = $stmt->fetch();
    
    $userId = null;
    $isNewUser = false;
    
    if ($existingUser) {
        // Usuário existente - atualizar informações se necessário
        $userId = $existingUser['user_id'];
        
        $updateStmt = $pdo->prepare("
            UPDATE users 
            SET email = ?, name = ?, photo_url = ?, last_login = NOW() 
            WHERE user_id = ?
        ");
        $updateStmt->execute([
            $firebaseUser['email'],
            $firebaseUser['name'],
            $firebaseUser['photo_url'],
            $userId
        ]);
    } else {
        // Novo usuário - criar registro
        $insertStmt = $pdo->prepare("
            INSERT INTO users (
                google_uid, email, name, photo_url, 
                balance, created_at, last_login
            ) VALUES (?, ?, ?, ?, 0.00, NOW(), NOW())
        ");
        $insertStmt->execute([
            $firebaseUser['uid'],
            $firebaseUser['email'],
            $firebaseUser['name'],
            $firebaseUser['photo_url']
        ]);
        
        $userId = $pdo->lastInsertId();
        $isNewUser = true;
        
        // Criar carteira inicial
        $walletStmt = $pdo->prepare("
            INSERT INTO wallets (user_id, balance, created_at) 
            VALUES (?, 0.00, NOW())
        ");
        $walletStmt->execute([$userId]);
    }
    
    // Gerar session token seguro
    $sessionToken = bin2hex(random_bytes(32));
    $sessionId = session_id();
    
    // Salvar sessão no banco
    $sessionStmt = $pdo->prepare("
        INSERT INTO user_sessions (
            user_id, session_id, session_token, google_uid, 
            ip_address, user_agent, created_at, expires_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
        ON DUPLICATE KEY UPDATE
            session_token = VALUES(session_token),
            expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
    ");
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $sessionStmt->execute([
        $userId,
        $sessionId,
        $sessionToken,
        $firebaseUser['uid'],
        $ipAddress,
        $userAgent
    ]);
    
    // Retornar resposta de sucesso
    echo json_encode([
        'success' => true,
        'user' => [
            'user_id' => $userId,
            'google_uid' => $firebaseUser['uid'],
            'email' => $firebaseUser['email'],
            'name' => $firebaseUser['name'],
            'photo_url' => $firebaseUser['photo_url'],
            'is_new_user' => $isNewUser
        ],
        'session' => [
            'session_id' => $sessionId,
            'session_token' => $sessionToken,
            'expires_in' => 604800 // 7 dias em segundos
        ]
    ]);
    
} catch (Exception $e) {
    error_log("❌ Erro na autenticação Firebase: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor na autenticação.']);
    exit();
}
?>