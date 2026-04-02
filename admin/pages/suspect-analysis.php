<?php
// ============================================
// UNOBIX - Análise de Contas Suspeitas
// admin/pages/suspect-analysis.php
// Detecção passiva de macro/bot por scoring
// ============================================

$pageTitle = 'Análise de Suspeitas';

$days = min(max((int)($_GET['days'] ?? 7), 1), 30);
$minSessions = max((int)($_GET['min_sessions'] ?? 5), 3);
$filterLevel = $_GET['level'] ?? 'all';
$analyzeEmail = trim($_GET['analyze_email'] ?? '');

// ============================================
// ANÁLISE INDIVIDUAL POR EMAIL
// ============================================
$accountAnalysis = null;
$accountError = null;

if (!empty($analyzeEmail)) {
    try {
        // Buscar usuário pelo email
        $userStmt = $pdo->prepare("
            SELECT u.*,
                   DATEDIFF(NOW(), u.created_at) as account_age_days,
                   (SELECT COUNT(*) FROM game_sessions gs WHERE gs.google_uid = u.google_uid) as total_sessions,
                   (SELECT COUNT(*) FROM game_sessions gs WHERE gs.google_uid = u.google_uid AND gs.status = 'completed') as completed_sessions,
                   (SELECT COUNT(*) FROM game_sessions gs WHERE gs.google_uid = u.google_uid AND gs.status = 'abandoned') as abandoned_sessions,
                   (SELECT COUNT(*) FROM game_sessions gs WHERE gs.google_uid = u.google_uid AND gs.status = 'flagged') as flagged_sessions,
                   (SELECT COUNT(*) FROM withdrawals w WHERE w.user_id = u.id) as total_withdrawals,
                   (SELECT COALESCE(SUM(w.amount_brl), 0) FROM withdrawals w WHERE w.user_id = u.id AND w.status = 'approved') as total_withdrawn,
                   (SELECT COUNT(*) FROM withdrawals w WHERE w.user_id = u.id AND w.status = 'pending') as pending_withdrawals,
                   (SELECT COUNT(DISTINCT gs.ip_address) FROM game_sessions gs WHERE gs.google_uid = u.google_uid) as distinct_ips
            FROM users u
            WHERE u.email = ? OR u.google_uid = ?
            LIMIT 1
        ");
        $userStmt->execute([$analyzeEmail, $analyzeEmail]);
        $acctUser = $userStmt->fetch();

        if (!$acctUser) {
            $accountError = "Conta não encontrada para: " . $analyzeEmail;
        } else {
            $uid = $acctUser['google_uid'];
            $accountAnalysis = [
                'user' => $acctUser,
                'risk_score' => 0,
                'risk_signals' => [],
                'positive_signals' => [],
                'stats' => []
            ];

            // Sessões recentes (30 dias)
            $sessStmt = $pdo->prepare("
                SELECT
                    COUNT(*) as sessions_30d,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_30d,
                    SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_30d,
                    SUM(CASE WHEN status = 'flagged' THEN 1 ELSE 0 END) as flagged_30d,
                    AVG(earnings_brl) as avg_earnings,
                    MAX(earnings_brl) as max_earnings,
                    STDDEV(earnings_brl) as stddev_earnings,
                    SUM(earnings_brl) as total_earnings_30d,
                    AVG(asteroids_destroyed) as avg_asteroids,
                    STDDEV(asteroids_destroyed) as stddev_asteroids,
                    AVG(legendary_asteroids) as avg_legendary,
                    AVG(epic_asteroids) as avg_epic,
                    AVG(game_duration) as avg_duration,
                    STDDEV(game_duration) as stddev_duration,
                    COUNT(DISTINCT DATE(started_at)) as active_days,
                    COUNT(DISTINCT ip_address) as ips_30d
                FROM game_sessions
                WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND status IN ('completed', 'flagged', 'abandoned')
            ");
            $sessStmt->execute([$uid]);
            $s30 = $sessStmt->fetch();

            $sessions30 = (int)$s30['sessions_30d'];
            $accountAnalysis['stats'] = $s30;

            $score = 0;
            $signals = [];
            $positives = [];

            if ($sessions30 >= 5) {
                $avgEarnings = (float)$s30['avg_earnings'];
                $stddevEarnings = (float)$s30['stddev_earnings'];
                $avgAsteroids = (float)$s30['avg_asteroids'];
                $stddevAsteroids = (float)$s30['stddev_asteroids'];
                $avgDuration = (float)$s30['avg_duration'];
                $stddevDuration = (float)$s30['stddev_duration'];
                $avgLegendary = (float)$s30['avg_legendary'];
                $avgEpic = (float)$s30['avg_epic'];
                $completed30 = (int)$s30['completed_30d'];
                $abandoned30 = (int)$s30['abandoned_30d'];
                $flagged30 = (int)$s30['flagged_30d'];

                // 1. Consistência de ganhos
                if ($sessions30 >= 10 && $avgEarnings > 0.05) {
                    $cv = ($stddevEarnings > 0) ? ($stddevEarnings / $avgEarnings) : 0;
                    if ($cv < 0.10) { $score += 20; $signals[] = 'Ganhos extremamente consistentes (CV=' . round($cv * 100, 1) . '%)'; }
                    elseif ($cv < 0.20) { $score += 10; $signals[] = 'Ganhos pouco variáveis (CV=' . round($cv * 100, 1) . '%)'; }
                    else { $positives[] = 'Variação natural de ganhos (CV=' . round($cv * 100, 1) . '%)'; }
                }

                // 2. Duração consistente
                if ($sessions30 >= 10 && $avgDuration > 0) {
                    $cvD = ($stddevDuration > 0) ? ($stddevDuration / $avgDuration) : 0;
                    if ($cvD < 0.03) { $score += 15; $signals[] = 'Duração quase idêntica entre sessões'; }
                    elseif ($cvD < 0.08) { $score += 7; $signals[] = 'Duração muito similar entre sessões'; }
                    else { $positives[] = 'Duração varia naturalmente'; }
                }

                // 3. Win rate
                $winRate = ($sessions30 > 0) ? ($completed30 / $sessions30) : 0;
                if ($sessions30 >= 10 && $winRate >= 0.95) {
                    $score += 25; $signals[] = 'Taxa de vitória ' . round($winRate * 100, 1) . '%';
                } elseif ($sessions30 >= 10 && $winRate >= 0.85) {
                    $score += 15; $signals[] = 'Vitória alta: ' . round($winRate * 100, 1) . '%';
                } elseif ($sessions30 >= 10) {
                    $positives[] = 'Win rate normal: ' . round($winRate * 100, 1) . '%';
                }

                // 4. Zero abandonos
                if ($sessions30 >= 15 && $abandoned30 === 0) {
                    $score += 20; $signals[] = 'Zero game over em ' . $sessions30 . ' sessões';
                } elseif ($sessions30 >= 15 && $abandoned30 <= 1) {
                    $score += 10; $signals[] = 'Apenas ' . $abandoned30 . ' game over em ' . $sessions30 . ' sessões';
                }

                // 5. Flagged
                if ($flagged30 > 0) {
                    $fr = $flagged30 / $sessions30;
                    if ($fr > 0.3) { $score += 15; $signals[] = round($fr * 100) . '% flagged (' . $flagged30 . ')'; }
                    elseif ($fr > 0.1) { $score += 8; $signals[] = $flagged30 . ' sessões flagged'; }
                } else { $positives[] = 'Nenhuma sessão flagged'; }

                // 6. Asteroides consistentes
                if ($sessions30 >= 10 && $avgAsteroids > 50) {
                    $cvAst = ($stddevAsteroids > 0) ? ($stddevAsteroids / $avgAsteroids) : 0;
                    if ($cvAst < 0.05) { $score += 20; $signals[] = 'Asteroides quase idênticos (CV=' . round($cvAst * 100, 1) . '%)'; }
                    elseif ($cvAst < 0.10) { $score += 10; $signals[] = 'Asteroides muito consistentes'; }
                    else { $positives[] = 'Variação natural de asteroides'; }
                }

                // 7. Intervalos entre sessões
                $gapStmt = $pdo->prepare("
                    SELECT started_at FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed', 'flagged')
                    ORDER BY started_at ASC LIMIT 100
                ");
                $gapStmt->execute([$uid]);
                $times = $gapStmt->fetchAll(PDO::FETCH_COLUMN);
                $gaps = [];
                for ($i = 1; $i < count($times); $i++) {
                    $gap = strtotime($times[$i]) - strtotime($times[$i - 1]);
                    if ($gap > 0 && $gap < 7200) $gaps[] = $gap;
                }
                if (count($gaps) >= 5) {
                    $avgGap = array_sum($gaps) / count($gaps);
                    $variance = array_sum(array_map(fn($g) => pow($g - $avgGap, 2), $gaps)) / count($gaps);
                    $stddevGap = sqrt($variance);
                    $cvG = ($stddevGap > 0 && $avgGap > 0) ? ($stddevGap / $avgGap) : 0;
                    if ($cvG < 0.08 && $avgGap < 300) { $score += 20; $signals[] = 'Intervalo mecânico (' . round($avgGap) . 's ±' . round($stddevGap) . 's)'; }
                    elseif ($cvG < 0.15 && $avgGap < 300) { $score += 10; $signals[] = 'Intervalo regular (' . round($avgGap) . 's)'; }
                    else { $positives[] = 'Intervalos naturais'; }
                }

                // 8. Pico por hora
                $hrStmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed', 'flagged')
                    GROUP BY DATE_FORMAT(started_at, '%Y-%m-%d %H:00')
                    ORDER BY cnt DESC LIMIT 1
                ");
                $hrStmt->execute([$uid]);
                $maxPerHour = (int)($hrStmt->fetchColumn() ?: 0);
                if ($maxPerHour >= 12) { $score += 15; $signals[] = 'Pico: ' . $maxPerHour . ' sessões/hora'; }
                elseif ($maxPerHour >= 8) { $score += 8; $signals[] = $maxPerHour . ' sessões/hora (pico)'; }

                // 9. Conta nova + volume
                $accountAge = (int)$acctUser['account_age_days'];
                if ($accountAge < 3 && $sessions30 >= 20) {
                    $score += 10; $signals[] = 'Conta nova (' . $accountAge . 'd) com ' . $sessions30 . ' sessões';
                }

                // 10. Madrugada
                $nightStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND HOUR(started_at) BETWEEN 2 AND 5 AND status IN ('completed', 'flagged')
                ");
                $nightStmt->execute([$uid]);
                $nightSessions = (int)$nightStmt->fetchColumn();
                $nightRatio = ($sessions30 > 0) ? ($nightSessions / $sessions30) : 0;
                if ($nightSessions >= 10 && $nightRatio > 0.5) {
                    $score += 10; $signals[] = round($nightRatio * 100) . '% madrugada (' . $nightSessions . ' sessões 2h-6h)';
                }

                // 11. Win streak
                $streakStmt = $pdo->prepare("
                    SELECT status FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed', 'abandoned')
                    ORDER BY started_at DESC LIMIT 100
                ");
                $streakStmt->execute([$uid]);
                $statuses = $streakStmt->fetchAll(PDO::FETCH_COLUMN);
                $maxStreak = 0; $curStreak = 0;
                foreach ($statuses as $st) {
                    if ($st === 'completed') { $curStreak++; $maxStreak = max($maxStreak, $curStreak); }
                    else { $curStreak = 0; }
                }
                if ($maxStreak >= 30) { $score += 25; $signals[] = $maxStreak . ' vitórias consecutivas'; }
                elseif ($maxStreak >= 20) { $score += 15; $signals[] = $maxStreak . ' vitórias seguidas'; }
                elseif ($maxStreak >= 12) { $score += 8; $signals[] = $maxStreak . ' vitórias seguidas'; }

                // 12. Multi-conta por IP
                $multiStmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT gs2.google_uid)
                    FROM game_sessions gs1
                    INNER JOIN game_sessions gs2 ON gs1.ip_address = gs2.ip_address AND gs2.google_uid != gs1.google_uid
                    WHERE gs1.google_uid = ? AND gs1.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND gs2.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    LIMIT 1
                ");
                $multiStmt->execute([$uid]);
                $otherAccounts = (int)$multiStmt->fetchColumn();
                if ($otherAccounts >= 3) { $score += 15; $signals[] = $otherAccounts . ' outras contas no mesmo IP'; }
                elseif ($otherAccounts >= 1) { $score += 5; $signals[] = $otherAccounts . ' outra(s) conta(s) no mesmo IP'; }
                else { $positives[] = 'IP único (sem multi-conta)'; }

                // 13. Horas/dia
                $activeDays = max(1, (int)$s30['active_days']);
                $totalPlaySec = $avgDuration * $completed30;
                $avgHoursDay = ($totalPlaySec / 3600) / $activeDays;
                if ($avgHoursDay >= 10) { $score += 15; $signals[] = round($avgHoursDay, 1) . 'h/dia jogando'; }
                elseif ($avgHoursDay >= 6) { $score += 8; $signals[] = round($avgHoursDay, 1) . 'h/dia'; }

                // Modos jogados
                $modeStmt = $pdo->prepare("
                    SELECT game_mode, COUNT(*) as cnt FROM game_sessions
                    WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND status IN ('completed', 'flagged')
                    GROUP BY game_mode ORDER BY cnt DESC
                ");
                $modeStmt->execute([$uid]);
                $modes = $modeStmt->fetchAll();
                $accountAnalysis['modes'] = $modes;

                if (count($modes) === 1 && $modes[0]['game_mode'] === 'extreme' && $sessions30 >= 10) {
                    $score += 10; $signals[] = '100% Extreme (' . $sessions30 . ' sessões)';
                }

                // Últimas transações
                $txStmt = $pdo->prepare("
                    SELECT type, amount_brl, description, status, created_at
                    FROM transactions WHERE google_uid = ?
                    ORDER BY created_at DESC LIMIT 10
                ");
                $txStmt->execute([$uid]);
                $accountAnalysis['recent_transactions'] = $txStmt->fetchAll();

                // Saques
                $wdStmt = $pdo->prepare("
                    SELECT id, amount_brl, status, pix_key, created_at
                    FROM withdrawals WHERE user_id = ?
                    ORDER BY created_at DESC LIMIT 10
                ");
                $wdStmt->execute([(int)$acctUser['id']]);
                $accountAnalysis['recent_withdrawals'] = $wdStmt->fetchAll();

                // Padrão de saque rápido
                $totalWithdrawn = (float)$acctUser['total_withdrawn'];
                $totalEarned = (float)$acctUser['total_earned_brl'];
                if ($totalEarned > 0 && $totalWithdrawn / $totalEarned > 0.9 && $totalWithdrawn > 5) {
                    $score += 5; $signals[] = 'Saca ' . round(($totalWithdrawn / $totalEarned) * 100) . '% do ganho (R$ ' . number_format($totalWithdrawn, 2, ',', '.') . ')';
                }

            } else {
                $positives[] = 'Poucas sessões para análise estatística (' . $sessions30 . ')';
            }

            $score = min($score, 100);
            $accountAnalysis['risk_score'] = $score;
            $accountAnalysis['risk_signals'] = $signals;
            $accountAnalysis['positive_signals'] = $positives;
            $accountAnalysis['risk_level'] = $score >= 60 ? 'alto' : ($score >= 30 ? 'medio' : 'baixo');
        }
    } catch (Exception $e) {
        $accountError = "Erro na análise: " . $e->getMessage();
    }
}

// Buscar dados da API interna
$analysisData = null;
$error = null;

try {
    // Reutilizar a lógica do endpoint diretamente (já temos $pdo)
    $stmt = $pdo->prepare("
        SELECT
            u.id AS user_id, u.google_uid, u.display_name, u.email,
            u.is_premium, u.is_banned, u.total_earned_brl, u.total_played,
            u.created_at AS account_created,
            COUNT(gs.id) AS sessions_count,
            SUM(CASE WHEN gs.status = 'completed' THEN 1 ELSE 0 END) AS completed_sessions,
            SUM(CASE WHEN gs.status = 'abandoned' THEN 1 ELSE 0 END) AS abandoned_sessions,
            SUM(CASE WHEN gs.status = 'flagged' THEN 1 ELSE 0 END) AS flagged_sessions,
            AVG(gs.earnings_brl) AS avg_earnings,
            MAX(gs.earnings_brl) AS max_earnings,
            STDDEV(gs.earnings_brl) AS stddev_earnings,
            SUM(gs.earnings_brl) AS total_earnings_period,
            AVG(gs.asteroids_destroyed) AS avg_asteroids,
            AVG(gs.legendary_asteroids) AS avg_legendary,
            AVG(gs.epic_asteroids) AS avg_epic,
            AVG(gs.rare_asteroids) AS avg_rare,
            AVG(gs.game_duration) AS avg_duration,
            STDDEV(gs.game_duration) AS stddev_duration,
            MIN(gs.started_at) AS first_session,
            MAX(gs.started_at) AS last_session,
            COUNT(DISTINCT DATE(gs.started_at)) AS active_days,
            COUNT(DISTINCT gs.ip_address) AS distinct_ips
        FROM users u
        INNER JOIN game_sessions gs ON gs.google_uid = u.google_uid
        WHERE gs.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND gs.status IN ('completed', 'flagged', 'abandoned')
        GROUP BY u.id
        HAVING sessions_count >= ?
        ORDER BY sessions_count DESC
        LIMIT 200
    ");
    $stmt->execute([$days, $minSessions]);
    $players = $stmt->fetchAll();

    $results = [];
    foreach ($players as $p) {
        $uid = $p['google_uid'];
        $sessions = (int)$p['sessions_count'];
        $avgEarnings = (float)$p['avg_earnings'];
        $stddevEarnings = (float)$p['stddev_earnings'];
        $avgLegendary = (float)$p['avg_legendary'];
        $avgEpic = (float)$p['avg_epic'];
        $avgAsteroids = (float)$p['avg_asteroids'];
        $avgDuration = (float)$p['avg_duration'];
        $stddevDuration = (float)$p['stddev_duration'];
        $flagged = (int)$p['flagged_sessions'];

        // Intervalos entre sessões
        $stmtGap = $pdo->prepare("
            SELECT started_at FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'flagged')
            ORDER BY started_at ASC LIMIT 100
        ");
        $stmtGap->execute([$uid, $days]);
        $sessionTimes = $stmtGap->fetchAll(PDO::FETCH_COLUMN);

        $gaps = [];
        for ($i = 1; $i < count($sessionTimes); $i++) {
            $gap = strtotime($sessionTimes[$i]) - strtotime($sessionTimes[$i - 1]);
            if ($gap > 0 && $gap < 7200) $gaps[] = $gap;
        }
        $avgGap = count($gaps) > 0 ? array_sum($gaps) / count($gaps) : 999;
        $stddevGap = 0;
        if (count($gaps) > 1) {
            $mean = $avgGap;
            $variance = array_sum(array_map(fn($g) => pow($g - $mean, 2), $gaps)) / count($gaps);
            $stddevGap = sqrt($variance);
        }

        // Pico por hora
        $stmtH = $pdo->prepare("
            SELECT COUNT(*) AS cnt FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'flagged')
            GROUP BY DATE_FORMAT(started_at, '%Y-%m-%d %H:00')
            ORDER BY cnt DESC LIMIT 1
        ");
        $stmtH->execute([$uid, $days]);
        $maxPerHour = (int)($stmtH->fetchColumn() ?: 0);

        // Suspicious count
        $suspCount = 0;
        try {
            $stmtS = $pdo->prepare("SELECT COUNT(*) FROM suspicious_activity WHERE google_uid = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmtS->execute([$uid, $days]);
            $suspCount = (int)$stmtS->fetchColumn();
        } catch (Exception $e) {}

        // === SCORE ===
        $score = 0;
        $signals = [];

        // 1. Ganhos consistentes
        if ($sessions >= 10 && $avgEarnings > 0.05) {
            $cv = ($stddevEarnings > 0) ? ($stddevEarnings / $avgEarnings) : 0;
            if ($cv < 0.10) { $score += 20; $signals[] = 'Ganhos extremamente consistentes (CV=' . round($cv * 100, 1) . '%)'; }
            elseif ($cv < 0.20) { $score += 10; $signals[] = 'Ganhos pouco variáveis (CV=' . round($cv * 100, 1) . '%)'; }
        }

        // 2. Duração consistente
        if ($sessions >= 10 && $avgDuration > 0) {
            $cvD = ($stddevDuration > 0) ? ($stddevDuration / $avgDuration) : 0;
            if ($cvD < 0.03) { $score += 15; $signals[] = 'Duração quase idêntica entre sessões'; }
            elseif ($cvD < 0.08) { $score += 7; $signals[] = 'Duração muito similar entre sessões'; }
        }

        // 3. Intervalo mecânico
        if (count($gaps) >= 5) {
            $cvG = ($stddevGap > 0 && $avgGap > 0) ? ($stddevGap / $avgGap) : 0;
            if ($cvG < 0.08 && $avgGap < 300) { $score += 20; $signals[] = 'Intervalo mecânico (' . round($avgGap) . 's ±' . round($stddevGap) . 's)'; }
            elseif ($cvG < 0.15 && $avgGap < 300) { $score += 10; $signals[] = 'Intervalo regular (' . round($avgGap) . 's)'; }
        }

        // 4. Sessões por hora
        if ($maxPerHour >= 12) { $score += 15; $signals[] = 'Pico: ' . $maxPerHour . ' sessões/hora'; }
        elseif ($maxPerHour >= 8) { $score += 8; $signals[] = $maxPerHour . ' sessões/hora (pico)'; }

        // 5. Proporção de lendários/épicos
        if ($avgAsteroids > 20) {
            $lr = $avgLegendary / $avgAsteroids;
            $er = $avgEpic / $avgAsteroids;
            if ($lr > 0.03) { $score += 10; $signals[] = 'Lendários: ' . round($lr * 100, 1) . '% (média)'; }
            if ($er > 0.12) { $score += 5; $signals[] = 'Épicos: ' . round($er * 100, 1) . '% (média)'; }
        }

        // 6. Flagged sessions
        if ($flagged > 0) {
            $fr = $flagged / $sessions;
            if ($fr > 0.3) { $score += 15; $signals[] = round($fr * 100) . '% flagged (' . $flagged . ')'; }
            elseif ($fr > 0.1) { $score += 8; $signals[] = $flagged . ' sessões flagged'; }
        }

        // 7. Suspicious alerts
        if ($suspCount >= 10) { $score += 10; $signals[] = $suspCount . ' alertas suspeitos'; }
        elseif ($suspCount >= 3) { $score += 5; $signals[] = $suspCount . ' alertas'; }

        // 8. Premium + volume
        if ($p['is_premium'] && $sessions >= 20 && $avgEarnings > 0.50) {
            $score += 5; $signals[] = 'Premium + alto volume';
        }

        // 9. Conta nova + muitas sessões
        $accountAge = (time() - strtotime($p['account_created'])) / 86400;
        if ($accountAge < 3 && $sessions >= 20) {
            $score += 10; $signals[] = 'Conta nova (' . round($accountAge, 1) . 'd) com ' . $sessions . ' sessões';
        }

        // 10. Taxa de vitória anormalmente alta
        $completed = (int)$p['completed_sessions'];
        $winRate = ($sessions > 0) ? ($completed / $sessions) : 0;
        if ($sessions >= 10 && $winRate >= 0.95) {
            $score += 25; $signals[] = 'Taxa de vitória ' . round($winRate * 100, 1) . '% (' . $completed . '/' . $sessions . ')';
        } elseif ($sessions >= 10 && $winRate >= 0.85) {
            $score += 15; $signals[] = 'Vitória alta: ' . round($winRate * 100, 1) . '%';
        } elseif ($sessions >= 10 && $winRate >= 0.75) {
            $score += 8; $signals[] = 'Vitória: ' . round($winRate * 100, 1) . '%';
        }

        // 11. Asteroides destruídos muito consistentes
        if ($sessions >= 10 && $avgAsteroids > 50) {
            $stmtAstStd = $pdo->prepare("
                SELECT STDDEV(asteroids_destroyed) FROM game_sessions
                WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND status IN ('completed', 'flagged')
            ");
            $stmtAstStd->execute([$uid, $days]);
            $stddevAst = (float)$stmtAstStd->fetchColumn();
            $cvAst = ($stddevAst > 0) ? ($stddevAst / $avgAsteroids) : 0;
            if ($cvAst < 0.05) {
                $score += 20; $signals[] = 'Asteroides quase idênticos (CV=' . round($cvAst * 100, 1) . '%, ~' . round($avgAsteroids) . '/partida)';
            } elseif ($cvAst < 0.10) {
                $score += 10; $signals[] = 'Asteroides consistentes (CV=' . round($cvAst * 100, 1) . '%)';
            }
        }

        // 12. Joga exclusivamente Extreme
        $stmtModes = $pdo->prepare("
            SELECT game_mode, COUNT(*) as cnt FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'flagged')
            GROUP BY game_mode ORDER BY cnt DESC
        ");
        $stmtModes->execute([$uid, $days]);
        $modes = $stmtModes->fetchAll();
        if (count($modes) === 1 && $modes[0]['game_mode'] === 'extreme' && $sessions >= 10) {
            $score += 10; $signals[] = '100% Extreme (' . $sessions . ' sessões)';
        }

        // 13. Sessões na madrugada (2h-6h)
        $stmtNight = $pdo->prepare("
            SELECT COUNT(*) FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND HOUR(started_at) BETWEEN 2 AND 5
              AND status IN ('completed', 'flagged')
        ");
        $stmtNight->execute([$uid, $days]);
        $nightSessions = (int)$stmtNight->fetchColumn();
        $nightRatio = ($sessions > 0) ? ($nightSessions / $sessions) : 0;
        if ($nightSessions >= 10 && $nightRatio > 0.5) {
            $score += 10; $signals[] = round($nightRatio * 100) . '% madrugada (' . $nightSessions . ' sessões 2h-6h)';
        } elseif ($nightSessions >= 5 && $nightRatio > 0.3) {
            $score += 5; $signals[] = $nightSessions . ' sessões 2h-6h';
        }

        // 14. Sequência de vitórias consecutivas
        $stmtStreak = $pdo->prepare("
            SELECT status FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'abandoned')
            ORDER BY started_at DESC LIMIT 100
        ");
        $stmtStreak->execute([$uid, $days]);
        $statuses = $stmtStreak->fetchAll(PDO::FETCH_COLUMN);
        $maxStreak = 0; $curStreak = 0;
        foreach ($statuses as $st) {
            if ($st === 'completed') { $curStreak++; $maxStreak = max($maxStreak, $curStreak); }
            else { $curStreak = 0; }
        }
        if ($maxStreak >= 30) { $score += 25; $signals[] = $maxStreak . ' vitórias consecutivas'; }
        elseif ($maxStreak >= 20) { $score += 15; $signals[] = $maxStreak . ' vitórias seguidas'; }
        elseif ($maxStreak >= 12) { $score += 8; $signals[] = $maxStreak . ' vitórias seguidas'; }

        // 15. Zero abandonos
        $abandoned = (int)$p['abandoned_sessions'];
        if ($sessions >= 15 && $abandoned === 0) {
            $score += 20; $signals[] = 'Zero game over em ' . $sessions . ' sessões';
        } elseif ($sessions >= 15 && $abandoned <= 1) {
            $score += 10; $signals[] = $abandoned . ' game over em ' . $sessions . ' sessões';
        }

        // 16. Horas jogadas por dia
        $activeDays = max(1, (int)$p['active_days']);
        $totalPlaySec = $avgDuration * $completed;
        $avgHoursDay = ($totalPlaySec / 3600) / $activeDays;
        if ($avgHoursDay >= 10) { $score += 15; $signals[] = round($avgHoursDay, 1) . 'h/dia jogando'; }
        elseif ($avgHoursDay >= 6) { $score += 8; $signals[] = round($avgHoursDay, 1) . 'h/dia'; }

        // 17. Mesmo IP múltiplas contas
        if ((int)$p['distinct_ips'] <= 2) {
            $stmtMulti = $pdo->prepare("
                SELECT COUNT(DISTINCT gs2.google_uid) FROM game_sessions gs1
                INNER JOIN game_sessions gs2 ON gs1.ip_address = gs2.ip_address AND gs2.google_uid != gs1.google_uid
                WHERE gs1.google_uid = ? AND gs1.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND gs2.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1
            ");
            $stmtMulti->execute([$uid, $days, $days]);
            $otherAccounts = (int)$stmtMulti->fetchColumn();
            if ($otherAccounts >= 3) { $score += 15; $signals[] = $otherAccounts . ' contas no mesmo IP'; }
            elseif ($otherAccounts >= 1) { $score += 5; $signals[] = $otherAccounts . ' conta(s) mesmo IP'; }
        }

        // 18. Ganho por asteroide acima do esperado
        if ($avgAsteroids > 50 && $avgEarnings > 0) {
            $epa = $avgEarnings / $avgAsteroids;
            if ($epa > 0.015) { $score += 15; $signals[] = 'R$/asteroide: R$ ' . round($epa, 4) . ' (esperado ~0.005)'; }
            elseif ($epa > 0.010) { $score += 8; $signals[] = 'R$/asteroide: R$ ' . round($epa, 4); }
        }

        // 19. Padrão de horário repetitivo
        $stmtHrP = $pdo->prepare("
            SELECT HOUR(started_at) as hr, COUNT(*) as cnt FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'abandoned')
            GROUP BY hr ORDER BY cnt DESC
        ");
        $stmtHrP->execute([$uid, $days]);
        $hrDist = $stmtHrP->fetchAll();
        if (count($hrDist) > 0 && $sessions >= 15) {
            $uniqueHrs = count($hrDist);
            if ($uniqueHrs <= 3) {
                $score += 10; $signals[] = 'Sempre nos mesmos horários (' . $uniqueHrs . ' horas distintas)';
            }
        }

        $score = min($score, 100);
        $level = $score >= 60 ? 'alto' : ($score >= 30 ? 'medio' : 'baixo');

        if ($filterLevel !== 'all' && $level !== $filterLevel) continue;

        $results[] = [
            'user_id' => (int)$p['user_id'],
            'display_name' => $p['display_name'],
            'email' => $p['email'],
            'is_premium' => (bool)$p['is_premium'],
            'is_banned' => (bool)$p['is_banned'],
            'account_age' => round($accountAge, 1),
            'score' => $score,
            'level' => $level,
            'signals' => $signals,
            'sessions' => $sessions,
            'completed' => $completed,
            'abandoned' => $abandoned,
            'flagged' => $flagged,
            'win_rate' => round($winRate * 100, 1),
            'max_streak' => $maxStreak,
            'avg_earnings' => round($avgEarnings, 4),
            'total_earnings' => round((float)$p['total_earnings_period'], 2),
            'avg_asteroids' => round($avgAsteroids, 1),
            'avg_legendary' => round($avgLegendary, 2),
            'avg_duration' => round($avgDuration, 1),
            'max_per_hour' => $maxPerHour,
            'avg_gap' => round($avgGap, 1),
            'active_days' => (int)$p['active_days'],
            'distinct_ips' => (int)$p['distinct_ips'],
        ];
    }

    usort($results, fn($a, $b) => $b['score'] - $a['score']);

    $highCount = count(array_filter($results, fn($r) => $r['level'] === 'alto'));
    $medCount = count(array_filter($results, fn($r) => $r['level'] === 'medio'));
    $lowCount = count(array_filter($results, fn($r) => $r['level'] === 'baixo'));

} catch (Exception $e) {
    $error = $e->getMessage();
    $results = [];
    $highCount = $medCount = $lowCount = 0;
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-robot"></i> Análise de Suspeitas</h1>
        <p class="page-subtitle">Detecção passiva de macro/bot — Score de 0 a 100%</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="panel" style="margin-bottom: 20px;">
        <div class="panel-body" style="padding: 16px 20px;">
            <form method="GET" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                <input type="hidden" name="page" value="suspect-analysis">
                <div>
                    <label class="form-label" style="font-size: 0.8rem;">Período</label>
                    <select name="days" class="form-control" style="min-width: 120px;">
                        <option value="3" <?php echo $days === 3 ? 'selected' : ''; ?>>3 dias</option>
                        <option value="7" <?php echo $days === 7 ? 'selected' : ''; ?>>7 dias</option>
                        <option value="14" <?php echo $days === 14 ? 'selected' : ''; ?>>14 dias</option>
                        <option value="30" <?php echo $days === 30 ? 'selected' : ''; ?>>30 dias</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size: 0.8rem;">Mín. sessões</label>
                    <select name="min_sessions" class="form-control" style="min-width: 100px;">
                        <option value="3" <?php echo $minSessions === 3 ? 'selected' : ''; ?>>3+</option>
                        <option value="5" <?php echo $minSessions === 5 ? 'selected' : ''; ?>>5+</option>
                        <option value="10" <?php echo $minSessions === 10 ? 'selected' : ''; ?>>10+</option>
                        <option value="20" <?php echo $minSessions === 20 ? 'selected' : ''; ?>>20+</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size: 0.8rem;">Nível</label>
                    <select name="level" class="form-control" style="min-width: 110px;">
                        <option value="all" <?php echo $filterLevel === 'all' ? 'selected' : ''; ?>>Todos</option>
                        <option value="alto" <?php echo $filterLevel === 'alto' ? 'selected' : ''; ?>>Alto</option>
                        <option value="medio" <?php echo $filterLevel === 'medio' ? 'selected' : ''; ?>>Médio</option>
                        <option value="baixo" <?php echo $filterLevel === 'baixo' ? 'selected' : ''; ?>>Baixo</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Analisar</button>
            </form>
        </div>
    </div>

    <!-- Análise Individual -->
    <div class="panel" style="margin-bottom: 20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-user-secret"></i> Análise Individual de Conta</h3>
        </div>
        <div class="panel-body" style="padding: 16px 20px;">
            <form method="GET" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                <input type="hidden" name="page" value="suspect-analysis">
                <input type="hidden" name="days" value="<?php echo $days; ?>">
                <input type="hidden" name="min_sessions" value="<?php echo $minSessions; ?>">
                <input type="hidden" name="level" value="<?php echo htmlspecialchars($filterLevel); ?>">
                <div style="flex:1;min-width:250px;">
                    <label class="form-label" style="font-size: 0.8rem;">Email ou Google UID</label>
                    <input type="text" name="analyze_email" class="form-control" placeholder="email@exemplo.com"
                        value="<?php echo htmlspecialchars($analyzeEmail); ?>" style="font-size: 0.9rem;">
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-microscope"></i> Analisar Conta</button>
                <?php if (!empty($analyzeEmail)): ?>
                    <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=suspect-analysis&days=<?php echo $days; ?>&min_sessions=<?php echo $minSessions; ?>&level=<?php echo htmlspecialchars($filterLevel); ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($accountError): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px;"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($accountError); ?></div>
    <?php endif; ?>

    <?php if ($accountAnalysis): ?>
    <?php
        $au = $accountAnalysis['user'];
        $aScore = $accountAnalysis['risk_score'];
        $aLevel = $accountAnalysis['risk_level'];
        $aScoreColor = '#05ffa1'; $aScoreBg = 'rgba(5,255,161,0.1)'; $aLabel = 'BAIXO';
        if ($aLevel === 'alto') { $aScoreColor = '#ff3366'; $aScoreBg = 'rgba(255,51,102,0.1)'; $aLabel = 'ALTO'; }
        elseif ($aLevel === 'medio') { $aScoreColor = '#ffaa00'; $aScoreBg = 'rgba(255,170,0,0.1)'; $aLabel = 'MÉDIO'; }
        $st = $accountAnalysis['stats'];
    ?>
    <div class="panel" style="margin-bottom: 20px; border: 1px solid <?php echo $aScoreColor; ?>30;">
        <div class="panel-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 class="panel-title"><i class="fas fa-microscope"></i> Resultado: <?php echo htmlspecialchars($au['email']); ?></h3>
            <div style="display:flex;align-items:center;gap:12px;">
                <?php if ($au['is_banned']): ?>
                    <span class="badge badge-danger">BANIDO</span>
                <?php endif; ?>
                <?php if ($au['is_premium']): ?>
                    <span class="badge" style="background: rgba(255,215,0,0.2); color: #ffd700;">PREMIUM</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="panel-body">
            <!-- Score grande + Info -->
            <div style="display:grid;grid-template-columns:140px 1fr;gap:20px;margin-bottom:20px;">
                <div style="text-align:center;padding:20px;background:<?php echo $aScoreBg; ?>;border-radius:12px;">
                    <div style="font-family:'Orbitron',monospace;font-size:2.5rem;font-weight:900;color:<?php echo $aScoreColor; ?>;"><?php echo $aScore; ?>%</div>
                    <div style="font-size:0.85rem;font-weight:700;color:<?php echo $aScoreColor; ?>;margin-top:4px;">RISCO <?php echo $aLabel; ?></div>
                </div>
                <div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">
                        <div style="padding:10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                            <div style="font-size:0.7rem;color:var(--text-dim);">Nome</div>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($au['display_name'] ?? 'N/A'); ?></div>
                        </div>
                        <div style="padding:10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                            <div style="font-size:0.7rem;color:var(--text-dim);">Conta criada</div>
                            <div style="font-weight:600;"><?php echo date('d/m/Y', strtotime($au['created_at'])); ?> (<?php echo (int)$au['account_age_days']; ?> dias)</div>
                        </div>
                        <div style="padding:10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                            <div style="font-size:0.7rem;color:var(--text-dim);">Saldo</div>
                            <div style="font-weight:600;color:var(--success);">R$ <?php echo number_format((float)$au['balance_brl'], 2, ',', '.'); ?></div>
                        </div>
                        <div style="padding:10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                            <div style="font-size:0.7rem;color:var(--text-dim);">Total Ganho</div>
                            <div style="font-weight:600;">R$ <?php echo number_format((float)$au['total_earned_brl'], 2, ',', '.'); ?></div>
                        </div>
                        <div style="padding:10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                            <div style="font-size:0.7rem;color:var(--text-dim);">Total Sacado</div>
                            <div style="font-weight:600;">R$ <?php echo number_format((float)$au['total_withdrawn'], 2, ',', '.'); ?></div>
                        </div>
                        <div style="padding:10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                            <div style="font-size:0.7rem;color:var(--text-dim);">Créditos</div>
                            <div style="font-weight:600;"><?php echo (int)$au['credits']; ?></div>
                        </div>
                        <div style="padding:10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                            <div style="font-size:0.7rem;color:var(--text-dim);">Sessões (total)</div>
                            <div style="font-weight:600;"><?php echo (int)$au['total_sessions']; ?> (<?php echo (int)$au['completed_sessions']; ?>W / <?php echo (int)$au['abandoned_sessions']; ?>L / <?php echo (int)$au['flagged_sessions']; ?>F)</div>
                        </div>
                        <div style="padding:10px;background:rgba(0,0,0,0.2);border-radius:8px;">
                            <div style="font-size:0.7rem;color:var(--text-dim);">IPs distintos</div>
                            <div style="font-weight:600;"><?php echo (int)$au['distinct_ips']; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sinais de risco + positivos -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                <div>
                    <h4 style="font-size:0.9rem;margin:0 0 10px;color:<?php echo $aScoreColor; ?>;"><i class="fas fa-exclamation-triangle"></i> Sinais de Risco (<?php echo count($accountAnalysis['risk_signals']); ?>)</h4>
                    <?php if (empty($accountAnalysis['risk_signals'])): ?>
                        <div style="padding:12px;background:rgba(5,255,161,0.05);border-radius:8px;font-size:0.85rem;color:var(--success);">
                            <i class="fas fa-check-circle"></i> Nenhum sinal de risco detectado
                        </div>
                    <?php else: ?>
                        <?php foreach ($accountAnalysis['risk_signals'] as $sig): ?>
                            <div style="padding:6px 10px;margin-bottom:4px;background:rgba(255,51,102,0.06);border-radius:6px;font-size:0.8rem;border-left:3px solid <?php echo $aScoreColor; ?>;">
                                <i class="fas fa-exclamation-circle" style="color:<?php echo $aScoreColor; ?>;margin-right:4px;"></i>
                                <?php echo htmlspecialchars($sig); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div>
                    <h4 style="font-size:0.9rem;margin:0 0 10px;color:#05ffa1;"><i class="fas fa-check-circle"></i> Sinais Positivos (<?php echo count($accountAnalysis['positive_signals']); ?>)</h4>
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
            <?php if ((int)($st['sessions_30d'] ?? 0) >= 5): ?>
            <div style="margin-bottom:16px;">
                <h4 style="font-size:0.9rem;margin:0 0 10px;"><i class="fas fa-chart-bar"></i> Estatísticas (30 dias)</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;">
                    <div style="padding:8px;background:rgba(0,0,0,0.2);border-radius:8px;text-align:center;">
                        <div style="font-size:0.65rem;color:var(--text-dim);">Sessões 30d</div>
                        <div style="font-weight:700;"><?php echo (int)$st['sessions_30d']; ?></div>
                    </div>
                    <div style="padding:8px;background:rgba(0,0,0,0.2);border-radius:8px;text-align:center;">
                        <div style="font-size:0.65rem;color:var(--text-dim);">R$ Médio</div>
                        <div style="font-weight:700;color:var(--success);">R$ <?php echo number_format((float)$st['avg_earnings'], 4, ',', '.'); ?></div>
                    </div>
                    <div style="padding:8px;background:rgba(0,0,0,0.2);border-radius:8px;text-align:center;">
                        <div style="font-size:0.65rem;color:var(--text-dim);">R$ Total 30d</div>
                        <div style="font-weight:700;">R$ <?php echo number_format((float)$st['total_earnings_30d'], 2, ',', '.'); ?></div>
                    </div>
                    <div style="padding:8px;background:rgba(0,0,0,0.2);border-radius:8px;text-align:center;">
                        <div style="font-size:0.65rem;color:var(--text-dim);">Asteroides/partida</div>
                        <div style="font-weight:700;"><?php echo round((float)$st['avg_asteroids'], 1); ?></div>
                    </div>
                    <div style="padding:8px;background:rgba(0,0,0,0.2);border-radius:8px;text-align:center;">
                        <div style="font-size:0.65rem;color:var(--text-dim);">Duração média</div>
                        <div style="font-weight:700;"><?php echo round((float)$st['avg_duration'], 1); ?>s</div>
                    </div>
                    <div style="padding:8px;background:rgba(0,0,0,0.2);border-radius:8px;text-align:center;">
                        <div style="font-size:0.65rem;color:var(--text-dim);">Dias ativos</div>
                        <div style="font-weight:700;"><?php echo (int)$st['active_days']; ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Modos jogados -->
            <?php if (!empty($accountAnalysis['modes'])): ?>
            <div style="margin-bottom:16px;">
                <h4 style="font-size:0.9rem;margin:0 0 10px;"><i class="fas fa-gamepad"></i> Modos Jogados (30 dias)</h4>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php foreach ($accountAnalysis['modes'] as $m): ?>
                        <span style="padding:6px 12px;background:rgba(0,200,255,0.1);border:1px solid rgba(0,200,255,0.2);border-radius:8px;font-size:0.8rem;">
                            <?php echo htmlspecialchars(ucfirst($m['game_mode'] ?? 'N/A')); ?>: <strong><?php echo (int)$m['cnt']; ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Últimos saques -->
            <?php if (!empty($accountAnalysis['recent_withdrawals'])): ?>
            <div style="margin-bottom:16px;">
                <h4 style="font-size:0.9rem;margin:0 0 10px;"><i class="fas fa-money-bill-wave"></i> Últimos Saques</h4>
                <div class="table-container">
                    <table>
                        <thead><tr><th>#</th><th>Valor</th><th>PIX</th><th>Status</th><th>Data</th></tr></thead>
                        <tbody>
                            <?php foreach ($accountAnalysis['recent_withdrawals'] as $w): ?>
                            <tr>
                                <td>#<?php echo $w['id']; ?></td>
                                <td style="font-weight:600;">R$ <?php echo number_format((float)$w['amount_brl'], 2, ',', '.'); ?></td>
                                <td style="font-size:0.8rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($w['pix_key'] ?? ''); ?></td>
                                <td>
                                    <?php
                                    $wColors = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'processing' => 'info'];
                                    $wLabels = ['pending' => 'Pendente', 'approved' => 'Aprovado', 'rejected' => 'Rejeitado', 'processing' => 'Processando'];
                                    ?>
                                    <span class="badge badge-<?php echo $wColors[$w['status']] ?? 'secondary'; ?>">
                                        <?php echo $wLabels[$w['status']] ?? $w['status']; ?>
                                    </span>
                                </td>
                                <td style="font-size:0.8rem;"><?php echo date('d/m/y H:i', strtotime($w['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Últimas transações -->
            <?php if (!empty($accountAnalysis['recent_transactions'])): ?>
            <div>
                <h4 style="font-size:0.9rem;margin:0 0 10px;"><i class="fas fa-exchange-alt"></i> Últimas Transações</h4>
                <div class="table-container">
                    <table>
                        <thead><tr><th>Tipo</th><th>Valor</th><th>Descrição</th><th>Status</th><th>Data</th></tr></thead>
                        <tbody>
                            <?php foreach ($accountAnalysis['recent_transactions'] as $tx): ?>
                            <tr>
                                <td style="font-size:0.8rem;"><?php echo htmlspecialchars($tx['type']); ?></td>
                                <td style="font-weight:600;color:<?php echo (float)$tx['amount_brl'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    R$ <?php echo number_format((float)$tx['amount_brl'], 2, ',', '.'); ?>
                                </td>
                                <td style="font-size:0.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($tx['description'] ?? ''); ?></td>
                                <td><span class="badge badge-<?php echo $tx['status'] === 'completed' ? 'success' : 'warning'; ?>"><?php echo $tx['status']; ?></span></td>
                                <td style="font-size:0.8rem;"><?php echo date('d/m/y H:i', strtotime($tx['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Link para perfil -->
            <div style="margin-top:15px;padding-top:15px;border-top:1px solid var(--border-color, rgba(255,255,255,0.1));">
                <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=players&search=<?php echo urlencode($au['email'] ?? ''); ?>" class="btn btn-secondary btn-sm" style="text-decoration:none;">
                    <i class="fas fa-user"></i> Ver Perfil Completo
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon" style="background: rgba(255,51,102,0.15); color: #ff3366;"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="value"><?php echo $highCount; ?></div>
            <div class="label">Risco Alto</div>
            <div class="change" style="color: #ff3366;">Score 60-100%</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-exclamation-circle"></i></div>
            <div class="value"><?php echo $medCount; ?></div>
            <div class="label">Risco Médio</div>
            <div class="change" style="color: var(--warning);">Score 30-59%</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check-circle"></i></div>
            <div class="value"><?php echo $lowCount; ?></div>
            <div class="label">Risco Baixo</div>
            <div class="change" style="color: var(--success);">Score 0-29%</div>
        </div>
        <div class="stat-card">
            <div class="icon" style="background: rgba(0,150,255,0.15); color: #0096ff;"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo count($results); ?></div>
            <div class="label">Analisados</div>
            <div class="change">Últimos <?php echo $days; ?> dias</div>
        </div>
    </div>

    <!-- Tabela de Resultados -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list-ol"></i> Ranking de Suspeitas</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($results)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-double"></i>
                    <h3>Nenhuma conta suspeita encontrada</h3>
                    <p>Ajuste os filtros ou o período de análise.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 70px;">Score</th>
                                <th>Jogador</th>
                                <th>Sessões</th>
                                <th>Win Rate</th>
                                <th>Streak</th>
                                <th>R$ Médio</th>
                                <th>R$ Total</th>
                                <th>Pico/h</th>
                                <th>Sinais</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results as $r): ?>
                            <?php
                            $scoreColor = '#05ffa1';
                            $scoreBg = 'rgba(5,255,161,0.1)';
                            $levelLabel = 'BAIXO';
                            if ($r['level'] === 'alto') {
                                $scoreColor = '#ff3366'; $scoreBg = 'rgba(255,51,102,0.1)'; $levelLabel = 'ALTO';
                            } elseif ($r['level'] === 'medio') {
                                $scoreColor = '#ffaa00'; $scoreBg = 'rgba(255,170,0,0.1)'; $levelLabel = 'MÉDIO';
                            }
                            ?>
                            <tr style="border-left: 3px solid <?php echo $scoreColor; ?>;">
                                <td style="text-align: center;">
                                    <div style="background: <?php echo $scoreBg; ?>; color: <?php echo $scoreColor; ?>; border-radius: 8px; padding: 6px 4px; font-family: 'Orbitron', monospace; font-weight: 700; font-size: 1rem;">
                                        <?php echo $r['score']; ?>%
                                    </div>
                                    <div style="font-size: 0.6rem; color: <?php echo $scoreColor; ?>; font-weight: 700; margin-top: 2px;"><?php echo $levelLabel; ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($r['display_name'] ?? 'Sem nome'); ?></div>
                                    <small style="color: var(--text-dim);"><?php echo htmlspecialchars($r['email'] ?? ''); ?></small>
                                    <?php if ($r['is_premium']): ?>
                                        <span class="badge" style="background: rgba(255,215,0,0.2); color: #ffd700; font-size: 0.6rem;">PREMIUM</span>
                                    <?php endif; ?>
                                    <?php if ($r['is_banned']): ?>
                                        <span class="badge badge-danger" style="font-size: 0.6rem;">BANIDO</span>
                                    <?php endif; ?>
                                    <div style="font-size: 0.65rem; color: var(--text-dim); margin-top: 2px;">
                                        Conta: <?php echo $r['account_age']; ?> dias | IPs: <?php echo $r['distinct_ips']; ?>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <div style="font-weight: 600;"><?php echo $r['sessions']; ?></div>
                                    <?php if ($r['flagged'] > 0): ?>
                                        <small style="color: var(--danger);"><?php echo $r['flagged']; ?> flag</small>
                                    <?php endif; ?>
                                    <div style="font-size: 0.65rem; color: var(--text-dim);"><?php echo $r['active_days']; ?> dias</div>
                                </td>
                                <td style="text-align: center;">
                                    <?php
                                        $wrColor = $r['win_rate'] >= 95 ? '#ff3366' : ($r['win_rate'] >= 80 ? '#ffaa00' : '#05ffa1');
                                    ?>
                                    <div style="font-weight: 700; color: <?php echo $wrColor; ?>;"><?php echo $r['win_rate']; ?>%</div>
                                    <div style="font-size: 0.6rem; color: var(--text-dim);"><?php echo $r['completed']; ?>W / <?php echo $r['abandoned']; ?>L</div>
                                </td>
                                <td style="text-align: center;">
                                    <?php $streakColor = $r['max_streak'] >= 20 ? '#ff3366' : ($r['max_streak'] >= 10 ? '#ffaa00' : 'inherit'); ?>
                                    <div style="font-weight: 700; color: <?php echo $streakColor; ?>;"><?php echo $r['max_streak']; ?></div>
                                    <div style="font-size: 0.6rem; color: var(--text-dim);">seguidas</div>
                                </td>
                                <td style="color: var(--success); font-weight: 600;">
                                    R$ <?php echo number_format($r['avg_earnings'], 4, ',', '.'); ?>
                                </td>
                                <td style="font-weight: 600;">
                                    R$ <?php echo number_format($r['total_earnings'], 2, ',', '.'); ?>
                                </td>
                                <td style="text-align: center;">
                                    <span style="font-weight: 700; <?php echo $r['max_per_hour'] >= 10 ? 'color: var(--danger);' : ''; ?>">
                                        <?php echo $r['max_per_hour']; ?>
                                    </span>
                                </td>
                                <td style="max-width: 280px;">
                                    <?php foreach ($r['signals'] as $signal): ?>
                                        <div style="font-size: 0.7rem; padding: 2px 0; color: <?php echo $scoreColor; ?>;">
                                            <i class="fas fa-exclamation-circle" style="margin-right: 3px;"></i><?php echo htmlspecialchars($signal); ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($r['signals'])): ?>
                                        <span style="color: var(--text-dim); font-size: 0.75rem;">Nenhum sinal forte</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Legenda -->
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-info-circle"></i> Como funciona o Score</h3>
        </div>
        <div class="panel-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-chart-bar" style="color: var(--primary);"></i> Consistência de Ganhos</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Coeficiente de variação dos ganhos por sessão. Bots têm variação &lt;10%. Humanos variam 30-80%. Peso: até 20pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-clock" style="color: var(--primary);"></i> Duração Constante</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Se todas as partidas duram quase o mesmo tempo exato, indica automação. Humanos variam naturalmente. Peso: até 15pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-stopwatch" style="color: var(--primary);"></i> Intervalo Mecânico</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Tempo entre sessões muito regular (ex: sempre 200s) indica macro reiniciando automaticamente. Peso: até 20pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-tachometer-alt" style="color: var(--primary);"></i> Frequência/Hora</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Pico de sessões em uma única hora. 12+ sessões/hora é inviável para humanos. Peso: até 15pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-gem" style="color: var(--primary);"></i> Asteroides Raros</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Taxa média de lendários e épicos acima do esperado indica possível manipulação de colisão. Peso: até 15pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-flag" style="color: var(--primary);"></i> Outros Sinais</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Sessões flagged, alertas suspeitos, conta nova com volume alto, premium + farming. Peso: até 15pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-trophy" style="color: var(--primary);"></i> Taxa de Vitória</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">95%+ vitória em 10+ sessões é quase impossível legitimamente, especialmente no Extreme. Peso: até 25pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-crosshairs" style="color: var(--primary);"></i> Asteroides Consistentes</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Destruir quase a mesma quantidade toda partida (ex: 650-690) indica automação. Humanos variam 20%+. Peso: até 20pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-moon" style="color: var(--primary);"></i> Horário + Modo Exclusivo</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Jogar majoritariamente na madrugada (2h-6h) e exclusivamente no modo mais lucrativo indica bot. Peso: até 20pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-fire" style="color: var(--primary);"></i> Win Streak + Zero Game Over</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">20+ vitórias consecutivas ou zero game overs em 15+ sessões é quase impossível para humanos. Peso: até 45pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-hourglass-half" style="color: var(--primary);"></i> Horas/Dia + Multi-conta</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Jogar 6-10+ horas/dia ou múltiplas contas no mesmo IP. Peso: até 30pts.</div>
                </div>
                <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <div style="font-weight: 600; margin-bottom: 6px; font-size: 0.85rem;"><i class="fas fa-dollar-sign" style="color: var(--primary);"></i> R$/Asteroide + Horário Fixo</div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Ganho por asteroide acima do esperado indica stats manipulados. Jogar sempre nas mesmas horas indica agendamento. Peso: até 25pts.</div>
                </div>
            </div>
        </div>
    </div>
</div>
