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
            'flagged' => $flagged,
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
                                <th>R$ Médio</th>
                                <th>R$ Total</th>
                                <th>Pico/h</th>
                                <th>Intervalo</th>
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
                                <td style="text-align: center; font-size: 0.85rem;">
                                    <?php echo $r['avg_gap'] < 999 ? round($r['avg_gap']) . 's' : '-'; ?>
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
            </div>
        </div>
    </div>
</div>
