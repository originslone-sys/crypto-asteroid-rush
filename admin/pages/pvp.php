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
                    'pvp_enabled'             => isset($_POST['pvp_enabled']) ? 'true' : 'false',
                    'pvp_entry_fee_credits'   => max(0, (int)($_POST['pvp_entry_fee_credits'] ?? 2)),
                    'pvp_winner_prize_credits' => max(0, (int)($_POST['pvp_winner_prize_credits'] ?? 3)),
                    'pvp_game_duration'       => max(60, (int)($_POST['pvp_game_duration'] ?? 180)),
                    'pvp_lives'               => max(1, (int)($_POST['pvp_lives'] ?? 6)),
                    'pvp_max_bullets'         => max(1, (int)($_POST['pvp_max_bullets'] ?? 5)),
                    'pvp_fire_rate_ms'        => max(100, (int)($_POST['pvp_fire_rate_ms'] ?? 350)),
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
                    'pvp_ranking_prize_1' => max(0, (int)($_POST['pvp_ranking_prize_1'] ?? 150)),
                    'pvp_ranking_prize_2' => max(0, (int)($_POST['pvp_ranking_prize_2'] ?? 80)),
                    'pvp_ranking_prize_3' => max(0, (int)($_POST['pvp_ranking_prize_3'] ?? 50)),
                    'pvp_ranking_prize_4' => max(0, (int)($_POST['pvp_ranking_prize_4'] ?? 30)),
                    'pvp_ranking_prize_5' => max(0, (int)($_POST['pvp_ranking_prize_5'] ?? 20)),
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
$winnerPrizeCredits = (int)($settings['pvp_winner_prize_credits'] ?? 3);
$gameDuration       = (int)($settings['pvp_game_duration'] ?? 180);
$pvpLives           = (int)($settings['pvp_lives'] ?? 6);
$maxBullets         = (int)($settings['pvp_max_bullets'] ?? 5);
$fireRateMs         = (int)($settings['pvp_fire_rate_ms'] ?? 350);
$matchmakingTimeout = (int)($settings['pvp_matchmaking_timeout'] ?? 60);

$rankingPrize1 = (int)($settings['pvp_ranking_prize_1'] ?? 150);
$rankingPrize2 = (int)($settings['pvp_ranking_prize_2'] ?? 80);
$rankingPrize3 = (int)($settings['pvp_ranking_prize_3'] ?? 50);
$rankingPrize4 = (int)($settings['pvp_ranking_prize_4'] ?? 30);
$rankingPrize5 = (int)($settings['pvp_ranking_prize_5'] ?? 20);

// ============================================
// MÉTRICAS PVP DO BANCO
// ============================================
$pvpStats = [
    'total_matches' => 0,
    'completed_matches' => 0,
    'cancelled_matches' => 0,
    'matches_today' => 0,
    'matches_week' => 0,
    'unique_players_today' => 0,
    'unique_players_week' => 0,
    'avg_duration' => 0,
    'total_credits_spent' => 0,
    'total_credits_awarded' => 0,
    'eliminations' => 0,
    'time_wins' => 0,
    'disconnects' => 0,
    'draws' => 0,
];

