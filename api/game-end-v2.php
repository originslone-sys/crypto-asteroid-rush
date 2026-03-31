<?php
// ============================================
// UNOBIX - Finalizar Sessão de Jogo
// api/game-end-v2.php v2.0 - Arquitetura Segura
// Validação server-side, anti-cheat simplificado, crédito
// Suporte a game_mode (training/normal/hard)
// ============================================

require_once __DIR__ . "/config.php";

// v2: Duração do jogo (override do config global)
if (!defined('GAME_DURATION_V2')) {
    define('GAME_DURATION_V2', 180); // 3 minutos
}

setCorsHeaders();

$input = getRequestInput();

$sessionId = (int)($input['session_id'] ?? 0);
$sessionToken = trim($input['session_token'] ?? '');
$googleUid = trim($input['google_uid'] ?? '');
$clientEarnings = (float)($input['earnings'] ?? 0);
$livesRemaining = (int)($input['lives_remaining'] ?? 0);
$victory = (bool)($input['victory'] ?? false);
$clientStats = $input['stats'] ?? [];
$captchaToken = $input['captcha_token'] ?? '';
$gameHash = $input['game_hash'] ?? ''; // Hash de verificação (opcional)

// Validação básica
if (!$sessionId || !$sessionToken) {
    echo json_encode(['success' => false, 'error' => 'Dados de sessão inválidos']);
    exit;
}

