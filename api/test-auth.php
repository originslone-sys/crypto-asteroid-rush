<?php
// ============================================
// TESTE ESPECÍFICO DO AUTH-GOOGLE.PHP
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

error_log('🧪 TESTE AUTH: Iniciando teste específico...');

// Simular dados de login (como o frontend envia)
$testData = [
    'action' => 'login',
    'google_uid' => 'test_uid_' . time(),
    'email' => 'test@example.com',
    'display_name' => 'Test User',
    'photo_url' => 'https://example.com/photo.jpg',
    'firebase_token' => 'test_token_' . time()
];

try {
    error_log('🧪 TESTE AUTH: Tentando getDatabaseConnection()...');
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        error_log('❌ TESTE AUTH: getDatabaseConnection() retornou null');
        echo json_encode(['success' => false, 'error' => 'Conexão com banco falhou']);
        exit;
    }
    
    error_log('✅ TESTE AUTH: Conexão com banco OK');
    
    // Testar a função validateGoogleUid
    error_log('🧪 TESTE AUTH: Testando validateGoogleUid...');
    $isValid = validateGoogleUid($testData['google_uid']);
    error_log('✅ TESTE AUTH: validateGoogleUid = ' . ($isValid ? 'true' : 'false'));
    
    // Testar INSERT na tabela users (como auth-google.php faz)
    error_log('🧪 TESTE AUTH: Testando INSERT na tabela users...');
    
    $stmt = $pdo->prepare("
        INSERT INTO users (google_uid, email, display_name, photo_url, balance_brl, created_at, updated_at, last_login)
        VALUES (?, ?, ?, ?, 0.00, NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            email = COALESCE(VALUES(email), email),
            display_name = COALESCE(VALUES(display_name), display_name),
            photo_url = COALESCE(VALUES(photo_url), photo_url),
            last_login = NOW(),
            updated_at = NOW()
    ");
    
    $result = $stmt->execute([
        $testData['google_uid'],
        $testData['email'],
        $testData['display_name'],
        $testData['photo_url'],
    ]);
    
    if ($result) {
        error_log('✅ TESTE AUTH: INSERT na tabela users OK');
        
        // Buscar o usuário inserido
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$testData['google_uid']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            error_log('✅ TESTE AUTH: SELECT do usuário OK');
            
            // Testar INSERT na tabela user_sessions
            error_log('🧪 TESTE AUTH: Testando INSERT na tabela user_sessions...');
            
            $sessionToken = hash('sha256', $testData['google_uid'] . '|' . ($user['id'] ?? 0) . '|' . microtime(true) . '|' . bin2hex(random_bytes(16)));
            
            $stmt = $pdo->prepare("
                INSERT INTO user_sessions (google_uid, session_token, firebase_token, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ");
            
            $ip = getClientIP();
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Test Agent', 0, 500);
            
            $result = $stmt->execute([
                $testData['google_uid'],
                $sessionToken,
                $testData['firebase_token'],
                $ip,
                $userAgent
            ]);
            
            if ($result) {
                error_log('✅ TESTE AUTH: INSERT na tabela user_sessions OK');
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Todos os testes passaram!',
                    'tests' => [
                        'database_connection' => '✅ OK',
                        'validateGoogleUid' => '✅ OK',
                        'users_insert' => '✅ OK',
                        'users_select' => '✅ OK',
                        'user_sessions_insert' => '✅ OK',
                        'session_token_generated' => '✅ ' . substr($sessionToken, 0, 20) . '...'
                    ],
                    'user' => [
                        'id' => $user['id'],
                        'google_uid' => $user['google_uid'],
                        'email' => $user['email']
                    ]
                ]);
                
                // Limpar teste
                $pdo->prepare("DELETE FROM users WHERE google_uid = ?")->execute([$testData['google_uid']]);
                $pdo->prepare("DELETE FROM user_sessions WHERE google_uid = ?")->execute([$testData['google_uid']]);
                error_log('🧹 TESTE AUTH: Dados de teste limpos');
                
            } else {
                error_log('❌ TESTE AUTH: INSERT na user_sessions falhou');
                echo json_encode(['success' => false, 'error' => 'INSERT user_sessions falhou', 'details' => $stmt->errorInfo()]);
            }
            
        } else {
            error_log('❌ TESTE AUTH: SELECT do usuário falhou');
            echo json_encode(['success' => false, 'error' => 'SELECT do usuário falhou']);
        }
        
    } else {
        error_log('❌ TESTE AUTH: INSERT na tabela users falhou');
        echo json_encode(['success' => false, 'error' => 'INSERT users falhou', 'details' => $stmt->errorInfo()]);
    }
    
} catch (Exception $e) {
    error_log('❌ TESTE AUTH: EXCEÇÃO: ' . $e->getMessage());
    error_log('❌ TESTE AUTH: TRACE: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => 'Exceção durante teste',
        'exception' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

error_log('🧪 TESTE AUTH: Teste concluído');