try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'pvp_matches'")->fetch();
    if ($tableExists) {
        // Total e por status
        $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM pvp_matches GROUP BY status");
        while ($row = $stmt->fetch()) {
            if ($row['status'] === 'completed') $pvpStats['completed_matches'] = (int)$row['cnt'];
            if ($row['status'] === 'cancelled') $pvpStats['cancelled_matches'] = (int)$row['cnt'];
            $pvpStats['total_matches'] += (int)$row['cnt'];
        }

        // Partidas hoje
        $stmt = $pdo->query("SELECT COUNT(*) FROM pvp_matches WHERE DATE(created_at) = CURDATE()");
        $pvpStats['matches_today'] = (int)$stmt->fetchColumn();

        // Partidas esta semana
        $stmt = $pdo->query("SELECT COUNT(*) FROM pvp_matches WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        $pvpStats['matches_week'] = (int)$stmt->fetchColumn();

        // Jogadores únicos hoje
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT uid) FROM (
                SELECT player1_google_uid as uid FROM pvp_matches WHERE DATE(created_at) = CURDATE()
                UNION
                SELECT player2_google_uid as uid FROM pvp_matches WHERE DATE(created_at) = CURDATE()
            ) t
        ");
        $pvpStats['unique_players_today'] = (int)$stmt->fetchColumn();

        // Jogadores únicos semana
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT uid) FROM (
                SELECT player1_google_uid as uid FROM pvp_matches WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                UNION
                SELECT player2_google_uid as uid FROM pvp_matches WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ) t
        ");
        $pvpStats['unique_players_week'] = (int)$stmt->fetchColumn();

        // Duração média
        $stmt = $pdo->query("SELECT AVG(game_duration) FROM pvp_matches WHERE status = 'completed' AND game_duration > 0");
        $pvpStats['avg_duration'] = round((float)$stmt->fetchColumn());

        // Créditos gastos e premiados
        $stmt = $pdo->query("SELECT SUM(entry_fee_credits * 2) as spent, SUM(winner_prize_credits) as awarded FROM pvp_matches WHERE status = 'completed'");
        $row = $stmt->fetch();
        $pvpStats['total_credits_spent'] = (int)($row['spent'] ?? 0);
        $pvpStats['total_credits_awarded'] = (int)($row['awarded'] ?? 0);

        // Win conditions
        $stmt = $pdo->query("SELECT win_condition, COUNT(*) as cnt FROM pvp_matches WHERE status = 'completed' GROUP BY win_condition");
        while ($row = $stmt->fetch()) {
            switch ($row['win_condition']) {
                case 'elimination': $pvpStats['eliminations'] = (int)$row['cnt']; break;
                case 'time_lives': case 'time_asteroids': $pvpStats['time_wins'] += (int)$row['cnt']; break;
                case 'disconnect': $pvpStats['disconnects'] = (int)$row['cnt']; break;
                case 'draw': $pvpStats['draws'] = (int)$row['cnt']; break;
            }
        }
    }
} catch (Exception $e) {}

// ============================================
// STATUS DO GAME SERVER (health check)
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

// Partidas recentes
$recentMatches = [];
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'pvp_matches'")->fetch();
    if ($tableExists) {
        $stmt = $pdo->query("
            SELECT pm.*,
                u1.display_name as p1_name,
                u2.display_name as p2_name,
                uw.display_name as winner_name
            FROM pvp_matches pm
            LEFT JOIN users u1 ON u1.google_uid = pm.player1_google_uid
            LEFT JOIN users u2 ON u2.google_uid = pm.player2_google_uid
            LEFT JOIN users uw ON uw.google_uid = pm.winner_google_uid
            ORDER BY pm.created_at DESC
            LIMIT 20
        ");
        $recentMatches = $stmt->fetchAll();
    }
} catch (Exception $e) {}
?>

