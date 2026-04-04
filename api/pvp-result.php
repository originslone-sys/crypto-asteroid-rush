<?php
// ============================================
// UNOBIX - PvP: Registrar resultado da partida
// api/pvp-result.php
// Chamado pelo Game Server ao final da partida PvP
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');

// Autenticação do Game Server: token + whitelist de IP
$serverToken = $_SERVER['HTTP_X_PVP_SERVER_TOKEN'] ?? '';
if ($serverToken !== PVP_JWT_SECRET) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Whitelist de IP do Game Server (aceita IP da VM ou localhost para testes)
$pvpAllowedIps = array_filter(array_map('trim', explode(',', getenv('PVP_ALLOWED_IPS') ?: '34.138.147.143,127.0.0.1,::1')));
$callerIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($callerIp, ',') !== false) $callerIp = trim(explode(',', $callerIp)[0]);
if (!in_array($callerIp, $pvpAllowedIps, true)) {
    error_log("❌ PVP-RESULT: IP bloqueado: $callerIp");
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'IP not allowed']);
    exit;
}

$input = getRequestInput();

// Validar campos obrigatórios
$required = ['match_id', 'player1_uid', 'player2_uid', 'status', 'win_condition'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        echo json_encode(['success' => false, 'error' => "Campo obrigatório: $field"]);
        exit;
    }
}

$matchId = $input['match_id'];
$player1Uid = $input['player1_uid'];
$player2Uid = $input['player2_uid'];
$winnerUid = $input['winner_uid'] ?? null;
$status = $input['status']; // completed, cancelled
$winCondition = $input['win_condition'];

// Whitelist win_condition
$validConditions = ['elimination', 'time_lives', 'time_asteroids', 'disconnect', 'draw', 'cancelled'];
if (!in_array($winCondition, $validConditions, true)) {
    echo json_encode(['success' => false, 'error' => 'win_condition inválido']);
    exit;
}

