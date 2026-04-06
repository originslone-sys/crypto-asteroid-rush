<?php
// ============================================
// UNOBIX - Análise Individual de Conta
// admin/pages/account-analysis.php
// ============================================

$pageTitle = 'Análise de Conta';

$searchEmail = trim($_GET['email'] ?? '');
$accountAnalysis = null;
$accountError = null;

if (!empty($searchEmail)) {
    try {
        $userStmt = $pdo->prepare("
            SELECT u.*,
                   DATEDIFF(NOW(), u.created_at) as account_age_days,
                   (SELECT COUNT(*) FROM game_sessions gs WHERE gs.google_uid = u.google_uid AND gs.game_mode = 'extreme') as total_sessions,
                   (SELECT COUNT(*) FROM game_sessions gs WHERE gs.google_uid = u.google_uid AND gs.status = 'completed' AND gs.game_mode = 'extreme') as completed_sessions,
                   (SELECT COUNT(*) FROM game_sessions gs WHERE gs.google_uid = u.google_uid AND gs.status = 'abandoned' AND gs.game_mode = 'extreme') as abandoned_sessions,
                   (SELECT COUNT(*) FROM game_sessions gs WHERE gs.google_uid = u.google_uid AND gs.status = 'flagged' AND gs.game_mode = 'extreme') as flagged_sessions,
                   (SELECT COALESCE(SUM(w.amount_brl), 0) FROM withdrawals w WHERE w.user_id = u.id AND w.status = 'approved') as total_withdrawn,
                   (SELECT COUNT(*) FROM withdrawals w WHERE w.user_id = u.id AND w.status = 'pending') as pending_withdrawals,
                   (SELECT COUNT(DISTINCT gs.ip_address) FROM game_sessions gs WHERE gs.google_uid = u.google_uid AND gs.game_mode = 'extreme') as distinct_ips
            FROM users u
            WHERE u.email = ? OR u.google_uid = ?
            LIMIT 1
        ");
        $userStmt->execute([$searchEmail, $searchEmail]);
        $acctUser = $userStmt->fetch();

        if (!$acctUser) {
            $accountError = "Conta não encontrada: " . htmlspecialchars($searchEmail);
        } else {
            $uid = $acctUser['google_uid'];

            // ── Agregados dos últimos 30 dias ─────────────────────────────────
            $s30Stmt = $pdo->prepare("
                SELECT
                    COUNT(*) as sessions_30d,
                    SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed_30d,
                    SUM(CASE WHEN status='abandoned' THEN 1 ELSE 0 END) as abandoned_30d,
                    SUM(CASE WHEN status='flagged'   THEN 1 ELSE 0 END) as flagged_30d,
                    AVG(earnings_brl)           as avg_earnings,
                    MAX(earnings_brl)           as max_earnings,
                    STDDEV(earnings_brl)        as stddev_earnings,
                    SUM(earnings_brl)           as total_earnings_30d,
                    AVG(asteroids_destroyed)    as avg_asteroids,
                    STDDEV(asteroids_destroyed) as stddev_asteroids,
                    AVG(legendary_asteroids)    as avg_legendary,
                    STDDEV(legendary_asteroids) as stddev_legendary,
                    AVG(epic_asteroids)         as avg_epic,
                    STDDEV(epic_asteroids)      as stddev_epic,
                    AVG(rare_asteroids)         as avg_rare,
                    STDDEV(rare_asteroids)      as stddev_rare,
                    AVG(common_asteroids)       as avg_common,
                    AVG(total_spawned)          as avg_spawned,
                    STDDEV(total_spawned)       as stddev_spawned,
                    AVG(game_duration)          as avg_duration,
                    STDDEV(game_duration)       as stddev_duration,
                    COUNT(DISTINCT DATE(started_at))  as active_days,
                    COUNT(DISTINCT ip_address)        as ips_30d,
                    COUNT(DISTINCT user_agent)        as distinct_uas
                FROM game_sessions
                WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND status IN ('completed','flagged','abandoned') AND game_mode = 'extreme'
            ");
            $s30Stmt->execute([$uid]);
            $s30 = $s30Stmt->fetch();

            $sessions30 = (int)$s30['sessions_30d'];
            $score    = 0;
            $signals  = [];
            $positives = [];

            if ($sessions30 >= 5) {
                $avgE    = (float)$s30['avg_earnings'];
                $stdE    = (float)$s30['stddev_earnings'];
                $avgD    = (float)$s30['avg_duration'];
                $stdD    = (float)$s30['stddev_duration'];
                $avgA    = (float)$s30['avg_asteroids'];
                $stdA    = (float)$s30['stddev_asteroids'];
                $avgLeg  = (float)$s30['avg_legendary'];
                $stdLeg  = (float)$s30['stddev_legendary'];
                $avgEpic = (float)$s30['avg_epic'];
                $stdEpic = (float)$s30['stddev_epic'];
                $avgRare = (float)$s30['avg_rare'];
                $stdRare = (float)$s30['stddev_rare'];
                $avgSpwn = (float)$s30['avg_spawned'];
                $stdSpwn = (float)$s30['stddev_spawned'];
                $comp    = (int)$s30['completed_30d'];
                $aband   = (int)$s30['abandoned_30d'];
                $flagd   = (int)$s30['flagged_30d'];
                $distUAs = (int)$s30['distinct_uas'];

                // ── 1. Consistência de ganhos ──────────────────────────────────
                if ($sessions30 >= 10 && $avgE > 0.05) {
                    $cv = ($stdE > 0) ? ($stdE / $avgE) : 0;
                    if ($cv < 0.10)     { $score += 20; $signals[]   = 'Ganhos extremamente consistentes (CV=' . round($cv*100,1) . '%)'; }
                    elseif ($cv < 0.20) { $score += 10; $signals[]   = 'Ganhos pouco variáveis (CV=' . round($cv*100,1) . '%)'; }
                    else                { $positives[] = 'Variação natural de ganhos (CV=' . round($cv*100,1) . '%)'; }
                }

                // ── 2. Eficiência (ganho/asteroide) consistente ────────────────
                // Mais preciso que ganho bruto: normaliza pela quantidade de asteroides
                $effStmt = $pdo->prepare("
                    SELECT earnings_brl / NULLIF(asteroids_destroyed,0) as eff
                    FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status = 'completed' AND asteroids_destroyed > 10 AND game_mode = 'extreme'
                    LIMIT 100
                ");
                $effStmt->execute([$uid]);
                $effVals = array_filter(array_column($effStmt->fetchAll(), 'eff'), fn($v) => $v !== null && $v > 0);
                if (count($effVals) >= 10) {
                    $avgEff = array_sum($effVals) / count($effVals);
                    $stdEff = sqrt(array_sum(array_map(fn($v) => pow($v - $avgEff, 2), $effVals)) / count($effVals));
                    $cvEff  = ($stdEff > 0 && $avgEff > 0) ? ($stdEff / $avgEff) : 0;
                    if ($cvEff < 0.05)     { $score += 25; $signals[]   = 'Eficiência R$/asteroide robótica (CV=' . round($cvEff*100,1) . '%)'; }
                    elseif ($cvEff < 0.12) { $score += 12; $signals[]   = 'Eficiência R$/asteroide muito uniforme (CV=' . round($cvEff*100,1) . '%)'; }
                    else                   { $positives[] = 'Eficiência R$/asteroide variável (CV=' . round($cvEff*100,1) . '%)'; }
                }

                // ── 3. Duração consistente ─────────────────────────────────────
                if ($sessions30 >= 10 && $avgD > 0) {
                    $cvD = ($stdD > 0) ? ($stdD / $avgD) : 0;
                    if ($cvD < 0.03)     { $score += 15; $signals[]   = 'Duração quase idêntica entre sessões (CV=' . round($cvD*100,1) . '%)'; }
                    elseif ($cvD < 0.08) { $score += 7;  $signals[]   = 'Duração muito similar (CV=' . round($cvD*100,1) . '%)'; }
                    else                 { $positives[] = 'Duração varia naturalmente (CV=' . round($cvD*100,1) . '%)'; }
                }

                // ── 4. Win rate ────────────────────────────────────────────────
                $winRate = ($sessions30 > 0) ? ($comp / $sessions30) : 0;
                if ($sessions30 >= 10 && $winRate >= 0.95)     { $score += 25; $signals[]   = 'Taxa de vitória ' . round($winRate*100,1) . '%'; }
                elseif ($sessions30 >= 10 && $winRate >= 0.85) { $score += 15; $signals[]   = 'Vitória alta: ' . round($winRate*100,1) . '%'; }
                elseif ($sessions30 >= 10)                      { $positives[] = 'Win rate normal: ' . round($winRate*100,1) . '%'; }

                // ── 5. Zero abandonos ──────────────────────────────────────────
                if ($sessions30 >= 15 && $aband === 0)    { $score += 20; $signals[] = 'Zero game over em ' . $sessions30 . ' sessões'; }
                elseif ($sessions30 >= 15 && $aband <= 1) { $score += 10; $signals[] = 'Apenas ' . $aband . ' game over em ' . $sessions30 . ' sessões'; }

                // ── 6. Sessões flagged ─────────────────────────────────────────
                if ($flagd > 0) {
                    $fr = $flagd / $sessions30;
                    if ($fr > 0.3)     { $score += 15; $signals[] = round($fr*100) . '% flagged (' . $flagd . ')'; }
                    elseif ($fr > 0.1) { $score += 8;  $signals[] = $flagd . ' sessões flagged'; }
                } else { $positives[] = 'Nenhuma sessão flagged'; }

                // ── 7. Asteroides totais consistentes ─────────────────────────
                if ($sessions30 >= 10 && $avgA > 20) {
                    $cvA = ($stdA > 0) ? ($stdA / $avgA) : 0;
                    if ($cvA < 0.05)     { $score += 20; $signals[]   = 'Asteroides destruídos quase idênticos (CV=' . round($cvA*100,1) . '%)'; }
                    elseif ($cvA < 0.10) { $score += 10; $signals[]   = 'Asteroides destruídos muito consistentes'; }
                    else                 { $positives[] = 'Variação natural de asteroides'; }
                }

                // ── 8. Proporção de lendários consistente (RNG deveria variar) ─
                // Ex: bot sempre destrói exatamente 3% de lendários — impossível humanamente
                if ($sessions30 >= 10 && $avgLeg > 0 && $avgA > 0) {
                    $legRatioStmt = $pdo->prepare("
                        SELECT legendary_asteroids / NULLIF(asteroids_destroyed,0) as ratio
                        FROM game_sessions
                        WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND status = 'completed' AND asteroids_destroyed > 10 AND game_mode = 'extreme'
                        LIMIT 100
                    ");
                    $legRatioStmt->execute([$uid]);
                    $legRatios = array_filter(array_column($legRatioStmt->fetchAll(), 'ratio'), fn($v) => $v !== null);
                    if (count($legRatios) >= 10) {
                        $avgLR = array_sum($legRatios) / count($legRatios);
                        $stdLR = sqrt(array_sum(array_map(fn($v) => pow($v - $avgLR, 2), $legRatios)) / count($legRatios));
                        $cvLR  = ($stdLR > 0 && $avgLR > 0) ? ($stdLR / $avgLR) : 0;
                        if ($cvLR < 0.10)     { $score += 20; $signals[]   = 'Proporção de lendários robótica — RNG suspeitamente uniforme (CV=' . round($cvLR*100,1) . '%)'; }
                        elseif ($cvLR < 0.20) { $score += 8;  $signals[]   = 'Proporção de lendários pouco variável (CV=' . round($cvLR*100,1) . '%)'; }
                        else                  { $positives[] = 'Proporção de lendários com variação normal (RNG natural)'; }
                    }
                }

                // ── 9. User-Agent: diversidade e headless ─────────────────────
                $uaStmt = $pdo->prepare("
                    SELECT user_agent, COUNT(*) as cnt
                    FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND game_mode = 'extreme'
                    GROUP BY user_agent ORDER BY cnt DESC LIMIT 10
                ");
                $uaStmt->execute([$uid]);
                $uaRows = $uaStmt->fetchAll();
                $headlessPatterns = ['HeadlessChrome','PhantomJS','Selenium','puppeteer','playwright','WebDriver','Googlebot','bot/'];
                $headlessFound = [];
                foreach ($uaRows as $ua) {
                    foreach ($headlessPatterns as $pat) {
                        if (stripos($ua['user_agent'], $pat) !== false) { $headlessFound[] = $pat; break; }
                    }
                }
                if (!empty($headlessFound)) {
                    $score += 40; $signals[] = 'User-Agent headless/bot detectado: ' . implode(', ', array_unique($headlessFound));
                } elseif ($distUAs === 1 && $sessions30 >= 20) {
                    $score += 15; $signals[] = 'Apenas 1 User-Agent em ' . $sessions30 . ' sessões (sem troca de dispositivo)';
                } elseif ($distUAs <= 2 && $sessions30 >= 30) {
                    $score += 7;  $signals[] = $distUAs . ' User-Agents em ' . $sessions30 . ' sessões';
                } elseif ($distUAs >= 3) {
                    $positives[] = $distUAs . ' User-Agents distintos (múltiplos dispositivos)';
                }

                // ── 10. Tempo de restart (ended_at → started_at) ──────────────
                // Bots reiniciam em <5s; humanos levam >15s normalmente
                $restartStmt = $pdo->prepare("
                    SELECT TIMESTAMPDIFF(SECOND, gs1.ended_at, gs2.started_at) as gap
                    FROM game_sessions gs1
                    JOIN game_sessions gs2
                      ON gs2.google_uid = gs1.google_uid
                     AND gs2.started_at > gs1.ended_at
                     AND gs2.started_at < DATE_ADD(gs1.ended_at, INTERVAL 120 SECOND)
                    WHERE gs1.google_uid = ?
                      AND gs1.ended_at IS NOT NULL
                      AND gs1.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND gs1.status IN ('completed','flagged') AND gs1.game_mode = 'extreme'
                    ORDER BY gs1.started_at ASC LIMIT 60
                ");
                $restartStmt->execute([$uid]);
                $restartGaps = array_filter(array_column($restartStmt->fetchAll(), 'gap'), fn($v) => $v >= 0);
                if (count($restartGaps) >= 5) {
                    $avgRG  = array_sum($restartGaps) / count($restartGaps);
                    $stdRG  = sqrt(array_sum(array_map(fn($v) => pow($v - $avgRG, 2), $restartGaps)) / count($restartGaps));
                    $under5 = count(array_filter($restartGaps, fn($v) => $v <= 5));
                    $ratio5 = $under5 / count($restartGaps);
                    if ($ratio5 >= 0.8)       { $score += 25; $signals[]   = round($ratio5*100) . '% dos restarts em ≤5s (média ' . round($avgRG,1) . 's) — automação evidente'; }
                    elseif ($ratio5 >= 0.5)   { $score += 12; $signals[]   = round($ratio5*100) . '% dos restarts em ≤5s (média ' . round($avgRG,1) . 's)'; }
                    elseif ($avgRG > 20)       { $positives[] = 'Tempo de restart natural (média ' . round($avgRG,1) . 's)'; }
                }

                // ── 11. Entropia de horários (sempre mesma hora = bot agendado) ─
                $hourStmt = $pdo->prepare("
                    SELECT HOUR(started_at) as hr, COUNT(*) as cnt
                    FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed','flagged') AND game_mode = 'extreme'
                    GROUP BY hr
                ");
                $hourStmt->execute([$uid]);
                $hourDist = $hourStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $totalHourSess = array_sum($hourDist);
                if ($totalHourSess >= 20) {
                    $entropy = 0;
                    foreach ($hourDist as $cnt) {
                        $p = $cnt / $totalHourSess;
                        $entropy -= $p * log($p, 2);
                    }
                    $maxEntropy = log(min(count($hourDist), 24), 2);
                    $relEntropy = ($maxEntropy > 0) ? ($entropy / $maxEntropy) : 1;
                    if ($relEntropy < 0.35)       { $score += 20; $signals[]   = 'Horário extremamente concentrado — padrão de agendamento (entropia=' . round($relEntropy*100) . '%)'; }
                    elseif ($relEntropy < 0.55)   { $score += 10; $signals[]   = 'Horário pouco variado — possível agendamento (entropia=' . round($relEntropy*100) . '%)'; }
                    else                           { $positives[] = 'Distribuição de horários variada (entropia=' . round($relEntropy*100) . '%)'; }
                }

                // ── 12. Sequência de mission_number (sem gaps = bot) ──────────
                $missionStmt = $pdo->prepare("
                    SELECT mission_number FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed','flagged','abandoned') AND game_mode = 'extreme'
                    ORDER BY started_at ASC LIMIT 100
                ");
                $missionStmt->execute([$uid]);
                $missions = array_column($missionStmt->fetchAll(), 'mission_number');
                if (count($missions) >= 10) {
                    $gaps_m = [];
                    for ($i = 1; $i < count($missions); $i++) {
                        $g = (int)$missions[$i] - (int)$missions[$i-1];
                        if ($g > 0) $gaps_m[] = $g;
                    }
                    if (!empty($gaps_m)) {
                        $always1 = count(array_filter($gaps_m, fn($g) => $g === 1)) / count($gaps_m);
                        if ($always1 >= 0.98 && count($missions) >= 20) { $score += 15; $signals[]   = 'Mission number sempre +1, sem nenhum gap (' . count($missions) . ' sessões) — sequência robótica'; }
                        elseif ($always1 >= 0.9)                          { $score += 7;  $signals[]   = round($always1*100) . '% das sessões com incremento exato +1'; }
                        else                                               { $positives[] = 'Mission numbers com variação natural (resets/gaps presentes)'; }
                    }
                }

                // ── 13. Intervalos entre sessões (started_at) ─────────────────
                $gapStmt = $pdo->prepare("
                    SELECT started_at FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed','flagged') AND game_mode = 'extreme'
                    ORDER BY started_at ASC LIMIT 100
                ");
                $gapStmt->execute([$uid]);
                $times = $gapStmt->fetchAll(PDO::FETCH_COLUMN);
                $gaps = [];
                for ($i = 1; $i < count($times); $i++) {
                    $gap = strtotime($times[$i]) - strtotime($times[$i-1]);
                    if ($gap > 0 && $gap < 7200) $gaps[] = $gap;
                }
                if (count($gaps) >= 5) {
                    $avgG = array_sum($gaps) / count($gaps);
                    $stdG = sqrt(array_sum(array_map(fn($g) => pow($g - $avgG, 2), $gaps)) / count($gaps));
                    $cvG  = ($stdG > 0 && $avgG > 0) ? ($stdG / $avgG) : 0;
                    if ($cvG < 0.08 && $avgG < 300)     { $score += 20; $signals[]   = 'Intervalo mecânico entre sessões (' . round($avgG) . 's ±' . round($stdG) . 's)'; }
                    elseif ($cvG < 0.15 && $avgG < 300) { $score += 10; $signals[]   = 'Intervalo regular entre sessões (' . round($avgG) . 's)'; }
                    else                                  { $positives[] = 'Intervalos entre sessões naturais'; }
                }

                // ── 14. Pico por hora ─────────────────────────────────────────
                $hrStmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed','flagged') AND game_mode = 'extreme'
                    GROUP BY DATE_FORMAT(started_at,'%Y-%m-%d %H:00')
                    ORDER BY cnt DESC LIMIT 1
                ");
                $hrStmt->execute([$uid]);
                $maxPerHour = (int)($hrStmt->fetchColumn() ?: 0);
                if ($maxPerHour >= 12)    { $score += 15; $signals[] = 'Pico: ' . $maxPerHour . ' sessões/hora'; }
                elseif ($maxPerHour >= 8) { $score += 8;  $signals[] = $maxPerHour . ' sessões/hora no pico'; }

                // ── 15. Conta nova + volume ───────────────────────────────────
                $accountAge = (int)$acctUser['account_age_days'];
                if ($accountAge < 3 && $sessions30 >= 20) { $score += 10; $signals[] = 'Conta nova (' . $accountAge . 'd) com ' . $sessions30 . ' sessões'; }

                // ── 16. Madrugada ─────────────────────────────────────────────
                $nightStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND HOUR(started_at) BETWEEN 2 AND 5 AND status IN ('completed','flagged') AND game_mode = 'extreme'
                ");
                $nightStmt->execute([$uid]);
                $nightSessions = (int)$nightStmt->fetchColumn();
                $nightRatio = ($sessions30 > 0) ? ($nightSessions / $sessions30) : 0;
                if ($nightSessions >= 10 && $nightRatio > 0.5) { $score += 10; $signals[] = round($nightRatio*100) . '% madrugada (' . $nightSessions . ' sessões 2h-6h)'; }

                // ── 17. Win streak ────────────────────────────────────────────
                $streakStmt = $pdo->prepare("
                    SELECT status FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed','abandoned') AND game_mode = 'extreme'
                    ORDER BY started_at DESC LIMIT 100
                ");
                $streakStmt->execute([$uid]);
                $statuses = $streakStmt->fetchAll(PDO::FETCH_COLUMN);
                $maxStreak = 0; $curStreak = 0;
                foreach ($statuses as $st2) {
                    if ($st2 === 'completed') { $curStreak++; $maxStreak = max($maxStreak, $curStreak); }
                    else { $curStreak = 0; }
                }
                if ($maxStreak >= 30)     { $score += 25; $signals[] = $maxStreak . ' vitórias consecutivas'; }
                elseif ($maxStreak >= 20) { $score += 15; $signals[] = $maxStreak . ' vitórias seguidas'; }
                elseif ($maxStreak >= 12) { $score += 8;  $signals[] = $maxStreak . ' vitórias seguidas'; }

                // ── 18. Multi-conta por IP ────────────────────────────────────
                $multiStmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT gs2.google_uid)
                    FROM game_sessions gs1
                    INNER JOIN game_sessions gs2 ON gs1.ip_address = gs2.ip_address AND gs2.google_uid != gs1.google_uid
                    WHERE gs1.google_uid = ? AND gs1.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND gs2.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND gs1.game_mode = 'extreme'
                    LIMIT 1
                ");
                $multiStmt->execute([$uid]);
                $otherAccounts = (int)$multiStmt->fetchColumn();
                if ($otherAccounts >= 3)     { $score += 15; $signals[]   = $otherAccounts . ' outras contas no mesmo IP'; }
                elseif ($otherAccounts >= 1) { $score += 5;  $signals[]   = $otherAccounts . ' outra(s) conta(s) no mesmo IP'; }
                else                          { $positives[] = 'IP único (sem multi-conta detectada)'; }

                // ── 19. Horas/dia ─────────────────────────────────────────────
                $activeDays = max(1, (int)$s30['active_days']);
                $avgHoursDay = (($avgD * $comp) / 3600) / $activeDays;
                if ($avgHoursDay >= 10)    { $score += 15; $signals[] = round($avgHoursDay,1) . 'h/dia jogando (inumano)'; }
                elseif ($avgHoursDay >= 6) { $score += 8;  $signals[] = round($avgHoursDay,1) . 'h/dia'; }

                // ── 20. (Removido - análise já filtra apenas modo Extreme)

                // ── 21. Saque rápido após ganho ───────────────────────────────
                $totalWithdrawn = (float)$acctUser['total_withdrawn'];
                $totalEarned    = (float)($acctUser['total_earned_brl'] ?? 0);
                if ($totalEarned > 0 && $totalWithdrawn / $totalEarned > 0.9 && $totalWithdrawn > 5) {
                    $score += 5; $signals[] = 'Saca ' . round(($totalWithdrawn/$totalEarned)*100) . '% do ganho (R$ ' . number_format($totalWithdrawn,2,',','.') . ')';
                }

            } else {
                $positives[] = 'Poucas sessões para análise estatística (' . $sessions30 . ')';
            }

            // ── Dados complementares ──────────────────────────────────────────
            $txStmt = $pdo->prepare("
                SELECT type, amount_brl, description, status, created_at
                FROM transactions WHERE google_uid = ?
                ORDER BY created_at DESC LIMIT 15
            ");
            $txStmt->execute([$uid]);
            $recentTx = $txStmt->fetchAll();

            $wdStmt = $pdo->prepare("
                SELECT id, amount_brl, status, admin_notes, created_at
                FROM withdrawals WHERE user_id = ?
                ORDER BY created_at DESC LIMIT 10
            ");
            $wdStmt->execute([(int)$acctUser['id']]);
            $recentWd = $wdStmt->fetchAll();

            $sameIpStmt = $pdo->prepare("
                SELECT DISTINCT u2.email, u2.display_name, u2.is_banned, gs1.ip_address
                FROM game_sessions gs1
                INNER JOIN game_sessions gs2 ON gs1.ip_address = gs2.ip_address AND gs2.google_uid != gs1.google_uid
                INNER JOIN users u2 ON u2.google_uid = gs2.google_uid
                WHERE gs1.google_uid = ? AND gs1.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                LIMIT 10
            ");
            $sameIpStmt->execute([$uid]);
            $sameIpAccounts = $sameIpStmt->fetchAll();

            $score = min($score, 100);
            $riskLevel = $score >= 60 ? 'alto' : ($score >= 30 ? 'medio' : 'baixo');

            $accountAnalysis = [
                'user'                => $acctUser,
                'risk_score'          => $score,
                'risk_level'          => $riskLevel,
                'risk_signals'        => $signals,
                'positive_signals'    => $positives,
                'stats'               => $s30,
                'sessions_30d'        => $sessions30,
                'modes'               => $modes ?? [],
                'ua_rows'             => $uaRows ?? [],
                'hour_dist'           => $hourDist ?? [],
                'recent_transactions' => $recentTx,
                'recent_withdrawals'  => $recentWd,
                'same_ip_accounts'    => $sameIpAccounts,
            ];
        }
    } catch (Exception $e) {
        $accountError = "Erro na análise: " . $e->getMessage();
    }
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-microscope"></i> Análise Individual de Conta</h1>
        <p class="page-subtitle">Insira o email ou Google UID para analisar padrões de comportamento e risco de fraude — Modo Extreme.</p>
    </div>

    <div class="panel" style="margin-bottom:20px;">
        <div class="panel-body" style="padding:16px 20px;">
            <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="page" value="account-analysis">
                <div style="flex:1;min-width:260px;">
                    <label class="form-label" style="font-size:0.8rem;">Email ou Google UID</label>
                    <input type="text" name="email" class="form-control" placeholder="email@exemplo.com ou google_uid"
                        value="<?php echo htmlspecialchars($searchEmail); ?>" style="font-size:0.9rem;">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Analisar</button>
                <?php if (!empty($searchEmail)): ?>
                    <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=account-analysis" class="btn btn-secondary" style="text-decoration:none;">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                <?php endif; ?>
                <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=suspect-analysis" class="btn btn-secondary" style="text-decoration:none;margin-left:auto;">
                    <i class="fas fa-robot"></i> Ver Ranking de Suspeitos
                </a>
            </form>
        </div>
    </div>

    <?php if ($accountError): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $accountError; ?></div>
    <?php endif; ?>

    <?php if ($accountAnalysis): ?>
    <?php
        $au      = $accountAnalysis['user'];
        $aScore  = $accountAnalysis['risk_score'];
        $aLevel  = $accountAnalysis['risk_level'];
        $st      = $accountAnalysis['stats'];
        $color   = '#05ffa1'; $bg = 'rgba(5,255,161,0.1)'; $label = 'BAIXO';
        if ($aLevel === 'alto')   { $color = '#ff3366'; $bg = 'rgba(255,51,102,0.1)';  $label = 'ALTO'; }
        elseif ($aLevel === 'medio') { $color = '#ffaa00'; $bg = 'rgba(255,170,0,0.1)'; $label = 'MÉDIO'; }
    ?>

    <!-- Score + Info principal -->
    <div class="panel" style="margin-bottom:20px;border:1px solid <?php echo $color; ?>40;">
        <div class="panel-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="panel-title"><i class="fas fa-user-secret"></i> <?php echo htmlspecialchars($au['display_name'] ?? $au['email']); ?></h3>
            <div style="display:flex;gap:8px;align-items:center;">
                <?php if ($au['is_banned']): ?>
                    <span class="badge badge-danger">BANIDO</span>
                <?php endif; ?>
                <?php if ($au['is_premium'] ?? false): ?>
                    <span class="badge" style="background:rgba(255,215,0,0.2);color:#ffd700;">PREMIUM</span>
                <?php endif; ?>
                <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=players&search=<?php echo urlencode($au['email'] ?? ''); ?>"
                   class="btn btn-secondary btn-sm" style="text-decoration:none;">
                    <i class="fas fa-user"></i> Perfil Completo
                </a>
            </div>
        </div>
        <div class="panel-body">
            <div style="display:grid;grid-template-columns:160px 1fr;gap:20px;margin-bottom:20px;">
                <!-- Score -->
                <div style="text-align:center;padding:24px 16px;background:<?php echo $bg; ?>;border-radius:12px;border:2px solid <?php echo $color; ?>30;">
                    <div style="font-family:'Orbitron',monospace;font-size:2.8rem;font-weight:900;color:<?php echo $color; ?>;"><?php echo $aScore; ?>%</div>
                    <div style="font-size:0.8rem;font-weight:700;color:<?php echo $color; ?>;margin-top:6px;letter-spacing:1px;">RISCO <?php echo $label; ?></div>
                    <div style="margin-top:10px;font-size:0.7rem;color:var(--text-dim);">
                        <?php echo count($accountAnalysis['risk_signals']); ?> sinal(is) de risco<br>
                        <?php echo count($accountAnalysis['positive_signals']); ?> sinal(is) positivo(s)
                    </div>
                </div>
                <!-- Info grid -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;align-content:start;">
                    <?php
                    $infoItems = [
                        ['Email',         htmlspecialchars($au['email'] ?? 'N/A')],
                        ['Conta criada',  date('d/m/Y', strtotime($au['created_at'])) . ' (' . (int)$au['account_age_days'] . 'd)'],
                        ['Saldo',         'R$ ' . number_format((float)($au['balance_brl'] ?? 0), 2, ',', '.')],
                        ['Total ganho',   'R$ ' . number_format((float)($au['total_earned_brl'] ?? 0), 2, ',', '.')],
                        ['Total sacado',  'R$ ' . number_format((float)($au['total_withdrawn'] ?? 0), 2, ',', '.')],
                        ['Créditos',      (int)($au['credits'] ?? 0)],
                        ['Sessões total', (int)$au['total_sessions'] . ' (' . (int)$au['completed_sessions'] . 'W/' . (int)$au['abandoned_sessions'] . 'L/' . (int)$au['flagged_sessions'] . 'F)'],
                        ['IPs distintos', (int)$au['distinct_ips']],
                        ['Saques pend.',  (int)$au['pending_withdrawals']],
                    ];
                    foreach ($infoItems as [$k, $v]):
                    ?>
                    <div style="padding:8px 10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                        <div style="font-size:0.65rem;color:var(--text-dim);margin-bottom:2px;"><?php echo $k; ?></div>
                        <div style="font-weight:600;font-size:0.82rem;word-break:break-all;"><?php echo $v; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Sinais -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                <div>
                    <h4 style="font-size:0.88rem;margin:0 0 10px;color:<?php echo $color; ?>;">
                        <i class="fas fa-exclamation-triangle"></i> Sinais de Risco (<?php echo count($accountAnalysis['risk_signals']); ?>)
                    </h4>
                    <?php if (empty($accountAnalysis['risk_signals'])): ?>
                        <div style="padding:12px;background:rgba(5,255,161,0.05);border-radius:8px;font-size:0.85rem;color:var(--success);">
                            <i class="fas fa-check-circle"></i> Nenhum sinal de risco detectado
                        </div>
                    <?php else: ?>
                        <?php foreach ($accountAnalysis['risk_signals'] as $sig): ?>
                        <div style="padding:6px 10px;margin-bottom:4px;background:rgba(255,51,102,0.06);border-radius:6px;font-size:0.8rem;border-left:3px solid <?php echo $color; ?>;">
                            <i class="fas fa-exclamation-circle" style="color:<?php echo $color; ?>;margin-right:4px;"></i>
                            <?php echo htmlspecialchars($sig); ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div>
                    <h4 style="font-size:0.88rem;margin:0 0 10px;color:#05ffa1;">
                        <i class="fas fa-check-circle"></i> Sinais Positivos (<?php echo count($accountAnalysis['positive_signals']); ?>)
                    </h4>
                    <?php if (empty($accountAnalysis['positive_signals'])): ?>
                        <div style="padding:12px;background:rgba(255,170,0,0.05);border-radius:8px;font-size:0.85rem;color:var(--text-dim);">
                            Sem sinais positivos suficientes
                        </div>
                    <?php else: ?>
                        <?php foreach ($accountAnalysis['positive_signals'] as $sig): ?>
                        <div style="padding:6px 10px;margin-bottom:4px;background:rgba(5,255,161,0.06);border-radius:6px;font-size:0.8rem;border-left:3px solid #05ffa1;">
                            <i class="fas fa-check" style="color:#05ffa1;margin-right:4px;"></i>
                            <?php echo htmlspecialchars($sig); ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Estatísticas 30 dias -->
            <?php if ($accountAnalysis['sessions_30d'] >= 5): ?>
            <div style="margin-bottom:20px;">
                <h4 style="font-size:0.88rem;margin:0 0 10px;"><i class="fas fa-chart-bar"></i> Estatísticas (últimos 30 dias)</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;">
                    <?php
                    $statItems = [
                        ['Sessões',        (int)$st['sessions_30d']],
                        ['Completas',      (int)$st['completed_30d']],
                        ['Abandonadas',    (int)$st['abandoned_30d']],
                        ['Flagged',        (int)$st['flagged_30d']],
                        ['R$ Médio/sessão','R$ ' . number_format((float)$st['avg_earnings'],4,',','.')],
                        ['R$ Total 30d',   'R$ ' . number_format((float)$st['total_earnings_30d'],2,',','.')],
                        ['Asteroides/pt',  round((float)$st['avg_asteroids'],1)],
                        ['Duração média',  round((float)$st['avg_duration'],1) . 's'],
                        ['Dias ativos',    (int)$st['active_days']],
                        ['IPs 30d',        (int)$st['ips_30d']],
                    ];
                    foreach ($statItems as [$k, $v]):
                    ?>
                    <div style="padding:8px;background:rgba(0,0,0,0.2);border-radius:8px;text-align:center;">
                        <div style="font-size:0.65rem;color:var(--text-dim);"><?php echo $k; ?></div>
                        <div style="font-weight:700;font-size:0.88rem;"><?php echo $v; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Modos jogados -->
            <?php if (!empty($accountAnalysis['modes'])): ?>
            <div style="margin-bottom:20px;">
                <h4 style="font-size:0.88rem;margin:0 0 10px;"><i class="fas fa-gamepad"></i> Modos Jogados (30 dias)</h4>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php foreach ($accountAnalysis['modes'] as $m): ?>
                    <span style="padding:6px 14px;background:rgba(0,200,255,0.1);border:1px solid rgba(0,200,255,0.2);border-radius:8px;font-size:0.82rem;">
                        <?php echo htmlspecialchars(ucfirst($m['game_mode'] ?? 'N/A')); ?>: <strong><?php echo (int)$m['cnt']; ?></strong>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- User-Agents -->
            <?php if (!empty($accountAnalysis['ua_rows'])): ?>
            <div style="margin-bottom:20px;">
                <h4 style="font-size:0.88rem;margin:0 0 10px;"><i class="fas fa-desktop"></i> User-Agents Detectados</h4>
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <?php foreach ($accountAnalysis['ua_rows'] as $ua):
                        $isHeadless = preg_match('/HeadlessChrome|PhantomJS|Selenium|puppeteer|playwright|WebDriver/i', $ua['user_agent']);
                    ?>
                    <div style="padding:6px 10px;background:<?php echo $isHeadless ? 'rgba(255,51,102,0.1)' : 'rgba(0,0,0,0.2)'; ?>;border-radius:6px;font-size:0.75rem;display:flex;justify-content:space-between;align-items:center;">
                        <span style="word-break:break-all;max-width:85%;color:<?php echo $isHeadless ? '#ff3366' : 'inherit'; ?>;">
                            <?php if ($isHeadless): ?><i class="fas fa-robot" style="margin-right:4px;"></i><?php endif; ?>
                            <?php echo htmlspecialchars(substr($ua['user_agent'], 0, 120)); ?>
                        </span>
                        <span style="color:var(--text-dim);white-space:nowrap;margin-left:8px;"><?php echo (int)$ua['cnt']; ?>x</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Distribuição de horários -->
            <?php if (!empty($accountAnalysis['hour_dist'])): ?>
            <div style="margin-bottom:20px;">
                <h4 style="font-size:0.88rem;margin:0 0 10px;"><i class="fas fa-clock"></i> Distribuição de Horários (30 dias)</h4>
                <?php
                $hd = $accountAnalysis['hour_dist'];
                $hdMax = max($hd) ?: 1;
                ?>
                <div style="display:grid;grid-template-columns:repeat(24,1fr);gap:2px;align-items:end;height:50px;">
                    <?php for ($h = 0; $h < 24; $h++):
                        $cnt = $hd[$h] ?? 0;
                        $pct = round(($cnt / $hdMax) * 100);
                        $barColor = ($h >= 2 && $h <= 5) ? '#ff3366' : 'var(--primary)';
                    ?>
                    <div title="<?php echo $h; ?>h: <?php echo $cnt; ?> sessões"
                         style="width:100%;height:<?php echo max(4,$pct); ?>%;background:<?php echo $barColor; ?>;opacity:<?php echo $cnt>0?'0.8':'0.15'; ?>;border-radius:2px 2px 0 0;">
                    </div>
                    <?php endfor; ?>
                </div>
                <div style="display:grid;grid-template-columns:repeat(24,1fr);gap:2px;margin-top:2px;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <div style="text-align:center;font-size:0.55rem;color:var(--text-dim);"><?php echo $h; ?></div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Contas no mesmo IP -->
            <?php if (!empty($accountAnalysis['same_ip_accounts'])): ?>
            <div style="margin-bottom:20px;">
                <h4 style="font-size:0.88rem;margin:0 0 10px;color:#ffaa00;"><i class="fas fa-network-wired"></i> Outras Contas no Mesmo IP</h4>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php foreach ($accountAnalysis['same_ip_accounts'] as $sip): ?>
                    <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=account-analysis&email=<?php echo urlencode($sip['email']); ?>"
                       style="padding:6px 14px;background:rgba(255,170,0,0.1);border:1px solid rgba(255,170,0,0.3);border-radius:8px;font-size:0.8rem;text-decoration:none;color:inherit;">
                        <?php if ($sip['is_banned']): ?><span style="color:#ff3366;">[BAN] </span><?php endif; ?>
                        <?php echo htmlspecialchars($sip['display_name'] ?? $sip['email']); ?>
                        <small style="color:var(--text-dim);"> — <?php echo htmlspecialchars($sip['ip_address']); ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Saques e Transações lado a lado -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
        <!-- Últimos Saques -->
        <div class="panel">
            <div class="panel-header"><h3 class="panel-title"><i class="fas fa-money-bill-wave"></i> Últimos Saques</h3></div>
            <div class="panel-body" style="padding:0;">
                <?php if (empty($accountAnalysis['recent_withdrawals'])): ?>
                    <p style="padding:16px;color:var(--text-dim);font-size:0.85rem;">Nenhum saque registrado.</p>
                <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead><tr><th>#</th><th>Valor</th><th>Status</th><th>Notas</th><th>Data</th></tr></thead>
                        <tbody>
                            <?php
                            $wColors = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','processing'=>'info'];
                            $wLabels = ['pending'=>'Pendente','approved'=>'Aprovado','rejected'=>'Rejeitado','processing'=>'Processando'];
                            foreach ($accountAnalysis['recent_withdrawals'] as $w):
                            ?>
                            <tr>
                                <td>#<?php echo $w['id']; ?></td>
                                <td style="font-weight:600;">R$ <?php echo number_format((float)$w['amount_brl'],2,',','.'); ?></td>
                                <td><span class="badge badge-<?php echo $wColors[$w['status']] ?? 'secondary'; ?>"><?php echo $wLabels[$w['status']] ?? $w['status']; ?></span></td>
                                <td style="font-size:0.75rem;color:var(--text-dim);max-width:120px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($w['admin_notes'] ?? ''); ?></td>
                                <td style="font-size:0.78rem;"><?php echo date('d/m/y H:i', strtotime($w['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Últimas Transações -->
        <div class="panel">
            <div class="panel-header"><h3 class="panel-title"><i class="fas fa-exchange-alt"></i> Últimas Transações</h3></div>
            <div class="panel-body" style="padding:0;">
                <?php if (empty($accountAnalysis['recent_transactions'])): ?>
                    <p style="padding:16px;color:var(--text-dim);font-size:0.85rem;">Nenhuma transação registrada.</p>
                <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Tipo</th><th>Valor</th><th>Descrição</th><th>Status</th><th>Data</th></tr></thead>
                        <tbody>
                            <?php foreach ($accountAnalysis['recent_transactions'] as $tx): ?>
                            <tr>
                                <td style="font-size:0.8rem;"><?php echo htmlspecialchars($tx['type']); ?></td>
                                <td style="font-weight:600;color:<?php echo (float)$tx['amount_brl'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    R$ <?php echo number_format((float)$tx['amount_brl'],2,',','.'); ?>
                                </td>
                                <td style="font-size:0.75rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($tx['description'] ?? ''); ?></td>
                                <td><span class="badge badge-<?php echo $tx['status']==='completed'?'success':'warning'; ?>"><?php echo $tx['status']; ?></span></td>
                                <td style="font-size:0.78rem;"><?php echo date('d/m/y H:i', strtotime($tx['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; // fim accountAnalysis ?>
</div>
