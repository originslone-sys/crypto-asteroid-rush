<?php
// ============================================
// UNOBIX - Autenticação Google v3.1 (CORRIGIDO)
// api/auth-google.php
// ============================================

// Desabilitar qualquer output antes do JSON
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . "/config.php";

// Limpar qualquer output anterior
ob_clean();

// Headers CORS e JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Função para retornar JSON e sair
function jsonResponse($data, $statusCode = 200) {
    ob_clean(); // Limpar buffer novamente
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Função para log seguro
function logError($message) {
    error_log("[AUTH-GOOGLE] " . date('Y-m-d H:i:s') . " - " . $message);
}

try {
    // Obter input
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!is_array($input)) {
        $input = array_merge($_GET, $_POST);
    }
    
    $action = $input['action'] ?? 'login';
    
    // Conectar ao banco
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        logError("Falha na conexão com banco de dados");
        jsonResponse([
            'success' => false,
            'error' => 'Erro de conexão com banco de dados'
        ], 500);
    }
    
    // Processar ações
    switch ($action) {
        case 'verify':
        case 'login':
            // Obter dados do usuário
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');
            $email = trim($input['email'] ?? '');
            $displayName = trim($input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? '');
            $photoUrl = trim($input['photo_url'] ?? $input['photoURL'] ?? '');
            
            // Validar google_uid
            if (empty($googleUid)) {
                jsonResponse([
                    'success' => false,
                    'error' => 'google_uid é obrigatório'
                ], 400);
            }
            
            if (!validateGoogleUid($googleUid)) {
                jsonResponse([
                    'success' => false,
                    'error' => 'google_uid inválido'
                ], 400);
            }
            
            // Upsert no banco
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        google_uid, email, display_name, photo_url,
                        balance_brl, total_played, total_earned_brl,
                        created_at, updated_at, last_login
                    ) VALUES (?, ?, ?, ?, 0, 0, 0, NOW(), NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        email = COALESCE(VALUES(email), email),
                        display_name = COALESCE(VALUES(display_name), display_name),
                        photo_url = COALESCE(VALUES(photo_url), photo_url),
                        last_login = NOW(),
                        updated_at = NOW()
                ");
                
                $stmt->execute([$googleUid, $email, $displayName, $photoUrl]);
                
            } catch (PDOException $e) {
                logError("Erro no upsert: " . $e->getMessage());
                jsonResponse([
                    'success' => false,
                    'error' => 'Erro ao processar dados do usuário'
                ], 500);
            }
            
            // Buscar usuário atualizado
            $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                logError("Usuário não encontrado após insert: " . $googleUid);
                jsonResponse([
                    'success' => false,
                    'error' => 'Falha ao carregar dados do usuário'
                ], 500);
            }
            
            // Verificar se usuário está banido
            if (!empty($user['is_banned'])) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violação dos termos de uso')
                ], 403);
            }
            
            // Gerar session token
            $sessionToken = hash('sha256', 
                $googleUid . '|' . 
                $user['id'] . '|' . 
                microtime(true) . '|' . 
                bin2hex(random_bytes(16))
            );
            
            // Log de login
            logError("Login bem-sucedido - User ID: " . $user['id'] . " | UID: " . substr($googleUid, 0, 15) . "...");
            
            // Retornar sucesso
            jsonResponse([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'session_token' => $sessionToken,
                'user' => [
                    'id' => (int)$user['id'],
                    'google_uid' => $googleUid,
                    'email' => $user['email'] ?? '',
                    'display_name' => $user['display_name'] ?? '',
                    'photo_url' => $user['photo_url'] ?? '',
                    'balance_brl' => (float)($user['balance_brl'] ?? 0),
                    'total_played' => (int)($user['total_played'] ?? 0),
                    'total_earned_brl' => (float)($user['total_earned_brl'] ?? 0),
                    'created_at' => $user['created_at'] ?? '',
                    'last_login' => $user['last_login'] ?? ''
                ]
            ]);
            break;
            
        case 'logout':
            jsonResponse([
                'success' => true,
                'message' => 'Logout realizado'
            ]);
            break;
            
        case 'profile':
        case 'balance':
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? '');
            
            if (empty($googleUid)) {
                jsonResponse([
                    'success' => false,
                    'error' => 'google_uid necessário'
                ], 400);
            }
            
            $user = findPlayer($pdo, $googleUid);
            
            if (!$user) {
                jsonResponse([
                    'success' => false,
                    'error' => 'Usuário não encontrado'
                ], 404);
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
            jsonResponse([
                'success' => false,
                'error' => 'Ação inválida: ' . $action
            ], 400);
    }
    
} catch (PDOException $e) {
    logError("Erro de banco: " . $e->getMessage());
    jsonResponse([
        'success' => false,
        'error' => 'Erro no banco de dados'
    ], 500);
    
} catch (Throwable $e) {
    logError("Erro geral: " . $e->getMessage());
    jsonResponse([
        'success' => false,
        'error' => 'Erro interno do servidor'
    ], 500);
}

// Não deve chegar aqui, mas por segurança
jsonResponse([
    'success' => false,
    'error' => 'Resposta inválida'
], 500);
