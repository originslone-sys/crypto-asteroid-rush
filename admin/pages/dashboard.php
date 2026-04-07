<?php
// ============================================
// UNOBIX - Dashboard
// Arquivo: admin/pages/dashboard.php
// v7.0 - Dashboard financeiro completo
// ============================================

$pageTitle = 'Dashboard';

try {
    // Estatísticas de jogadores — tabela: users (doc 3.1)
    $playerStats = $pdo->query("
        SELECT
            COUNT(*) as total_players,
            COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as new_today,
            COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as new_week,
            SUM(balance_brl) as total_balance,
            SUM(total_earned_brl) as total_earned,
            SUM(total_played) as total_games,
            SUM(credits) as total_credits,
            COUNT(CASE WHEN is_banned = 1 THEN 1 END) as banned,
            COUNT(CASE WHEN last_login > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as active_today
        FROM users
    ")->fetch();

    // Offsets para zerar contagem a partir de uma data
    $balanceOffset = 0;
    $creditsOffset = 0;
    $offRow = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key IN ('balance_offset','credits_offset')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $balanceOffset = floatval($offRow['balance_offset'] ?? 0);
    $creditsOffset = intval($offRow['credits_offset'] ?? 0);
    $displayBalance = max(0, ($playerStats['total_balance'] ?? 0) - $balanceOffset);
    $displayCredits = max(0, ($playerStats['total_credits'] ?? 0) - $creditsOffset);

    // Estatísticas de saques — tabela: withdrawals (doc 3.4)
    $withdrawStats = $pdo->query("
        SELECT
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            SUM(CASE WHEN status = 'pending' THEN amount_brl ELSE 0 END) as pending_amount,
            COUNT(CASE WHEN status = 'completed' AND processed_at >= CURDATE() AND processed_at < CURDATE() + INTERVAL 1 DAY THEN 1 END) as completed_today_count,
            SUM(CASE WHEN status = 'completed' AND processed_at >= CURDATE() AND processed_at < CURDATE() + INTERVAL 1 DAY THEN amount_brl ELSE 0 END) as completed_today_amount,
            COUNT(CASE WHEN status = 'completed' AND processed_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as completed_month_count,
            SUM(CASE WHEN status = 'completed' AND processed_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount_brl ELSE 0 END) as completed_month_amount,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_total_count,
            SUM(CASE WHEN status = 'completed' THEN amount_brl ELSE 0 END) as completed_total_amount,
            COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_count
        FROM withdrawals
    ")->fetch();

    // Estatísticas de sessões (hoje) — tabela: game_sessions (doc 3.3)
    $sessionStats = $pdo->query("
        SELECT
            COUNT(*) as total_today,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'flagged' THEN 1 END) as flagged,
            SUM(earnings_brl) as earnings_today,
            SUM(asteroids_destroyed) as asteroids_today
        FROM game_sessions
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
    ")->fetch();

    // Estatísticas de sessões (30 dias)
    $sessionStats30d = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(earnings_brl) as earnings,
            COUNT(CASE WHEN status = 'flagged' THEN 1 END) as flagged
        FROM game_sessions
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")->fetch();

    // Total de sessões (all-time)
    $sessionStatsTotal = $pdo->query("
        SELECT COUNT(*) as total, SUM(earnings_brl) as earnings
        FROM game_sessions
    ")->fetch();

    // Compras de créditos — tabela: credit_purchases
    $creditPurchaseStats = ['today_count' => 0, 'today_amount' => 0, 'month_count' => 0, 'month_amount' => 0, 'total_count' => 0, 'total_amount' => 0];
    try {
        $creditPurchaseStats = $pdo->query("
            SELECT
                COUNT(CASE WHEN confirmed_at >= CURDATE() AND confirmed_at < CURDATE() + INTERVAL 1 DAY AND status = 'confirmed' THEN 1 END) as today_count,
                COALESCE(SUM(CASE WHEN confirmed_at >= CURDATE() AND confirmed_at < CURDATE() + INTERVAL 1 DAY AND status = 'confirmed' THEN price_brl ELSE 0 END), 0) as today_amount,
                COUNT(CASE WHEN confirmed_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'confirmed' THEN 1 END) as month_count,
                COALESCE(SUM(CASE WHEN confirmed_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'confirmed' THEN price_brl ELSE 0 END), 0) as month_amount,
                COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as total_count,
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN price_brl ELSE 0 END), 0) as total_amount
            FROM credit_purchases
        ")->fetch() ?: $creditPurchaseStats;
    } catch (Exception $e) {
        // Fallback: tentar via transactions
        try {
            $creditPurchaseStats = $pdo->query("
                SELECT
                    COUNT(CASE WHEN created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY AND type = 'credit_purchase' AND status = 'completed' THEN 1 END) as today_count,
                    COALESCE(SUM(CASE WHEN created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY AND type = 'credit_purchase' AND status = 'completed' THEN amount_brl ELSE 0 END), 0) as today_amount,
                    COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND type = 'credit_purchase' AND status = 'completed' THEN 1 END) as month_count,
                    COALESCE(SUM(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND type = 'credit_purchase' AND status = 'completed' THEN amount_brl ELSE 0 END), 0) as month_amount,
                    COUNT(CASE WHEN type = 'credit_purchase' AND status = 'completed' THEN 1 END) as total_count,
                    COALESCE(SUM(CASE WHEN type = 'credit_purchase' AND status = 'completed' THEN amount_brl ELSE 0 END), 0) as total_amount
                FROM transactions
            ")->fetch() ?: $creditPurchaseStats;
        } catch (Exception $e2) {}
    }

    // Depósitos diretos (saldo BRL) — tabela: transactions
    $depositStats = ['today_amount' => 0, 'month_amount' => 0, 'total_amount' => 0];
    try {
        $depositStats = $pdo->query("
            SELECT
                COALESCE(SUM(CASE WHEN created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY AND type = 'deposit' AND status = 'completed' THEN amount_brl ELSE 0 END), 0) as today_amount,
                COALESCE(SUM(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND type = 'deposit' AND status = 'completed' THEN amount_brl ELSE 0 END), 0) as month_amount,
                COALESCE(SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount_brl ELSE 0 END), 0) as total_amount
            FROM transactions
        ")->fetch() ?: $depositStats;
    } catch (Exception $e) {}

    // Estatísticas de referrals — tabela: referrals
    $referralStats = ['total' => 0, 'qualified' => 0, 'total_paid' => 0, 'paid_today' => 0, 'paid_month' => 0];
    try {
        $referralStats = $pdo->query("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'qualified' THEN 1 END) as qualified,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_brl ELSE 0 END), 0) as total_paid,
                COALESCE(SUM(CASE WHEN status = 'paid' AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY THEN commission_brl ELSE 0 END), 0) as paid_today,
                COALESCE(SUM(CASE WHEN status = 'paid' AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN commission_brl ELSE 0 END), 0) as paid_month
            FROM referrals
        ")->fetch() ?: $referralStats;
    } catch (Exception $e) {}

} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php (Regra de Ouro: 6 decimais)

if (!function_exists('formatBRLShort')) {
    function formatBRLShort($value) {
        return 'R$ ' . number_format($value ?? 0, 2, ',', '.');
    }
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
            <div class="change positive">+<?php echo $playerStats['new_today'] ?? 0; ?> hoje | <?php echo $playerStats['active_today'] ?? 0; ?> ativos</div>
        </div>

        <div class="stat-card">
            <div class="icon success"><i class="fas fa-gamepad"></i></div>
            <div class="value"><?php echo number_format($sessionStats['total_today'] ?? 0); ?></div>
            <div class="label">Partidas Hoje</div>
            <div class="change"><?php echo $sessionStats['completed'] ?? 0; ?> completadas</div>
        </div>

        <div class="stat-card" style="position:relative;">
            <div class="icon warning"><i class="fas fa-wallet"></i></div>
            <div class="value"><?php echo formatBRLShort($displayBalance); ?></div>
            <div class="label">Saldo Total Jogadores</div>
            <div class="change"><?php echo number_format($displayCredits); ?> créditos em conta</div>
            <button onclick="resetBalanceCredits()" title="Zerar contagem a partir de agora" style="position:absolute;top:8px;right:8px;background:none;border:1px solid rgba(255,255,255,0.1);border-radius:6px;color:var(--text-dim);font-size:0.65rem;padding:3px 6px;cursor:pointer;opacity:0.5;transition:opacity 0.3s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.5'"><i class="fas fa-redo-alt"></i></button>
        </div>

        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-clock"></i></div>
            <div class="value"><?php echo $withdrawStats['pending_count'] ?? 0; ?></div>
            <div class="label">Saques Pendentes</div>
            <div class="change"><?php echo formatBRLShort($withdrawStats['pending_amount']); ?><?php if (($withdrawStats['processing_count'] ?? 0) > 0): ?> | <?php echo $withdrawStats['processing_count']; ?> processando<?php endif; ?></div>
        </div>
    </div>

    <!-- Segunda linha de stats -->
    <div class="stats-grid" style="margin-top: 20px;">
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-shopping-cart"></i></div>
            <div class="value"><?php echo formatBRLShort($creditPurchaseStats['today_amount']); ?></div>
            <div class="label">Compras Hoje</div>
            <div class="change"><?php echo $creditPurchaseStats['today_count'] ?? 0; ?> compra(s) de créditos</div>
        </div>

        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-check-circle"></i></div>
            <div class="value"><?php echo formatBRLShort($withdrawStats['completed_today_amount']); ?></div>
            <div class="label">Saques Aprovados Hoje</div>
            <div class="change"><?php echo $withdrawStats['completed_today_count'] ?? 0; ?> saque(s) pagos</div>
        </div>

        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-coins"></i></div>
            <div class="value"><?php echo formatBRLShort($sessionStats['earnings_today']); ?></div>
            <div class="label">Ganhos Jogadores Hoje</div>
            <div class="change"><?php echo number_format($sessionStats['asteroids_today'] ?? 0); ?> asteroides destruídos</div>
        </div>

        <div class="stat-card">
            <div class="icon success"><i class="fas fa-user-friends"></i></div>
            <div class="value"><?php echo $referralStats['total'] ?? 0; ?></div>
            <div class="label">Indicações</div>
            <div class="change"><?php echo $referralStats['qualified'] ?? 0; ?> qualificadas</div>
        </div>
    </div>

    <!-- Resumo Financeiro -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-chart-bar"></i> Resumo Financeiro</h3>
        </div>
        <div class="panel-body">
            <!-- Tabela financeira -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align: left;">Métrica</th>
                            <th style="text-align: right;">Hoje</th>
                            <th style="text-align: right;">30 Dias</th>
                            <th style="text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><i class="fas fa-arrow-down" style="color: var(--success); margin-right: 8px;"></i><strong>Receita (Compras de Créditos)</strong></td>
                            <td style="text-align: right; color: var(--success); font-weight: 600;"><?php echo formatBRLShort($creditPurchaseStats['today_amount']); ?></td>
                            <td style="text-align: right; color: var(--success); font-weight: 600;"><?php echo formatBRLShort($creditPurchaseStats['month_amount']); ?></td>
                            <td style="text-align: right; color: var(--success); font-weight: 700; font-size: 1.05rem;"><?php echo formatBRLShort($creditPurchaseStats['total_amount']); ?></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-arrow-down" style="color: var(--primary); margin-right: 8px;"></i>Depósitos (Saldo BRL)</td>
                            <td style="text-align: right;"><?php echo formatBRLShort($depositStats['today_amount']); ?></td>
                            <td style="text-align: right;"><?php echo formatBRLShort($depositStats['month_amount']); ?></td>
                            <td style="text-align: right; font-weight: 600;"><?php echo formatBRLShort($depositStats['total_amount']); ?></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-trophy" style="color: var(--warning); margin-right: 8px;"></i>Ganhos dos Jogadores</td>
                            <td style="text-align: right;"><?php echo formatBRLShort($sessionStats['earnings_today']); ?></td>
                            <td style="text-align: right;"><?php echo formatBRLShort($sessionStats30d['earnings']); ?></td>
                            <td style="text-align: right; font-weight: 600;"><?php echo formatBRLShort($playerStats['total_earned']); ?></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-arrow-up" style="color: var(--danger); margin-right: 8px;"></i><strong>Saques Pagos</strong></td>
                            <td style="text-align: right; color: var(--danger);"><?php echo formatBRLShort($withdrawStats['completed_today_amount']); ?> <span style="color: var(--text-dim); font-size: 0.8rem;">(<?php echo $withdrawStats['completed_today_count'] ?? 0; ?>)</span></td>
                            <td style="text-align: right; color: var(--danger);"><?php echo formatBRLShort($withdrawStats['completed_month_amount']); ?> <span style="color: var(--text-dim); font-size: 0.8rem;">(<?php echo $withdrawStats['completed_month_count'] ?? 0; ?>)</span></td>
                            <td style="text-align: right; color: var(--danger); font-weight: 700; font-size: 1.05rem;"><?php echo formatBRLShort($withdrawStats['completed_total_amount']); ?> <span style="color: var(--text-dim); font-size: 0.8rem;">(<?php echo $withdrawStats['completed_total_count'] ?? 0; ?>)</span></td>
                        </tr>
                        <tr>
                            <td><i class="fas fa-handshake" style="color: var(--secondary); margin-right: 8px;"></i>Comissões de Indicação</td>
                            <td style="text-align: right;"><?php echo formatBRLShort($referralStats['paid_today']); ?></td>
                            <td style="text-align: right;"><?php echo formatBRLShort($referralStats['paid_month']); ?></td>
                            <td style="text-align: right; font-weight: 600;"><?php echo formatBRLShort($referralStats['total_paid']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Resumo do Sistema -->
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-server"></i> Resumo do Sistema</h3>
        </div>
        <div class="panel-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                <div style="padding: 15px; background: rgba(0,240,255,0.08); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.8rem;">Total Partidas</div>
                    <div style="font-size: 1.2rem; font-weight: 700;"><?php echo number_format($sessionStatsTotal['total'] ?? 0); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);"><?php echo number_format($sessionStats30d['total'] ?? 0); ?> nos últimos 30d</div>
                </div>
                <div style="padding: 15px; background: rgba(0,229,204,0.08); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.8rem;">Saldo em Jogo</div>
                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--warning);"><?php echo formatBRLShort($displayBalance); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">Passivo total (carteiras)</div>
                </div>
                <div style="padding: 15px; background: rgba(0,240,255,0.08); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.8rem;">Compras de Créditos</div>
                    <div style="font-size: 1.2rem; font-weight: 700;"><?php echo number_format($creditPurchaseStats['total_count'] ?? 0); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);"><?php echo $creditPurchaseStats['month_count'] ?? 0; ?> nos últimos 30d</div>
                </div>
                <div style="padding: 15px; background: rgba(255,71,87,0.08); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.8rem;">Sessões Flagged</div>
                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--danger);"><?php echo $sessionStats30d['flagged'] ?? 0; ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);"><?php echo $sessionStats['flagged'] ?? 0; ?> hoje | 30d</div>
                </div>
                <div style="padding: 15px; background: rgba(0,229,204,0.08); border-radius: 10px;">
                    <div style="color: var(--text-dim); font-size: 0.8rem;">Novos Jogadores (7d)</div>
                    <div style="font-size: 1.2rem; font-weight: 700; color: var(--success);"><?php echo $playerStats['new_week'] ?? 0; ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-dim);">+<?php echo $playerStats['new_today'] ?? 0; ?> hoje</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
async function resetBalanceCredits() {
    if (!confirm('Zerar a contagem de saldo e créditos a partir de agora?\n\nOs valores atuais serão salvos como offset e o card passará a mostrar apenas o acumulado a partir deste momento.')) return;
    try {
        const res = await adminAjax({ action: 'reset_balance_credits_offset' });
        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(res.error || res.message, 'error');
        }
    } catch (e) {
        showToast('Erro: ' + e.message, 'error');
    }
}
</script>
