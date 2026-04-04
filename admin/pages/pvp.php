<?php
// ============================================
// UNOBIX - Admin: Modo PvP
// Arquivo: admin/pages/pvp.php
// Configurações, métricas e status do servidor PvP
// ============================================

$pageTitle = 'Modo PvP';

$message = '';
$error = '';

// ============================================
// PROCESSAR AÇÕES POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'update_pvp_settings':
                $pvpSettings = [
                    'pvp_enabled'              => isset($_POST['pvp_enabled']) ? 'true' : 'false',
                    'pvp_entry_fee_credits'    => max(0, (int)($_POST['pvp_entry_fee_credits'] ?? 2)),
                    'pvp_winner_prize_brl'     => max(0, (float)($_POST['pvp_winner_prize_brl'] ?? 0)),
                    'pvp_game_duration'        => max(60, (int)($_POST['pvp_game_duration'] ?? 180)),
                    'pvp_lives'                => max(1, (int)($_POST['pvp_lives'] ?? 6)),
                    'pvp_max_bullets'          => max(1, (int)($_POST['pvp_max_bullets'] ?? 5)),
                    'pvp_fire_rate_ms'         => max(100, (int)($_POST['pvp_fire_rate_ms'] ?? 350)),
                    'pvp_matchmaking_timeout'  => max(10, (int)($_POST['pvp_matchmaking_timeout'] ?? 60)),
                ];

                foreach ($pvpSettings as $key => $value) {
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $value]);
                }

                $message = "Configurações PvP salvas!";
                break;

            case 'update_pvp_ranking_prizes':
                $prizes = [
                    'pvp_ranking_prize_1_brl' => max(0, (float)($_POST['pvp_ranking_prize_1_brl'] ?? 0)),
                    'pvp_ranking_prize_2_brl' => max(0, (float)($_POST['pvp_ranking_prize_2_brl'] ?? 0)),
                    'pvp_ranking_prize_3_brl' => max(0, (float)($_POST['pvp_ranking_prize_3_brl'] ?? 0)),
                    'pvp_ranking_prize_4_brl' => max(0, (float)($_POST['pvp_ranking_prize_4_brl'] ?? 0)),
                    'pvp_ranking_prize_5_brl' => max(0, (float)($_POST['pvp_ranking_prize_5_brl'] ?? 0)),
                ];

                foreach ($prizes as $key => $value) {
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $value]);
                }

                $message = "Prêmios do ranking PvP salvos!";
                break;
        }
    } catch (Exception $e) {
        $error = "Erro: " . $e->getMessage();
    }
}