if (!$googleUid) {
    echo json_encode(['success' => false, 'error' => 'google_uid ausente']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão com banco']);
        exit;
    }

    // ============================================
    // 1. BUSCAR E VALIDAR SESSÃO
    // ============================================

    // Buscar sessão e usuário
    $stmt = $pdo->prepare("
        SELECT gs.*, u.id as user_id, u.balance_brl as user_balance, u.is_banned,
               u.is_premium as user_is_premium, u.premium_expires_at as user_premium_expires
        FROM game_sessions gs
        LEFT JOIN users u ON u.google_uid = gs.google_uid
        WHERE gs.id = ?
        AND gs.session_token = ?
        LIMIT 1
    ");
    $stmt->execute([$sessionId, $sessionToken]);
    $session = $stmt->fetch();

    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada ou token inválido']);
        exit;
    }

    // Verificar UID (permitir truncado)
    $sessionUid = $session['google_uid'];
    if (strpos($googleUid, '...') === false && $sessionUid !== $googleUid) {
        echo json_encode(['success' => false, 'error' => 'UID não corresponde à sessão']);
        exit;
    }

    // Ler game_mode da sessão (definido por game-start-v2.php)
    $gameMode = $session['game_mode'] ?? 'normal';

    if ($session['status'] !== 'active') {
        // Sessão já finalizada - retornar dados anteriores
        echo json_encode([
            'success' => true,
            'already_completed' => true,
            'final_earnings' => (float)$session['earnings_brl'],
            'game_mode' => $gameMode,
            'message' => 'Sessão já foi finalizada anteriormente'
        ]);
        exit;
    }

    // ============================================
    // TRAINING MODE: retorno imediato, sem DB updates
    // ============================================
    if ($gameMode === 'training') {
        echo json_encode([
            'success' => true,
            'session_id' => $sessionId,
            'victory' => (bool)$victory,
            'final_earnings' => 0,
            'credited' => false,
            'new_balance' => (float)($session['user_balance'] ?? 0),
            'stats' => $clientStats,
            'game_mode' => 'training',
            'game_duration' => 0,
            'message' => 'Modo treino - sem ganhos'
        ]);
        exit;
    }

    if (!empty($session['is_banned'])) {
        $pdo->prepare("UPDATE game_sessions SET status = 'abandoned', ended_at = NOW() WHERE id = ?")
            ->execute([$sessionId]);
        echo json_encode(['success' => false, 'error' => 'Conta suspensa', 'banned' => true]);
        exit;
    }

    // ============================================
    // 2. CALCULAR DURAÇÃO E VALIDAR TEMPO
    // ============================================

    $startedAt = strtotime($session['started_at']);
    $gameDuration = time() - $startedAt;

    // Verificar se não passou muito tempo
    // Reenvio com captcha_token recebe tolerância extra (tempo de ads postgame + CAPTCHA)
    $maxAllowed = GAME_DURATION_V2 + GAME_TOLERANCE;
    if (!empty($captchaToken)) {
        $maxAllowed += defined('CAPTCHA_RESEND_TOLERANCE') ? CAPTCHA_RESEND_TOLERANCE : 60;
    }

    if ($gameDuration > $maxAllowed) {
        $pdo->prepare("UPDATE game_sessions SET status = 'abandoned', ended_at = NOW() WHERE id = ?")
            ->execute([$sessionId]);
        echo json_encode([
            'success' => false,
            'error' => 'Sessão expirada',
            'elapsed' => $gameDuration,
            'max_allowed' => $maxAllowed
        ]);
        exit;
    }

    // ANTI-EXPLOIT: Validar tempo mínimo para vitória
    // Impossível completar legitimamente em menos de 150s (180s - 30s tolerância countdown/latência)
    $minDurationForVictory = GAME_DURATION_V2 - 30;
    if ($victory && $gameDuration < $minDurationForVictory) {
        $pdo->prepare("UPDATE game_sessions SET status = 'flagged', ended_at = NOW(), game_duration = ? WHERE id = ?")
            ->execute([min($gameDuration, GAME_DURATION_V2), $sessionId]);
        secureLog("EXPLOIT_BLOCKED | Session: $sessionId | UID: $googleUid | Duration: {$gameDuration}s < {$minDurationForVictory}s | Mode: $gameMode | IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        echo json_encode([
            'success' => false,
            'error' => 'Sessão inválida',
            'flagged' => true
        ]);
        exit;
    }

    // ============================================
    // 3. DETERMINAR ESTATÍSTICAS FINAIS
    // ============================================

    // Usar stats do cliente se fornecidos, senão usar contadores da sessão
    $finalStats = [
        'common' => (int)($clientStats['common'] ?? $session['common_asteroids'] ?? 0),
        'rare' => (int)($clientStats['rare'] ?? $session['rare_asteroids'] ?? 0),
        'epic' => (int)($clientStats['epic'] ?? $session['epic_asteroids'] ?? 0),
        'legendary' => (int)($clientStats['legendary'] ?? $session['legendary_asteroids'] ?? 0)
    ];

    $totalAsteroids = array_sum($finalStats);

    // ANTI-EXPLOIT: Validar stats proporcionais ao tempo
    // Máximo realista: ~5 asteroides por segundo (clique/tiro muito rápido)
    $maxRealisticAsteroids = max(50, $gameDuration * 5);
    if ($totalAsteroids > $maxRealisticAsteroids) {
        $pdo->prepare("UPDATE game_sessions SET status = 'flagged', ended_at = NOW(), game_duration = ? WHERE id = ?")
            ->execute([min($gameDuration, GAME_DURATION_V2), $sessionId]);
        secureLog("EXPLOIT_STATS | Session: $sessionId | UID: $googleUid | Asteroids: $totalAsteroids > max $maxRealisticAsteroids | Duration: {$gameDuration}s | Mode: $gameMode");
        echo json_encode([
            'success' => false,
            'error' => 'Sessão inválida',
            'flagged' => true
        ]);
        exit;
    }

    // ANTI-EXPLOIT: Validar proporção de raros impossível
    // Em condições normais, raros+épicos+lendários não passam de 40% do total
    $specialAsteroids = $finalStats['rare'] + $finalStats['epic'] + $finalStats['legendary'];
    if ($totalAsteroids > 20 && $specialAsteroids > $totalAsteroids * 0.5) {
        $pdo->prepare("UPDATE game_sessions SET status = 'flagged', ended_at = NOW(), game_duration = ? WHERE id = ?")
            ->execute([min($gameDuration, GAME_DURATION_V2), $sessionId]);
        secureLog("EXPLOIT_RATIO | Session: $sessionId | UID: $googleUid | Special: $specialAsteroids/$totalAsteroids (" . round($specialAsteroids/$totalAsteroids*100) . "%) | Mode: $gameMode");
        echo json_encode([
            'success' => false,
            'error' => 'Sessão inválida',
            'flagged' => true
        ]);
        exit;
    }

    // ANTI-EXPLOIT: Validar proporções contra spawn rates configurados no admin
    if ($totalAsteroids >= 30 && $gameMode !== 'training') {
        $spawnPrefix = "mode_{$gameMode}_spawn_";
        $stmtSpawn = $pdo->prepare("
            SELECT setting_key, setting_value FROM game_settings
            WHERE setting_key IN (?, ?, ?)
        ");
        $stmtSpawn->execute([
            $spawnPrefix . 'rare',
            $spawnPrefix . 'epic',
            $spawnPrefix . 'legendary'
        ]);
        $spawnCfg = [];
        while ($row = $stmtSpawn->fetch()) {
            $spawnCfg[$row['setting_key']] = (float)$row['setting_value'];
        }

        $defaultRates = [
            'normal'  => ['rare' => 7, 'epic' => 2, 'legendary' => 1],
            'hard'    => ['rare' => 15, 'epic' => 4, 'legendary' => 1],
            'extreme' => ['rare' => 20, 'epic' => 8, 'legendary' => 2],
        ];
        $defs = $defaultRates[$gameMode] ?? $defaultRates['normal'];

        $expRare = ($spawnCfg[$spawnPrefix . 'rare'] ?? $defs['rare']) / 100;
        $expEpic = ($spawnCfg[$spawnPrefix . 'epic'] ?? $defs['epic']) / 100;
        $expLegendary = ($spawnCfg[$spawnPrefix . 'legendary'] ?? $defs['legendary']) / 100;

        // Tolerância 3x o esperado (variância natural do RNG)
        $tolerance = 3.0;
        $spawnFlags = [];

        if ($finalStats['rare'] > $totalAsteroids * $expRare * $tolerance) {
            $spawnFlags[] = "Rare:{$finalStats['rare']}/$totalAsteroids(" . round($finalStats['rare']/$totalAsteroids*100,1) . "% vs " . round($expRare*100,1) . "%)";
        }
        if ($finalStats['epic'] > $totalAsteroids * $expEpic * $tolerance) {
            $spawnFlags[] = "Epic:{$finalStats['epic']}/$totalAsteroids(" . round($finalStats['epic']/$totalAsteroids*100,1) . "% vs " . round($expEpic*100,1) . "%)";
        }
        if ($finalStats['legendary'] > $totalAsteroids * $expLegendary * $tolerance) {
            $spawnFlags[] = "Legendary:{$finalStats['legendary']}/$totalAsteroids(" . round($finalStats['legendary']/$totalAsteroids*100,1) . "% vs " . round($expLegendary*100,1) . "%)";
        }

        if (!empty($spawnFlags)) {
            $pdo->prepare("UPDATE game_sessions SET status = 'flagged', ended_at = NOW(), game_duration = ? WHERE id = ?")
                ->execute([min($gameDuration, GAME_DURATION_V2), $sessionId]);
            secureLog("EXPLOIT_SPAWN_RATE | Session: $sessionId | UID: $googleUid | Mode: $gameMode | " . implode(' | ', $spawnFlags));
            echo json_encode(['success' => false, 'error' => 'Sessão inválida', 'flagged' => true]);
            exit;
        }
    }

    // ANTI-EXPLOIT: Validar asteroides destruídos vs capacidade real do modo
    // Usa velocidade, spawn interval e max_asteroids configurados no admin
    if ($totalAsteroids >= 30 && $gameMode !== 'training') {
        $cfgPrefix = "mode_{$gameMode}_";
        $stmtModeCfg = $pdo->prepare("
            SELECT setting_key, setting_value FROM game_settings
            WHERE setting_key IN (?, ?, ?)
        ");
        $stmtModeCfg->execute([
            $cfgPrefix . 'speed',
            $cfgPrefix . 'spawn_interval',
            $cfgPrefix . 'max_asteroids'
        ]);
        $modeCfg = [];
        while ($row = $stmtModeCfg->fetch()) {
            $modeCfg[$row['setting_key']] = (float)$row['setting_value'];
        }

        $defaultCfg = [
            'normal'  => ['speed' => 1.3, 'spawn_interval' => 400, 'max_asteroids' => 14],
            'hard'    => ['speed' => 1.7, 'spawn_interval' => 200, 'max_asteroids' => 18],
            'extreme' => ['speed' => 2.4, 'spawn_interval' => 100, 'max_asteroids' => 22],
        ];
        $defCfg = $defaultCfg[$gameMode] ?? $defaultCfg['normal'];

        $cfgSpeed = $modeCfg[$cfgPrefix . 'speed'] ?? $defCfg['speed'];
        $cfgSpawnInterval = $modeCfg[$cfgPrefix . 'spawn_interval'] ?? $defCfg['spawn_interval'];
        $cfgMaxAsteroids = $modeCfg[$cfgPrefix . 'max_asteroids'] ?? $defCfg['max_asteroids'];

        // Total possível de spawns no tempo jogado
        $effectiveDuration = min($gameDuration, GAME_DURATION_V2);
        $maxSpawns = ($effectiveDuration * 1000) / max($cfgSpawnInterval, 50);

        // Com velocidade alta, jogador tem menos tempo pra acertar cada asteroide
        // Taxa de acerto máxima realista diminui com a velocidade
        // Velocidade 1x = ~80% acerto possível, 6x = ~40% acerto possível
        $hitRateCap = max(0.25, 0.85 - ($cfgSpeed * 0.08));
        $maxRealisticDestroyed = ceil($maxSpawns * $hitRateCap);

        if ($totalAsteroids > $maxRealisticDestroyed) {
            $pdo->prepare("UPDATE game_sessions SET status = 'flagged', ended_at = NOW(), game_duration = ? WHERE id = ?")
                ->execute([min($gameDuration, GAME_DURATION_V2), $sessionId]);
            secureLog("EXPLOIT_SPEED_ABUSE | Session: $sessionId | UID: $googleUid | Mode: $gameMode | Destroyed: $totalAsteroids > max $maxRealisticDestroyed | Speed: {$cfgSpeed}x | SpawnInterval: {$cfgSpawnInterval}ms | HitRate: " . round($hitRateCap*100) . "%");
            echo json_encode(['success' => false, 'error' => 'Sessão inválida', 'flagged' => true]);
            exit;
        }
    }

    // ANTI-EXPLOIT: Extreme mode - taxa de destruição > 90% é humanamente impossível
    if ($gameMode === 'extreme' && $totalAsteroids >= 30) {
        // Buscar spawn interval do extreme (se ainda não carregado)
        if (!isset($cfgSpawnInterval)) {
            $cfgPrefix = "mode_extreme_";
            $stmtExt = $pdo->prepare("SELECT setting_key, setting_value FROM game_settings WHERE setting_key = ?");
            $stmtExt->execute([$cfgPrefix . 'spawn_interval']);
            $extRow = $stmtExt->fetch();
            $cfgSpawnInterval = $extRow ? (float)$extRow['setting_value'] : 100;
        }
        $effectiveDuration = min($gameDuration, GAME_DURATION_V2);
        $estimatedSpawns = ($effectiveDuration * 1000) / max($cfgSpawnInterval, 50);
        $destructionRate = $totalAsteroids / max($estimatedSpawns, 1);

        if ($destructionRate > 0.90) {
            $pdo->prepare("UPDATE game_sessions SET status = 'flagged', ended_at = NOW(), game_duration = ? WHERE id = ?")
                ->execute([min($gameDuration, GAME_DURATION_V2), $sessionId]);
            secureLog("EXPLOIT_EXTREME_RATE | Session: $sessionId | UID: $googleUid | DestructionRate: " . round($destructionRate * 100, 1) . "% | Destroyed: $totalAsteroids / ~" . round($estimatedSpawns) . " spawns");
            echo json_encode(['success' => false, 'error' => 'Sessão inválida', 'flagged' => true]);
            exit;
        }
    }

    // ============================================
    // 4. CALCULAR GANHOS NO SERVIDOR
    // ============================================

    $isVictory = $victory && $livesRemaining > 0;

    if (!$isVictory) {
        // Perdeu - não ganha nada
        $finalEarnings = 0;
    } else {
        // Calcular ganhos baseado nas estatísticas validadas
        $finalEarnings = calculateServerEarnings($finalStats);
    }

    // Anti-cheat: apenas log para ganhos altos, sem bloqueio
    if ($finalEarnings > EARNINGS_ALERT_BRL) {
        secureLog("⚠️ HIGH_EARNINGS | Session: $sessionId | Earnings: $finalEarnings | Mode: $gameMode");
    }

    // ============================================
    // 5. VERIFICAR CAPTCHA (se necessário)
    // ============================================

    // Premium users skip CAPTCHA (dados já carregados na query principal com alias)
    $userIsPremium = false;
    if (!empty($session['user_is_premium']) && !empty($session['user_premium_expires']) && strtotime($session['user_premium_expires']) > time()) {
        $userIsPremium = true;
    }

    if ($isVictory && $finalEarnings > 0 && CAPTCHA_REQUIRED_ON_VICTORY && !$userIsPremium) {
        $captchaResult = verifyCaptcha($captchaToken);

        if (!$captchaResult['success']) {
            // Não bloqueia, apenas solicita captcha
            echo json_encode([
                'success' => true,
                'captcha_required' => true,
                'message' => 'Complete a verificação para resgatar os ganhos',
                'pending_earnings' => $finalEarnings,
                'session_id' => $sessionId,
                'game_mode' => $gameMode,
                'credited' => false
            ]);
            exit;
        }
    }

    // ============================================
    // 6. TRANSAÇÃO: ATUALIZAR BANCO
    // ============================================

    $pdo->beginTransaction();

    try {
        $realGoogleUid = $session['google_uid'];
        $status = $isVictory ? 'completed' : 'abandoned';

        // 6a. Atualizar sessão
        $stmt = $pdo->prepare("
            UPDATE game_sessions SET
                status = ?,
                earnings_brl = ?,
                asteroids_destroyed = ?,
                common_asteroids = ?,
                rare_asteroids = ?,
                epic_asteroids = ?,
                legendary_asteroids = ?,
                game_duration = ?,
                ended_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $finalEarnings,
            $totalAsteroids,
            $finalStats['common'],
            $finalStats['rare'],
            $finalStats['epic'],
            $finalStats['legendary'],
            min($gameDuration, GAME_DURATION_V2), // Cap duration at game time
            $sessionId
        ]);

        $credited = false;
        $newBalance = (float)($session['user_balance'] ?? 0);

        // 6b. Incrementar total de missões jogadas
        if ($session['user_id']) {
            $pdo->prepare("
                UPDATE users SET total_played = total_played + 1, updated_at = NOW()
                WHERE id = ?
            ")->execute([$session['user_id']]);
        }

        // 6c. Creditar saldo (apenas se vitória e ganhos > 0)
        if ($isVictory && $finalEarnings > 0 && $session['user_id']) {
            $stmt = $pdo->prepare("
                UPDATE users SET
                    balance_brl = balance_brl + ?,
                    total_earned_brl = total_earned_brl + ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$finalEarnings, $finalEarnings, $session['user_id']]);

            $credited = true;

            // 6d. Registrar transação com label do modo
            $modeLabels = [
                'normal' => '',
                'hard' => ' (Hard)',
            ];
            $modeLabel = $modeLabels[$gameMode] ?? " ($gameMode)";

            $description = sprintf(
                "Missão #%d%s: %d raros, %d épicos, %d lendários",
                $session['mission_number'],
                $modeLabel,
                $finalStats['rare'],
                $finalStats['epic'],
                $finalStats['legendary']
            );

            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    google_uid, type, amount, amount_brl,
                    description, status, created_at
                ) VALUES (?, 'game_reward', ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $realGoogleUid,
                $finalEarnings,
                $finalEarnings,
                $description
            ]);

            // 6e. Buscar novo saldo
            $stmt = $pdo->prepare("SELECT balance_brl FROM users WHERE id = ?");
            $stmt->execute([$session['user_id']]);
            $newBalance = (float)$stmt->fetchColumn();
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // ============================================
    // 7. ATUALIZAR PROGRESSO DE REFERRAL
    // ============================================
    // Toda partida finalizada conta para o progresso do indicado
    if (!empty($realGoogleUid)) {
        try {
            require_once __DIR__ . '/referral-helper.php';
            $refResult = updateReferralProgress($pdo, $realGoogleUid);
            if ($refResult['completed']) {
                secureLog("REFERRAL_QUALIFIED_VIA_GAME | Referred: {$realGoogleUid} | Referrer: {$refResult['referrer_google_uid']}");
            }
        } catch (Exception $e) {
            // Não falhar o game-end por erro de referral
            error_log("Referral progress error: " . $e->getMessage());
        }
    }

    // ============================================
    // 8. LOG E RESPOSTA
    // ============================================

    secureLog(sprintf(
        "GAME_END | Session: %d | Status: %s | Mode: %s | Earnings: %.4f | Credited: %s | Balance: %.4f",
        $sessionId,
        $status,
        $gameMode,
        $finalEarnings,
        $credited ? 'YES' : 'NO',
        $newBalance
    ));

    $response = [
        'success' => true,
        'session_id' => $sessionId,
        'victory' => $isVictory,
        'final_earnings' => round($finalEarnings, 6),
        'credited' => $credited,
        'new_balance' => round($newBalance, 6),
        'stats' => $finalStats,
        'mission_number' => (int)$session['mission_number'],
        'game_mode' => $gameMode,
        'is_hard_mode' => (bool)$session['is_hard_mode'],
        'game_duration' => min($gameDuration, GAME_DURATION_V2)
    ];

    echo json_encode($response);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    secureLog("GAME_END_ERROR | Session: $sessionId | " . $e->getMessage());
    error_log("game-end-v2.php error: " . $e->getMessage() . " | Line: " . $e->getLine());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao finalizar sessão'
    ]);
}
