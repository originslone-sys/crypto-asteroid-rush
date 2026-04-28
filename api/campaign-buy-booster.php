<?php
// ============================================
// UNOBIX - Campaign Buy Booster
// api/campaign-buy-booster.php
//
// POST { google_uid, type: 'triple_star' }
//
// Compra um booster para a próxima fase. Marca pending_booster
// em campaign_progress; o booster será consumido em
// campaign-start.php e aplicado ao engine (shield no início).
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
$type      = trim($input['type']       ?? '');

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}
if ($type !== 'triple_star') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'type inválido']);
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
    $cost = (int)getCampaignSetting($pdo, 'campaign.monetization.booster_cost', 3);

    // Auto-init de progress
    $maxLives = (int)getCampaignSetting($pdo, 'campaign.lives.max', 5);
    $pdo->prepare("
        INSERT IGNORE INTO campaign_progress
            (google_uid, current_level, total_xp, current_lives, total_stars, created_at, updated_at)
        VALUES (?, 1, 0, ?, 0, NOW(), NOW())
    ")->execute([$googleUid, $maxLives]);

    // Detecta coluna pending_booster (migrate pode não ter rodado ainda)
    $hasBooster = false;
    try {
        $hasBooster = (bool)$pdo->query("SHOW COLUMNS FROM campaign_progress LIKE 'pending_booster'")->fetch();
    } catch (Exception $e) {}
    if (!$hasBooster) {
        echo json_encode(['success' => false, 'error' => 'Boosters indisponíveis no servidor (rodar migration mais recente).']);
        exit;
    }

    // Verifica se já tem booster armado
    $checkStmt = $pdo->prepare("SELECT pending_booster FROM campaign_progress WHERE google_uid = ? LIMIT 1");
    $checkStmt->execute([$googleUid]);
    $check = $checkStmt->fetch();
    if ($check && !empty($check['pending_booster'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Você já tem um booster armado. Use-o na próxima fase.',
            'data' => ['pending_booster' => $check['pending_booster']],
        ]);
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
        $pdo->prepare("UPDATE campaign_progress SET pending_booster = ?, updated_at = NOW() WHERE google_uid = ?")
            ->execute([$type, $googleUid]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $u = $pdo->prepare("SELECT credits FROM users WHERE google_uid = ? LIMIT 1");
    $u->execute([$googleUid]);
    $userRow = $u->fetch();

    secureLog("CAMPAIGN_BUY_BOOSTER | uid: $googleUid | type: $type | cost: $cost");

    echo json_encode([
        'success' => true,
        'data' => [
            'type' => $type,
            'cost' => $cost,
            'remaining_credits' => (int)($userRow['credits'] ?? 0),
        ],
    ]);

} catch (Exception $e) {
    error_log('campaign-buy-booster error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

function getCampaignSetting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}