<!-- Alertas -->
<?php if ($message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="page-header">
    <h1 class="page-title"><i class="fas fa-crosshairs" style="color: #ff6600;"></i> Modo PvP</h1>
</div>

<!-- Status do Servidor -->
<div class="panel" style="margin-bottom: 20px; border-color: <?php echo $serverOnline ? 'var(--success)' : 'var(--danger)'; ?>;">
    <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3 class="panel-title" style="margin: 0;">
            <i class="fas fa-server" style="color: <?php echo $serverOnline ? 'var(--success)' : 'var(--danger)'; ?>;"></i>
            Status do Servidor PvP
        </h3>
        <span style="color: <?php echo $serverOnline ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: 700; font-size: 0.9rem;">
            <?php echo $serverOnline ? '● ONLINE' : '● OFFLINE'; ?>
        </span>
    </div>
    <div class="panel-body">
        <?php if ($serverOnline && $serverStatus): ?>
            <style>.pvp-server-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; } @media (max-width: 900px) { .pvp-server-grid { grid-template-columns: repeat(2, 1fr); } }</style>
            <div class="pvp-server-grid">
                <div style="text-align: center; padding: 12px; background: rgba(0,240,255,0.05); border-radius: 10px; border: 1px solid rgba(0,240,255,0.1);">
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);"><?php echo (int)($serverStatus['queueSize'] ?? 0); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 4px;">Na Fila</div>
                </div>
                <div style="text-align: center; padding: 12px; background: rgba(5,255,161,0.05); border-radius: 10px; border: 1px solid rgba(5,255,161,0.1);">
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--success);"><?php echo (int)($serverStatus['activeMatches'] ?? 0); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 4px;">Partidas Ativas</div>
                </div>
                <div style="text-align: center; padding: 12px; background: rgba(255,209,102,0.05); border-radius: 10px; border: 1px solid rgba(255,209,102,0.1);">
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--warning);"><?php echo (int)($serverStatus['totalRooms'] ?? 0); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 4px;">Salas Total</div>
                </div>
                <div style="text-align: center; padding: 12px; background: rgba(0,240,255,0.05); border-radius: 10px; border: 1px solid rgba(0,240,255,0.1);">
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);"><?php echo gmdate('H:i:s', (int)($serverStatus['uptime'] ?? 0)); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 4px;">Uptime</div>
                </div>
            </div>
            <div style="margin-top: 10px;"><small style="color: var(--text-dim);">Servidor: <?php echo htmlspecialchars($gameServerUrl); ?></small></div>
        <?php else: ?>
            <p style="color: var(--danger); margin: 0;">
                <i class="fas fa-exclamation-triangle"></i> Servidor PvP não respondendo em <code><?php echo htmlspecialchars($gameServerUrl); ?></code>
            </p>
            <p style="color: var(--text-dim); font-size: 0.85rem; margin: 8px 0 0;">Verifique: <code>sudo pm2 status</code> na VM</p>
        <?php endif; ?>
    </div>
</div>

<!-- Métricas PvP -->
<style>.pvp-metrics-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; } @media (max-width: 1100px) { .pvp-metrics-grid { grid-template-columns: repeat(2, 1fr); } } @media (max-width: 768px) { .pvp-metrics-grid { grid-template-columns: 1fr; } }</style>
<div class="pvp-metrics-grid" style="margin-bottom: 20px;">
    <div style="padding: 18px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(0,240,255,0.1); color: var(--primary); font-size: 1.1rem;"><i class="fas fa-gamepad"></i></div>
            <div>
                <div style="font-size: 1.3rem; font-weight: 700; color: var(--text-light);"><?php echo number_format($pvpStats['total_matches']); ?></div>
                <div style="font-size: 0.75rem; color: var(--text-dim);">Total Partidas <span style="color: var(--primary);">(<?php echo $pvpStats['matches_today']; ?> hoje)</span></div>
            </div>
        </div>
    </div>
    <div style="padding: 18px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(5,255,161,0.1); color: var(--success); font-size: 1.1rem;"><i class="fas fa-check-circle"></i></div>
            <div>
                <div style="font-size: 1.3rem; font-weight: 700; color: var(--text-light);"><?php echo number_format($pvpStats['completed_matches']); ?></div>
                <div style="font-size: 0.75rem; color: var(--text-dim);">Completadas <span style="color: var(--success);">(<?php echo $pvpStats['matches_week']; ?> semana)</span></div>
            </div>
        </div>
    </div>
    <div style="padding: 18px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255,209,102,0.1); color: var(--warning); font-size: 1.1rem;"><i class="fas fa-user-friends"></i></div>
            <div>
                <div style="font-size: 1.3rem; font-weight: 700; color: var(--text-light);"><?php echo $pvpStats['unique_players_today']; ?> / <?php echo $pvpStats['unique_players_week']; ?></div>
                <div style="font-size: 0.75rem; color: var(--text-dim);">Jogadores PvP <span style="color: var(--text-dim);">(hoje / semana)</span></div>
            </div>
        </div>
    </div>
    <div style="padding: 18px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(0,240,255,0.1); color: var(--primary); font-size: 1.1rem;"><i class="fas fa-stopwatch"></i></div>
            <div>
                <div style="font-size: 1.3rem; font-weight: 700; color: var(--text-light);"><?php echo $pvpStats['avg_duration']; ?>s</div>
                <div style="font-size: 0.75rem; color: var(--text-dim);">Duração Média</div>
            </div>
        </div>
    </div>
    <div style="padding: 18px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255,42,109,0.1); color: var(--danger); font-size: 1.1rem;"><i class="fas fa-coins"></i></div>
            <div>
                <div style="font-size: 1.3rem; font-weight: 700; color: var(--text-light);"><?php echo number_format($pvpStats['total_credits_spent']); ?></div>
                <div style="font-size: 0.75rem; color: var(--text-dim);">Créditos Gastos <span style="color: var(--success);">(<?php echo number_format($pvpStats['total_credits_awarded']); ?> premiados)</span></div>
            </div>
        </div>
    </div>
    <div style="padding: 18px; background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255,42,109,0.1); color: var(--danger); font-size: 1.1rem;"><i class="fas fa-skull-crossbones"></i></div>
            <div>
                <div style="font-size: 1.3rem; font-weight: 700; color: var(--text-light);"><?php echo $pvpStats['eliminations']; ?></div>
                <div style="font-size: 0.75rem; color: var(--text-dim);">Eliminações | <?php echo $pvpStats['time_wins']; ?> tempo | <?php echo $pvpStats['disconnects']; ?> W.O. | <?php echo $pvpStats['draws']; ?> empates</div>
            </div>
        </div>
    </div>
