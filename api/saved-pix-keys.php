<?php
// ============================================
// UNOBIX - Chaves PIX Salvas
// api/saved-pix-keys.php
// Gerencia até 3 chaves PIX salvas por usuário
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? '');
$action = trim($input['action'] ?? 'list');

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    // Buscar user_id
    $stmt = $pdo->prepare("SELECT id FROM users WHERE google_uid = ?");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    $userId = (int)$user['id'];

    // Garantir que a tabela existe (fallback para primeiro uso)
    try {
        $pdo->query("SELECT 1 FROM saved_pix_keys LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS saved_pix_keys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                pix_key VARCHAR(255) NOT NULL,
                pix_key_type VARCHAR(20) NOT NULL,
                label VARCHAR(50) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    switch ($action) {
        case 'list':
            $stmt = $pdo->prepare("SELECT id, pix_key, pix_key_type, label, created_at FROM saved_pix_keys WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$userId]);
            $keys = $stmt->fetchAll();

            echo json_encode(['success' => true, 'keys' => $keys]);
            break;

        case 'save':
            $pixKey = trim($input['pix_key'] ?? '');
            $pixKeyType = strtolower(trim($input['pix_key_type'] ?? ''));
            $label = trim($input['label'] ?? '');

            if (empty($pixKey)) {
                echo json_encode(['success' => false, 'error' => 'Chave PIX é obrigatória']);
                exit;
            }

            // Apenas CPF é aceito
            if ($pixKeyType !== 'cpf') {
                echo json_encode(['success' => false, 'error' => 'Apenas chave PIX do tipo CPF é aceita.']);
                exit;
            }

            // Normalizar CPF
            $pixKey = preg_replace('/\D/', '', $pixKey);

            if (!validatePixKey($pixKey, 'cpf')) {
                echo json_encode(['success' => false, 'error' => 'CPF inválido. Verifique o número informado.']);
                exit;
            }

            // Sanitizar label
            $label = $label ? substr($label, 0, 50) : null;

            // Verificar limite de 1 chave e duplicata atomicamente com lock
            $pdo->beginTransaction();

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM saved_pix_keys WHERE user_id = ? FOR UPDATE");
            $countStmt->execute([$userId]);
            $total = (int)$countStmt->fetchColumn();

            if ($total >= 1) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Já existe uma chave CPF salva. Remova-a antes de cadastrar outra.']);
                exit;
            }

            // Verificar se CPF já está em uso por outra conta
            $cpfInUse = $pdo->prepare("
                SELECT spk.user_id FROM saved_pix_keys spk
                WHERE spk.pix_key = ? AND spk.pix_key_type = 'cpf' AND spk.user_id != ?
                LIMIT 1
            ");
            $cpfInUse->execute([$pixKey, $userId]);
            if ($cpfInUse->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Este CPF já está cadastrado em outra conta. Cada CPF só pode ser vinculado a uma conta.']);
                exit;
            }

            // Verificar duplicata na mesma conta
            $dup = $pdo->prepare("SELECT id FROM saved_pix_keys WHERE user_id = ? AND pix_key = ?");
            $dup->execute([$userId, $pixKey]);
            if ($dup->fetch()) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Esta chave CPF já está salva']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO saved_pix_keys (user_id, pix_key, pix_key_type, label) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $pixKey, $pixKeyType, $label]);
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Chave PIX salva com sucesso', 'id' => (int)$pdo->lastInsertId()]);
            break;

        case 'delete':
            $keyId = (int)($input['key_id'] ?? 0);

            if ($keyId <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID da chave é obrigatório']);
                exit;
            }

            // Deletar apenas se pertence ao usuário
            $stmt = $pdo->prepare("DELETE FROM saved_pix_keys WHERE id = ? AND user_id = ?");
            $stmt->execute([$keyId, $userId]);

            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'Chave não encontrada']);
                exit;
            }

            echo json_encode(['success' => true, 'message' => 'Chave PIX removida']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida. Use: list, save, delete']);
    }

} catch (Throwable $e) {
    secureLog("SAVED_PIX_KEYS_ERROR | " . $e->getMessage());
    error_log("saved-pix-keys.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
