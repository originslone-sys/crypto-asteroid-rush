<?php
// ============================================
// UNOBIX - PvP: Debitar créditos ao iniciar partida
// api/pvp-debit.php
// Chamado pelo Game Server quando match é confirmado
// (ambos jogadores conectados e prontos)
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');

// Autenticação do Game Server
$serverToken = $_SERVER['HTTP_X_PVP_SERVER_TOKEN'] ?? '';
if ($serverToken !== PVP_JWT_SECRET) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = getRequestInput();

$player1Uid = trim($input['player1_uid'] ?? '');
$player2Uid = trim($input['player2_uid'] ?? '');
$matchId = trim($input['match_id'] ?? '');

if (!$player1Uid || !$player2Uid || !$matchId) {
    echo json_encode(['success' => false, 'error' => 'player1_uid, player2_uid e match_id são obrigatórios']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    // Carregar entry fee
    $entryFee = PVP_ENTRY_FEE_CREDITS;
    try {
        $row = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'pvp_entry_fee_credits' LIMIT 1")->fetch();
        if ($row) $entryFee = (int)$row['setting_value'];
    } catch (Exception $e) {}

    if ($entryFee <= 0) {
        echo json_encode(['success' => true, 'message' => 'Entry fee é 0, nada a debitar']);
        exit;
    }

    $pdo->beginTransaction();

    // Debitar player 1
    $stmt = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE google_uid = ? AND credits >= ?");
    $stmt->execute([$entryFee, $player1Uid, $entryFee]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Player 1 sem créditos suficientes', 'player' => $player1Uid]);
        exit;
    }

    // Debitar player 2
    $stmt = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE google_uid = ? AND credits >= ?");
    $stmt->execute([$entryFee, $player2Uid, $entryFee]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Player 2 sem créditos suficientes', 'player' => $player2Uid]);
        exit;
    }

    $pdo->commit();

    secureLog("PVP_DEBIT | Match: $matchId | P1: $player1Uid | P2: $player2Uid | Fee: $entryFee each");

    echo json_encode([
        'success' => true,
        'match_id' => $matchId,
        'entry_fee' => $entryFee,
        'debited' => [$player1Uid, $player2Uid]
    ]);

} catch (Throwable $e) {
    error_log("PVP-DEBIT ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
