<?php
// ============================================
// UNOBIX - Histórico de Transações
// Arquivo: api/transactions.php
// v3.0 - Unobix-only (google_uid) + BRL-only + compat schema
// ============================================

// ============================================================
// JSON Guard (Railway): impedir HTML/Warnings de quebrar JSON
// ============================================================
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

if (!ob_get_level()) {
    ob_start();
}

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) { ob_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erro no servidor',
            'debug_error' => $err['message'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});
// ============================================================

require_once __DIR__ . "/config.php";

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

// ----------------------------
// Input (JSON + POST + GET)
// ----------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$googleUid = $input['google_uid'] ?? ($_POST['google_uid'] ?? ($_GET['google_uid'] ?? ''));
$limit  = (int)($input['limit'] ?? ($_GET['limit'] ?? 50));
$offset = (int)($input['offset'] ?? ($_GET['offset'] ?? 0));
$type   = $input['type'] ?? ($_GET['type'] ?? ''); // filtro opcional

$googleUid = trim($googleUid);
$limit  = min(max($limit, 1), 100);
$offset = max($offset, 0);
$type   = is_string($type) ? trim($type) : '';

if ($googleUid === '' || !validateGoogleUid($googleUid)) {
    if (ob_get_length()) { ob_clean(); }
    echo json_encode(['success' => false, 'error' => 'Identificação inválida. Envie google_uid.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    // Verificar se tabela existe
    $tableExists = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    if (!$tableExists) {
        if (ob_get_length()) { ob_clean(); }
        echo json_encode([
            'success' => true,
            'transactions' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => false
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Detectar colunas disponíveis (compat)
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[] = $row['Field'];
    }

    $hasGoogleUid = in_array('google_uid', $cols, true);
    if (!$hasGoogleUid) {
        // Se não existe google_uid no schema, este endpoint não pode funcionar no modo Unobix-only
        if (ob_get_length()) { ob_clean(); }
        echo json_encode([
            'success' => false,
            'error' => 'Schema antigo: transactions.google_uid não existe. Execute migração do banco.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $amountCol = in_array('amount_brl', $cols, true) ? 'amount_brl' : (in_array('amount', $cols, true) ? 'amount' : null);
    $dateCol   = in_array('created_at', $cols, true) ? 'created_at' : (in_array('date', $cols, true) ? 'date' : null);
    $hasTxHash = in_array('tx_hash', $cols, true);

    if (!$amountCol || !$dateCol) {
        if (ob_get_length()) { ob_clean(); }
        echo json_encode([
            'success' => false,
            'error' => 'Schema inválido: transactions precisa ter amount_brl/amount e created_at/date.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Filtro por tipo (Unobix)
    $validTypes = [
        'game_reward',
        'stake',
        'unstake',
        'withdraw',          // solicitação de saque
        'withdraw_reject',   // estorno/rejeição (se você usar)
        'referral_commission',
        'deposit'            // se existir no futuro
    ];

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
    $paramsList = $params;
    $paramsList[] = $limit;
    $paramsList[] = $offset;

    $selectTxHash = $hasTxHash ? "tx_hash," : "NULL AS tx_hash,";

    $stmt = $pdo->prepare("
        SELECT
            id,
            google_uid,
            type,
            {$amountCol} AS amount_brl,
            description,
            status,
            {$selectTxHash}
            {$dateCol} AS created_at
        FROM transactions
        WHERE {$whereClause}
        ORDER BY {$dateCol} DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($paramsList);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Regras de crédito/débito (Unobix)
    $creditTypes = ['game_reward', 'referral_commission', 'unstake', 'deposit', 'withdraw_reject'];
    $debitTypes  = ['stake', 'withdraw'];

    $formatted = [];
    foreach ($rows as $tx) {
        $amount = (float)($tx['amount_brl'] ?? 0);

        // Normalizar status
        $status = $tx['status'] ?? 'completed';

        $isCredit = in_array($tx['type'], $creditTypes, true);
        $isDebit  = in_array($tx['type'], $debitTypes, true);

        // fallback: se não bater em nenhuma lista, decide pelo sinal
        if (!$isCredit && !$isDebit) {
            $isCredit = ($amount >= 0);
        }

        $absAmount = abs($amount);

        $formatted[] = [
            'id' => (int)$tx['id'],
            'type' => $tx['type'],
            'type_label' => getTypeLabel($tx['type']),
            'amount_brl' => round($absAmount, 2),
            'amount_signed_brl' => round($isCredit ? $absAmount : -$absAmount, 2),
            'amount_formatted' => ($isCredit ? '+' : '-') . ' R$ ' . number_format($absAmount, 2, ',', '.'),
            'is_credit' => $isCredit,
            'description' => $tx['description'] ?? '',
            'status' => $status,
            'status_label' => getStatusLabel($status),
            'tx_hash' => $tx['tx_hash'] ?? null, // pode existir em legado; não depende disso
            'created_at' => $tx['created_at'],
            'created_at_formatted' => date('d/m/Y H:i', strtotime($tx['created_at']))
        ];
    }

    if (ob_get_length()) { ob_clean(); }
    echo json_encode([
        'success' => true,
        'transactions' => $formatted,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log("transactions.php error: " . $e->getMessage());
    if (ob_get_length()) { ob_clean(); }
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar transações'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        'withdraw_reject' => 'Saque Rejeitado (Estorno)'
    ];
    return $labels[$type] ?? ucfirst((string)$type);
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
    return $labels[$status] ?? ucfirst((string)$status);
}
