<?php
// ============================================
// UNOBIX - PvP: Validar token JWT do jogador
// api/pvp-validate-token.php
// Chamado pelo Game Server para validar jogador
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');

$input = getRequestInput();

// Autenticação do Game Server via shared secret
$serverToken = $_SERVER['HTTP_X_PVP_SERVER_TOKEN'] ?? ($input['server_token'] ?? '');
if ($serverToken !== PVP_JWT_SECRET) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pvpToken = $input['pvp_token'] ?? '';
if (empty($pvpToken)) {
    echo json_encode(['success' => false, 'error' => 'pvp_token não fornecido']);
    exit;
}

// Decodificar e validar JWT
$parts = explode('.', $pvpToken);
if (count($parts) !== 3) {
    echo json_encode(['success' => false, 'error' => 'Token inválido']);
    exit;
}

[$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

// Verificar assinatura
$expectedSignature = base64_encode(hash_hmac('sha256', "$headerEncoded.$payloadEncoded", PVP_JWT_SECRET, true));
if (!hash_equals($expectedSignature, $signatureEncoded)) {
    echo json_encode(['success' => false, 'error' => 'Assinatura inválida']);
    exit;
}

$payload = json_decode(base64_decode($payloadEncoded), true);
if (!$payload) {
    echo json_encode(['success' => false, 'error' => 'Payload inválido']);
    exit;
}

// Verificar expiração
if (($payload['exp'] ?? 0) < time()) {
    echo json_encode(['success' => false, 'error' => 'Token expirado']);
    exit;
}

// Verificar se o usuário ainda existe e não está banido
try {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("SELECT id, google_uid, display_name, is_banned FROM users WHERE google_uid = ?");
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    if (!empty($user['is_banned'])) {
        echo json_encode(['success' => false, 'error' => 'Usuário banido']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'google_uid' => $user['google_uid'],
        'user_id' => (int)$user['id'],
        'display_name' => $user['display_name'] ?? 'Jogador'
    ]);

} catch (Throwable $e) {
    error_log("❌ PVP-VALIDATE ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
