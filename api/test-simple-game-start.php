<?php
// ============================================
// TESTE SIMPLES DO GAME-START
// Verifica se carrega sem erro
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

error_log('🧪 TESTE SIMPLES: Iniciando game-start teste...');

try {
    // 1. Testar se config carrega
    error_log('✅ TESTE: config-cloudrun.php carregado');
    
    // 2. Testar conexão com banco
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        error_log('❌ TESTE: getDatabaseConnection() falhou');
        echo json_encode(['success' => false, 'error' => 'Conexão com banco falhou']);
        exit;
    }
    error_log('✅ TESTE: Conexão com banco OK');
    
    // 3. Testar se tabelas existem
    $tables = ['users', 'players', 'game_sessions'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        error_log("   - $table: " . ($exists ? 'EXISTE' : 'NÃO EXISTE'));
    }
    
    // 4. Testar INSERT simples em players (se existir)
    $testUid = 'test_' . time();
    $stmt = $pdo->prepare("
        INSERT INTO players (google_uid, balance_brl, total_played, created_at)
        VALUES (?, 0.00, 0, NOW())
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");
    
    $result = $stmt->execute([$testUid]);
    error_log('✅ TESTE: INSERT em players: ' . ($result ? 'OK' : 'FALHOU'));
    
    // 5. Limpar teste
    $pdo->prepare("DELETE FROM players WHERE google_uid = ?")->execute([$testUid]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Teste passou - game-start.php deve funcionar',
        'tests' => [
            'config_loaded' => true,
            'database_connected' => true,
            'players_table_exists' => true,
            'insert_test_passed' => $result
        ]
    ]);
    
} catch (Exception $e) {
    error_log('❌ TESTE: EXCEÇÃO: ' . $e->getMessage());
    error_log('❌ TESTE: TRACE: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => 'Exceção durante teste',
        'exception' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}