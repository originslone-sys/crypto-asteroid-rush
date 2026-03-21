<?php
// ============================================
// UNOBIX - Histórico de Transações
// Arquivo: api/transactions.php v4.0
// Usa config.php
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? '');
$limit = min(max((int)($input['limit'] ?? 50), 1), 100);
$offset = max((int)($input['offset'] ?? 0), 0);
$type = trim($input['type'] ?? '');

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    // Verificar se tabela existe
    $tableExists = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    if (!$tableExists) {
        echo json_encode([
            'success' => true,
            'transactions' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => false
        ]);
        exit;
    }

    // Tipos válidos
    $validTypes = ['game_reward', 'stake', 'unstake', 'withdraw', 'withdraw_reject', 'referral_commission', 'deposit', 'admin_adjust', 'credit_purchase'];

    $where = ["google_uid = ?"];
    $params = [$googleUid];

    if ($type !== '' && in_array($type, $validTypes, true)) {
        $where[] = "type = ?";
        $params[] = $type;
    }

    $whereClause = implode(' AND ', $where);

    // Total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE {$whereClause}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // Buscar
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare("
        SELECT id, google_uid, type, amount_brl, description, status, created_at
        FROM transactions
        WHERE {$whereClause}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Tipos de crédito/débito
    $creditTypes = ['game_reward', 'referral_commission', 'unstake', 'deposit', 'withdraw_reject', 'admin_adjust'];
    $debitTypes = ['stake', 'withdraw'];

    $formatted = [];
    foreach ($rows as $tx) {
        $amount = (float)($tx['amount_brl'] ?? 0);
        $isCredit = in_array($tx['type'], $creditTypes, true);
        $absAmount = abs($amount);

        $formatted[] = [
            'id' => (int)$tx['id'],
            'type' => $tx['type'],
            'type_label' => getTypeLabel($tx['type']),
            'amount_brl' => round($absAmount, 6),
            'amount_signed_brl' => round($isCredit ? $absAmount : -$absAmount, 6),
            'amount_formatted' => ($isCredit ? '+' : '-') . ' R$ ' . number_format($absAmount, 6, ',', '.'),
            'is_credit' => $isCredit,
            'description' => $tx['description'] ?? '',
            'status' => $tx['status'] ?? 'completed',
            'status_label' => getStatusLabel($tx['status'] ?? 'completed'),
            'created_at' => $tx['created_at'],
            'created_at_formatted' => date('d/m/Y H:i', strtotime($tx['created_at']))
        ];
    }

    echo json_encode([
        'success' => true,
        'transactions' => $formatted,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total
    ]);

} catch (Throwable $e) {
    error_log("transactions.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar transações']);
}

function getTypeLabel($type) {
    $labels = [
        'stake' => 'Stake',
        'unstake' => 'Unstake',
        'deposit' => 'Depósito',
        'withdraw' => 'Saque',
        'game_reward' => 'Recompensa de Missão',
        'referral_commission' => 'Comissão de Indicação',
        'withdraw_reject' => 'Saque Rejeitado (Estorno)',
        'admin_adjust' => 'Ajuste Administrativo',
        'credit_purchase' => 'Compra de Créditos'
    ];
    return $labels[$type] ?? ucfirst((string)$type);
}

function getStatusLabel($status) {
    $labels = [
        'pending' => 'Pendente',
        'completed' => 'Concluído',
        'success' => 'Sucesso',
        'approved' => 'Aprovado',
        'processing' => 'Processando',
        'confirmed' => 'Confirmado',
        'failed' => 'Falhou',
        'rejected' => 'Rejeitado',
        'cancelled' => 'Cancelado',
        'expired' => 'Expirado'
    ];
    return $labels[$status] ?? ucfirst((string)$status);
}
