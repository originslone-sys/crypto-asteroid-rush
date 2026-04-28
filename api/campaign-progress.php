<?php
// ============================================
// UNOBIX - Campaign Progress (autenticado)
// api/campaign-progress.php
//
// Lê o progresso do jogador no Modo Campanha.
// Auto-cria a linha em campaign_progress no
// primeiro acesso e aplica recarga de vidas
// baseada em next_life_at antes de retornar.
//
// Métodos:
//   GET  ?google_uid=... | POST {action:'reset', google_uid}
//
// Resposta GET:
//   {
//     success: true,
//     data: {
//       progress: { current_level, total_xp, current_lives, ... },
//       stages:   [ { stage_id, stars, attempts, ... } ],
//       lives:    { current, max, recharge_minutes, next_life_at, seconds_to_next }
//     }
//   }
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $_GET['google_uid'] ?? '');
$action    = $input['action'] ?? $_GET['action'] ?? '';

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

    // Confirma que o usuário existe na base
    $userStmt = $pdo->prepare("SELECT id, google_uid FROM users WHERE google_uid = ? LIMIT 1");
    $userStmt->execute([$googleUid]);
    $user = $userStmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    // Carrega settings relevantes para a recarga (defaults se faltarem)
    $maxLives    = (int)getCampaignSetting($pdo, 'campaign.lives.max', 5);
    $rechargeMin = (int)getCampaignSetting($pdo, 'campaign.lives.recharge_minutes', 30);

    // Auto-init de campaign_progress (idempotente)
    $pdo->prepare("
        INSERT IGNORE INTO campaign_progress
            (google_uid, current_level, total_xp, current_lives, total_stars, created_at, updated_at)
        VALUES (?, 1, 0, ?, 0, NOW(), NOW())
    ")->execute([$googleUid, $maxLives]);

    // POST /reset (apenas para testes isolados; remove tudo do jogador)
    if ($method === 'POST' && $action === 'reset') {
        $pdo->prepare("DELETE FROM campaign_stage_progress WHERE google_uid = ?")->execute([$googleUid]);
        $pdo->prepare("DELETE FROM campaign_tutorial_seen WHERE google_uid = ?")->execute([$googleUid]);
        $pdo->prepare("DELETE FROM campaign_player_skins WHERE google_uid = ?")->execute([$googleUid]);
        $pdo->prepare("DELETE FROM campaign_player_missions WHERE google_uid = ?")->execute([$googleUid]);
        $pdo->prepare("DELETE FROM campaign_player_achievements WHERE google_uid = ?")->execute([$googleUid]);
        $pdo->prepare("DELETE FROM campaign_streak WHERE google_uid = ?")->execute([$googleUid]);
        $pdo->prepare("
            UPDATE campaign_progress
            SET current_level = 1, total_xp = 0, current_lives = ?, total_stars = 0,
                streak_count = 0, daily_brl_earned = 0, daily_brl_reset_at = NULL,
                next_life_at = NULL, equipped_skin_id = NULL, updated_at = NOW()
            WHERE google_uid = ?
        ")->execute([$maxLives, $googleUid]);
        secureLog("CAMPAIGN_PROGRESS_RESET | uid: $googleUid");
    }

    // Aplica recarga automática de vidas baseada em next_life_at
    rechargeLives($pdo, $googleUid, $maxLives, $rechargeMin);

    // Carrega progress atualizado
    // Detecta se a coluna pending_booster já existe (pode não existir se
    // a migration mais recente ainda não rodou). Mantém o endpoint
    // funcional em ambos os casos.
    $hasBooster = false;
    try {
        $hasBooster = (bool)$pdo->query("SHOW COLUMNS FROM campaign_progress LIKE 'pending_booster'")->fetch();
    } catch (Exception $e) { $hasBooster = false; }
    $boosterCol = $hasBooster ? 'pending_booster' : 'NULL AS pending_booster';

    $stmt = $pdo->prepare("
        SELECT current_level, total_xp, current_lives, next_life_at, streak_count,
               daily_brl_earned, daily_brl_reset_at, total_stars, equipped_skin_id,
               $boosterCol, created_at, updated_at
        FROM campaign_progress WHERE google_uid = ? LIMIT 1
    ");
    $stmt->execute([$googleUid]);
    $progress = $stmt->fetch();
    if (!$progress) {
        echo json_encode(['success' => false, 'error' => 'Falha ao inicializar progresso']);
        exit;
    }

    // Stage progress
    $sStmt = $pdo->prepare("
        SELECT stage_id, stars, best_time, attempts, wins, losses,
               total_brl_earned, max_combo, total_enemies_destroyed,
               last_played_at, first_completed_at
        FROM campaign_stage_progress WHERE google_uid = ?
        ORDER BY stage_id
    ");
    $sStmt->execute([$googleUid]);
    $stages = [];
    while ($row = $sStmt->fetch()) {
        $stages[] = [
            'stage_id'                => $row['stage_id'],
            'stars'                   => (int)$row['stars'],
            'best_time'               => $row['best_time'] !== null ? (int)$row['best_time'] : null,
            'attempts'                => (int)$row['attempts'],
            'wins'                    => (int)$row['wins'],
            'losses'                  => (int)$row['losses'],
            'total_brl_earned'        => (float)$row['total_brl_earned'],
            'max_combo'               => (int)$row['max_combo'],
            'total_enemies_destroyed' => (int)$row['total_enemies_destroyed'],
            'last_played_at'          => $row['last_played_at'],
            'first_completed_at'      => $row['first_completed_at'],
        ];
    }

    // Skins desbloqueadas (apenas IDs por enquanto)
    $skStmt = $pdo->prepare("SELECT skin_id FROM campaign_player_skins WHERE google_uid = ?");
    $skStmt->execute([$googleUid]);
    $skins = array_map(fn($r) => (int)$r['skin_id'], $skStmt->fetchAll());

    // Calcula segundos até próxima recarga
    $nextLifeAt = $progress['next_life_at'];
    $secondsToNext = null;
    if ($nextLifeAt && (int)$progress['current_lives'] < $maxLives) {
        $diff = strtotime($nextLifeAt) - time();
        $secondsToNext = $diff > 0 ? $diff : 0;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'progress' => [
                'current_level'    => (int)$progress['current_level'],
                'total_xp'         => (int)$progress['total_xp'],
                'current_lives'    => (int)$progress['current_lives'],
                'next_life_at'     => $progress['next_life_at'],
                'streak_count'     => (int)$progress['streak_count'],
                'daily_brl_earned' => (float)$progress['daily_brl_earned'],
                'total_stars'      => (int)$progress['total_stars'],
                'equipped_skin_id' => $progress['equipped_skin_id'] !== null ? (int)$progress['equipped_skin_id'] : null,
                'pending_booster'  => $progress['pending_booster'] ?? null,
            ],
            'stages' => $stages,
            'skins_owned' => $skins,
            'lives' => [
                'current'          => (int)$progress['current_lives'],
                'max'              => $maxLives,
                'recharge_minutes' => $rechargeMin,
                'next_life_at'     => $nextLifeAt,
                'seconds_to_next'  => $secondsToNext,
            ],
        ],
    ]);

} catch (Exception $e) {
    error_log('campaign-progress error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

// ----------------------------------------------------------------------
// Helpers locais
// ----------------------------------------------------------------------

function getCampaignSetting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

/**
 * Recarrega vidas com base em next_life_at + recharge_minutes.
 * Idempotente; recalcula next_life_at a cada chamada quando vidas < max.
 */
function rechargeLives($pdo, $googleUid, $maxLives, $rechargeMin) {
    if ($rechargeMin <= 0) return;

    $stmt = $pdo->prepare("
        SELECT current_lives, next_life_at
        FROM campaign_progress WHERE google_uid = ? LIMIT 1
    ");
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
        // Sem timer — começa a contar agora
        $newNext = date('Y-m-d H:i:s', $now + $intervalSeconds);
        $pdo->prepare("UPDATE campaign_progress SET next_life_at = ?, updated_at = NOW() WHERE google_uid = ?")
            ->execute([$newNext, $googleUid]);
        return;
    }

    if ($now < $nextAt) {
        // Ainda contando
        return;
    }

    // Calcula quantas vidas devem ser entregues
    $elapsed = $now - $nextAt;
    $extraLives = 1 + intdiv($elapsed, $intervalSeconds);
    $newLives = min($current + $extraLives, $maxLives);

    if ($newLives >= $maxLives) {
        $pdo->prepare("
            UPDATE campaign_progress
            SET current_lives = ?, next_life_at = NULL, updated_at = NOW()
            WHERE google_uid = ?
        ")->execute([$newLives, $googleUid]);
    } else {
        // Ajusta next_life_at preservando o resto do intervalo
        $remainder = $elapsed % $intervalSeconds;
        $newNext = date('Y-m-d H:i:s', $now + ($intervalSeconds - $remainder));
        $pdo->prepare("
            UPDATE campaign_progress
            SET current_lives = ?, next_life_at = ?, updated_at = NOW()
            WHERE google_uid = ?
        ")->execute([$newLives, $newNext, $googleUid]);
    }
}