</div>

<!-- Configurações + Ranking -->
<style>.pvp-config-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; } @media (max-width: 1100px) { .pvp-config-grid { grid-template-columns: 1fr; } }</style>
<div class="pvp-config-grid" style="margin-bottom: 20px;">

    <!-- Configurações PvP -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-cog"></i> Configurações PvP</h3>
        </div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_pvp_settings">

                <div class="form-group">
                    <label class="form-label" style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="pvp_enabled" <?php echo $pvpEnabled ? 'checked' : ''; ?>>
                        Modo PvP Ativo
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label">Custo de Entrada (créditos)</label>
                    <input type="number" name="pvp_entry_fee_credits" class="form-control" value="<?php echo $entryFeeCredits; ?>" min="0" max="50">
                    <small style="color: var(--text-dim);">0 = modo gratuito</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Prêmio do Vencedor (créditos)</label>
                    <input type="number" name="pvp_winner_prize_credits" class="form-control" value="<?php echo $winnerPrizeCredits; ?>" min="0" max="100">
                    <small style="color: var(--text-dim);">0 = sem prêmio (apenas ranking)</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Duração da Partida (segundos)</label>
                    <input type="number" name="pvp_game_duration" class="form-control" value="<?php echo $gameDuration; ?>" min="60" max="600">
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
                </div>

                <div class="form-group">
                    <label class="form-label">Timeout Matchmaking (segundos)</label>
                    <input type="number" name="pvp_matchmaking_timeout" class="form-control" value="<?php echo $matchmakingTimeout; ?>" min="10" max="300">
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Salvar Configurações
                </button>
            </form>
        </div>
    </div>

    <!-- Prêmios do Ranking Semanal -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-trophy" style="color: #ffd700;"></i> Prêmios Ranking Semanal</h3>
        </div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_pvp_ranking_prizes">

                <div class="form-group">
                    <label class="form-label">🥇 1º Lugar (créditos)</label>
                    <input type="number" name="pvp_ranking_prize_1" class="form-control" value="<?php echo $rankingPrize1; ?>" min="0">
                </div>

                <div class="form-group">
                    <label class="form-label">🥈 2º Lugar (créditos)</label>
                    <input type="number" name="pvp_ranking_prize_2" class="form-control" value="<?php echo $rankingPrize2; ?>" min="0">
                </div>

                <div class="form-group">
                    <label class="form-label">🥉 3º Lugar (créditos)</label>
                    <input type="number" name="pvp_ranking_prize_3" class="form-control" value="<?php echo $rankingPrize3; ?>" min="0">
                </div>

                <div class="form-group">
                    <label class="form-label">4º Lugar (créditos)</label>
                    <input type="number" name="pvp_ranking_prize_4" class="form-control" value="<?php echo $rankingPrize4; ?>" min="0">
                </div>

                <div class="form-group">
                    <label class="form-label">5º Lugar (créditos)</label>
                    <input type="number" name="pvp_ranking_prize_5" class="form-control" value="<?php echo $rankingPrize5; ?>" min="0">
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Salvar Prêmios
                </button>
            </form>

            <div style="margin-top: 20px; padding: 15px; background: rgba(255,209,102,0.05); border: 1px solid rgba(255,209,102,0.2); border-radius: 8px;">
                <small style="color: var(--warning);">
                    <i class="fas fa-info-circle"></i> Prêmios são distribuídos automaticamente ao final de cada semana (domingo 22h) para os top 5 jogadores PvP por número de vitórias.
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Partidas Recentes -->
<div class="panel">
    <div class="panel-header">
        <h3 class="panel-title"><i class="fas fa-history"></i> Partidas Recentes</h3>
    </div>
    <div class="panel-body">
        <?php if (empty($recentMatches)): ?>
            <p style="color: var(--text-dim); text-align: center; padding: 30px;">
                <i class="fas fa-inbox" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                Nenhuma partida PvP registrada ainda.
            </p>
        <?php else: ?>
            <div class="table-container" style="overflow-x: auto;">
                <table style="min-width: 700px;">
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
                        <?php foreach ($recentMatches as $match): ?>
                            <tr>
                                <td><?php echo date('d/m H:i', strtotime($match['created_at'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($match['p1_name'] ?? 'Jogador'); ?>
                                    <small style="color: var(--text-dim);">(<?php echo $match['player1_lives']; ?>♥ / <?php echo $match['player1_asteroids_destroyed']; ?>☄)</small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($match['p2_name'] ?? 'Jogador'); ?>
                                    <small style="color: var(--text-dim);">(<?php echo $match['player2_lives']; ?>♥ / <?php echo $match['player2_asteroids_destroyed']; ?>☄)</small>
                                </td>
                                <td>
                                    <?php if ($match['winner_google_uid']): ?>
                                        <span style="color: var(--success);"><?php echo htmlspecialchars($match['winner_name'] ?? '?'); ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--warning);">Empate</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $conditions = [
                                        'elimination' => '<span style="color:#ff4444;">Eliminação</span>',
                                        'time_lives' => 'Tempo (vidas)',
                                        'time_asteroids' => 'Tempo (asteroides)',
                                        'disconnect' => '<span style="color:var(--warning);">W.O.</span>',
                                        'draw' => '<span style="color:var(--warning);">Empate</span>',
                                        'cancelled' => '<span style="color:var(--text-dim);">Cancelado</span>',
                                    ];
                                    echo $conditions[$match['win_condition'] ?? ''] ?? ($match['win_condition'] ?? '-');
                                    ?>
                                </td>
                                <td><?php echo $match['game_duration'] ? $match['game_duration'] . 's' : '-'; ?></td>
                                <td>
                                    <?php if ($match['status'] === 'completed'): ?>
                                        <span class="badge" style="background: var(--success); color: #000;">Completa</span>
                                    <?php elseif ($match['status'] === 'cancelled'): ?>
                                        <span class="badge" style="background: var(--warning); color: #000;">Cancelada</span>
                                    <?php elseif ($match['status'] === 'active'): ?>
                                        <span class="badge" style="background: var(--primary); color: #000;">Ativa</span>
                                    <?php else: ?>
                                        <span class="badge"><?php echo $match['status']; ?></span>
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

<script>
// Auto-refresh status do servidor a cada 30s
setInterval(function() {
    fetch('<?php echo htmlspecialchars($gameServerUrl); ?>/health')
        .then(r => r.json())
        .then(data => {
            document.querySelectorAll('.stat-card .value')[0].textContent = data.queueSize || 0;
            document.querySelectorAll('.stat-card .value')[1].textContent = data.activeMatches || 0;
            document.querySelectorAll('.stat-card .value')[2].textContent = data.totalRooms || 0;
        })
        .catch(() => {});
}, 30000);
</script>
