<?php
// ============================================
// UNOBIX - Campaign Buy Life
// api/campaign-buy-life.php
//
// POST { google_uid, package: 'single' | 'pack5' | 'refill' }
//
// Compra de vidas com créditos. Custos vêm de campaign_settings:
//   campaign.lives.cost_single  (default 1)  → +1 vida
//   campaign.lives.cost_pack5   (default 4)  → +5 vidas (clamp ao max)
//   campaign.lives.cost_refill  (default 3)  → enche até max
// Resposta: créditos restantes, vidas atuais, próxima recarga.
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
$package   = trim($input['package']    ?? '');

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}
if (!in_array($package, ['single', 'pack5', 'refill'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'package inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    $maxLives    = (int)getCampaignSetting($pdo, 'campaign.lives.max', 5);
    $costSingle  = (int)getCampaignSetting($pdo, 'campaign.lives.cost_single', 1);
    $costPack5   = (int)getCampaignSetting($pdo, 'campaign.lives.cost_pack5',  4);
    $costRefill  = (int)getCampaignSetting($pdo, 'campaign.lives.cost_refill', 3);

    // Auto-init de progress
    $pdo->prepare("
        INSERT IGNORE INTO campaign_progress
            (google_uid, current_level, total_xp, current_lives, total_stars, created_at, updated_at)
        VALUES (?, 1, 0, ?, 0, NOW(), NOW())
    ")->execute([$googleUid, $maxLives]);

    // Lock e leitura do progresso atual
    $pdo->beginTransaction();
    try {
        $pStmt = $pdo->prepare("SELECT current_lives, next_life_at FROM campaign_progress WHERE google_uid = ? FOR UPDATE");
        $pStmt->execute([$googleUid]);
        $p = $pStmt->fetch();
        $cur = (int)($p['current_lives'] ?? 0);

        if ($cur >= $maxLives) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Vidas já estão no máximo']);
            exit;
        }

        // Decide custo + delta
        $cost = 0; $delta = 0;
        switch ($package) {
            case 'single':
                $cost = $costSingle; $delta = 1; break;
            case 'pack5':
                $cost = $costPack5;  $delta = 5; break;
            case 'refill':
                $cost = $costRefill; $delta = $maxLives - $cur; break;
        }
        if ($delta <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Pacote sem efeito']);
            exit;
        }

        // Debita créditos
        $debit = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE google_uid = ? AND credits >= ?");
        $debit->execute([$cost, $googleUid, $cost]);
        if ($debit->rowCount() !== 1) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Créditos insuficientes']);
            exit;
        }

        $newLives = min($maxLives, $cur + $delta);
        $newNextLifeAt = $p['next_life_at']; // mantém se ainda não estiver cheio
        if ($newLives >= $maxLives) {
            $newNextLifeAt = null;
        }

        $pdo->prepare("
            UPDATE campaign_progress
            SET current_lives = ?, next_life_at = ?, updated_at = NOW()
            WHERE google_uid = ?
        ")->execute([$newLives, $newNextLifeAt, $googleUid]);

        $pdo->commit();

        $u = $pdo->prepare("SELECT credits FROM users WHERE google_uid = ? LIMIT 1");
        $u->execute([$googleUid]);
        $userRow = $u->fetch();

        secureLog("CAMPAIGN_BUY_LIFE | uid: $googleUid | package: $package | cost: $cost | delta: $delta | newLives: $newLives");

        echo json_encode([
            'success' => true,
            'data' => [
                'package' => $package,
                'cost'    => $cost,
                'delta'   => (int)($newLives - $cur),
                'lives'   => [
                    'current'      => $newLives,
                    'max'          => $maxLives,
                    'next_life_at' => $newNextLifeAt,
                ],
                'remaining_credits' => (int)($userRow['credits'] ?? 0),
            ],
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('campaign-buy-life error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

function getCampaignSetting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}
