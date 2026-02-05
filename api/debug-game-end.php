<?php
// ============================================
// UNOBIX - Diagnóstico Game End
// api/debug-game-end.php
// REMOVA APÓS TESTAR!
// ============================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'step' => 'init'
];

try {
    $debug['step'] = 'loading_config';
    require_once __DIR__ . '/config.php';
    $debug['config_loaded'] = true;
    
    $debug['step'] = 'getting_connection';
    $pdo = getDatabaseConnection();
    $debug['pdo_connected'] = $pdo !== null;
    
    if (!$pdo) {
        throw new Exception("Conexão falhou");
    }
    
    // Verificar estrutura da tabela game_sessions
    $debug['step'] = 'checking_game_sessions';
    $stmt = $pdo->query("DESCRIBE game_sessions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $debug['game_sessions_columns'] = $columns;
    
    // Verificar estrutura da tabela game_events
    $debug['step'] = 'checking_game_events';
    $stmt = $pdo->query("DESCRIBE game_events");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $debug['game_events_columns'] = $columns;
    
    // Verificar estrutura da tabela users
    $debug['step'] = 'checking_users';
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $debug['users_columns'] = $columns;
    
    // Verificar estrutura da tabela transactions
    $debug['step'] = 'checking_transactions';
    $stmt = $pdo->query("DESCRIBE transactions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $debug['transactions_columns'] = $columns;
    
    // Verificar última sessão
    $debug['step'] = 'checking_last_session';
    $stmt = $pdo->query("SELECT * FROM game_sessions ORDER BY id DESC LIMIT 1");
    $lastSession = $stmt->fetch(PDO::FETCH_ASSOC);
    $debug['last_session'] = $lastSession;
    
    // Verificar eventos da última sessão - CORRIGIDO: usar earnings_brl
    if ($lastSession) {
        $debug['step'] = 'checking_session_events';
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(earnings_brl) as total FROM game_events WHERE session_id = ?");
        $stmt->execute([$lastSession['id']]);
        $debug['last_session_events'] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Verificar usuários
    $debug['step'] = 'checking_users_count';
    $stmt = $pdo->query("SELECT id, google_uid, balance_brl, total_earned_brl FROM users ORDER BY id DESC LIMIT 5");
    $debug['recent_users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar transações recentes
    $debug['step'] = 'checking_transactions';
    $stmt = $pdo->query("SELECT * FROM transactions ORDER BY id DESC LIMIT 5");
    $debug['recent_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug['step'] = 'complete';
    $debug['success'] = true;
    
} catch (Throwable $e) {
    $debug['success'] = false;
    $debug['error'] = $e->getMessage();
    $debug['error_file'] = $e->getFile();
    $debug['error_line'] = $e->getLine();
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
