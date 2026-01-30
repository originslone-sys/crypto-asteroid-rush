<?php
// ============================================
// UNOBIX - Dashboard
// Arquivo: admin/pages/dashboard.php
// ATUALIZADO: Google Auth, BRL, sem BNB
// ============================================

$pageTitle = 'Dashboard';

try {
    // Estatísticas de jogadores
    $playerStats = $pdo->query("
        SELECT 
            COUNT(*) as total_players,
            COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as new_today,
            COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as new_week,
            SUM(balance_brl) as total_balance,
            SUM(total_earned_brl) as total_earned,
            SUM(total_played) as total_games,
            COUNT(CASE WHEN is_banned = 1 THEN 1 END) as banned
        FROM players
    ")->fetch();

    // Estatísticas de saques
    $withdrawStats = $pdo->query("
        SELECT 
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            SUM(CASE WHEN status = 'pending' THEN amount_brl ELSE 0 END) as pending_amount,
            COUNT(CASE WHEN status = 'approved' AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as approved_month,
            SUM(CASE WHEN status = 'approved' AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount_brl ELSE 0 END) as approved_amount_month
        FROM withdrawals
    ")->fetch();

    // Estatísticas de staking
    $stakeStats = $pdo->query("
        SELECT 
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_stakes,
            SUM(CASE WHEN status = 'active' THEN amount_brl ELSE 0 END) as total_staked,
            SUM(total_earned_brl) as total_rewards
        FROM stakes
    ")->fetch();

    // Estatísticas de sessões (hoje)
    $sessionStats = $pdo->query("
        SELECT 
            COUNT(*) as total_today,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'flagged' THEN 1 END) as flagged,
            SUM(earnings_brl) as earnings_today,
            SUM(asteroids_destroyed) as asteroids_today
        FROM game_sessions 
        WHERE DATE(created_at) = CURDATE()
    ")->fetch();

    // Estatísticas de referrals
    $referralStats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            SUM(CASE WHEN status = 'claimed' THEN commission_brl ELSE 0 END) as total_paid
        FROM referrals
    ")->fetch();

    // Estatísticas de anúncios (últimos 7 dias)
    $adStats = $pdo->query("
        SELECT 
            COUNT(DISTINCT i.id) as impressions,
            COUNT(DISTINCT c.id) as clicks
        FROM ad_impressions i
        LEFT JOIN ad_clicks c ON DATE(i.created_at) = DATE(c.created_at)
        WHERE i.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetch();

    // Últimas transações
    $recentTransactions = $pdo->query("
        SELECT t.*, p.display_name 
        FROM transactions t 
        LEFT JOIN players p ON t.google_uid = p.google_uid 
        ORDER BY t.created_at DESC 
        LIMIT 10
    ")->fetchAll();

    // Últimos jogadores
    $recentPlayers = $pdo->query("
        SELECT * FROM players ORDER BY created_at DESC LIMIT 5
    ")->fetchAll();

} catch (Exception $e) {
    $error = $e->getMessage();
}

function formatBRL($value) {
    return 'R$ ' . number_format($value ?? 0, 2, ',', '.');
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-chart-line"></i> Dashboard</h1>
        <p class="page-subtitle">Visão geral do UNOBIX</p>
    </div>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Estatísticas Principais -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo number_format($playerStats['total_players'] ?? 0); ?></div>
            <div class="label">Jogadores</div>
            <div class="change positive">+<?php echo $playerStats['new_today'] ?? 0; ?> hoje</div>
        </div>
        
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-gamepad"></i></div>
            <div class="value"><?php echo number_format($sessionStats['total_today'] ?? 0); ?></div>
            <div class="label">Partidas Hoje</div>
            <div class="change"><?php echo $sessionStats['completed'] ?? 0; ?> completadas</div>
        </div>
        
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-wallet"></i></div>
            <div class="value"><?php echo formatBRL($playerStats['total_balance']); ?></div>
            <div class="label">Saldo Total Jogadores</div>
        </div>
        
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-clock"></i></div>
            <div class="value"><?php echo $withdrawStats['pending_count'] ?? 0; ?></div>
            <div class="label">Saques Pendentes</div>
            <div class="change"><?php echo formatBRL($withdrawStats['pending_amount']); ?></div>
        </div>
    </div>
    
    <!-- Segunda linha de stats -->
    <div class="stats-grid" style="margin-top: 20px;">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-coins"></i></div>
            <div class="value"><?php echo formatBRL($stakeStats['total_staked']); ?></div>
            <div class="label">Em Staking</div>
            <div class="change"><?php echo $stakeStats['active_stakes'] ?? 0; ?> ativos</div>
        </div>
        
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-user-friends"></i></div>
            <div class="value"><?php echo $referralStats['total'] ?? 0; ?></div>
            <div class="label">Indicações</div>
            <div class="change"><?php echo $referralStats['completed'] ?? 0; ?> completadas</div>
        </div>
        
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-ad"></i></div>
            <div class="value"><?php echo number_format($adStats['impressions'] ?? 0); ?></div>
            <div class="label">Impressões (7d)</div>
            <div class="change"><?php echo $adStats['clicks'] ?? 0; ?> cliques</div>
        </div>
        
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-flag"></i></div>
            <div class="value"><?php echo $sessionStats['flagged'] ?? 0; ?></div>
            <div class="label">Sessões Flagged</div>
            <div class="change">Hoje</div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-top: 30px;">
        <!-- Últimas Transações -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-exchange-alt"></i> Últimas Transações</h3>
                <a href="?page=transactions" class="btn btn-outline btn-sm">Ver Todas</a>
            </div>
            <div class="panel-body">
                <?php if (empty($recentTransactions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>Nenhuma transação ainda</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Usuário</th>
                                    <th>Tipo</th>
                                    <th>Valor</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentTransactions as $tx): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tx['display_name'] ?? 'Usuário'); ?></td>
                                <td>
                                    <?php
                                    $typeLabels = [
                                        'game_earning' => ['Ganho Jogo', 'success'],
                                        'withdrawal' => ['Saque', 'warning'],
                                        'stake' => ['Stake', 'primary'],
                                        'unstake' => ['Unstake', 'info'],
                                        'referral_bonus' => ['Bônus Indicação', 'success'],
                                        'stake_reward' => ['Rendimento', 'success']
                                    ];
                                    $label = $typeLabels[$tx['type']] ?? [$tx['type'], 'primary'];
                                    ?>
                                    <span class="badge badge-<?php echo $label[1]; ?>"><?php echo $label[0]; ?></span>
                                </td>
                                <td style="color: <?php echo $tx['amount_brl'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: 600;">
                                    <?php echo $tx['amount_brl'] >= 0 ? '+' : ''; ?><?php echo formatBRL($tx['amount_brl']); ?>
                                </td>
                                <td style="color: var(--text-dim);"><?php echo date('d/m H:i', strtotime($tx['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Novos Jogadores -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-user-plus"></i> Novos Jogadores</h3>
                <a href="?page=players" class="btn btn-outline btn-sm">Ver Todos</a>
            </div>
            <div class="panel-body">
                <?php if (empty($recentPlayers)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>Nenhum jogador ainda</p>
                    </div>
                <?php else: ?>
                    <div class="player-list">
                        <?php foreach ($recentPlayers as $player): ?>
                        <div class="player-item" style="display: flex; align-items: center; gap: 15px; padding: 12px; border-bottom: 1px solid var(--border);">
                            <?php if ($player['photo_url']): ?>
                                <img src="<?php echo htmlspecialchars($player['photo_url']); ?>" alt="" style="width: 40px; height: 40px; border-radius: 50%;">
                            <?php else: ?>
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                            <div style="flex: 1;">
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($player['display_name'] ?? 'Usuário'); ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-dim);"><?php echo date('d/m/Y H:i', strtotime($player['created_at'])); ?></div>
                            </div>
                            <div style="text-align: right;">
                                <div style="color: var(--success); font-weight: 600;"><?php echo formatBRL($player['balance_brl']); ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-dim);"><?php echo $player['total_played']; ?> jogos</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Resumo do Sistema -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-info-circle"></i> Resumo do Sistema</h3>
        </div>
        <div class="panel-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div class="info-box" style="padding: 15px; background: rgba(0,229,204,0.1); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.85rem;">Total Ganho Jogadores</div>
                    <div style="font-size: 1.3rem; font-weight: 700; color: var(--success);"><?php echo formatBRL($playerStats['total_earned']); ?></div>
                </div>
                <div class="info-box" style="padding: 15px; background: rgba(123,245,66,0.1); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.85rem;">Saques Pagos (30d)</div>
                    <div style="font-size: 1.3rem; font-weight: 700; color: var(--warning);"><?php echo formatBRL($withdrawStats['approved_amount_month']); ?></div>
                </div>
                <div class="info-box" style="padding: 15px; background: rgba(255,209,102,0.1); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.85rem;">Rendimentos Staking</div>
                    <div style="font-size: 1.3rem; font-weight: 700; color: var(--primary);"><?php echo formatBRL($stakeStats['total_rewards']); ?></div>
                </div>
                <div class="info-box" style="padding: 15px; background: rgba(255,71,87,0.1); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.85rem;">Comissões Indicação</div>
                    <div style="font-size: 1.3rem; font-weight: 700; color: var(--secondary);"><?php echo formatBRL($referralStats['total_paid']); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
