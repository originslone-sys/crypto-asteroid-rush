<?php
// ============================================
// UNOBIX - Campaign Tutorial Flags
// api/campaign-tutorial.php
//
// GET  ?google_uid=...                  → lista de chaves vistas.
// POST { google_uid, key }              → marca a chave como vista
//                                         (idempotente).
//
// Usado pelo cliente para:
// - Mostrar tela de boas-vindas só na 1ª entrada (key=welcome).
// - Tooltips contextuais únicos (key=tooltip_<algo>).
// - Cinemáticas entre setores (key=cinematic_sector1to2).
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $_GET['google_uid'] ?? '');
if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT tutorial_key FROM campaign_tutorial_seen WHERE google_uid = ?");
        $stmt->execute([$googleUid]);
        $keys = array_map(fn($r) => $r['tutorial_key'], $stmt->fetchAll());
        echo json_encode(['success' => true, 'data' => ['seen' => $keys]]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $key = trim($input['key'] ?? '');
    if (empty($key) || !preg_match('/^[a-z0-9_]+$/i', $key) || strlen($key) > 80) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'key inválida']);
        exit;
    }

    $pdo->prepare("INSERT IGNORE INTO campaign_tutorial_seen (google_uid, tutorial_key, seen_at) VALUES (?, ?, NOW())")
        ->execute([$googleUid, $key]);

    echo json_encode(['success' => true, 'data' => ['key' => $key]]);

} catch (Exception $e) {
    error_log('campaign-tutorial error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
