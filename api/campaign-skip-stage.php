<?php
// ============================================
// UNOBIX - Campaign Skip Stage
// api/campaign-skip-stage.php
//
// POST { google_uid, stage_id }
//
// Permite o jogador "pular" uma fase após ter tentado N vezes
// (campaign.monetization.skip_attempts, default 3) sem completar.
// Custa M créditos (campaign.monetization.skip_cost, default 5)
// e concede 1⭐ na fase. Se o admin habilitar
// campaign.monetization.skip_pays_brl=true, paga BRL base também.
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = getRequestInput();
$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? '');
$stageId   = trim($input['stage_id']   ?? $input['stageId']   ?? '');

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}
if (empty($stageId) || !preg_match('/^[a-z0-9_]+$/i', $stageId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'stage_id inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    // Settings
    $maintenance = getCampaignSetting($pdo, 'campaign.launch.maintenance', 'false') === 'true';
    if ($maintenance) {
        echo json_encode(['success' => false, 'error' => 'Modo Campanha em manutenção']);
        exit;
    }
    $killSwitch = getCampaignSetting($pdo, 'campaign.monetization.kill_switch', 'false') === 'true';
    if ($killSwitch) {
        echo json_encode(['success' => false, 'error' => 'Recurso temporariamente desabilitado']);
        exit;
    }
    $minAttempts = (int)getCampaignSetting($pdo, 'campaign.monetization.skip_attempts', 3);
    $cost        = (int)getCampaignSetting($pdo, 'campaign.monetization.skip_cost',     5);
    $paysBrl     = getCampaignSetting($pdo, 'campaign.monetization.skip_pays_brl', 'true') === 'true';

    // Carrega fase
    $stStmt = $pdo->prepare("
        SELECT stage_id, sector, brl_base, xp_reward, is_enabled, is_boss
        FROM campaign_stages WHERE stage_id = ? LIMIT 1
    ");
    $stStmt->execute([$stageId]);
    $stage = $stStmt->fetch();
    if (!$stage) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Fase não encontrada']);
        exit;
    }
    if (!(int)$stage['is_enabled']) {
        echo json_encode(['success' => false, 'error' => 'Fase desabilitada']);
        exit;
    }

    // Carrega progress da fase
    $sgStmt = $pdo->prepare("
        SELECT id, stars, attempts, wins
        FROM campaign_stage_progress
        WHERE google_uid = ? AND stage_id = ? LIMIT 1
    ");
    $sgStmt->execute([$googleUid, $stageId]);
    $sgRow = $sgStmt->fetch();
    $attempts = $sgRow ? (int)$sgRow['attempts'] : 0;
    $prevStars = $sgRow ? (int)$sgRow['stars'] : 0;
    $prevWins  = $sgRow ? (int)$sgRow['wins']  : 0;

    if ($prevWins > 0) {
        echo json_encode(['success' => false, 'error' => 'Fase já completada (use re-jogar para melhorar estrelas)']);
        exit;
    }
    if ($attempts < $minAttempts) {
        echo json_encode([
            'success' => false,
            'error'   => 'Skip ainda não disponível',
            'data'    => ['min_attempts' => $minAttempts, 'current_attempts' => $attempts],
        ]);
        exit;
    }

    // Carrega usuário
    $uStmt = $pdo->prepare("SELECT credits FROM users WHERE google_uid = ? LIMIT 1");
    $uStmt->execute([$googleUid]);
    $user = $uStmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    $brlAwarded = $paysBrl ? (float)$stage['brl_base'] : 0.0;

    $pdo->beginTransaction();
    try {
        // Debita créditos
        if ($cost > 0) {
            $debit = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE google_uid = ? AND credits >= ?");
            $debit->execute([$cost, $googleUid, $cost]);
            if ($debit->rowCount() !== 1) {
                throw new Exception('Créditos insuficientes');
            }
        }

        // Credita BRL se pays_brl=true
        if ($brlAwarded > 0) {
            $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?, updated_at = NOW() WHERE google_uid = ?")
                ->execute([$brlAwarded, $googleUid]);
        }

        // Marca a fase como vencida com 1⭐ (UPSERT)
        if ($sgRow) {
            $pdo->prepare("
                UPDATE campaign_stage_progress
                SET stars = GREATEST(stars, 1),
                    wins = wins + 1,
                    total_brl_earned = total_brl_earned + ?,
                    last_played_at = NOW(),
                    first_completed_at = COALESCE(first_completed_at, NOW())
                WHERE id = ?
            ")->execute([$brlAwarded, (int)$sgRow['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO campaign_stage_progress
                    (google_uid, stage_id, stars, attempts, wins, total_brl_earned, last_played_at, first_completed_at)
                VALUES (?, ?, 1, ?, 1, ?, NOW(), NOW())
            ")->execute([$googleUid, $stageId, $attempts, $brlAwarded]);
        }

        // Atualiza progresso global (estrelas totais e BRL diário)
        $delta = max(0, 1 - $prevStars);
        $pdo->prepare("
            INSERT IGNORE INTO campaign_progress
                (google_uid, current_level, current_lives, total_stars, created_at, updated_at)
            VALUES (?, 1, 5, 0, NOW(), NOW())
        ")->execute([$googleUid]);
        $pdo->prepare("
            UPDATE campaign_progress
            SET total_stars = total_stars + ?,
                daily_brl_earned = daily_brl_earned + ?,
                daily_brl_reset_at = ?,
                updated_at = NOW()
            WHERE google_uid = ?
        ")->execute([$delta, $brlAwarded, date('Y-m-d H:i:s'), $googleUid]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    secureLog("CAMPAIGN_SKIP | uid: $googleUid | stage: $stageId | cost: $cost | brl: $brlAwarded");

    $u2 = $pdo->prepare("SELECT credits FROM users WHERE google_uid = ? LIMIT 1");
    $u2->execute([$googleUid]);
    $userRow = $u2->fetch();

    echo json_encode([
        'success' => true,
        'data' => [
            'stage_id'   => $stageId,
            'cost'       => $cost,
            'brl_awarded'=> $brlAwarded,
            'stars'      => 1,
            'remaining_credits' => (int)($userRow['credits'] ?? 0),
        ],
    ]);

} catch (Exception $e) {
    error_log('campaign-skip-stage error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

function getCampaignSetting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}
