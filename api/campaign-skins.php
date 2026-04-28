<?php
// ============================================
// UNOBIX - Campaign Skins (listar/comprar/equipar)
// api/campaign-skins.php
//
// GET  ?google_uid=...                  → lista todas as skins
//                                         habilitadas + flags do
//                                         jogador (owned, equipped).
// POST { google_uid, action: 'buy'    }   → debita créditos, registra
//                                            em campaign_player_skins.
// POST { google_uid, action: 'equip'  }   → atualiza
//                                            campaign_progress.equipped_skin_id.
//                                            Skin padrão sempre disponível
//                                            sem precisar comprar.
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input  = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $_GET['google_uid'] ?? '');
if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    $maxLives = (int)getCampaignSetting($pdo, 'campaign.lives.max', 5);
    $pdo->prepare("
        INSERT IGNORE INTO campaign_progress
            (google_uid, current_level, current_lives, total_stars, created_at, updated_at)
        VALUES (?, 1, ?, 0, NOW(), NOW())
    ")->execute([$googleUid, $maxLives]);

    if ($method === 'GET') {
        // Lista skins ativas
        $sStmt = $pdo->query("
            SELECT id, skin_key, name, description, sprite_path,
                   credit_cost, is_purchasable, is_default
            FROM campaign_skins
            WHERE is_enabled = 1
            ORDER BY sort_order, id
        ");
        $skinRows = $sStmt->fetchAll();

        // Skins desbloqueadas pelo jogador
        $oStmt = $pdo->prepare("SELECT skin_id FROM campaign_player_skins WHERE google_uid = ?");
        $oStmt->execute([$googleUid]);
        $owned = array_map(fn($r) => (int)$r['skin_id'], $oStmt->fetchAll());

        // Equipped
        $pStmt = $pdo->prepare("SELECT equipped_skin_id FROM campaign_progress WHERE google_uid = ? LIMIT 1");
        $pStmt->execute([$googleUid]);
        $progRow = $pStmt->fetch();
        $equippedId = $progRow && $progRow['equipped_skin_id'] !== null ? (int)$progRow['equipped_skin_id'] : null;

        // Skin default sempre disponível
        $skins = array_map(function($s) use ($owned, $equippedId) {
            $id = (int)$s['id'];
            $isDefault = (int)$s['is_default'] === 1;
            return [
                'id'             => $id,
                'skin_key'       => $s['skin_key'],
                'name'           => $s['name'],
                'description'    => $s['description'],
                'sprite_path'    => $s['sprite_path'],
                'credit_cost'    => (int)$s['credit_cost'],
                'is_purchasable' => (int)$s['is_purchasable'] === 1,
                'is_default'     => $isDefault,
                'owned'          => $isDefault || in_array($id, $owned, true),
                'equipped'       => $equippedId === $id,
            ];
        }, $skinRows);

        echo json_encode(['success' => true, 'data' => ['skins' => $skins]]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $action  = trim($input['action']  ?? '');
    $skinKey = trim($input['skin_key'] ?? '');
    if (empty($skinKey) || !preg_match('/^[a-z0-9_]+$/i', $skinKey)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'skin_key inválido']);
        exit;
    }

    $sk = $pdo->prepare("SELECT id, credit_cost, is_purchasable, is_default FROM campaign_skins WHERE skin_key = ? AND is_enabled = 1 LIMIT 1");
    $sk->execute([$skinKey]);
    $skin = $sk->fetch();
    if (!$skin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Skin não encontrada']);
        exit;
    }
    $skinId = (int)$skin['id'];

    if ($action === 'buy') {
        if (!(int)$skin['is_purchasable']) {
            echo json_encode(['success' => false, 'error' => 'Skin não está à venda']);
            exit;
        }
        // Verifica se já tem
        $own = $pdo->prepare("SELECT id FROM campaign_player_skins WHERE google_uid = ? AND skin_id = ? LIMIT 1");
        $own->execute([$googleUid, $skinId]);
        if ($own->fetch() || (int)$skin['is_default']) {
            echo json_encode(['success' => false, 'error' => 'Você já possui esta skin']);
            exit;
        }

        $cost = (int)$skin['credit_cost'];
        $pdo->beginTransaction();
        try {
            if ($cost > 0) {
                $debit = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE google_uid = ? AND credits >= ?");
                $debit->execute([$cost, $googleUid, $cost]);
                if ($debit->rowCount() !== 1) {
                    throw new Exception('Créditos insuficientes');
                }
            }
            $pdo->prepare("INSERT INTO campaign_player_skins (google_uid, skin_id, obtained_via) VALUES (?, ?, 'purchase')")
                ->execute([$googleUid, $skinId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }

        $u = $pdo->prepare("SELECT credits FROM users WHERE google_uid = ? LIMIT 1");
        $u->execute([$googleUid]);
        $userRow = $u->fetch();

        secureLog("CAMPAIGN_BUY_SKIN | uid: $googleUid | skin: $skinKey | cost: $cost");

        echo json_encode([
            'success' => true,
            'data' => [
                'skin_key' => $skinKey,
                'cost'     => $cost,
                'remaining_credits' => (int)($userRow['credits'] ?? 0),
            ],
        ]);
        exit;
    }

    if ($action === 'equip') {
        // Verifica posse (default está sempre disponível)
        $isDefault = (int)$skin['is_default'] === 1;
        if (!$isDefault) {
            $own = $pdo->prepare("SELECT id FROM campaign_player_skins WHERE google_uid = ? AND skin_id = ? LIMIT 1");
            $own->execute([$googleUid, $skinId]);
            if (!$own->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Você não possui esta skin']);
                exit;
            }
        }
        $pdo->prepare("UPDATE campaign_progress SET equipped_skin_id = ?, updated_at = NOW() WHERE google_uid = ?")
            ->execute([$skinId, $googleUid]);

        secureLog("CAMPAIGN_EQUIP_SKIN | uid: $googleUid | skin: $skinKey");

        echo json_encode(['success' => true, 'data' => ['skin_key' => $skinKey]]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action inválida']);

} catch (Exception $e) {
    error_log('campaign-skins error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

function getCampaignSetting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}
