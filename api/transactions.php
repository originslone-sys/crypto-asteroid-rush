<?php
// ============================================
// UNOBIX - Histórico de Transações
// Arquivo: api/transactions.php
// v2.0 - Google Auth + BRL
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// ----------------------------
// Input (JSON + POST + GET)
// ----------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$googleUid = $input['google_uid'] ?? ($_POST['google_uid'] ?? ($_GET['google_uid'] ?? ''));
$wallet = $input['wallet'] ?? ($_POST['wallet'] ?? ($_GET['wallet'] ?? ''));
$limit = (int)($input['limit'] ?? ($_GET['limit'] ?? 50));
$offset = (int)($input['offset'] ?? ($_GET['offset'] ?? 0));
$type = $input['type'] ?? ($_GET['type'] ?? ''); // Filtro por tipo

$googleUid = trim($googleUid);
$wallet = trim(strtolower($wallet));
$limit = min(max($limit, 1), 100); // Entre 1 e 100
$offset = max($offset, 0);

// Determinar identificador
$identifier = '';
$identifierType = '';

if (!empty($googleUid) && validateGoogleUid($googleUid)) {
    $identifier = $googleUid;
    $identifierType = 'google_uid';
} elseif (!empty($wallet) && validateWallet($wallet)) {
    $identifier = $wallet;
    $identifierType = 'wallet';
} else {
    echo json_encode(['success' => false, 'error' => 'Identificação inválida']);
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
            'offset' => $offset
        ]);
        exit;
    }

    // Detectar colunas disponíveis
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[] = $row['Field'];
    }

    $amountCol = in_array('amount_brl', $cols) ? 'amount_brl' : 
                 (in_array('amount_usdt', $cols) ? 'amount_usdt' : 'amount');
    $dateCol = in_array('created_at', $cols) ? 'created_at' : 'date';
    $hasGoogleUid = in_array('google_uid', $cols);

    // Construir WHERE
    $whereConditions = [];
    $params = [];

    if ($hasGoogleUid && $identifierType === 'google_uid') {
        $whereConditions[] = "(google_uid = ? OR LOWER(wallet_address) = ?)";
        $params[] = $identifier;
        $params[] = $identifier;
    } else {
        $whereConditions[] = "LOWER(wallet_address) = ?";
        $params[] = $identifier;
    }

    // Filtro por tipo
    $validTypes = ['stake', 'unstake', 'deposit', 'withdraw', 'game_reward', 'referral_commission'];
    if (!empty($type) && in_array($type, $validTypes)) {
        $whereConditions[] = "type = ?";
        $params[] = $type;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Contar total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE {$whereClause}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // Buscar transações
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare("
        SELECT 
            id,
            " . ($hasGoogleUid ? "google_uid," : "") . "
            wallet_address,
            type,
            {$amountCol} as amount,
            description,
            status,
            tx_hash,
            {$dateCol} as created_at
        FROM transactions
        WHERE {$whereClause}
        ORDER BY {$dateCol} DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatar transações
    $formattedTransactions = [];
    foreach ($transactions as $tx) {
        $amount = (float)($tx['amount'] ?? 0);
        
        // Determinar se é entrada ou saída
        $isCredit = in_array($tx['type'], ['game_reward', 'referral_commission', 'unstake', 'deposit', 'withdrawal_rejected']);
        
        $formattedTransactions[] = [
            'id' => (int)$tx['id'],
            'type' => $tx['type'],
            'type_label' => getTypeLabel($tx['type']),
            'amount_brl' => round($amount, 2),
            'amount_formatted' => ($isCredit ? '+' : '-') . ' R$ ' . number_format($amount, 2, ',', '.'),
            'is_credit' => $isCredit,
            'description' => $tx['description'] ?? '',
            'status' => $tx['status'] ?? 'completed',
            'status_label' => getStatusLabel($tx['status'] ?? 'completed'),
            'tx_hash' => $tx['tx_hash'] ?? null,
            'created_at' => $tx['created_at'],
            'created_at_formatted' => date('d/m/Y H:i', strtotime($tx['created_at']))
        ];
    }

    echo json_encode([
        'success' => true,
        'transactions' => $formattedTransactions,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total
    ]);

} catch (Exception $e) {
    error_log("transactions.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar transações']);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function getTypeLabel($type) {
    $labels = [
        'stake' => 'Stake',
        'unstake' => 'Unstake',
        'deposit' => 'Depósito',
        'withdraw' => 'Saque',
        'game_reward' => 'Recompensa de Missão',
        'referral_commission' => 'Comissão de Indicação',
        'withdrawal_rejected' => 'Saque Rejeitado (Estorno)'
    ];
    return $labels[$type] ?? ucfirst($type);
}

function getStatusLabel($status) {
    $labels = [
        'pending' => 'Pendente',
        'completed' => 'Concluído',
        'success' => 'Sucesso',
        'approved' => 'Aprovado',
        'failed' => 'Falhou',
        'rejected' => 'Rejeitado',
        'cancelled' => 'Cancelado'
    ];
    return $labels[$status] ?? ucfirst($status);
}
