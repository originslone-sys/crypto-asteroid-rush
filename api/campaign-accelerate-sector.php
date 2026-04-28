<?php
// ============================================
// UNOBIX - Campaign Accelerate Sector
// api/campaign-accelerate-sector.php
//
// POST { google_uid, sector: 1|2 }
//
// Compra um setor inteiro com 1⭐ em cada fase. NÃO entrega o BRL
// das fases — apenas o desbloqueio + estrelas. Custo lido de
// campaign_settings (campaign.monetization.accelerate_sN, default
// 20 para S1, 30 para S2).
//
// Recusa se o jogador já completou todas as fases do setor com
// pelo menos 1⭐ (não há nada a adquirir).
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
$sector    = (int)($input['sector'] ?? 0);

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}
if (!in_array($sector, [1, 2], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'sector inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    $killSwitch = getCampaignSetting($pdo, 'campaign.monetization.kill_switch', 'false') === 'true';
    if ($killSwitch) {
        echo json_encode(['success' => false, 'error' => 'Recurso desabilitado']);
        exit;
    }
    if ($sector === 2) {
        $sec2On = getCampaignSetting($pdo, 'campaign.launch.sector2_enabled', 'false') === 'true';
        if (!$sec2On) {
            echo json_encode(['success' => false, 'error' => 'Setor 2 ainda não disponível']);
            exit;
        }
    }
    $cost = (int)getCampaignSetting($pdo, "campaign.monetization.accelerate_s{$sector}", $sector === 1 ? 20 : 30);

    // Carrega fases ativas do setor (apenas as não-boss para preservar a graça do boss)
    // Decisão: incluir bosses também (spec: "5 fases com 1⭐").
    $stStmt = $pdo->prepare("
        SELECT stage_id FROM campaign_stages
        WHERE sector = ? AND is_enabled = 1
        ORDER BY order_in_sector
    ");
    $stStmt->execute([$sector]);
    $stageIds = array_column($stStmt->fetchAll(), 'stage_id');
    if (empty($stageIds)) {
        echo json_encode(['success' => false, 'error' => 'Setor sem fases']);
        exit;
    }

    // Verifica se ainda há algo a desbloquear (pelo menos uma fase não tem 1⭐)
    $placeholders = implode(',', array_fill(0, count($stageIds), '?'));
    $sgStmt = $pdo->prepare("
        SELECT stage_id, stars, wins
        FROM campaign_stage_progress
        WHERE google_uid = ? AND stage_id IN ($placeholders)
    ");
    $sgStmt->execute(array_merge([$googleUid], $stageIds));
    $existing = [];
    while ($row = $sgStmt->fetch()) $existing[$row['stage_id']] = $row;

    $needsUpdate = false;
    foreach ($stageIds as $sid) {
        $cur = $existing[$sid] ?? null;
        if (!$cur || (int)$cur['stars'] < 1) { $needsUpdate = true; break; }
    }
    if (!$needsUpdate) {
        echo json_encode(['success' => false, 'error' => 'Setor já tem 1⭐ em todas as fases']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        if ($cost > 0) {
            $debit = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE google_uid = ? AND credits >= ?");
            $debit->execute([$cost, $googleUid, $cost]);
            if ($debit->rowCount() !== 1) {
                throw new Exception('Créditos insuficientes');
            }
        }

        // Garante campaign_progress
        $pdo->prepare("
            INSERT IGNORE INTO campaign_progress
                (google_uid, current_level, current_lives, total_stars, created_at, updated_at)
            VALUES (?, 1, 5, 0, NOW(), NOW())
        ")->execute([$googleUid]);

        $totalDelta = 0;
        $upsertStmt = $pdo->prepare("
            INSERT INTO campaign_stage_progress
                (google_uid, stage_id, stars, attempts, wins, last_played_at, first_completed_at)
            VALUES (?, ?, 1, 1, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                stars = GREATEST(stars, 1),
                wins = wins + IF(wins = 0, 1, 0),
                first_completed_at = COALESCE(first_completed_at, NOW()),
                last_played_at = NOW()
        ");
        foreach ($stageIds as $sid) {
            $cur = $existing[$sid] ?? null;
            $prevStars = $cur ? (int)$cur['stars'] : 0;
            if ($prevStars < 1) $totalDelta += 1;
            $upsertStmt->execute([$googleUid, $sid]);
        }

        $pdo->prepare("
            UPDATE campaign_progress
            SET total_stars = total_stars + ?, updated_at = NOW()
            WHERE google_uid = ?
        ")->execute([$totalDelta, $googleUid]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $u = $pdo->prepare("SELECT credits FROM users WHERE google_uid = ? LIMIT 1");
    $u->execute([$googleUid]);
    $userRow = $u->fetch();

    secureLog("CAMPAIGN_ACCELERATE | uid: $googleUid | sector: $sector | cost: $cost | stars_delta: $totalDelta");

    echo json_encode([
        'success' => true,
        'data' => [
            'sector'      => $sector,
            'cost'        => $cost,
            'stars_added' => $totalDelta,
            'remaining_credits' => (int)($userRow['credits'] ?? 0),
        ],
    ]);

} catch (Exception $e) {
    error_log('campaign-accelerate-sector error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

function getCampaignSetting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}
