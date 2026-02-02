<?php
// ============================================
// VERIFICAR USUÁRIOS NO BANCO
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['error' => 'Conexão com banco falhou']);
        exit;
    }
    
    // 1. Contar usuários
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Listar últimos 5 usuários
    $stmt = $pdo->query("SELECT id, google_uid, email, display_name, created_at FROM users ORDER BY id DESC LIMIT 5");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Verificar se há usuário com Google UID específico
    $searchUid = 'DqnexVtvrt...'; // O UID do seu login
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_uid LIKE ? LIMIT 1");
    $stmt->execute([$searchUid . '%']);
    $specificUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 4. Verificar user_sessions (para ver se login criou sessão)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM user_sessions");
    $sessionsCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT user_id, session_token, created_at FROM user_sessions ORDER BY id DESC LIMIT 3");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'database_status' => 'connected',
        'users' => [
            'total' => $count['total'],
            'recent' => $users,
            'search' => [
                'google_uid' => $searchUid,
                'found' => $specificUser ? true : false,
                'user' => $specificUser
            ]
        ],
        'sessions' => [
            'total' => $sessionsCount['total'],
            'recent' => $sessions
        ],
        'analysis' => [
            'problem' => $count['total'] == 0 ? 'Tabela users está vazia' : ($specificUser ? 'Usuário encontrado' : 'Usuário não encontrado (possível erro no INSERT)'),
            'suggestion' => $count['total'] == 0 ? 'auth-google.php não está inserindo usuários' : 'auth-google.php pode estar falhando silenciosamente'
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}