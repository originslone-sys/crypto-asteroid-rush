<?php
require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================
// Input (JSON + POST)
// ===============================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet        = $input['wallet']        ?? ($_POST['wallet'] ?? '');
$sessionId     = $input['session_id']    ?? ($_POST['session_id'] ?? '');
$sessionToken  = $input['session_token'] ?? ($_POST['session_token'] ?? '');

$wallet = trim(strtolower($wallet));

if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet) || !$sessionId || !$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Erro ao conectar ao banco');

    $pdo->beginTransaction();

    // ===============================
    // Buscar sessão válida
    // ===============================
    $stmt = $pdo->prepare("
        SELECT id, earnings_usdt, status
        FROM game_sessions
        WHERE id = ?
          AND wallet_address = ?
          AND session_token = ?
          AND status = 'active'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$sessionId, $wallet, $sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        throw new Exception('Sessão inválida ou já finalizada');
    }

    // ===============================
    // Calcular ganho (já salvo pelo game-event)
    // ===============================
    $earnings = (float)$session['earnings_usdt'];
    if ($earnings < 0) $earnings = 0;

    // ===============================
    // Finalizar sessão
    // ===============================
    $stmt = $pdo->prepare("
        UPDATE game_sessions
        SET status = 'completed',
            ended_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$sessionId]);

    // ===============================
    // Atualizar saldo do player
    // ===============================
    if ($earnings > 0) {
        $stmt = $pdo->prepare("
            UPDATE players
            SET balance_usdt = balance_usdt + ?
            WHERE wallet_address = ?
        ");
        $stmt->execute([$earnings, $wallet]);
    }

    // ===============================
    // Inserir transação (compatível com schema)
    // ===============================
    if ($earnings > 0 && $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch()) {
        $cols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN, 0);

        $amountCol  = in_array('amount_usdt', $cols, true) ? 'amount_usdt' : (in_array('amount', $cols, true) ? 'amount' : null);
        $dateCol    = in_array('created_at', $cols, true) ? 'created_at' : (in_array('date', $cols, true) ? 'date' : null);

        if ($amountCol && $dateCol) {
            $sql = "
                INSERT INTO transactions (wallet_address, type, {$amountCol}, {$dateCol})
                VALUES (?, 'game_reward', ?, NOW())
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$wallet, $earnings]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'earnings_usdt' => $earnings
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("game-end error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao finalizar partida']);
}
