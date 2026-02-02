<?php
// ============================================
// VERIFICAR TABELA PLAYERS
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
    
    // 1. Verificar se tabela players existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'players'");
    $playersTableExists = $stmt->rowCount() > 0;
    
    $result = [
        'players_table' => [
            'exists' => $playersTableExists
        ]
    ];
    
    if ($playersTableExists) {
        // 2. Verificar schema
        $stmt = $pdo->query("DESCRIBE players");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['players_table']['columns'] = array_column($columns, 'Field');
        $result['players_table']['full_schema'] = $columns;
        
        // 3. Contar registros
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM players");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        $result['players_table']['total_records'] = $count['total'];
        
        // 4. Buscar por Google UID específico
        $searchUid = 'DqnexVtvrtdG3fe8fSGzHk8NA713'; // Seu UID completo
        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$searchUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result['players_table']['search'] = [
            'google_uid' => $searchUid,
            'found' => $player ? true : false,
            'player' => $player
        ];
        
        // 5. Listar alguns players
        $stmt = $pdo->query("SELECT id, google_uid, created_at FROM players ORDER BY id DESC LIMIT 5");
        $result['players_table']['sample'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 6. Comparar com tabela users
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $usersCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $result['comparison'] = [
        'users_table_records' => $usersCount['total'],
        'players_table_records' => $playersTableExists ? $result['players_table']['total_records'] : 0,
        'problem' => $playersTableExists ? 
            ($result['players_table']['total_records'] == 0 ? 'Tabela players vazia' : 
             ($result['players_table']['search']['found'] ? 'Player encontrado' : 'Player não encontrado (mas usuário existe)')) :
            'Tabela players não existe'
    ];
    
    // 7. Sugestão de correção
    if (!$playersTableExists) {
        $result['suggestion'] = [
            'problem' => 'Tabela players não existe, mas game-start.php tenta usá-la',
            'fix' => 'Criar tabela players ou modificar game-start.php para usar users',
            'sql_create' => "CREATE TABLE IF NOT EXISTS players (
                id INT PRIMARY KEY AUTO_INCREMENT,
                google_uid VARCHAR(255) UNIQUE,
                balance_brl DECIMAL(10,2) DEFAULT 0.00,
                total_played INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"
        ];
    } elseif ($result['players_table']['total_records'] == 0) {
        $result['suggestion'] = [
            'problem' => 'Tabela players existe mas está vazia',
            'fix' => 'game-start.php deve criar player se não existir (já tenta, mas pode falhar)',
            'alternative' => 'Modificar game-start.php para usar tabela users em vez de players'
        ];
    } elseif (!$result['players_table']['search']['found']) {
        $result['suggestion'] = [
            'problem' => 'Player não encontrado na tabela players (mas usuário existe em users)',
            'fix' => 'Sincronizar tabelas ou modificar game-start.php para buscar em users',
            'sql_sync' => "INSERT INTO players (google_uid, balance_brl, total_played) 
                          SELECT google_uid, balance_brl, 0 FROM users WHERE google_uid = ? 
                          ON DUPLICATE KEY UPDATE balance_brl = VALUES(balance_brl)"
        ];
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}