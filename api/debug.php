<?php
/**
 * Debug das APIs - Acessível via web com autenticação
 * URL: /api/debug.php?token=DEBUG_TOKEN
 */

require_once __DIR__ . "/config-cloudrun.php";

// Autenticação simples por token
$validToken = 'DEBUG_' . date('Ymd');
$providedToken = $_GET['token'] ?? '';

if ($providedToken !== $validToken) {
    http_response_code(403);
    echo "Debug requer token. Token de hoje: " . $validToken;
    exit;
}

setCorsHeaders();

// ============================================
// FUNÇÕES DE DEBUG
// ============================================

function debugAuthGoogle() {
    $testData = [
        'action' => 'login',
        'google_uid' => 'DEBUG_' . time(),
        'email' => 'debug_' . time() . '@example.com',
        'display_name' => 'Debug User'
    ];
    
    $result = [
        'endpoint' => 'auth-google.php',
        'test_data' => $testData,
        'response' => null
    ];
    
    // Simular chamada
    ob_start();
    $_POST = $testData;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    include __DIR__ . '/auth-google.php';
    $result['response'] = json_decode(ob_get_clean(), true);
    
    return $result;
}

function debugGameStart() {
    $testData = [
        'google_uid' => 'DqnexVtvrtdG3fe8fSGzVvQ7Y5J3',
        'email' => 'originslone@gmail.com',
        'display_name' => 'Lone Labs'
    ];
    
    $result = [
        'endpoint' => 'game-start.php',
        'test_data' => $testData,
        'response' => null
    ];
    
    ob_start();
    $_POST = $testData;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    include __DIR__ . '/game-start.php';
    $result['response'] = json_decode(ob_get_clean(), true);
    
    return $result;
}

function debugGameEvent($sessionId, $sessionToken) {
    $testData = [
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'google_uid' => 'DqnexVtvrtdG3fe8fSGzVvQ7Y5J3',
        'asteroid_id' => 1,
        'reward_type' => 'common'
    ];
    
    $result = [
        'endpoint' => 'game-event.php',
        'test_data' => $testData,
        'response' => null
    ];
    
    ob_start();
    $_POST = $testData;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    include __DIR__ . '/game-event.php';
    $result['response'] = json_decode(ob_get_clean(), true);
    
    return $result;
}

function debugGameEnd($sessionId, $sessionToken) {
    $testData = [
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'google_uid' => 'DqnexVtvrtdG3fe8fSGzVvQ7Y5J3',
        'is_victory' => true,
        'client_score' => 100,
        'client_earnings' => 0.01
    ];
    
    $result = [
        'endpoint' => 'game-end.php',
        'test_data' => $testData,
        'response' => null
    ];
    
    ob_start();
    $_POST = $testData;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    include __DIR__ . '/game-end.php';
    $result['response'] = json_decode(ob_get_clean(), true);
    
    return $result;
}

function getRecentSessions($pdo) {
    $stmt = $pdo->query("
        SELECT id, google_uid, session_token, status, started_at 
        FROM game_sessions 
        ORDER BY id DESC 
        LIMIT 10
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// EXECUÇÃO PRINCIPAL
// ============================================

header('Content-Type: application/json');

try {
    $pdo = getDatabaseConnection();
    
    $debugResults = [
        'timestamp' => date('Y-m-d H:i:s'),
        'environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown',
        'database_connected' => !!$pdo,
        'recent_sessions' => $pdo ? getRecentSessions($pdo) : [],
        'api_tests' => []
    ];
    
    // Testar auth-google
    $debugResults['api_tests'][] = debugAuthGoogle();
    
    // Testar game-start
    $debugResults['api_tests'][] = debugGameStart();
    
    // Testar game-event com sessão mais recente
    if ($pdo && !empty($debugResults['recent_sessions'])) {
        $latestSession = $debugResults['recent_sessions'][0];
        $debugResults['api_tests'][] = debugGameEvent(
            $latestSession['id'],
            $latestSession['session_token']
        );
        
        // Testar game-end
        $debugResults['api_tests'][] = debugGameEnd(
            $latestSession['id'],
            $latestSession['session_token']
        );
    }
    
    echo json_encode($debugResults, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>