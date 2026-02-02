<?php
// ============================================
// VERIFICAR SCHEMA DAS TABELAS
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
    
    // Verificar schema da tabela user_sessions
    $result = [];
    
    $stmt = $pdo->query("DESCRIBE user_sessions");
    $userSessionsColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result['user_sessions'] = [
        'columns' => array_column($userSessionsColumns, 'Field'),
        'full_schema' => $userSessionsColumns
    ];
    
    // Verificar schema da tabela users
    $stmt = $pdo->query("DESCRIBE users");
    $usersColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result['users'] = [
        'columns' => array_column($usersColumns, 'Field'),
        'full_schema' => $usersColumns
    ];
    
    // Verificar relação entre as tabelas
    $result['analysis'] = [
        'user_sessions_has_google_uid' => in_array('google_uid', $result['user_sessions']['columns']),
        'user_sessions_has_user_id' => in_array('user_id', $result['user_sessions']['columns']),
        'users_has_google_uid' => in_array('google_uid', $result['users']['columns']),
        'users_has_id' => in_array('id', $result['users']['columns']),
    ];
    
    // Sugerir correção baseada no schema real
    if ($result['analysis']['user_sessions_has_user_id'] && !$result['analysis']['user_sessions_has_google_uid']) {
        $result['suggestion'] = [
            'problem' => 'Tabela user_sessions usa user_id, não google_uid',
            'fix' => 'Alterar INSERT para usar user_id em vez de google_uid',
            'sql_example' => "INSERT INTO user_sessions (user_id, session_token, ...) VALUES (?, ?, ...)"
        ];
    } elseif ($result['analysis']['user_sessions_has_google_uid']) {
        $result['suggestion'] = [
            'problem' => 'Tabela user_sessions tem google_uid mas algo está errado',
            'fix' => 'Verificar se a coluna existe com tipo correto'
        ];
    } else {
        $result['suggestion'] = [
            'problem' => 'Estrutura desconhecida da tabela user_sessions',
            'fix' => 'Verificar schema completo'
        ];
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}