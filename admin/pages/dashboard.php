<?php
// ============================================
// CRYPTO ASTEROID RUSH - Dashboard
// Arquivo: admin/pages/dashboard.php
// ============================================

$pageTitle = 'Dashboard';

// Inicializar variáveis
$totalPlayers = 0;
$playersToday = 0;
$totalBalance = 0;
$totalEarned = 0;
$totalFees = 0;
$feesToday = 0;
$withdrawalsToday = 0;
$pendingWithdrawals = 0;
$recentSessions = [];
$recentWithdrawals = [];
$systemAlerts = [];

// Descobrir tabelas existentes
$tables = [];
try {
    $res = $pdo->query("SHOW TABLES");
    while ($row = $res->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
} catch (Exception $e) {}

// Flags de colunas
$hasBalanceColumn = false;
$hasEarnedColumn  = false;
$hasFeeColumn     = false;
$hasEntryFeeCol   = false;

try {
    if (in_array('players', $tables)) {
        $c = $pdo->query("SHOW COLUMNS FROM players LIKE 'balance_usdt'");
        $hasBalanceColumn = $c && $c->fetch();

        $c = $pdo->query("SHOW COLUMNS FROM players LIKE 'total_earned'");
        $hasEarnedColumn = $c && $c->fetch();
    }

    if (in_array('transactions', $tables)) {
        $c = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'fee_bnb'");
        $hasFeeColumn = $c && $c->fetch();
    }

    if (in_array('game_sessions', $tables)) {
        $c = $pdo->query("SHOW COLUMNS FROM game_sessions LIKE 'entry_fee_bnb'");
        $hasEntryFeeCol = $c && $c->fetch();
    }
} catch (Exception $e) {}

// =====================
// Estatísticas gerais
// =====================
try {

    if (in_array('players', $tables)) {
        $totalPlayers = $pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();

        $playersToday = $pdo->query(
            "SELECT COUNT(*) FROM players WHERE DATE(created_at) = CURDATE()"
        )->fetchColumn();

        if ($hasBalanceColumn) {
            $totalBalance = $pdo->query(
                "SELECT COALESCE(SUM(balance_usdt),0) FROM players"
            )->fetchColumn();
        }

        if ($hasEarnedColumn) {
            $totalEarned = $pdo->query(
                "SELECT COALESCE(SUM(total_earned),0) FROM players"
            )->fetchColumn();
        }
    }

    if (in_array('transactions', $tables) && $hasFeeColumn) {
        $totalFees = $pdo->query(
            "SELECT COALESCE(SUM(fee_bnb),0) FROM transactions"
        )->fetchColumn();

        $feesToday = $pdo->query(
            "SELECT COALESCE(SUM(fee_bnb),0) FROM transactions WHERE DATE(created_at)=CURDATE()"
        )->fetchColumn();
    }

    if (in_array('game_sessions', $tables) && $hasEntryFeeCol) {
        if ($feesToday == 0) {
            $feesToday = $pdo->query(
                "SELECT COALESCE(SUM(entry_fee_bnb),0) FROM game_sessions WHERE DATE(created_at)=CURDATE()"
            )->fetchColumn();
        }

        if ($totalFees == 0) {
            $totalFees = $pdo->query(
                "SELECT COALESCE(SUM(entry_fee_bnb),0) FROM game_sessions"
            )->fetchColumn();
        }
    }

    if (in_array('withdrawals', $tables)) {
        $withdrawalsToday = $pdo->query(
            "SELECT COUNT(*) FROM withdrawals WHERE DATE(created_at)=CURDATE()"
        )->fetchColumn();

        $pendingWithdrawals = $pdo->query(
            "SELECT COUNT(*) FROM withdrawals WHERE status='pending'"
        )->fetchColumn();
    }

    if (in_array('game_sessions', $tables)) {
        $stmt = $pdo->query(
            "SELECT gs.*, p.wallet
             FROM game_sessions gs
             LEFT JOIN players p ON gs.player_id = p.id
             ORDER BY gs.created_at DESC
             LIMIT 10"
        );
        $recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (in_array('withdrawals', $tables)) {
        $stmt = $pdo->query(
            "SELECT w.*, p.wallet
             FROM withdrawals w
             LEFT JOIN players p ON w.player_id = p.id
             ORDER BY w.created_at DESC
             LIMIT 10"
        );
        $recentWithdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    $systemAlerts[] = [
        'type' => 'danger',
        'message' => 'Erro ao carregar estatísticas: '.$e->getMessage()
    ];
}

// =====================
// Alertas do sistema
// =====================
try {
    $requiredTables = ['players','transactions','withdrawals','game_sessions'];
    $missing = [];

    foreach ($requiredTables as $t) {
        if (!in_array($t, $tables)) {
            $missing[] = $t;
        }
    }

    if ($missing) {
        $systemAlerts[] = [
            'type' => 'warning',
            'message' => 'Tabelas ausentes: '.implode(', ', $missing)
        ];
    }

    if ($pendingWithdrawals > 0) {
        $systemAlerts[] = [
            'type' => 'info',
            'message' => "Existem {$pendingWithdrawals} saques pendentes."
        ];
    }

    if (in_array('game_sessions', $tables)) {
        $flagged = $pdo->query(
            "SELECT COUNT(*) FROM game_sessions WHERE status='flagged'"
        )->fetchColumn();

        if ($flagged > 0) {
            $systemAlerts[] = [
                'type' => 'warning',
                'message' => "Existem {$flagged} sessões suspeitas."
            ];
        }
    }

} catch (Exception $e) {}
?>

<div class="main-content">

    <div class="page-header">
        <h1><i class="fas fa-chart-line"></i> Dashboard</h1>
        <div class="page-actions">
            <a href="<?= $ADMIN_INDEX_URL ?>?page=security" class="btn btn-outline">
                <i class="fas fa-shield-alt"></i> Status do Sistema
            </a>
        </div>
    </div>

    <?php foreach ($systemAlerts as $alert): ?>
        <div class="alert alert-<?= $alert['type'] ?>">
            <?= htmlspecialchars($alert['message']) ?>
        </div>
    <?php endforeach; ?>

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-title">Jogadores</div>
            <div class="stat-value"><?= number_format($totalPlayers) ?></div>
            <div class="stat-sub">Hoje: <?= number_format($playersToday) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Saldo Total (USDT)</div>
            <div class="stat-value"><?= number_format($totalBalance, 2) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Total Earned</div>
            <div class="stat-value"><?= number_format($totalEarned, 2) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Taxas BNB</div>
            <div class="stat-value"><?= number_format($totalFees, 6) ?></div>
            <div class="stat-sub">Hoje: <?= number_format($feesToday, 6) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Saques Hoje</div>
            <div class="stat-value"><?= number_format($withdrawalsToday) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-title">Pendentes</div>
            <div class="stat-value"><?= number_format($pendingWithdrawals) ?></div>
        </div>

    </div>

</div>