// ============================================
// CARREGAR CONFIGURAÇÕES
// ============================================
$settings = [];
try {
    $result = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key LIKE 'pvp_%'");
    while ($row = $result->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$pvpEnabled         = ($settings['pvp_enabled'] ?? 'true') === 'true';
$entryFeeCredits    = (int)($settings['pvp_entry_fee_credits'] ?? 2);
$winnerPrizeBrl     = (float)($settings['pvp_winner_prize_brl'] ?? 0);
$gameDuration       = (int)($settings['pvp_game_duration'] ?? 180);
$pvpLives           = (int)($settings['pvp_lives'] ?? 6);
$maxBullets         = (int)($settings['pvp_max_bullets'] ?? 5);
$fireRateMs         = (int)($settings['pvp_fire_rate_ms'] ?? 350);
$matchmakingTimeout = (int)($settings['pvp_matchmaking_timeout'] ?? 60);

$rankingPrize1 = number_format((float)($settings['pvp_ranking_prize_1_brl'] ?? 50), 2, '.', '');
$rankingPrize2 = number_format((float)($settings['pvp_ranking_prize_2_brl'] ?? 30), 2, '.', '');
$rankingPrize3 = number_format((float)($settings['pvp_ranking_prize_3_brl'] ?? 20), 2, '.', '');
$rankingPrize4 = number_format((float)($settings['pvp_ranking_prize_4_brl'] ?? 10), 2, '.', '');
$rankingPrize5 = number_format((float)($settings['pvp_ranking_prize_5_brl'] ?? 5), 2, '.', '');

// ============================================
// MÉTRICAS PVP
// ============================================
$pvpStats = [
    'total_matches' => 0, 'completed_matches' => 0, 'cancelled_matches' => 0,
    'matches_today' => 0, 'unique_players_week' => 0, 'avg_duration' => 0,
    'eliminations' => 0, 'time_wins' => 0, 'disconnects' => 0, 'draws' => 0
];

try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'pvp_matches'")->fetch();
    if ($tableExists) {
        $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM pvp_matches GROUP BY status");
        while ($row = $stmt->fetch()) {
            if ($row['status'] === 'completed') $pvpStats['completed_matches'] = (int)$row['cnt'];
            if ($row['status'] === 'cancelled')  $pvpStats['cancelled_matches'] = (int)$row['cnt'];
            $pvpStats['total_matches'] += (int)$row['cnt'];
        }
        $pvpStats['matches_today']        = (int)$pdo->query("SELECT COUNT(*) FROM pvp_matches WHERE DATE(created_at) = CURDATE()")->fetchColumn();
        $pvpStats['unique_players_week']  = (int)$pdo->query("SELECT COUNT(DISTINCT uid) FROM (SELECT player1_google_uid as uid FROM pvp_matches WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) UNION SELECT player2_google_uid as uid FROM pvp_matches WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) t")->fetchColumn();
        $pvpStats['avg_duration']         = round((float)$pdo->query("SELECT AVG(game_duration) FROM pvp_matches WHERE status = 'completed' AND game_duration > 0")->fetchColumn());

        $stmt = $pdo->query("SELECT win_condition, COUNT(*) as cnt FROM pvp_matches WHERE status = 'completed' GROUP BY win_condition");
        while ($row = $stmt->fetch()) {
            switch ($row['win_condition']) {
                case 'elimination':  $pvpStats['eliminations'] = (int)$row['cnt']; break;
                case 'time_lives':
                case 'time_asteroids': $pvpStats['time_wins'] += (int)$row['cnt']; break;
                case 'disconnect':   $pvpStats['disconnects'] = (int)$row['cnt']; break;
                case 'draw':         $pvpStats['draws'] = (int)$row['cnt']; break;
            }
        }
    }
} catch (Exception $e) {}

// ============================================
// STATUS DO GAME SERVER
// ============================================
$serverStatus = null;
$serverOnline = false;
$gameServerUrl = getenv('PVP_GAME_SERVER_URL') ?: 'http://34.138.147.143:3000';

try {
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $healthResponse = @file_get_contents($gameServerUrl . '/health', false, $ctx);
    if ($healthResponse) {
        $serverStatus = json_decode($healthResponse, true);
        $serverOnline = !empty($serverStatus['status']) && $serverStatus['status'] === 'ok';
    }
} catch (Exception $e) {}

// ============================================
// PARTIDAS RECENTES
// ============================================
$recentMatches = [];
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'pvp_matches'")->fetch();
    if ($tableExists) {
        $stmt = $pdo->query("
            SELECT pm.*, u1.display_name as p1_name, u2.display_name as p2_name, uw.display_name as winner_name
            FROM pvp_matches pm
            LEFT JOIN users u1 ON u1.google_uid = pm.player1_google_uid
            LEFT JOIN users u2 ON u2.google_uid = pm.player2_google_uid
            LEFT JOIN users uw ON uw.google_uid = pm.winner_google_uid
            ORDER BY pm.created_at DESC LIMIT 20
        ");
        $recentMatches = $stmt->fetchAll();
    }
} catch (Exception $e) {}
?>

<div class="main-content">

    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-crosshairs" style="color: #ff6600;"></i> Modo PvP</h1>
        <p class="page-subtitle">Status do servidor, configurações e histórico de partidas</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Métricas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-gamepad"></i></div>
            <div class="value"><?php echo number_format($pvpStats['matches_today']); ?></div>
            <div class="label">Partidas Hoje</div>
            <div class="change"><?php echo number_format($pvpStats['total_matches']); ?> no total</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check-circle"></i></div>
            <div class="value"><?php echo number_format($pvpStats['completed_matches']); ?></div>
            <div class="label">Completadas</div>
            <div class="change"><?php echo $pvpStats['avg_duration']; ?>s duração média</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-skull-crossbones"></i></div>
            <div class="value"><?php echo $pvpStats['eliminations']; ?></div>
            <div class="label">Eliminações</div>
            <div class="change"><?php echo $pvpStats['time_wins']; ?> por tempo • <?php echo $pvpStats['disconnects']; ?> W.O.</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-user-friends"></i></div>
            <div class="value"><?php echo $pvpStats['unique_players_week']; ?></div>
            <div class="label">Jogadores (7d)</div>
            <div class="change"><?php echo $pvpStats['draws']; ?> empates</div>
        </div>
    </div>

    <!-- Status do Servidor -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title">
                <i class="fas fa-server" style="color: <?php echo $serverOnline ? 'var(--success)' : 'var(--danger)'; ?>;"></i>
                Servidor PvP
                <span style="font-size: 0.8rem; font-weight: 400; margin-left: 10px; color: <?php echo $serverOnline ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <i class="fas fa-circle" style="font-size: 0.55rem;"></i>
                    <?php echo $serverOnline ? 'ONLINE' : 'OFFLINE'; ?>
                </span>
            </h3>
        </div>
        <div class="panel-body">
            <?php if ($serverOnline && $serverStatus): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 16px;">
                    <div style="text-align: center; background: var(--bg-secondary, rgba(255,255,255,0.04)); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 1.6rem; font-weight: 700; color: var(--warning);"><?php echo (int)($serverStatus['queueSize'] ?? 0); ?></div>
                        <div style="font-size: 0.78rem; color: var(--text-dim); margin-top: 4px;">Na Fila</div>
                    </div>
                    <div style="text-align: center; background: var(--bg-secondary, rgba(255,255,255,0.04)); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 1.6rem; font-weight: 700; color: var(--primary);"><?php echo (int)($serverStatus['activeMatches'] ?? 0); ?></div>
                        <div style="font-size: 0.78rem; color: var(--text-dim); margin-top: 4px;">Partidas Ativas</div>
                    </div>
                    <div style="text-align: center; background: var(--bg-secondary, rgba(255,255,255,0.04)); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 1.6rem; font-weight: 700; color: var(--success);"><?php echo (int)($serverStatus['totalRooms'] ?? 0); ?></div>
                        <div style="font-size: 0.78rem; color: var(--text-dim); margin-top: 4px;">Salas</div>
                    </div>
                    <div style="text-align: center; background: var(--bg-secondary, rgba(255,255,255,0.04)); border-radius: 10px; padding: 14px;">
                        <div style="font-size: 1.3rem; font-weight: 700; color: #fff;"><?php echo gmdate('H:i:s', (int)($serverStatus['uptime'] ?? 0)); ?></div>
                        <div style="font-size: 0.78rem; color: var(--text-dim); margin-top: 4px;">Uptime</div>
                    </div>
                </div>
                <div style="font-size: 0.82rem; color: var(--text-dim); border-top: 1px solid var(--border-color); padding-top: 12px;">
                    <i class="fas fa-link"></i> <code style="font-size: 0.8rem;"><?php echo htmlspecialchars($gameServerUrl); ?></code>
                </div>
            <?php else: ?>
                <div class="alert alert-danger" style="margin: 0;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Servidor PvP não respondendo em <code><?php echo htmlspecialchars($gameServerUrl); ?></code>
                    <br><small style="opacity: 0.8;">Verifique na VM: <code>sudo pm2 status</code></small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Configurações PvP -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-cog"></i> Configurações PvP</h3>
        </div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_pvp_settings">

                <!-- Modo PvP ativo -->
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" name="pvp_enabled" <?php echo $pvpEnabled ? 'checked' : ''; ?> style="width: 16px; height: 16px;">
                        Modo PvP Ativo
                        <span style="font-size: 0.8rem; font-weight: 400; color: <?php echo $pvpEnabled ? 'var(--success)' : 'var(--danger)'; ?>;">
                            (<?php echo $pvpEnabled ? 'Ativado' : 'Desativado'; ?>)
                        </span>
                    </label>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px;">
                    <div class="form-group">
                        <label class="form-label">Custo de Entrada (créditos)</label>
                        <input type="number" name="pvp_entry_fee_credits" class="form-control" value="<?php echo $entryFeeCredits; ?>" min="0" max="50">
                        <small style="color: var(--text-dim);">0 = gratuito</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Prêmio do Vencedor (R$)</label>
                        <input type="number" name="pvp_winner_prize_brl" class="form-control" value="<?php echo number_format($winnerPrizeBrl, 2, '.', ''); ?>" min="0" step="0.01">
                        <small style="color: var(--text-dim);">0 = sem prêmio (apenas ranking)</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Duração da Partida (segundos)</label>
                        <input type="number" name="pvp_game_duration" class="form-control" value="<?php echo $gameDuration; ?>" min="60" max="600">
                        <small style="color: var(--text-dim);"><?php echo round($gameDuration / 60, 1); ?> minutos</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Timeout Matchmaking (segundos)</label>
                        <input type="number" name="pvp_matchmaking_timeout" class="form-control" value="<?php echo $matchmakingTimeout; ?>" min="10" max="300">
                        <small style="color: var(--text-dim);">Tempo máximo aguardando adversário</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Vidas por Jogador</label>
                        <input type="number" name="pvp_lives" class="form-control" value="<?php echo $pvpLives; ?>" min="1" max="20">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Máx. Balas Simultâneas</label>
                        <input type="number" name="pvp_max_bullets" class="form-control" value="<?php echo $maxBullets; ?>" min="1" max="20">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Fire Rate (ms entre tiros)</label>
                        <input type="number" name="pvp_fire_rate_ms" class="form-control" value="<?php echo $fireRateMs; ?>" min="100" max="2000">
                        <small style="color: var(--text-dim);"><?php echo round($fireRateMs / 1000, 2); ?>s entre tiros</small>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salvar Configurações
                </button>
            </form>
        </div>
    </div>

    <!-- Prêmios do Ranking Semanal -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-trophy" style="color: #ffd700;"></i> Prêmios Ranking Semanal (R$)</h3>
        </div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_pvp_ranking_prizes">

                <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 20px;">
                    <?php
                    $places = [
                        ['label' => '1º Lugar', 'icon' => 'fas fa-medal', 'color' => '#ffd700', 'name' => 'pvp_ranking_prize_1_brl', 'value' => $rankingPrize1],
                        ['label' => '2º Lugar', 'icon' => 'fas fa-medal', 'color' => '#c0c0c0', 'name' => 'pvp_ranking_prize_2_brl', 'value' => $rankingPrize2],
                        ['label' => '3º Lugar', 'icon' => 'fas fa-medal', 'color' => '#cd7f32', 'name' => 'pvp_ranking_prize_3_brl', 'value' => $rankingPrize3],
                        ['label' => '4º Lugar', 'icon' => 'fas fa-award', 'color' => 'var(--text-dim)', 'name' => 'pvp_ranking_prize_4_brl', 'value' => $rankingPrize4],
                        ['label' => '5º Lugar', 'icon' => 'fas fa-award', 'color' => 'var(--text-dim)', 'name' => 'pvp_ranking_prize_5_brl', 'value' => $rankingPrize5],
                    ];
                    foreach ($places as $p):
                    ?>
                    <div class="form-group" style="text-align: center;">
                        <label class="form-label" style="display: block; margin-bottom: 6px;">
                            <i class="<?php echo $p['icon']; ?>" style="color: <?php echo $p['color']; ?>;"></i>
                            <?php echo $p['label']; ?>
                        </label>
                        <input type="number" name="<?php echo $p['name']; ?>" class="form-control" value="<?php echo $p['value']; ?>" min="0" step="0.01" style="text-align: center;">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar Prêmios
                    </button>
                    <small style="color: var(--text-dim);">
                        <i class="fas fa-info-circle"></i>
                        Valores em R$ creditados no saldo do jogador. Distribuídos domingo 22h para os top 5 por vitórias.
                    </small>
                </div>
            </form>
        </div>
    </div>

    <!-- Partidas Recentes -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-history"></i> Partidas Recentes</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($recentMatches)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>Nenhuma partida PvP registrada</h3>
                    <p>As partidas aparecerão aqui assim que os jogadores começarem a jogar.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Jogador 1</th>
                                <th>Jogador 2</th>
                                <th>Vencedor</th>
                                <th>Condição</th>
                                <th>Duração</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $conds = [
                                'elimination'    => '<span style="color:#ff4444;"><i class="fas fa-skull-crossbones"></i> Eliminação</span>',
                                'time_lives'     => '<span style="color:var(--text-secondary);"><i class="fas fa-clock"></i> Tempo (vidas)</span>',
                                'time_asteroids' => '<span style="color:var(--text-secondary);"><i class="fas fa-clock"></i> Tempo (☄)</span>',
                                'disconnect'     => '<span style="color:var(--warning);"><i class="fas fa-wifi"></i> W.O.</span>',
                                'draw'           => '<span style="color:var(--warning);"><i class="fas fa-equals"></i> Empate</span>',
                                'cancelled'      => '<span style="color:var(--text-dim);"><i class="fas fa-ban"></i> Cancelado</span>',
                            ];
                            foreach ($recentMatches as $match):
                            ?>
                            <tr>
                                <td><span style="color: var(--text-dim); font-size: 0.82rem; white-space: nowrap;"><?php echo date('d/m H:i', strtotime($match['created_at'])); ?></span></td>
                                <td>
                                    <span style="font-weight: 500;"><?php echo htmlspecialchars($match['p1_name'] ?? 'Jogador'); ?></span>
                                    <br><small style="color: var(--text-dim);"><?php echo (int)$match['player1_lives']; ?> ♥ &nbsp; <?php echo (int)$match['player1_asteroids_destroyed']; ?> ☄</small>
                                </td>
                                <td>
                                    <span style="font-weight: 500;"><?php echo htmlspecialchars($match['p2_name'] ?? 'Jogador'); ?></span>
                                    <br><small style="color: var(--text-dim);"><?php echo (int)$match['player2_lives']; ?> ♥ &nbsp; <?php echo (int)$match['player2_asteroids_destroyed']; ?> ☄</small>
                                </td>
                                <td>
                                    <?php if ($match['winner_google_uid']): ?>
                                        <span style="color: var(--success);"><i class="fas fa-crown"></i> <?php echo htmlspecialchars($match['winner_name'] ?? '?'); ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--warning);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $conds[$match['win_condition'] ?? ''] ?? ('<span style="color:var(--text-dim);">' . htmlspecialchars($match['win_condition'] ?? '-') . '</span>'); ?></td>
                                <td><span style="color: var(--text-dim); font-size: 0.85rem;"><?php echo $match['game_duration'] ? $match['game_duration'] . 's' : '—'; ?></span></td>
                                <td>
                                    <?php if ($match['status'] === 'completed'): ?>
                                        <span class="badge badge-success">Concluída</span>
                                    <?php elseif ($match['status'] === 'cancelled'): ?>
                                        <span class="badge badge-warning">Cancelada</span>
                                    <?php elseif ($match['status'] === 'active'): ?>
                                        <span class="badge badge-info">Ativa</span>
                                    <?php else: ?>
                                        <span class="badge"><?php echo htmlspecialchars($match['status']); ?></span>
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

</div>