// Stats com bounds seguros
$p1Lives = max(0, (int)($input['player1_lives'] ?? 0));
$p2Lives = max(0, (int)($input['player2_lives'] ?? 0));
$p1Asteroids = max(0, (int)($input['player1_asteroids_destroyed'] ?? 0));
$p2Asteroids = max(0, (int)($input['player2_asteroids_destroyed'] ?? 0));
$p1Shots = max(0, (int)($input['player1_shots_fired'] ?? 0));
$p2Shots = max(0, (int)($input['player2_shots_fired'] ?? 0));
$p1Hits = max(0, min($p1Shots, (int)($input['player1_hits'] ?? 0))); // hits não pode exceder shots
$p2Hits = max(0, min($p2Shots, (int)($input['player2_hits'] ?? 0)));
$gameDuration = max(0, min(700, (int)($input['game_duration'] ?? 0))); // máx 700s (margem sobre 600s)

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    // Carregar valores dinâmicos do admin
    $entryFee = PVP_ENTRY_FEE_CREDITS;
    $winnerPrizeBrl = 0;
    try {
        $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key IN ('pvp_entry_fee_credits', 'pvp_winner_prize_brl')");
        while ($row = $settingsStmt->fetch()) {
            if ($row['setting_key'] === 'pvp_entry_fee_credits') $entryFee = (int)$row['setting_value'];
            if ($row['setting_key'] === 'pvp_winner_prize_brl') $winnerPrizeBrl = (float)$row['setting_value'];
        }
    } catch (Exception $e) { /* usa constantes padrão */ }

    // Verificar se match já foi processado
    $stmt = $pdo->prepare("SELECT id, status FROM pvp_matches WHERE match_id = ?");
    $stmt->execute([$matchId]);
    $existing = $stmt->fetch();

    if ($existing && in_array($existing['status'], ['completed', 'cancelled'], true)) {
        echo json_encode(['success' => false, 'error' => 'Partida já processada']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        if ($existing) {
            // Atualizar partida existente
            $stmt = $pdo->prepare("
                UPDATE pvp_matches SET
                    winner_google_uid = ?,
                    status = ?,
                    win_condition = ?,
                    player1_lives = ?,
                    player2_lives = ?,
                    player1_asteroids_destroyed = ?,
                    player2_asteroids_destroyed = ?,
                    player1_shots_fired = ?,
                    player2_shots_fired = ?,
                    player1_hits = ?,
                    player2_hits = ?,
                    game_duration = ?,
                    ended_at = NOW()
                WHERE match_id = ?
            ");
            $stmt->execute([
                $winnerUid, $status, $winCondition,
                $p1Lives, $p2Lives, $p1Asteroids, $p2Asteroids,
                $p1Shots, $p2Shots, $p1Hits, $p2Hits,
                $gameDuration, $matchId
            ]);
        } else {
            // Criar registro da partida
            $stmt = $pdo->prepare("
                INSERT INTO pvp_matches (
                    match_id, player1_google_uid, player2_google_uid,
                    winner_google_uid, status, win_condition,
                    entry_fee_credits, winner_prize_credits,
                    player1_lives, player2_lives,
                    player1_asteroids_destroyed, player2_asteroids_destroyed,
                    player1_shots_fired, player2_shots_fired,
                    player1_hits, player2_hits,
                    game_duration, started_at, ended_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? SECOND), NOW())
            ");
            $stmt->execute([
                $matchId, $player1Uid, $player2Uid,
                $winnerUid, $status, $winCondition,
                $entryFee, $winnerPrizeBrl,
                $p1Lives, $p2Lives, $p1Asteroids, $p2Asteroids,
                $p1Shots, $p2Shots, $p1Hits, $p2Hits,
                $gameDuration, $gameDuration
            ]);
        }

        // Creditar prêmio em BRL ao vencedor (se houve vencedor, partida completada, e prêmio > 0)
        if ($status === 'completed' && $winnerUid && $winCondition !== 'draw' && $winnerPrizeBrl > 0) {
            $stmt = $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?, total_earned_brl = total_earned_brl + ? WHERE google_uid = ?");
            $stmt->execute([$winnerPrizeBrl, $winnerPrizeBrl, $winnerUid]);

            // Registrar transação do vencedor
            $loserUid = ($winnerUid === $player1Uid) ? $player2Uid : $player1Uid;
            $pdo->prepare("
                INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
                VALUES (?, 'pvp_win', 0, ?, ?, 'completed', NOW())
            ")->execute([
                $winnerUid,
                $winnerPrizeBrl,
                "PvP vitória R$ " . number_format($winnerPrizeBrl, 2, ',', '.') . " (match: " . substr($matchId, 0, 8) . ")"
            ]);

            // Registrar transação do perdedor (entry fee em créditos já foi debitado no authorize)
            $pdo->prepare("
                INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
                VALUES (?, 'pvp_loss', 0, 0, ?, 'completed', NOW())
            ")->execute([
                $loserUid,
                "PvP derrota (match: " . substr($matchId, 0, 8) . ")"
            ]);
        }

        // Em caso de empate, reembolsar ambos (metade do entry fee)
        if ($status === 'completed' && $winCondition === 'draw' && $entryFee > 0) {
            $refund = max(1, intdiv($entryFee, 2));
            $pdo->prepare("UPDATE users SET credits = credits + ? WHERE google_uid = ?")->execute([$refund, $player1Uid]);
            $pdo->prepare("UPDATE users SET credits = credits + ? WHERE google_uid = ?")->execute([$refund, $player2Uid]);

            $pdo->prepare("
                INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
                VALUES (?, 'pvp_draw', ?, 0, 'PvP empate - reembolso parcial', 'completed', NOW()),
                       (?, 'pvp_draw', ?, 0, 'PvP empate - reembolso parcial', 'completed', NOW())
            ")->execute([$player1Uid, $refund, $player2Uid, $refund]);
        }

        // Se cancelado (sem oponente/disconnect antes de começar), reembolsar ambos
        if ($status === 'cancelled' && $entryFee > 0) {
            $pdo->prepare("UPDATE users SET credits = credits + ? WHERE google_uid = ?")->execute([$entryFee, $player1Uid]);
            $pdo->prepare("UPDATE users SET credits = credits + ? WHERE google_uid = ?")->execute([$entryFee, $player2Uid]);
            // Registrar auditoria do reembolso
            $cancelDesc = "PvP cancelado - reembolso " . $entryFee . " crédito(s) (match: " . substr($matchId, 0, 8) . ")";
            $pdo->prepare("INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at) VALUES (?, 'pvp_refund', ?, 0, ?, 'completed', NOW())")
                ->execute([$player1Uid, $entryFee, $cancelDesc]);
            $pdo->prepare("INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at) VALUES (?, 'pvp_refund', ?, 0, ?, 'completed', NOW())")
                ->execute([$player2Uid, $entryFee, $cancelDesc]);
        }

        // Se disconnect durante partida, reembolsar quem ficou (vencedor ganha prêmio)
        if ($status === 'completed' && $winCondition === 'disconnect' && $winnerUid) {
            // Vencedor já foi creditado acima
            // Nada extra necessário
        }

        $pdo->commit();

        secureLog("PVP_RESULT | Match: $matchId | Winner: " . ($winnerUid ?: 'DRAW') . " | Condition: $winCondition | Duration: {$gameDuration}s");

        echo json_encode([
            'success' => true,
            'match_id' => $matchId,
            'winner_uid' => $winnerUid,
            'status' => $status,
            'win_condition' => $winCondition,
            'prize_credited' => ($status === 'completed' && $winnerUid && $winCondition !== 'draw') ? $winnerPrizeBrl : 0
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Throwable $e) {
    error_log("❌ PVP-RESULT ERROR: " . $e->getMessage() . " | Line: " . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
