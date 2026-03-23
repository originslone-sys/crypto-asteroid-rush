<?php
// ============================================
// UNOBIX - Salvar WhatsApp
// Arquivo: api/save-whatsapp.php
// ============================================

require_once __DIR__ . "/config.php";
setCorsHeaders();

$input = getRequestInput();
$googleUid = trim($input['google_uid'] ?? '');
$whatsapp = trim($input['whatsapp'] ?? '');

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

// Limpar número: manter apenas dígitos
$whatsapp = preg_replace('/\D/', '', $whatsapp);

if (strlen($whatsapp) < 10 || strlen($whatsapp) > 13) {
    echo json_encode(['success' => false, 'error' => 'Número de WhatsApp inválido. Use DDD + número.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão");

    // Garantir coluna whatsapp existe
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'whatsapp'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp VARCHAR(20) DEFAULT NULL");
    }

    $stmt = $pdo->prepare("UPDATE users SET whatsapp = ? WHERE google_uid = ?");
    $stmt->execute([$whatsapp, $googleUid]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'WhatsApp salvo com sucesso']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
