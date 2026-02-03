<?php
// ============================================
// UNOBIX - Autenticação Google v5.0 LIMPO
// api/auth-google.php
// Usa funções existentes do config.php
// ============================================

require_once __DIR__ . "/config.php";

// Usar função do config.php para CORS
setCorsHeaders();

// Função para retornar JSON limpo
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Processar input
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = array_merge($_GET, $_POST);
    }
    
    $action = $input['action'] ?? 'login';
    
    // Conectar ao banco usando função do config.php
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        jsonResponse(['success' => false, 'error' => 'Erro de conexão com banco'], 500);
    }
    
    // PROCESSAR AÇÕES
    switch ($action) {
        case 'verify':
        case 'login':
            // Obter dados
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');
            $email = trim($input['email'] ?? '');
            $displayName = trim($input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? '');
            
            // Validar google_uid
            if (empty($googleUid)) {
                jsonResponse(['success' => false, 'error' => 'google_uid obrigatório'], 400);
            }
            
            // Usar função do config.php se existir
            if (function_exists('validateGoogleUid') && !validateGoogleUid($googleUid)) {
                jsonResponse(['success' => false, 'error' => 'google_uid inválido'], 400);
            }
            
            // Limitar tamanho
            $googleUid = substr($googleUid, 0, 128);
            $email = substr($email, 0, 255);
            $displayName = substr($displayName, 0, 100);
            
            // UPSERT - estrutura da sua tabela
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    google_uid, 
                    email, 
                    display_name, 
                    balance_brl, 
                    total_played, 
                    total_earned_brl,
                    created_at, 
                    updated_at, 
                    last_login
                ) VALUES (?, ?, ?, 0, 0, 0, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    email = COALESCE(VALUES(email), email),
                    display_name = COALESCE(VALUES(display_name), display_name),
                    last_login = NOW(),
                    updated_at = NOW()
            ");
            
            $stmt->execute([$googleUid, $email, $displayName]);
            
            // Buscar usuário
            $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                jsonResponse(['success' => false, 'error' => 'Usuário não encontrado'], 500);
            }
            
            // Gerar session token
            $sessionToken = hash('sha256', 
                $googleUid . '|' . 
                $user['id'] . '|' . 
                microtime(true) . '|' . 
                bin2hex(random_bytes(16))
            );
            
            // Log usando função do config.php
            if (function_exists('secureLog')) {
                secureLog("LOGIN | User: {$user['id']} | UID: " . substr($googleUid, 0, 15));
            }
            
            // Retornar dados
            jsonResponse([
                'success' => true,
                'message' => 'Login realizado com sucesso',
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
                    'referral_code' => $user['referral_code'] ?? null,
                    'created_at' => $user['created_at'] ?? '',
                    'last_login' => $user['last_login'] ?? ''
                ]
            ]);
            break;
            
        case 'logout':
            jsonResponse(['success' => true, 'message' => 'Logout realizado']);
            break;
            
        case 'profile':
        case 'balance':
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? '');
            
            if (empty($googleUid)) {
                jsonResponse(['success' => false, 'error' => 'google_uid necessário'], 400);
            }
            
            // Usar função do config.php se existir
            $user = null;
            if (function_exists('findPlayer')) {
                $user = findPlayer($pdo, $googleUid);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
                $stmt->execute([$googleUid]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$user) {
                jsonResponse(['success' => false, 'error' => 'Usuário não encontrado'], 404);
            }
            
            jsonResponse([
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
            jsonResponse(['success' => false, 'error' => 'Ação inválida: ' . $action], 400);
    }
    
} catch (PDOException $e) {
    if (function_exists('secureLog')) {
        secureLog("AUTH ERROR: " . $e->getMessage());
    }
    jsonResponse(['success' => false, 'error' => 'Erro no banco de dados'], 500);
    
} catch (Throwable $e) {
    if (function_exists('secureLog')) {
        secureLog("AUTH EXCEPTION: " . $e->getMessage());
    }
    jsonResponse(['success' => false, 'error' => 'Erro interno'], 500);
}
