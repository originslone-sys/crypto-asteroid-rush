<?php
// ============================================
// UNOBIX - Debug Game Start
// api/debug-game-start.php
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
    // Simular input
    $debug['step'] = 'getting_input';
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    $input = is_array($json) ? array_merge($_GET, $_POST, $json) : array_merge($_GET, $_POST);
    
    $debug['raw_input'] = $raw;
    $debug['parsed_input'] = $input;
    $debug['google_uid'] = $input['google_uid'] ?? '(não enviado)';
    
    $debug['step'] = 'loading_config';
    require_once __DIR__ . '/config.php';
    $debug['config_loaded'] = true;
    
    $debug['step'] = 'getting_connection';
    $pdo = getDatabaseConnection();
    $debug['pdo_connected'] = $pdo !== null;
    
    if (!$pdo) {
        throw new Exception("Conexão falhou");
    }
    
    // Verificar colunas de game_sessions
    $debug['step'] = 'checking_game_sessions_structure';
    $stmt = $pdo->query("DESCRIBE game_sessions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $debug['game_sessions_columns'] = $columns;
    
    // Verificar se usuário existe
    $googleUid = trim($input['google_uid'] ?? '');
    if ($googleUid) {
        $debug['step'] = 'finding_user';
        $stmt = $pdo->prepare("SELECT id, google_uid, balance_brl, is_banned FROM users WHERE google_uid = ? OR google_uid LIKE ?");
        $stmt->execute([$googleUid, str_replace('...', '%', $googleUid)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $debug['user_found'] = $user ? true : false;
        $debug['user_data'] = $user;
    }
    
    // Testar INSERT simples
    $debug['step'] = 'test_insert';
    
    // Verificar quais colunas são obrigatórias
    $stmt = $pdo->query("SHOW COLUMNS FROM game_sessions WHERE `Null` = 'NO' AND `Default` IS NULL AND `Key` != 'PRI'");
    $requiredCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $debug['required_columns'] = $requiredCols;
    
    $debug['step'] = 'complete';
    $debug['success'] = true;
    
} catch (Throwable $e) {
    $debug['success'] = false;
    $debug['error'] = $e->getMessage();
    $debug['error_file'] = $e->getFile();
    $debug['error_line'] = $e->getLine();
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
