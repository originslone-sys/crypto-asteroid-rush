<?php
// ============================================
// DEBUG: VERIFICAR GOOGLE UID ENVIADO
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Capturar todos os dados recebidos
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST ?: $_GET;

echo json_encode([
    'debug' => 'Google UID Analysis',
    'timestamp' => date('Y-m-d H:i:s'),
    'received_data' => $input,
    'analysis' => [
        'has_google_uid' => isset($input['google_uid']),
        'google_uid_value' => $input['google_uid'] ?? 'NOT PROVIDED',
        'google_uid_length' => isset($input['google_uid']) ? strlen($input['google_uid']) : 0,
        'google_uid_truncated' => isset($input['google_uid']) && (strlen($input['google_uid']) < 20),
        'other_identifiers' => [
            'has_user_id' => isset($input['user_id']),
            'has_email' => isset($input['email']),
            'has_session_token' => isset($input['session_token'])
        ]
    ],
    'database_check' => checkGoogleUidInDatabase($input['google_uid'] ?? '')
], JSON_PRETTY_PRINT);

function checkGoogleUidInDatabase($googleUid) {
    if (empty($googleUid)) {
        return ['found' => false, 'error' => 'No google_uid provided'];
    }
    
    try {
        $pdo = getDatabaseConnection();
        if (!$pdo) {
            return ['found' => false, 'error' => 'Database connection failed'];
        }
        
        // Buscar exato
        $stmt = $pdo->prepare("SELECT id, google_uid, email FROM users WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $exact = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Buscar parcial (LIKE)
        $stmt = $pdo->prepare("SELECT id, google_uid, email FROM users WHERE google_uid LIKE ? LIMIT 3");
        $stmt->execute([$googleUid . '%']);
        $partial = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar todos (para debug)
        $stmt = $pdo->query("SELECT id, LEFT(google_uid, 20) as google_uid_part, LENGTH(google_uid) as length FROM users WHERE google_uid IS NOT NULL ORDER BY id DESC LIMIT 5");
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'exact_match' => $exact ?: false,
            'partial_matches' => $partial,
            'all_users_with_uid' => $all,
            'search_analysis' => [
                'searching_for' => $googleUid,
                'search_length' => strlen($googleUid),
                'exact_found' => (bool)$exact,
                'partial_found' => count($partial) > 0
            ]
        ];
        
    } catch (Exception $e) {
        return ['found' => false, 'error' => $e->getMessage()];
    }
}