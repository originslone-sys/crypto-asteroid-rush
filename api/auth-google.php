<?php
// ============================================
// UNOBIX - Autenticação Google v3.2 (ULTRA ROBUSTO)
// api/auth-google.php
// ============================================

// CRÍTICO: Desabilitar TODOS os outputs de erro
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
@error_reporting(0);

// Iniciar output buffering ANTES de qualquer coisa
@ob_start();

// Limpar qualquer output anterior
while (@ob_get_level()) {
    @ob_end_clean();
}
@ob_start();

// Headers PRIMEIRO (antes de qualquer output)
@header('Content-Type: application/json; charset=utf-8', true);
@header('Access-Control-Allow-Origin: *', true);
@header('Access-Control-Allow-Methods: GET, POST, OPTIONS', true);
@header('Access-Control-Allow-Headers: Content-Type, Authorization', true);

// OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    @http_response_code(204);
    exit(0);
}

// Função para log em arquivo
function logDebug($message) {
    $logFile = __DIR__ . '/auth-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Função para retornar JSON e sair IMEDIATAMENTE
function sendJson($data, $statusCode = 200) {
    // Limpar TODO o buffer
    while (@ob_get_level()) {
        @ob_end_clean();
    }
    
    // Definir status
    @http_response_code($statusCode);
    
    // Headers novamente (garantir)
    @header('Content-Type: application/json; charset=utf-8', true);
    @header('Access-Control-Allow-Origin: *', true);
    
    // Output JSON puro
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Flush e exit
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit(0);
}

// CAPTURAR QUALQUER ERRO FATAL
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        logDebug("FATAL ERROR: " . json_encode($error));
        sendJson([
            'success' => false,
            'error' => 'Erro fatal no servidor',
            'debug' => 'Check auth-debug.log'
        ], 500);
    }
});

// TENTAR CARREGAR CONFIG
try {
    logDebug("=== INICIANDO AUTH REQUEST ===");
    
    if (!file_exists(__DIR__ . "/config.php")) {
        logDebug("ERRO: config.php não encontrado");
        sendJson([
            'success' => false,
            'error' => 'Arquivo de configuração não encontrado'
        ], 500);
    }
    
    require_once __DIR__ . "/config.php";
    logDebug("Config carregado com sucesso");
    
} catch (Throwable $e) {
    logDebug("ERRO ao carregar config: " . $e->getMessage());
    sendJson([
        'success' => false,
        'error' => 'Erro ao carregar configuração',
        'details' => $e->getMessage()
    ], 500);
}

// PROCESSAR INPUT
try {
    $rawInput = @file_get_contents('php://input');
    logDebug("Raw input: " . substr($rawInput, 0, 200));
    
    $input = @json_decode($rawInput, true);
    
    if (!is_array($input)) {
        $input = array_merge($_GET ?? [], $_POST ?? []);
        logDebug("Usando GET/POST params");
    }
    
    $action = $input['action'] ?? 'login';
    logDebug("Action: $action");
    
} catch (Throwable $e) {
    logDebug("ERRO ao processar input: " . $e->getMessage());
    sendJson([
        'success' => false,
        'error' => 'Erro ao processar requisição'
    ], 400);
}

// CONECTAR AO BANCO
try {
    logDebug("Tentando conectar ao banco...");
    
    if (!function_exists('getDatabaseConnection')) {
        logDebug("ERRO: getDatabaseConnection não existe");
        sendJson([
            'success' => false,
            'error' => 'Função de banco não encontrada'
        ], 500);
    }
    
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        logDebug("ERRO: PDO retornou null");
        sendJson([
            'success' => false,
            'error' => 'Falha na conexão com banco de dados'
        ], 500);
    }
    
    logDebug("Banco conectado com sucesso");
    
} catch (Throwable $e) {
    logDebug("ERRO na conexão: " . $e->getMessage());
    sendJson([
        'success' => false,
        'error' => 'Erro ao conectar no banco',
        'details' => $e->getMessage()
    ], 500);
}

