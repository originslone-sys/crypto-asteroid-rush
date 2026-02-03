<?php
// ============================================
// UNOBIX - Autenticação Google v4.0 FINAL
// api/auth-google.php
// Adaptado para tabela users existente
// ============================================

// CRÍTICO: Desabilitar outputs de erro
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
@error_reporting(0);

// Limpar buffer
while (@ob_get_level()) {
    @ob_end_clean();
}
@ob_start();

// Headers CORS
@header('Content-Type: application/json; charset=utf-8', true);
@header('Access-Control-Allow-Origin: *', true);
@header('Access-Control-Allow-Methods: GET, POST, OPTIONS', true);
@header('Access-Control-Allow-Headers: Content-Type, Authorization', true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    @http_response_code(204);
    exit(0);
}

// Função para enviar JSON
function sendJson($data, $statusCode = 200) {
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    @http_response_code($statusCode);
    @header('Content-Type: application/json; charset=utf-8', true);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit(0);
}

// Log de debug (comentar em produção)
function logDebug($msg) {
    @file_put_contents(__DIR__ . '/auth-debug.log', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

// Capturar erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logDebug("FATAL: {$error['message']} in {$error['file']}:{$error['line']}");
        sendJson([
            'success' => false,
            'error' => 'Erro fatal no servidor',
            'debug' => $error['message']
        ], 500);
    }
});

try {
    logDebug("=== INÍCIO DA REQUISIÇÃO ===");
    
    // Carregar config
    $configPath = __DIR__ . "/config.php";
    if (!file_exists($configPath)) {
        logDebug("ERRO: config.php não encontrado em " . __DIR__);
        sendJson(['success' => false, 'error' => 'Configuração não encontrada'], 500);
    }
    
    require_once $configPath;
    logDebug("Config carregado");
    
    // Processar input
    $rawInput = @file_get_contents('php://input');
    $input = @json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = array_merge($_GET ?? [], $_POST ?? []);
    }
    
    $action = $input['action'] ?? 'login';
    logDebug("Action: $action");
    
    // Conectar banco - TENTAR TODAS AS OPÇÕES
    $pdo = null;
    
    // Opção 1: getDatabaseConnection (se existir)
    if (function_exists('getDatabaseConnection')) {
        $pdo = getDatabaseConnection();
        logDebug("Usando getDatabaseConnection");
    }
    
    // Opção 2: getDBConnection (se existir)
    if (!$pdo && function_exists('getDBConnection')) {
        $pdo = getDBConnection();
        logDebug("Usando getDBConnection");
    }
    
    // Opção 3: Conexão direta
    if (!$pdo) {
        logDebug("Tentando conexão direta...");
        
        // Verificar se constantes existem
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
            logDebug("ERRO: Constantes de DB não definidas");
            sendJson(['success' => false, 'error' => 'Configuração de banco incompleta'], 500);
        }
        
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . (defined('DB_PORT') ? DB_PORT : '3306') . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            logDebug("DSN: $dsn");
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            logDebug("Conexão direta estabelecida");
        } catch (PDOException $e) {
            logDebug("ERRO na conexão: " . $e->getMessage());
            sendJson(['success' => false, 'error' => 'Erro ao conectar no banco'], 500);
        }
    }
    
    if (!$pdo) {
        logDebug("ERRO: PDO é null após todas tentativas");
        sendJson(['success' => false, 'error' => 'Falha na conexão com banco'], 500);
    }
    
    logDebug("Banco conectado com sucesso");
    
    // PROCESSAR AÇÕES
    switch ($action) {
        case 'verify':
        case 'login':
            logDebug("=== LOGIN ===");
            
            // Obter dados
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');
            $email = trim($input['email'] ?? '');
            $displayName = trim($input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? '');
            
            logDebug("GoogleUid: " . substr($googleUid, 0, 30));
            
            if (empty($googleUid)) {
                sendJson(['success' => false, 'error' => 'google_uid obrigatório'], 400);
            }
            
            // Validação básica
            $googleUid = substr($googleUid, 0, 128); // Truncar se muito longo
            if (strlen($googleUid) < 10) {
                sendJson(['success' => false, 'error' => 'google_uid muito curto'], 400);
            }
            
            // UPSERT adaptado para SUA ESTRUTURA
            try {
                logDebug("Executando UPSERT...");
                
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
                
                $result = $stmt->execute([$googleUid, $email, $displayName]);
                logDebug("UPSERT OK - Affected rows: " . $stmt->rowCount());
                
            } catch (PDOException $e) {
                logDebug("ERRO UPSERT: " . $e->getMessage());
                sendJson(['success' => false, 'error' => 'Erro ao salvar usuário: ' . $e->getMessage()], 500);
            }
            
            // Buscar usuário
            try {
                logDebug("Buscando usuário...");
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
                $stmt->execute([$googleUid]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    logDebug("ERRO: Usuário não encontrado após UPSERT");
                    sendJson(['success' => false, 'error' => 'Usuário não carregado'], 500);
                }
                
                logDebug("Usuário carregado: ID " . $user['id']);
                
            } catch (PDOException $e) {
                logDebug("ERRO SELECT: " . $e->getMessage());
                sendJson(['success' => false, 'error' => 'Erro ao buscar usuário'], 500);
            }
            
            // Gerar session token
            $sessionToken = hash('sha256', 
                $googleUid . '|' . 
                $user['id'] . '|' . 
                microtime(true) . '|' . 
                bin2hex(random_bytes(16))
            );
            
            logDebug("=== LOGIN SUCESSO ===");
            
            // Retornar dados
            sendJson([
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
            sendJson(['success' => true, 'message' => 'Logout OK']);
            break;
            
        case 'profile':
        case 'balance':
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? '');
            
            if (empty($googleUid)) {
                sendJson(['success' => false, 'error' => 'google_uid necessário'], 400);
            }
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                sendJson(['success' => false, 'error' => 'Usuário não encontrado'], 404);
            }
            
            sendJson([
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
            sendJson(['success' => false, 'error' => 'Ação inválida'], 400);
    }
    
} catch (PDOException $e) {
    logDebug("PDO Exception: " . $e->getMessage());
    sendJson(['success' => false, 'error' => 'Erro de banco: ' . $e->getMessage()], 500);
    
} catch (Throwable $e) {
    logDebug("Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    sendJson(['success' => false, 'error' => 'Erro: ' . $e->getMessage()], 500);
}

// Não deve chegar aqui
logDebug("AVISO: Fim do script sem retorno");
sendJson(['success' => false, 'error' => 'Fim inesperado'], 500);
