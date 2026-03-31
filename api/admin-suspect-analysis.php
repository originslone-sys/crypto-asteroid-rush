<?php
// ============================================
// UNOBIX - Análise de Contas Suspeitas
// api/admin-suspect-analysis.php
// Algoritmo de scoring passivo (0-100%)
// NÃO altera mecânica do jogo
// ============================================

require_once __DIR__ . "/config.php";

// Proteger: só admin autenticado via cookie ou token interno
$isAdmin = false;
if (isset($_COOKIE['unobix_admin_token'])) {
    $isAdmin = true; // sessão admin ativa
} elseif (php_sapi_name() === 'cli') {
    $isAdmin = true;
}
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão");

    $days = min(max((int)($_GET['days'] ?? 7), 1), 30);
    $minSessions = max((int)($_GET['min_sessions'] ?? 5), 3);

    // ============================================
    // STEP 1: Buscar jogadores com sessões recentes
    // ============================================
    $stmt = $pdo->prepare("
        SELECT
            u.id AS user_id,
            u.google_uid,
            u.display_name,
            u.email,
            u.is_premium,
            u.is_banned,
            u.total_earned_brl,
            u.total_played,
            u.created_at AS account_created,
            COUNT(gs.id) AS sessions_count,
            SUM(CASE WHEN gs.status = 'completed' THEN 1 ELSE 0 END) AS completed_sessions,
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
          AND gs.status IN ('completed', 'flagged')
        GROUP BY u.id
        HAVING sessions_count >= ?
        ORDER BY sessions_count DESC
        LIMIT 200
    ");
    $stmt->execute([$days, $minSessions]);
    $players = $stmt->fetchAll();

    // ============================================
    // STEP 2: Para cada jogador, buscar métricas de timing
    // ============================================
    $results = [];

    foreach ($players as $p) {
        $uid = $p['google_uid'];

        // Buscar intervalos entre sessões
        $stmtGap = $pdo->prepare("
            SELECT started_at
            FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'flagged')
            ORDER BY started_at ASC
            LIMIT 100
        ");
        $stmtGap->execute([$uid, $days]);
        $sessionTimes = $stmtGap->fetchAll(PDO::FETCH_COLUMN);

        // Calcular intervalos entre sessões
        $gaps = [];
        for ($i = 1; $i < count($sessionTimes); $i++) {
            $gap = strtotime($sessionTimes[$i]) - strtotime($sessionTimes[$i - 1]);
            if ($gap > 0 && $gap < 7200) { // ignorar gaps > 2h (pausas naturais)
                $gaps[] = $gap;
            }
        }

        $avgGap = count($gaps) > 0 ? array_sum($gaps) / count($gaps) : 999;
        $stddevGap = 0;
        if (count($gaps) > 1) {
            $mean = $avgGap;
            $variance = array_sum(array_map(fn($g) => pow($g - $mean, 2), $gaps)) / count($gaps);
            $stddevGap = sqrt($variance);
        }

        // Máximo de sessões em uma única hora
        $stmtHourly = $pdo->prepare("
            SELECT DATE_FORMAT(started_at, '%Y-%m-%d %H:00') AS hr, COUNT(*) AS cnt
            FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'flagged')
            GROUP BY hr
            ORDER BY cnt DESC
            LIMIT 1
        ");
        $stmtHourly->execute([$uid, $days]);
        $peakHour = $stmtHourly->fetch();
        $maxSessionsPerHour = $peakHour ? (int)$peakHour['cnt'] : 0;

        // Suspicious activity count
        $stmtSusp = $pdo->prepare("
            SELECT COUNT(*) FROM suspicious_activity
            WHERE google_uid = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        try {
            $stmtSusp->execute([$uid, $days]);
            $suspCount = (int)$stmtSusp->fetchColumn();
        } catch (Exception $e) {
            $suspCount = 0;
        }

        // ============================================
        // STEP 3: CALCULAR SCORE DE SUSPEITA (0-100)
        // ============================================
        $score = 0;
        $signals = [];

        $sessions = (int)$p['sessions_count'];
        $avgEarnings = (float)$p['avg_earnings'];
        $stddevEarnings = (float)$p['stddev_earnings'];
        $avgLegendary = (float)$p['avg_legendary'];
        $avgEpic = (float)$p['avg_epic'];
        $avgAsteroids = (float)$p['avg_asteroids'];
        $avgDuration = (float)$p['avg_duration'];
        $stddevDuration = (float)$p['stddev_duration'];
        $flagged = (int)$p['flagged_sessions'];

        // --- SINAL 1: Ganhos muito consistentes (baixa variância) ---
        // Humanos têm variância alta. Bots repetem padrões.
        if ($sessions >= 10 && $avgEarnings > 0.05) {
            $cv = ($stddevEarnings > 0) ? ($stddevEarnings / $avgEarnings) : 0;
            if ($cv < 0.10) {
                $score += 20;
                $signals[] = 'Ganhos extremamente consistentes (CV=' . round($cv * 100, 1) . '%)';
            } elseif ($cv < 0.20) {
                $score += 10;
                $signals[] = 'Ganhos pouco variáveis (CV=' . round($cv * 100, 1) . '%)';
            }
        }

        // --- SINAL 2: Duração de jogo muito consistente ---
        if ($sessions >= 10 && $avgDuration > 0) {
            $cvDuration = ($stddevDuration > 0) ? ($stddevDuration / $avgDuration) : 0;
            if ($cvDuration < 0.03) {
                $score += 15;
                $signals[] = 'Duração quase idêntica entre sessões (CV=' . round($cvDuration * 100, 1) . '%)';
            } elseif ($cvDuration < 0.08) {
                $score += 7;
                $signals[] = 'Duração muito similar entre sessões';
            }
        }

        // --- SINAL 3: Intervalo entre sessões muito regular (macro) ---
        if (count($gaps) >= 5) {
            $cvGap = ($stddevGap > 0 && $avgGap > 0) ? ($stddevGap / $avgGap) : 0;
            if ($cvGap < 0.08 && $avgGap < 300) {
                $score += 20;
                $signals[] = 'Intervalo entre sessões mecânico (' . round($avgGap) . 's ±' . round($stddevGap) . 's)';
            } elseif ($cvGap < 0.15 && $avgGap < 300) {
                $score += 10;
                $signals[] = 'Intervalo entre sessões muito regular (' . round($avgGap) . 's)';
            }
        }

        // --- SINAL 4: Muitas sessões por hora (farming intensivo) ---
        if ($maxSessionsPerHour >= 12) {
            $score += 15;
            $signals[] = 'Pico: ' . $maxSessionsPerHour . ' sessões em 1 hora';
        } elseif ($maxSessionsPerHour >= 8) {
            $score += 8;
            $signals[] = 'Alta frequência: ' . $maxSessionsPerHour . ' sessões/hora';
        }

        // --- SINAL 5: Proporção alta de lendários/épicos ---
        if ($avgAsteroids > 20) {
            $legendaryRatio = $avgLegendary / $avgAsteroids;
            $epicRatio = $avgEpic / $avgAsteroids;
            if ($legendaryRatio > 0.03) {
                $score += 10;
                $signals[] = 'Taxa média de lendários alta (' . round($legendaryRatio * 100, 1) . '%)';
            }
            if ($epicRatio > 0.12) {
                $score += 5;
                $signals[] = 'Taxa média de épicos alta (' . round($epicRatio * 100, 1) . '%)';
            }
        }

        // --- SINAL 6: Sessões flagged ---
        if ($flagged > 0) {
            $flagRate = $flagged / $sessions;
            if ($flagRate > 0.3) {
                $score += 15;
                $signals[] = round($flagRate * 100) . '% das sessões flagged (' . $flagged . '/' . $sessions . ')';
            } elseif ($flagRate > 0.1) {
                $score += 8;
                $signals[] = $flagged . ' sessões flagged';
            }
        }

        // --- SINAL 7: Atividades suspeitas registradas ---
        if ($suspCount >= 10) {
            $score += 10;
            $signals[] = $suspCount . ' alertas de atividade suspeita';
        } elseif ($suspCount >= 3) {
            $score += 5;
            $signals[] = $suspCount . ' alertas registrados';
        }

        // --- SINAL 8: Premium + volume alto (vetor de risco) ---
        if ($p['is_premium'] && $sessions >= 20 && $avgEarnings > 0.50) {
            $score += 5;
            $signals[] = 'Premium com alto volume de jogo e ganhos médios elevados';
        }

        // --- SINAL 9: Conta muito nova com muitas sessões ---
        $accountAge = (time() - strtotime($p['account_created'])) / 86400;
        if ($accountAge < 3 && $sessions >= 20) {
            $score += 10;
            $signals[] = 'Conta nova (' . round($accountAge, 1) . ' dias) com ' . $sessions . ' sessões';
        }

        // --- SINAL 10: Taxa de vitória anormalmente alta ---
        $completed = (int)$p['completed_sessions'];
        $winRate = ($sessions > 0) ? ($completed / $sessions) : 0;
        if ($sessions >= 10 && $winRate >= 0.95) {
            $score += 25;
            $signals[] = 'Taxa de vitória ' . round($winRate * 100, 1) . '% (' . $completed . '/' . $sessions . ') — quase impossível';
        } elseif ($sessions >= 10 && $winRate >= 0.85) {
            $score += 15;
            $signals[] = 'Taxa de vitória alta: ' . round($winRate * 100, 1) . '% (' . $completed . '/' . $sessions . ')';
        } elseif ($sessions >= 10 && $winRate >= 0.75) {
            $score += 8;
            $signals[] = 'Taxa de vitória: ' . round($winRate * 100, 1) . '%';
        }

        // --- SINAL 11: Asteroides destruídos muito consistentes (baixa variância) ---
        if ($sessions >= 10 && $avgAsteroids > 50) {
            $stddevAsteroids = 0;
            $stmtAstStd = $pdo->prepare("
                SELECT STDDEV(asteroids_destroyed) FROM game_sessions
                WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND status IN ('completed', 'flagged')
            ");
            $stmtAstStd->execute([$uid, $days]);
            $stddevAsteroids = (float)$stmtAstStd->fetchColumn();

            $cvAst = ($stddevAsteroids > 0) ? ($stddevAsteroids / $avgAsteroids) : 0;
            if ($cvAst < 0.05) {
                $score += 20;
                $signals[] = 'Asteroides destruídos quase idênticos entre sessões (CV=' . round($cvAst * 100, 1) . '%, média=' . round($avgAsteroids) . ')';
            } elseif ($cvAst < 0.10) {
                $score += 10;
                $signals[] = 'Asteroides destruídos muito consistentes (CV=' . round($cvAst * 100, 1) . '%, média=' . round($avgAsteroids) . ')';
            }
        }

        // --- SINAL 12: Joga exclusivamente no modo mais lucrativo ---
        $stmtModes = $pdo->prepare("
            SELECT game_mode, COUNT(*) as cnt FROM game_sessions
            WHERE google_uid = ? AND started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('completed', 'flagged')
            GROUP BY game_mode ORDER BY cnt DESC
        ");
        $stmtModes->execute([$uid, $days]);
        $modes = $stmtModes->fetchAll();
        if (count($modes) === 1 && $modes[0]['game_mode'] === 'extreme' && $sessions >= 10) {
            $score += 10;
            $signals[] = '100% das sessões no Extreme (' . $sessions . ' sessões, nenhum outro modo)';
        }

        // --- SINAL 13: Sessões em horários incomuns (madrugada 2h-6h) ---
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
            $score += 10;
            $signals[] = round($nightRatio * 100) . '% das sessões entre 2h-6h (' . $nightSessions . ' sessões na madrugada)';
        } elseif ($nightSessions >= 5 && $nightRatio > 0.3) {
            $score += 5;
            $signals[] = $nightSessions . ' sessões na madrugada (2h-6h)';
        }

        // Cap at 100
        $score = min($score, 100);

        // Determinar nível
        if ($score >= 60) {
            $level = 'alto';
        } elseif ($score >= 30) {
            $level = 'medio';
        } else {
            $level = 'baixo';
        }

        $results[] = [
            'user_id' => (int)$p['user_id'],
            'google_uid' => $uid,
            'display_name' => $p['display_name'],
            'email' => $p['email'],
            'is_premium' => (bool)$p['is_premium'],
            'is_banned' => (bool)$p['is_banned'],
            'account_age_days' => round($accountAge, 1),
            'score' => $score,
            'level' => $level,
            'signals' => $signals,
            'stats' => [
                'sessions' => $sessions,
                'completed' => (int)$p['completed_sessions'],
                'flagged' => $flagged,
                'avg_earnings' => round($avgEarnings, 4),
                'max_earnings' => round((float)$p['max_earnings'], 4),
                'total_earnings_period' => round((float)$p['total_earnings_period'], 2),
                'avg_asteroids' => round($avgAsteroids, 1),
                'avg_legendary' => round($avgLegendary, 2),
                'avg_epic' => round($avgEpic, 2),
                'avg_duration' => round($avgDuration, 1),
                'max_sessions_per_hour' => $maxSessionsPerHour,
                'avg_gap_seconds' => round($avgGap, 1),
                'active_days' => (int)$p['active_days'],
                'distinct_ips' => (int)$p['distinct_ips'],
                'suspicious_alerts' => $suspCount
            ]
        ];
    }

    // Ordenar por score DESC
    usort($results, fn($a, $b) => $b['score'] - $a['score']);

    echo json_encode([
        'success' => true,
        'period_days' => $days,
        'min_sessions' => $minSessions,
        'total_analyzed' => count($results),
        'high_risk' => count(array_filter($results, fn($r) => $r['level'] === 'alto')),
        'medium_risk' => count(array_filter($results, fn($r) => $r['level'] === 'medio')),
        'low_risk' => count(array_filter($results, fn($r) => $r['level'] === 'baixo')),
        'players' => $results
    ]);

} catch (Throwable $e) {
    error_log("admin-suspect-analysis error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