// PROCESSAR AÇÃO
try {
    switch ($action) {
        case 'verify':
        case 'login':
            logDebug("=== PROCESSANDO LOGIN ===");
            
            // Obter dados
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');
            $email = trim($input['email'] ?? '');
            $displayName = trim($input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? '');
            $photoUrl = trim($input['photo_url'] ?? $input['photoURL'] ?? '');
            
            logDebug("GoogleUid: " . substr($googleUid, 0, 20));
            logDebug("Email: $email");
            
            // Validar
            if (empty($googleUid)) {
                logDebug("ERRO: google_uid vazio");
                sendJson([
                    'success' => false,
                    'error' => 'google_uid é obrigatório'
                ], 400);
            }
            
            if (!function_exists('validateGoogleUid')) {
                logDebug("AVISO: validateGoogleUid não existe, pulando validação");
            } else if (!validateGoogleUid($googleUid)) {
                logDebug("ERRO: google_uid inválido");
                sendJson([
                    'success' => false,
                    'error' => 'google_uid inválido'
                ], 400);
            }
            
            // Verificar se tabela users existe
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
                $tableExists = $stmt->fetch();
                
                if (!$tableExists) {
                    logDebug("ERRO: Tabela 'users' não existe");
                    sendJson([
                        'success' => false,
                        'error' => 'Tabela de usuários não encontrada. Execute o SQL de criação.'
                    ], 500);
                }
                
                logDebug("Tabela 'users' existe");
                
            } catch (PDOException $e) {
                logDebug("ERRO ao verificar tabela: " . $e->getMessage());
                sendJson([
                    'success' => false,
                    'error' => 'Erro ao verificar estrutura do banco'
                ], 500);
            }
            
            // Upsert
            try {
                logDebug("Executando UPSERT...");
                
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
                
                logDebug("UPSERT executado com sucesso");
                
            } catch (PDOException $e) {
                logDebug("ERRO no UPSERT: " . $e->getMessage());
                sendJson([
                    'success' => false,
                    'error' => 'Erro ao salvar usuário',
                    'details' => $e->getMessage()
                ], 500);
            }
            
            // Buscar usuário
            try {
                logDebug("Buscando usuário...");
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
                $stmt->execute([$googleUid]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    logDebug("ERRO: Usuário não encontrado após insert");
                    sendJson([
                        'success' => false,
                        'error' => 'Usuário não encontrado após criação'
                    ], 500);
                }
                
                logDebug("Usuário encontrado: ID " . $user['id']);
                
            } catch (PDOException $e) {
                logDebug("ERRO ao buscar usuário: " . $e->getMessage());
                sendJson([
                    'success' => false,
                    'error' => 'Erro ao carregar usuário'
                ], 500);
            }
            
            // Verificar ban
            if (!empty($user['is_banned'])) {
                logDebug("Usuário banido: " . $user['id']);
                sendJson([
                    'success' => false,
                    'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violação dos termos')
                ], 403);
            }
            
            // Gerar token
            $sessionToken = hash('sha256', 
                $googleUid . '|' . 
                $user['id'] . '|' . 
                microtime(true) . '|' . 
                bin2hex(random_bytes(16))
            );
            
            logDebug("Token gerado");
            
            // Sucesso!
            logDebug("=== LOGIN SUCESSO ===");
            
            sendJson([
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
            logDebug("Logout");
            sendJson([
                'success' => true,
                'message' => 'Logout realizado'
            ]);
            break;
            
        case 'profile':
        case 'balance':
            logDebug("Profile/Balance");
            
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? '');
            
            if (empty($googleUid)) {
                sendJson([
                    'success' => false,
                    'error' => 'google_uid necessário'
                ], 400);
            }
            
            $user = null;
            if (function_exists('findPlayer')) {
                $user = findPlayer($pdo, $googleUid);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
                $stmt->execute([$googleUid]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$user) {
                sendJson([
                    'success' => false,
                    'error' => 'Usuário não encontrado'
                ], 404);
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
            logDebug("Ação inválida: $action");
            sendJson([
                'success' => false,
                'error' => 'Ação inválida: ' . $action
            ], 400);
    }
    
} catch (PDOException $e) {
    logDebug("PDO EXCEPTION: " . $e->getMessage());
    sendJson([
        'success' => false,
        'error' => 'Erro no banco de dados',
        'details' => $e->getMessage()
    ], 500);
    
} catch (Throwable $e) {
    logDebug("THROWABLE: " . $e->getMessage());
    sendJson([
        'success' => false,
        'error' => 'Erro interno do servidor',
        'details' => $e->getMessage()
    ], 500);
}

// Não deve chegar aqui
logDebug("AVISO: Chegou ao final do script sem retornar");
sendJson([
    'success' => false,
    'error' => 'Resposta inválida'
], 500);
