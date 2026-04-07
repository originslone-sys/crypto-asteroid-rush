<?php
// ============================================
// UNOBIX - PvP: Reembolsar créditos (busca sem match)
// api/pvp-refund.php
// Chamado pelo frontend quando jogador cancela busca
// ou timeout sem encontrar oponente
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? '');
if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
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
        echo json_encode(['success' => true, 'message' => 'Nada a reembolsar']);
        exit;
    }

    // Verificar se o jogador NÃO tem partida ativa/recente (evitar abuso)
    $stmt = $pdo->prepare("
        SELECT id FROM pvp_matches
        WHERE (player1_google_uid = ? OR player2_google_uid = ?)
          AND status IN ('waiting', 'countdown', 'active')
          AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        LIMIT 1
    ");
    $stmt->execute([$googleUid, $googleUid]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Partida ativa encontrada, reembolso não aplicável']);
        exit;
    }

    // Verificar se já não foi reembolsado recentemente (evitar duplo reembolso)
    $stmt = $pdo->prepare("
        SELECT id FROM transactions
        WHERE google_uid = ? AND type = 'pvp_refund' AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
        LIMIT 1
    ");
    $stmt->execute([$googleUid]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Reembolso já processado']);
        exit;
    }

    // Devolver créditos
    $pdo->prepare("UPDATE users SET credits = credits + ? WHERE google_uid = ?")->execute([$entryFee, $googleUid]);

    // Registrar transação
    $pdo->prepare("INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
        VALUES (?, 'pvp_refund', ?, 0, 'PvP reembolso - busca sem oponente', 'completed', NOW())")
        ->execute([$googleUid, $entryFee]);

    secureLog("PVP_REFUND | User: $googleUid | Credits: +$entryFee | Reason: no match found");

    // Buscar saldo atualizado
    $stmt = $pdo->prepare("SELECT credits FROM users WHERE google_uid = ?");
    $stmt->execute([$googleUid]);
    $credits = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => "Reembolso de {$entryFee} crédito(s) realizado",
        'credits' => $credits,
        'refunded' => $entryFee
    ]);

} catch (Throwable $e) {
    error_log("PVP-REFUND ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
