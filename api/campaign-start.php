<?php
// ============================================
// UNOBIX - Campaign Start Stage (autenticado)
// api/campaign-start.php
//
// POST { google_uid, stage_id }
//
// Valida pré-requisitos, debita créditos, NÃO consome
// vida (vida só é consumida ao falhar — vide spec
// seção 3), gera session_token único e retorna a
// configuração da fase para o cliente.
//
// Resposta:
//   {
//     success: true,
//     data: {
//       session_token: "...",
//       seed: "...",
//       stage: { stage_id, sector, duration_seconds, ... },
//       expires_at: "...",
//       remaining: { credits, lives }
//     }
//   }
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

    // ---------- 1. Carrega settings relevantes ----------
    $maintenance = getCampaignSetting($pdo, 'campaign.launch.maintenance', 'false') === 'true';
    if ($maintenance) {
        echo json_encode(['success' => false, 'error' => 'Modo Campanha em manutenção']);
        exit;
    }
    $sector2Enabled = getCampaignSetting($pdo, 'campaign.launch.sector2_enabled', 'false') === 'true';
    $maxLives       = (int)getCampaignSetting($pdo, 'campaign.lives.max', 5);
    $rechargeMin    = (int)getCampaignSetting($pdo, 'campaign.lives.recharge_minutes', 30);

    // ---------- 2. Carrega fase ----------
    $stmt = $pdo->prepare("
        SELECT stage_id, sector, order_in_sector, name, duration_seconds, credit_cost,
               min_level, xp_reward, brl_base, is_boss, boss_id, waves_json, is_enabled
        FROM campaign_stages WHERE stage_id = ? LIMIT 1
    ");
    $stmt->execute([$stageId]);
    $stage = $stmt->fetch();
    if (!$stage) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Fase não encontrada']);
        exit;
    }
    if (!(int)$stage['is_enabled']) {
        echo json_encode(['success' => false, 'error' => 'Fase desabilitada']);
        exit;
    }
    if ((int)$stage['sector'] === 2 && !$sector2Enabled) {
        echo json_encode(['success' => false, 'error' => 'Setor 2 ainda não disponível']);
        exit;
    }

    // ---------- 3. Carrega usuário ----------
    $uStmt = $pdo->prepare("SELECT id, google_uid, credits, balance_brl, total_xp FROM users WHERE google_uid = ? LIMIT 1");
    $uStmt->execute([$googleUid]);
    $user = $uStmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }
    $userId = (int)$user['id'];

    // ---------- 4. Auto-init + recarrega vidas ----------
    $pdo->prepare("
        INSERT IGNORE INTO campaign_progress
            (google_uid, current_level, total_xp, current_lives, total_stars, created_at, updated_at)
        VALUES (?, 1, 0, ?, 0, NOW(), NOW())
    ")->execute([$googleUid, $maxLives]);
    rechargeLives($pdo, $googleUid, $maxLives, $rechargeMin);

    $pStmt = $pdo->prepare("SELECT current_level, current_lives FROM campaign_progress WHERE google_uid = ? LIMIT 1");
    $pStmt->execute([$googleUid]);
    $progress = $pStmt->fetch();

    // ---------- 5. Validações ----------
    if ((int)$progress['current_level'] < (int)$stage['min_level']) {
        echo json_encode([
            'success' => false,
            'error'   => 'Nível insuficiente',
            'data'    => [
                'required_level' => (int)$stage['min_level'],
                'current_level'  => (int)$progress['current_level'],
            ],
        ]);
        exit;
    }
    if ((int)$progress['current_lives'] < 1) {
        echo json_encode(['success' => false, 'error' => 'Sem vidas disponíveis']);
        exit;
    }
    $cost = (int)$stage['credit_cost'];
    if ((int)$user['credits'] < $cost) {
        echo json_encode([
            'success' => false,
            'error'   => 'Créditos insuficientes',
            'data'    => ['cost' => $cost, 'current_credits' => (int)$user['credits']],
        ]);
        exit;
    }

    // ---------- 6. Anti multi-sessão ----------
    $aStmt = $pdo->prepare("
        SELECT id FROM campaign_session
        WHERE google_uid = ? AND status = 'active' AND expires_at > NOW()
        LIMIT 1
    ");
    $aStmt->execute([$googleUid]);
    if ($aStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Sessão já em andamento']);
        exit;
    }

    // Marca sessões expiradas como abandonadas (cleanup oportunista)
    $pdo->prepare("
        UPDATE campaign_session SET status = 'abandoned', ended_at = NOW()
        WHERE google_uid = ? AND status = 'active' AND expires_at <= NOW()
    ")->execute([$googleUid]);

    // ---------- 7. Cria sessão (transação) ----------
    $sessionToken = bin2hex(random_bytes(24));   // 48 chars
    $seed         = bin2hex(random_bytes(8));    // 16 chars
    $duration     = max(30, (int)$stage['duration_seconds'] ?: 60);  // bosses têm 0 → fallback 60
    $expireMult   = (float)getCampaignSetting($pdo, 'campaign.anticheat.jwt_expire_mult', 1.5);
    $expiresInSec = (int)round($duration * max(1.0, $expireMult));
    $expiresAt    = date('Y-m-d H:i:s', time() + $expiresInSec);

    $pdo->beginTransaction();
    try {
        // Debita créditos do usuário (atômico, condicional)
        $debit = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?");
        $debit->execute([$cost, $userId, $cost]);
        if ($debit->rowCount() !== 1) {
            throw new Exception('Falha ao debitar créditos');
        }

        // Cria a sessão
        $pdo->prepare("
            INSERT INTO campaign_session
                (session_token, google_uid, stage_id, status, seed, credits_spent, expires_at, created_at)
            VALUES (?, ?, ?, 'active', ?, ?, ?, NOW())
        ")->execute([$sessionToken, $googleUid, $stageId, $seed, $cost, $expiresAt]);

        // Incrementa attempts em campaign_stage_progress
        $pdo->prepare("
            INSERT INTO campaign_stage_progress (google_uid, stage_id, attempts, last_played_at)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_played_at = NOW()
        ")->execute([$googleUid, $stageId]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('campaign-start tx error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Falha ao iniciar sessão']);
        exit;
    }

    secureLog("CAMPAIGN_START | uid: $googleUid | stage: $stageId | cost: $cost | token: " . substr($sessionToken, 0, 8) . "...");

    // ---------- 8. Resposta ----------
    $remainingCredits = (int)$user['credits'] - $cost;

    echo json_encode([
        'success' => true,
        'data' => [
            'session_token' => $sessionToken,
            'seed'          => $seed,
            'expires_at'    => $expiresAt,
            'stage' => [
                'stage_id'         => $stage['stage_id'],
                'sector'           => (int)$stage['sector'],
                'name'             => $stage['name'],
                'duration_seconds' => $duration,
                'is_boss'          => (bool)$stage['is_boss'],
                'boss_id'          => $stage['boss_id'] !== null ? (int)$stage['boss_id'] : null,
                'waves_json'       => $stage['waves_json'] ? json_decode($stage['waves_json'], true) : null,
                'brl_base'         => (float)$stage['brl_base'],
                'xp_reward'        => (int)$stage['xp_reward'],
            ],
            'remaining' => [
                'credits' => $remainingCredits,
                'lives'   => (int)$progress['current_lives'],
            ],
        ],
    ]);

} catch (Exception $e) {
    error_log('campaign-start error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

// ----------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------

function getCampaignSetting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function rechargeLives($pdo, $googleUid, $maxLives, $rechargeMin) {
    if ($rechargeMin <= 0) return;
    $stmt = $pdo->prepare("SELECT current_lives, next_life_at FROM campaign_progress WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $p = $stmt->fetch();
    if (!$p) return;

    $current = (int)$p['current_lives'];
    if ($current >= $maxLives) {
        if ($p['next_life_at'] !== null) {
            $pdo->prepare("UPDATE campaign_progress SET next_life_at = NULL, updated_at = NOW() WHERE google_uid = ?")
                ->execute([$googleUid]);
        }
        return;
    }

    $now = time();
    $intervalSeconds = $rechargeMin * 60;
    $nextAt = $p['next_life_at'] ? strtotime($p['next_life_at']) : null;

    if ($nextAt === null) {
        $pdo->prepare("UPDATE campaign_progress SET next_life_at = ?, updated_at = NOW() WHERE google_uid = ?")
            ->execute([date('Y-m-d H:i:s', $now + $intervalSeconds), $googleUid]);
        return;
    }
    if ($now < $nextAt) return;

    $elapsed = $now - $nextAt;
    $extraLives = 1 + intdiv($elapsed, $intervalSeconds);
    $newLives = min($current + $extraLives, $maxLives);

    if ($newLives >= $maxLives) {
        $pdo->prepare("UPDATE campaign_progress SET current_lives = ?, next_life_at = NULL, updated_at = NOW() WHERE google_uid = ?")
            ->execute([$newLives, $googleUid]);
    } else {
        $remainder = $elapsed % $intervalSeconds;
        $pdo->prepare("UPDATE campaign_progress SET current_lives = ?, next_life_at = ?, updated_at = NOW() WHERE google_uid = ?")
            ->execute([$newLives, date('Y-m-d H:i:s', $now + ($intervalSeconds - $remainder)), $googleUid]);
    }
}
