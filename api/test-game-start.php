<?php
// ============================================
// TESTE ESPECÍFICO DO GAME-START.PHP
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

error_log('🧪 TESTE GAME-START: Iniciando...');

// Simular dados que o frontend envia
$testData = [
    'google_uid' => 'DqnexVtvrt...', // O mesmo do seu login
    'mission_id' => 1,
    'ship_id' => 'nebula_phantom',
    'difficulty' => 'normal'
];

try {
    error_log('🧪 TESTE GAME-START: 1. Testando conexão com banco...');
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        error_log('❌ TESTE GAME-START: Conexão com banco falhou');
        echo json_encode(['success' => false, 'error' => 'Conexão com banco falhou']);
        exit;
    }
    
    error_log('✅ TESTE GAME-START: Conexão com banco OK');
    
    // 2. Verificar se usuário existe
    error_log('🧪 TESTE GAME-START: 2. Verificando usuário...');
    $stmt = $pdo->prepare("SELECT id, google_uid, balance_brl FROM users WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$testData['google_uid']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        error_log('❌ TESTE GAME-START: Usuário não encontrado: ' . $testData['google_uid']);
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }
    
    error_log('✅ TESTE GAME-START: Usuário encontrado: ID=' . $user['id']);
    
    // 3. Verificar tabela game_sessions
    error_log('🧪 TESTE GAME-START: 3. Verificando tabela game_sessions...');
    $stmt = $pdo->query("SHOW CREATE TABLE game_sessions");
    $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tableInfo) {
        error_log('❌ TESTE GAME-START: Tabela game_sessions não existe ou erro ao verificar');
    } else {
        error_log('✅ TESTE GAME-START: Tabela game_sessions existe');
    }
    
    // 4. Testar INSERT na game_sessions (como game-start.php faz)
    error_log('🧪 TESTE GAME-START: 4. Testando INSERT na game_sessions...');
    
    $sessionUuid = bin2hex(random_bytes(16));
    
    // Primeiro, verificar colunas da tabela
    $stmt = $pdo->query("DESCRIBE game_sessions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    error_log('🧪 TESTE GAME-START: Colunas de game_sessions: ' . implode(', ', $columns));
    
    // Tentar INSERT com colunas mínimas
    $sql = "INSERT INTO game_sessions (user_id, session_uuid, mission_id, ship_id, difficulty, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";
    
    if (in_array('google_uid', $columns)) {
        // Se tabela tem google_uid (schema antigo)
        $sql = "INSERT INTO game_sessions (user_id, google_uid, session_uuid, mission_id, ship_id, difficulty, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $params = [$user['id'], $testData['google_uid'], $sessionUuid, $testData['mission_id'], $testData['ship_id'], $testData['difficulty']];
    } else {
        // Schema novo (só user_id)
        $params = [$user['id'], $sessionUuid, $testData['mission_id'], $testData['ship_id'], $testData['difficulty']];
    }
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);
    
    if ($result) {
        $sessionId = $pdo->lastInsertId();
        error_log('✅ TESTE GAME-START: INSERT na game_sessions OK, ID=' . $sessionId);
        
        // 5. Retornar resposta similar ao game-start.php real
        echo json_encode([
            'success' => true,
            'message' => 'Sessão de jogo iniciada com sucesso',
            'session' => [
                'id' => $sessionId,
                'uuid' => $sessionUuid,
                'user_id' => $user['id'],
                'mission_id' => $testData['mission_id'],
                'ship_id' => $testData['ship_id'],
                'difficulty' => $testData['difficulty'],
                'created_at' => date('Y-m-d H:i:s')
            ],
            'user' => [
                'id' => $user['id'],
                'google_uid' => $user['google_uid'],
                'balance_brl' => $user['balance_brl']
            ]
        ]);
        
        // Limpar teste
        $pdo->prepare("DELETE FROM game_sessions WHERE id = ?")->execute([$sessionId]);
        error_log('🧹 TESTE GAME-START: Sessão de teste limpa');
        
    } else {
        error_log('❌ TESTE GAME-START: INSERT na game_sessions falhou');
        $errorInfo = $stmt->errorInfo();
        error_log('❌ TESTE GAME-START: Erro SQL: ' . json_encode($errorInfo));
        
        echo json_encode([
            'success' => false,
            'error' => 'INSERT na game_sessions falhou',
            'sql_error' => $errorInfo,
            'sql' => $sql,
            'params' => $params
        ]);
    }
    
} catch (Exception $e) {
    error_log('❌ TESTE GAME-START: EXCEÇÃO: ' . $e->getMessage());
    error_log('❌ TESTE GAME-START: TRACE: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => 'Exceção durante teste',
        'exception' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

error_log('🧪 TESTE GAME-START: Teste concluído');