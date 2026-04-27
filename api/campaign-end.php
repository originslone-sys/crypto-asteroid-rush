<?php
// ============================================
// UNOBIX - Campaign End Stage (autenticado)
// api/campaign-end.php
//
// POST {
//   google_uid, session_token,
//   result: 'win' | 'loss',
//   damage_taken: int (0..ship_max_hp),
//   time_elapsed: int (segundos),
//   enemies_destroyed: int,
//   max_combo: int (0..combo_max)
// }
//
// Servidor:
// - Valida token + janela de tempo + valores plausíveis.
// - Calcula estrelas no servidor (cliente não escolhe).
// - Calcula XP/BRL com base em base + multiplicador estrela.
// - Aplica política de re-jogada (apenas a diferença de estrelas).
// - Aplica limite diário de BRL.
// - Atualiza users.credits/balance_brl/total_xp e
//   campaign_progress + campaign_stage_progress.
// - Consome vida apenas em derrota (vide spec seção 3).
// - Marca sessão como completed (anti-replay).
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = getRequestInput();
$googleUid     = trim($input['google_uid']    ?? $input['googleUid']    ?? '');
$sessionToken  = trim($input['session_token'] ?? $input['sessionToken'] ?? '');
$result        = trim($input['result']        ?? '');
$damageTaken   = (int)($input['damage_taken']      ?? $input['damageTaken']      ?? 0);
$timeElapsed   = (int)($input['time_elapsed']      ?? $input['timeElapsed']      ?? 0);
$enemiesDestr  = (int)($input['enemies_destroyed'] ?? $input['enemiesDestroyed'] ?? 0);
$maxCombo      = (int)($input['max_combo']         ?? $input['maxCombo']         ?? 0);

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}
if (empty($sessionToken) || !preg_match('/^[a-f0-9]{32,64}$/', $sessionToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session_token inválido']);
    exit;
}
if (!in_array($result, ['win', 'loss'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'result inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    // ---------- 1. Carrega sessão (anti-replay) ----------
    $sStmt = $pdo->prepare("
        SELECT id, google_uid, stage_id, status, seed, credits_spent, expires_at, created_at
        FROM campaign_session WHERE session_token = ? LIMIT 1
    ");
    $sStmt->execute([$sessionToken]);
    $session = $sStmt->fetch();
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
        exit;
    }
    if ($session['google_uid'] !== $googleUid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sessão não pertence ao usuário']);
        exit;
    }
    if ($session['status'] !== 'active') {
        echo json_encode(['success' => false, 'error' => 'Sessão já finalizada']);
        exit;
    }

    // ---------- 2. Carrega fase ----------
    $stStmt = $pdo->prepare("
        SELECT stage_id, sector, name, duration_seconds, credit_cost, min_level,
               xp_reward, brl_base, is_boss
        FROM campaign_stages WHERE stage_id = ? LIMIT 1
    ");
    $stStmt->execute([$session['stage_id']]);
    $stage = $stStmt->fetch();
    if (!$stage) {
        echo json_encode(['success' => false, 'error' => 'Fase da sessão não encontrada']);
        exit;
    }

    // ---------- 3. Carrega settings de anti-cheat / mecânicas / recompensas ----------
    $shipMaxHp     = (int)getCampaignSetting($pdo, 'campaign.mechanics.ship_max_hp', 100);
    $comboMax      = (int)getCampaignSetting($pdo, 'campaign.mechanics.combo_max', 5);
    $maxLives      = (int)getCampaignSetting($pdo, 'campaign.lives.max', 5);
    $rechargeMin   = (int)getCampaignSetting($pdo, 'campaign.lives.recharge_minutes', 30);
    $star1Mult     = (float)getCampaignSetting($pdo, 'campaign.rewards.star1_multiplier', 1.0);
    $star2Mult     = (float)getCampaignSetting($pdo, 'campaign.rewards.star2_multiplier', 1.25);
    $star3Mult     = (float)getCampaignSetting($pdo, 'campaign.rewards.star3_multiplier', 1.5);
    $star2MaxDmg   = (int)getCampaignSetting($pdo, 'campaign.rewards.star2_max_dmg_pct', 50);
    $replayPolicy  = getCampaignSetting($pdo, 'campaign.rewards.replay_policy', 'diff'); // diff|full|reduced
    $dailyCap      = (float)getCampaignSetting($pdo, 'campaign.rewards.daily_brl_cap', 10.0);
    $dailyCapOn    = getCampaignSetting($pdo, 'campaign.rewards.daily_cap_enabled', 'true') === 'true';
    $timeMinPct    = (int)getCampaignSetting($pdo, 'campaign.anticheat.time_min_pct', 80);
    $timeMaxPct    = (int)getCampaignSetting($pdo, 'campaign.anticheat.time_max_pct', 200);
    $brlTolPct     = (int)getCampaignSetting($pdo, 'campaign.anticheat.brl_tolerance_pct', 50);
    $xpTolPct      = (int)getCampaignSetting($pdo, 'campaign.anticheat.xp_tolerance_pct', 50);

    // ---------- 4. Sanity dos valores reportados ----------
    $damageTaken  = max(0, min($damageTaken, $shipMaxHp));
    $timeElapsed  = max(0, $timeElapsed);
    $enemiesDestr = max(0, $enemiesDestr);
    $maxCombo     = max(0, min($maxCombo, $comboMax));

    // Janela de tempo (não se aplica a bosses, que não têm timer fixo)
    $duration = (int)$stage['duration_seconds'];
    $suspicious = false;
    if ($duration > 0) {
        $minOk = (int)floor($duration * ($timeMinPct / 100));
        $maxOk = (int)ceil($duration * ($timeMaxPct / 100));
        if ($result === 'win' && $timeElapsed < $minOk) $suspicious = true;
        if ($timeElapsed > $maxOk + 30) $suspicious = true; // tolerância extra para latência
    }

    // ---------- 5. Servidor decide as estrelas ----------
    $stars = 0;
    if ($result === 'win') {
        if ($damageTaken === 0)                                    $stars = 3;
        elseif ($damageTaken <= ($shipMaxHp * $star2MaxDmg / 100)) $stars = 2;
        else                                                       $stars = 1;
    }

    // ---------- 6. Carrega progress ----------
    $pdo->prepare("
        INSERT IGNORE INTO campaign_progress
            (google_uid, current_level, total_xp, current_lives, total_stars, created_at, updated_at)
        VALUES (?, 1, 0, ?, 0, NOW(), NOW())
    ")->execute([$googleUid, $maxLives]);

    $pStmt = $pdo->prepare("
        SELECT current_level, total_xp, current_lives, total_stars,
               daily_brl_earned, daily_brl_reset_at, streak_count, next_life_at
        FROM campaign_progress WHERE google_uid = ? FOR UPDATE
    ");

    $sgStmt = $pdo->prepare("
        SELECT id, stars, best_time, attempts, wins, losses, total_brl_earned,
               max_combo, total_enemies_destroyed
        FROM campaign_stage_progress WHERE google_uid = ? AND stage_id = ? FOR UPDATE
    ");

    // ---------- 7. Recompensas ----------
    $brlAwarded = 0.0;
    $xpAwarded  = 0;

    if ($result === 'win') {
        $mult = $star1Mult;
        if ($stars >= 3) $mult = $star3Mult;
        elseif ($stars >= 2) $mult = $star2Mult;

        $brlFull = round((float)$stage['brl_base'] * $mult, 2);
        $xpFull  = (int)$stage['xp_reward'];

        // Bônus de combo no XP (não no BRL — anti-abuse)
        if ($maxCombo > 1) {
            $xpFull += (int)round($xpFull * (($maxCombo - 1) * 0.05)); // até +20% em combo x5
        }

        // Anti-cheat: limite plausível
        $brlMax = round($brlFull * (1 + $brlTolPct / 100), 2);
        $xpMax  = (int)round($xpFull * (1 + $xpTolPct / 100));

        $xpAwarded  = min($xpFull, $xpMax);
        $brlAwarded = min($brlFull, $brlMax);

        // Política de re-jogada: paga só a diferença entre estrelas
        if ($replayPolicy === 'diff') {
            $sgStmt->execute([$googleUid, $session['stage_id']]);
            $prev = $sgStmt->fetch();
            if ($prev && (int)$prev['stars'] > 0) {
                $prevMult = match ((int)$prev['stars']) {
                    3 => $star3Mult,
                    2 => $star2Mult,
                    default => $star1Mult,
                };
                $diff = max(0.0, $brlAwarded - round((float)$stage['brl_base'] * $prevMult, 2));
                $brlAwarded = round($diff, 2);
                // XP da fase também só na primeira vitória
                if ((int)$prev['wins'] > 0) $xpAwarded = 0;
            }
        }
    }

    // ---------- 8. Aplica em transação ----------
    $pdo->beginTransaction();
    try {
        // Marca a sessão (idempotente — se já estiver completed, abortamos cedo)
        $upd = $pdo->prepare("
            UPDATE campaign_session
            SET status = ?, damage_taken = ?, enemies_destroyed = ?, max_combo = ?,
                stars_awarded = ?, xp_awarded = ?, brl_awarded = ?, time_elapsed = ?,
                ended_at = NOW()
            WHERE session_token = ? AND status = 'active'
        ");
        $finalStatus = $suspicious ? 'review' : 'completed';
        $upd->execute([
            $finalStatus, $damageTaken, $enemiesDestr, $maxCombo,
            $stars, $xpAwarded, $brlAwarded, $timeElapsed, $sessionToken,
        ]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Sessão já processada']);
            exit;
        }

        // Carrega progress travado
        $pStmt->execute([$googleUid]);
        $progress = $pStmt->fetch();

        // Reset diário do BRL (se virou outro dia desde o último reset)
        $today = date('Y-m-d');
        $lastReset = $progress['daily_brl_reset_at'] ? substr($progress['daily_brl_reset_at'], 0, 10) : null;
        $dailyEarned = (float)$progress['daily_brl_earned'];
        if ($lastReset !== $today) {
            $dailyEarned = 0.0;
        }

        // Aplica cap diário (clamp)
        if ($result === 'win' && $dailyCapOn && $dailyCap > 0) {
            $remainingCap = max(0.0, $dailyCap - $dailyEarned);
            if ($brlAwarded > $remainingCap) $brlAwarded = $remainingCap;
        }

        // Calcula novo XP / nível
        $newXp = (int)$progress['total_xp'] + $xpAwarded;
        $newLevel = computeLevelForXp($pdo, $newXp);

        // Vidas: -1 em derrota (sem cair abaixo de 0)
        $newLives = (int)$progress['current_lives'];
        $newNextLifeAt = $progress['next_life_at'];
        if ($result === 'loss' && $newLives > 0) {
            $newLives -= 1;
            // Se vidas estavam cheias e próxima recarga estava NULL, inicia o timer
            if ($newNextLifeAt === null) {
                $newNextLifeAt = date('Y-m-d H:i:s', time() + ($rechargeMin * 60));
            }
        }

        // Streak: vitória sem dano alto incrementa, derrota zera
        $newStreak = (int)$progress['streak_count'];
        if ($result === 'win') $newStreak += 1;
        else $newStreak = 0;

        // Atualiza users (créditos foram debitados em start; aqui só XP/BRL)
        if ($brlAwarded > 0 || $xpAwarded > 0) {
            $pdo->prepare("
                UPDATE users
                SET balance_brl = balance_brl + ?,
                    total_xp = total_xp + ?,
                    updated_at = NOW()
                WHERE google_uid = ?
            ")->execute([$brlAwarded, $xpAwarded, $googleUid]);
        }

        // Carrega stage progress atual (depois do FOR UPDATE de cima)
        $sgStmt->execute([$googleUid, $session['stage_id']]);
        $sgRow = $sgStmt->fetch();

        $prevStars = $sgRow ? (int)$sgRow['stars'] : 0;
        $newStars = max($prevStars, $stars);

        // total_stars do progress = soma de máximos por fase. Atualizamos
        // adicionando apenas o ganho líquido (newStars - prevStars).
        $totalStarsDelta = max(0, $newStars - $prevStars);

        // Best time: menor tempo entre vitórias (só conta se result win)
        $newBestTime = $sgRow && $sgRow['best_time'] !== null ? (int)$sgRow['best_time'] : null;
        if ($result === 'win') {
            if ($newBestTime === null || $timeElapsed < $newBestTime) $newBestTime = $timeElapsed;
        }

        // UPSERT stage_progress
        if ($sgRow) {
            $pdo->prepare("
                UPDATE campaign_stage_progress
                SET stars = GREATEST(stars, ?),
                    best_time = ?,
                    wins = wins + ?,
                    losses = losses + ?,
                    total_brl_earned = total_brl_earned + ?,
                    max_combo = GREATEST(max_combo, ?),
                    total_enemies_destroyed = total_enemies_destroyed + ?,
                    last_played_at = NOW(),
                    first_completed_at = COALESCE(first_completed_at, IF(? = 'win', NOW(), NULL))
                WHERE id = ?
            ")->execute([
                $stars, $newBestTime,
                $result === 'win' ? 1 : 0, $result === 'loss' ? 1 : 0,
                $brlAwarded, $maxCombo, $enemiesDestr,
                $result, (int)$sgRow['id'],
            ]);
        } else {
            // Não deveria acontecer porque start.php já criou attempts=1, mas defensivo
            $pdo->prepare("
                INSERT INTO campaign_stage_progress
                    (google_uid, stage_id, stars, best_time, attempts, wins, losses,
                     total_brl_earned, max_combo, total_enemies_destroyed,
                     last_played_at, first_completed_at)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, NOW(), ?)
            ")->execute([
                $googleUid, $session['stage_id'], $stars, $newBestTime,
                $result === 'win' ? 1 : 0, $result === 'loss' ? 1 : 0,
                $brlAwarded, $maxCombo, $enemiesDestr,
                $result === 'win' ? date('Y-m-d H:i:s') : null,
            ]);
        }

        // Atualiza campaign_progress
        $pdo->prepare("
            UPDATE campaign_progress
            SET current_level = ?,
                total_xp = ?,
                current_lives = ?,
                next_life_at = ?,
                streak_count = ?,
                daily_brl_earned = ?,
                daily_brl_reset_at = ?,
                total_stars = total_stars + ?,
                updated_at = NOW()
            WHERE google_uid = ?
        ")->execute([
            $newLevel, $newXp, $newLives, $newNextLifeAt, $newStreak,
            $dailyEarned + $brlAwarded, date('Y-m-d H:i:s'),
            $totalStarsDelta, $googleUid,
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('campaign-end tx error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Falha ao registrar resultado']);
        exit;
    }

    secureLog("CAMPAIGN_END | uid: $googleUid | stage: {$session['stage_id']} | result: $result | stars: $stars | brl: $brlAwarded | xp: $xpAwarded | suspicious: " . ($suspicious ? 'Y' : 'N'));

    // ---------- 9. Resposta ----------
    echo json_encode([
        'success' => true,
        'data' => [
            'result'       => $result,
            'stars'        => $stars,
            'xp_awarded'   => $xpAwarded,
            'brl_awarded'  => $brlAwarded,
            'new_level'    => $newLevel,
            'level_up'     => $newLevel > (int)$progress['current_level'],
            'lives_left'   => $newLives,
            'streak'       => $newStreak,
            'suspicious'   => $suspicious,
            'session_status' => $finalStatus,
        ],
    ]);

} catch (Exception $e) {
    error_log('campaign-end error: ' . $e->getMessage());
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

/** Retorna o maior nível tal que xp_required <= totalXp (mín 1). */
function computeLevelForXp($pdo, $totalXp) {
    $stmt = $pdo->prepare("SELECT level FROM campaign_xp_table WHERE xp_required <= ? ORDER BY level DESC LIMIT 1");
    $stmt->execute([$totalXp]);
    $row = $stmt->fetch();
    return $row ? (int)$row['level'] : 1;
}
