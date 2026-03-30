<?php
// ============================================
// UNOBIX - Histórico de Saques do Usuário
// api/withdrawal-history.php
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? '');
$limit = min(max((int)($input['limit'] ?? 10), 1), 50);
$offset = max((int)($input['offset'] ?? 0), 0);

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    // Buscar user_id
    $stmt = $pdo->prepare("SELECT id FROM users WHERE google_uid = ?");
    $stmt->execute([$googleUid]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    $userId = (int)$user['id'];

    // Buscar saques (limit + 1 para saber se tem mais)
    $stmt = $pdo->prepare("
        SELECT id, amount_brl, wallet_address, admin_notes, status, created_at, processed_at
        FROM withdrawals
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $limit + 1, $offset]);
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    $statusLabels = [
        'pending' => 'Pendente',
        'processing' => 'Processando',
        'completed' => 'Concluído',
        'rejected' => 'Rejeitado',
        'failed' => 'Falhou'
    ];

    $formatted = [];
    foreach ($rows as $w) {
        $details = json_decode($w['admin_notes'] ?? '', true);
        $pixKey = $details['details'] ?? null;
        $pixKeyType = $details['pix_key_type'] ?? null;

        // Mascarar chave PIX
        $maskedKey = $pixKey;
        if ($pixKey) {
            if ($pixKeyType === 'cpf') {
                $d = preg_replace('/\D/', '', $pixKey);
                $maskedKey = substr($d, 0, 3) . '.***.***-' . substr($d, -2);
            } elseif ($pixKeyType === 'email' && strpos($pixKey, '@') !== false) {
                $parts = explode('@', $pixKey);
                $maskedKey = substr($parts[0], 0, 2) . '***@' . $parts[1];
            } elseif ($pixKeyType === 'phone') {
                $d = preg_replace('/\D/', '', $pixKey);
                $maskedKey = '(**)*****-' . substr($d, -4);
            } elseif (strlen($pixKey) > 12) {
                $maskedKey = substr($pixKey, 0, 8) . '...' . substr($pixKey, -4);
            }
        }

        // Motivo da rejeição (quando rejeitado, admin_notes é texto direto, não JSON)
        $rejectReason = null;
        if ($w['status'] === 'rejected' && !$details) {
            $rejectReason = $w['admin_notes'];
        }

        $formatted[] = [
            'id' => (int)$w['id'],
            'amount_brl' => round((float)$w['amount_brl'], 2),
            'method' => strtoupper($w['wallet_address'] ?? 'PIX'),
            'pix_key' => $maskedKey,
            'pix_key_type' => $pixKeyType,
            'status' => $w['status'],
            'status_label' => $statusLabels[$w['status']] ?? $w['status'],
            'reject_reason' => $rejectReason,
            'created_at' => $w['created_at'],
            'processed_at' => $w['processed_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'withdrawals' => $formatted,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => $hasMore
    ]);

} catch (Throwable $e) {
    error_log("withdrawal-history.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar histórico de saques']);
}
