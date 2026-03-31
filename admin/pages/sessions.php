<?php
// ============================================
// UNOBIX - Sessões de Jogo
// Arquivo: admin/pages/sessions.php
// v7.0 - Paginação
// ============================================

$pageTitle = 'Sessões';
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

try {
    $baseSql = "FROM game_sessions gs
            LEFT JOIN users p ON gs.google_uid = p.google_uid WHERE 1=1";
    $params = [];
    $filterSql = '';

    if ($filter !== 'all') {
        $filterSql .= " AND gs.status = ?";
        $params[] = $filter;
    }
    if ($search) {
        $filterSql .= " AND (gs.google_uid LIKE ? OR p.display_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $modeFilter = $_GET['mode'] ?? 'all';
    if ($modeFilter !== 'all' && in_array($modeFilter, ['normal', 'hard', 'extreme'])) {
        $filterSql .= " AND gs.game_mode = ?";
        $params[] = $modeFilter;
    }

    // Contagem
    $stmtCount = $pdo->prepare("SELECT COUNT(*) " . $baseSql . $filterSql);
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));

    $sql = "SELECT gs.*, p.display_name, p.is_banned " . $baseSql . $filterSql;
    $sql .= " ORDER BY gs.created_at DESC LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();

    $stats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'flagged' THEN 1 ELSE 0 END) as flagged,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            COALESCE(SUM(earnings_brl), 0) as total_earnings,
            COALESCE(SUM(asteroids_destroyed), 0) as total_asteroids
        FROM game_sessions WHERE DATE(created_at) = CURDATE()
    ")->fetch();

    // Estatísticas por período (24h, 7d, 30d)
    $periodStats = $pdo->query("
        SELECT
            -- Última hora
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as total_1h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'completed' THEN 1 ELSE 0 END) as completed_1h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_1h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'flagged' THEN 1 ELSE 0 END) as flagged_1h,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN earnings_brl ELSE 0 END), 0) as earnings_1h,
            COALESCE(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'completed' THEN earnings_brl END), 0) as avg_earnings_1h,
            -- 24 horas
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as total_24h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'completed' THEN 1 ELSE 0 END) as completed_24h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_24h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'flagged' THEN 1 ELSE 0 END) as flagged_24h,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN earnings_brl ELSE 0 END), 0) as earnings_24h,
            COALESCE(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'completed' THEN earnings_brl END), 0) as avg_earnings_24h,
            -- 7 dias
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as total_7d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'completed' THEN 1 ELSE 0 END) as completed_7d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_7d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'flagged' THEN 1 ELSE 0 END) as flagged_7d,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN earnings_brl ELSE 0 END), 0) as earnings_7d,
            COALESCE(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'completed' THEN earnings_brl END), 0) as avg_earnings_7d,
            -- 30 dias
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as total_30d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'completed' THEN 1 ELSE 0 END) as completed_30d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_30d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'flagged' THEN 1 ELSE 0 END) as flagged_30d,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN earnings_brl ELSE 0 END), 0) as earnings_30d,
            COALESCE(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'completed' THEN earnings_brl END), 0) as avg_earnings_30d,
            -- Modos (7 dias)
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND game_mode = 'normal' THEN 1 ELSE 0 END) as mode_normal_7d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND game_mode = 'hard' THEN 1 ELSE 0 END) as mode_hard_7d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND game_mode = 'extreme' THEN 1 ELSE 0 END) as mode_extreme_7d
        FROM game_sessions
    ")->fetch();

    // Estatísticas por modo por período (completadas x abandonadas + earnings)
    $modePerPeriod = $pdo->query("
        SELECT
            game_mode,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'completed' THEN 1 ELSE 0 END) as completed_1h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_1h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as total_1h,
            COALESCE(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'completed' THEN earnings_brl END), 0) as avg_earn_1h,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN earnings_brl ELSE 0 END), 0) as total_earn_1h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'completed' THEN 1 ELSE 0 END) as completed_24h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_24h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as total_24h,
            COALESCE(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'completed' THEN earnings_brl END), 0) as avg_earn_24h,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN earnings_brl ELSE 0 END), 0) as total_earn_24h,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'completed' THEN 1 ELSE 0 END) as completed_7d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_7d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as total_7d,
            COALESCE(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'completed' THEN earnings_brl END), 0) as avg_earn_7d,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN earnings_brl ELSE 0 END), 0) as total_earn_7d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'completed' THEN 1 ELSE 0 END) as completed_30d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_30d,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as total_30d,
            COALESCE(AVG(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'completed' THEN earnings_brl END), 0) as avg_earn_30d,
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN earnings_brl ELSE 0 END), 0) as total_earn_30d
        FROM game_sessions
        WHERE game_mode IN ('normal', 'hard', 'extreme')
        GROUP BY game_mode
    ")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

    // Custo em créditos por modo (ler do banco, fallback hardcoded)
    $modeCreditCosts = ['normal' => 1, 'hard' => 2, 'extreme' => 3];
    foreach (['normal', 'hard', 'extreme'] as $m) {
        $stmtCc = $pdo->prepare("SELECT setting_value FROM game_settings WHERE setting_key = ?");
        $stmtCc->execute(["mode_{$m}_credits"]);
        $row = $stmtCc->fetch();
        if ($row && is_numeric($row['setting_value'])) {
            $modeCreditCosts[$m] = max(1, (int)$row['setting_value']);
        }
    }

    // Preço médio do crédito (baseado nos pacotes ativos)
    $avgCreditPrice = 0.50; // fallback
    $stmtAvgPrice = $pdo->query("
        SELECT SUM(price_brl) / SUM(credits + COALESCE(bonus_credits, 0)) as avg_price
        FROM credit_packages WHERE is_active = 1
    ");
    $avgRow = $stmtAvgPrice->fetch();
    if ($avgRow && $avgRow['avg_price'] > 0) {
        $avgCreditPrice = round((float)$avgRow['avg_price'], 2);
    }

    // Calcular porcentagens
    function calcPercent($part, $total) {
        return $total > 0 ? round(($part / $total) * 100, 1) : 0;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-gamepad"></i> Sessões de Jogo</h1>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-play"></i></div>
            <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="label">Hoje</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check"></i></div>
            <div class="value"><?php echo number_format($stats['completed'] ?? 0); ?></div>
            <div class="label">Completadas</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-flag"></i></div>
            <div class="value"><?php echo $stats['flagged'] ?? 0; ?></div>
            <div class="label">Flagged</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-coins"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_earnings']); ?></div>
            <div class="label">Ganhos Hoje</div>
        </div>
    </div>

    <!-- Cards informativos por período -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <?php
        $periods = [
            ['label' => 'Última hora', 'suffix' => '1h', 'color' => '#e74c3c'],
            ['label' => 'Últimas 24h', 'suffix' => '24h', 'color' => '#4da6ff'],
            ['label' => 'Últimos 7 dias', 'suffix' => '7d', 'color' => '#00d68f'],
            ['label' => 'Últimos 30 dias', 'suffix' => '30d', 'color' => '#f39c12'],
        ];
        foreach ($periods as $p):
            $s = $p['suffix'];
            $total = (int)($periodStats["total_{$s}"] ?? 0);
            $completed = (int)($periodStats["completed_{$s}"] ?? 0);
            $abandoned = (int)($periodStats["abandoned_{$s}"] ?? 0);
            $flagged = (int)($periodStats["flagged_{$s}"] ?? 0);
            $earnings = (float)($periodStats["earnings_{$s}"] ?? 0);
            $avgEarnings = (float)($periodStats["avg_earnings_{$s}"] ?? 0);
            $pctCompleted = calcPercent($completed, $total);
            $pctAbandoned = calcPercent($abandoned, $total);
            $pctFlagged = calcPercent($flagged, $total);
        ?>
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 18px; border-left: 3px solid <?php echo $p['color']; ?>;">
            <div style="font-family: 'Orbitron', monospace; font-size: 0.85rem; font-weight: 700; color: <?php echo $p['color']; ?>; margin-bottom: 14px;">
                <?php echo $p['label']; ?>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                <div>
                    <div style="font-size: 1.4rem; font-weight: 700; color: #fff;"><?php echo number_format($total); ?></div>
                    <div style="font-size: 0.7rem; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px;">Total sessões</div>
                </div>
                <div>
                    <div style="font-size: 1.4rem; font-weight: 700; color: #00d68f;"><?php echo formatBRL($earnings); ?></div>
                    <div style="font-size: 0.7rem; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px;">Earnings pagos</div>
                </div>
            </div>
            <!-- Barra visual -->
            <div style="height: 8px; background: rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden; display: flex; margin-bottom: 10px;">
                <div style="width: <?php echo $pctCompleted; ?>%; background: #00d68f;" title="Completadas <?php echo $pctCompleted; ?>%"></div>
                <div style="width: <?php echo $pctAbandoned; ?>%; background: #95a5a6;" title="Abandonadas <?php echo $pctAbandoned; ?>%"></div>
                <div style="width: <?php echo $pctFlagged; ?>%; background: #e74c3c;" title="Flagged <?php echo $pctFlagged; ?>%"></div>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.75rem;">
                <span style="color: #00d68f;"><i class="fas fa-check" style="margin-right: 3px;"></i> <?php echo $pctCompleted; ?>% completadas</span>
                <span style="color: #95a5a6;"><i class="fas fa-times" style="margin-right: 3px;"></i> <?php echo $pctAbandoned; ?>% abandonadas</span>
                <span style="color: #e74c3c;"><i class="fas fa-flag" style="margin-right: 3px;"></i> <?php echo $pctFlagged; ?>% flagged</span>
            </div>
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 0.75rem; color: rgba(255,255,255,0.4);">
                Média por sessão: <span style="color: #fff;"><?php echo formatBRL($avgEarnings); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Completadas x Abandonadas por Modo por Período -->
    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 18px; margin-bottom: 20px;">
        <div style="font-family: 'Orbitron', monospace; font-size: 0.85rem; font-weight: 700; color: #fff; margin-bottom: 16px;">
            <i class="fas fa-chart-bar" style="margin-right: 6px; color: #9b59b6;"></i> Sessões por Modo — Completadas vs Abandonadas
            <span style="font-size: 0.65rem; font-weight: 400; color: rgba(255,255,255,0.3); margin-left: 8px;">(Preço médio crédito: <?php echo formatBRL($avgCreditPrice); ?>)</span>
        </div>
        <?php
        $modePeriods = [
            ['label' => 'Última hora', 'suffix' => '1h'],
            ['label' => 'Últimas 24h', 'suffix' => '24h'],
            ['label' => 'Últimos 7 dias', 'suffix' => '7d'],
            ['label' => 'Últimos 30 dias', 'suffix' => '30d'],
        ];
        $modesList = [
            'normal'  => ['name' => 'Normal',  'color' => '#2ecc71'],
            'hard'    => ['name' => 'Difícil', 'color' => '#f39c12'],
            'extreme' => ['name' => 'Extreme', 'color' => '#e74c3c'],
        ];
        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
        <?php foreach ($modePeriods as $mp): $sf = $mp['suffix']; ?>
            <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; padding: 14px;">
                <div style="font-size: 0.8rem; font-weight: 700; color: rgba(255,255,255,0.6); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px;">
                    <?php echo $mp['label']; ?>
                </div>
                <?php foreach ($modesList as $mKey => $mInfo):
                    $mData = $modePerPeriod[$mKey] ?? [];
                    $mCompleted = (int)($mData["completed_{$sf}"] ?? 0);
                    $mAbandoned = (int)($mData["abandoned_{$sf}"] ?? 0);
                    $mTotal = (int)($mData["total_{$sf}"] ?? 0);
                    $pctC = calcPercent($mCompleted, $mTotal);
                    $pctA = calcPercent($mAbandoned, $mTotal);
                ?>
                <?php
                    $avgEarn = (float)($mData["avg_earn_{$sf}"] ?? 0);
                    $totalEarn = (float)($mData["total_earn_{$sf}"] ?? 0);
                    $creditCost = $modeCreditCosts[$mKey] ?? 1;
                    $receita = $mTotal * $creditCost * $avgCreditPrice;
                    $lucro = $receita - $totalEarn;
                    $lucroColor = $lucro >= 0 ? '#00d68f' : '#e74c3c';
                    $lucroIcon = $lucro >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                ?>
                <div style="margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.04);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <span style="font-size: 0.8rem; font-weight: 700; color: <?php echo $mInfo['color']; ?>;">
                            <?php echo $mInfo['name']; ?> <span style="font-size: 0.65rem; color: rgba(255,255,255,0.3);">(<?php echo $creditCost; ?> cred)</span>
                        </span>
                        <span style="font-size: 0.7rem; color: rgba(255,255,255,0.4);">
                            <?php echo $mTotal; ?> total
                        </span>
                    </div>
                    <div style="height: 6px; background: rgba(255,255,255,0.06); border-radius: 3px; overflow: hidden; display: flex; margin-bottom: 4px;">
                        <div style="width: <?php echo $pctC; ?>%; background: #00d68f;" title="Completadas"></div>
                        <div style="width: <?php echo $pctA; ?>%; background: #95a5a6;" title="Abandonadas"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem; margin-bottom: 6px;">
                        <span style="color: #00d68f;"><i class="fas fa-check" style="margin-right: 2px;"></i><?php echo $mCompleted; ?> (<?php echo $pctC; ?>%)</span>
                        <span style="color: #95a5a6;"><i class="fas fa-times" style="margin-right: 2px;"></i><?php echo $mAbandoned; ?> (<?php echo $pctA; ?>%)</span>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; font-size: 0.68rem;">
                        <div style="color: rgba(255,255,255,0.5);"><i class="fas fa-trophy" style="width: 12px; color: #4da6ff;"></i> Média/vitória: <span style="color: #4da6ff; font-weight: 600;"><?php echo formatBRL($avgEarn); ?></span></div>
                        <div style="color: rgba(255,255,255,0.5);"><i class="fas fa-hand-holding-usd" style="width: 12px; color: #e74c3c;"></i> Pago aos jogadores: <span style="color: #e74c3c; font-weight: 600;"><?php echo formatBRL($totalEarn); ?></span></div>
                        <div style="color: rgba(255,255,255,0.5);"><i class="fas fa-coins" style="width: 12px; color: #00d68f;"></i> Receita (créditos): <span style="color: #00d68f; font-weight: 600;"><?php echo formatBRL($receita); ?></span></div>
                        <div style="color: rgba(255,255,255,0.5);"><i class="fas fa-balance-scale" style="width: 12px; color: <?php echo $lucroColor; ?>;"></i> Saldo: <span style="color: <?php echo $lucroColor; ?>; font-weight: 700;"><?php echo $lucro >= 0 ? '+' : '-'; ?><?php echo formatBRL(abs($lucro)); ?></span></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Distribuição por modo (7 dias) -->
    <?php
    $modeTotal = (int)($periodStats['total_7d'] ?? 0);
    $modeNormal = (int)($periodStats['mode_normal_7d'] ?? 0);
    $modeHard = (int)($periodStats['mode_hard_7d'] ?? 0);
    $modeExtreme = (int)($periodStats['mode_extreme_7d'] ?? 0);
    ?>
    <?php if ($modeTotal > 0): ?>
    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 18px; margin-bottom: 20px;">
        <div style="font-family: 'Orbitron', monospace; font-size: 0.85rem; font-weight: 700; color: #fff; margin-bottom: 14px;">
            <i class="fas fa-chart-pie" style="margin-right: 6px; color: #4da6ff;"></i> Distribuição por Modo (7 dias)
        </div>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 12px; height: 12px; border-radius: 3px; background: #2ecc71;"></div>
                <span style="font-size: 0.85rem; color: rgba(255,255,255,0.7);">Normal: <strong style="color: #fff;"><?php echo number_format($modeNormal); ?></strong> (<?php echo calcPercent($modeNormal, $modeTotal); ?>%)</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 12px; height: 12px; border-radius: 3px; background: #f39c12;"></div>
                <span style="font-size: 0.85rem; color: rgba(255,255,255,0.7);">Difícil: <strong style="color: #fff;"><?php echo number_format($modeHard); ?></strong> (<?php echo calcPercent($modeHard, $modeTotal); ?>%)</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 12px; height: 12px; border-radius: 3px; background: #e74c3c;"></div>
                <span style="font-size: 0.85rem; color: rgba(255,255,255,0.7);">Extreme: <strong style="color: #fff;"><?php echo number_format($modeExtreme); ?></strong> (<?php echo calcPercent($modeExtreme, $modeTotal); ?>%)</span>
            </div>
        </div>
        <!-- Barra visual modos -->
        <div style="height: 8px; background: rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden; display: flex; margin-top: 12px;">
            <div style="width: <?php echo calcPercent($modeNormal, $modeTotal); ?>%; background: #2ecc71;"></div>
            <div style="width: <?php echo calcPercent($modeHard, $modeTotal); ?>%; background: #f39c12;"></div>
            <div style="width: <?php echo calcPercent($modeExtreme, $modeTotal); ?>%; background: #e74c3c;"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="sessions">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar jogador..." class="form-control" style="width: 200px;">
                <select name="filter" class="form-control">
                    <option value="all">Todos Status</option>
                    <option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>Completadas</option>
                    <option value="abandoned" <?php echo $filter === 'abandoned' ? 'selected' : ''; ?>>Abandonadas</option>
                    <option value="flagged" <?php echo $filter === 'flagged' ? 'selected' : ''; ?>>Flagged</option>
                    <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Ativas</option>
                </select>
                <?php $modeFilter = $_GET['mode'] ?? 'all'; ?>
                <select name="mode" class="form-control">
                    <option value="all">Todos Modos</option>
                    <option value="normal" <?php echo $modeFilter === 'normal' ? 'selected' : ''; ?>>Normal</option>
                    <option value="hard" <?php echo $modeFilter === 'hard' ? 'selected' : ''; ?>>Difícil</option>
                    <option value="extreme" <?php echo $modeFilter === 'extreme' ? 'selected' : ''; ?>>Extreme</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <?php if (empty($sessions)): ?>
                <div class="empty-state"><i class="fas fa-gamepad"></i><h3>Nenhuma sessão</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Jogador</th><th>Missão</th><th>Asteroides</th><th>Ganho</th><th>Duração</th><th>Status</th><th>Data</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td>#<?php echo $s['id']; ?></td>
                        <td>
                            <?php echo htmlspecialchars($s['display_name'] ?? 'Usuário'); ?>
                            <?php if ($s['is_banned']): ?><span class="badge badge-danger">BAN</span><?php endif; ?>
                            <?php
                                $gm = $s['game_mode'] ?? ($s['is_hard_mode'] ? 'hard' : 'normal');
                                $modeColors = ['normal' => '#2ecc71', 'hard' => '#f39c12', 'extreme' => '#e74c3c'];
                                $modeLabels = ['normal' => 'NORMAL', 'hard' => 'DIFÍCIL', 'extreme' => 'EXTREME'];
                                $mc = $modeColors[$gm] ?? '#95a5a6';
                                $ml = $modeLabels[$gm] ?? strtoupper($gm);
                            ?>
                            <span style="background: <?php echo $mc; ?>22; color: <?php echo $mc; ?>; padding: 1px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: 700;"><?php echo $ml; ?></span>
                            <?php
                                $tSpawned = (int)($s['total_spawned'] ?? 0);
                                $tDestroyed = (int)($s['asteroids_destroyed'] ?? 0);
                                $destroyRate = $tSpawned > 0 ? ($tDestroyed / $tSpawned) : 0;
                                if ($tSpawned > 0 && $destroyRate > 0.90):
                            ?>
                            <span style="background: #e74c3c22; color: #e74c3c; padding: 1px 6px; border-radius: 4px; font-size: 0.6rem; font-weight: 700; margin-left: 4px;" title="Destruiu <?php echo round($destroyRate * 100, 1); ?>% dos asteroides gerados (<?php echo $tDestroyed; ?>/<?php echo $tSpawned; ?>)"><i class="fas fa-exclamation-triangle" style="margin-right: 2px;"></i>SUSPEITO</span>
                            <?php endif; ?>
                        </td>
                        <td>#<?php echo $s['mission_number']; ?></td>
                        <td>
                            <?php echo $s['asteroids_destroyed']; ?>
                            <?php if ($tSpawned > 0): ?>
                                <small style="color: rgba(255,255,255,0.35);">/<?php echo $tSpawned; ?></small>
                                <small style="color: <?php echo $destroyRate > 0.90 ? '#e74c3c' : ($destroyRate > 0.75 ? '#f39c12' : '#00d68f'); ?>; font-weight: 600;">(<?php echo round($destroyRate * 100); ?>%)</small>
                            <?php endif; ?>
                            <small style="color: var(--warning);">(<?php echo $s['rare_asteroids']; ?>R/<?php echo $s['epic_asteroids']; ?>E)</small>
                        </td>
                        <td style="color: var(--success);"><?php echo formatBRL($s['earnings_brl']); ?></td>
                        <td><?php echo $s['game_duration'] ? $s['game_duration'] . 's' : '-'; ?></td>
                        <td>
                            <?php $sc = ['completed'=>'success','flagged'=>'danger','active'=>'warning'][$s['status']] ?? 'primary'; ?>
                            <span class="badge badge-<?php echo $sc; ?>"><?php echo $s['status']; ?></span>
                        </td>
                        <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <?php
                    $queryParams = $_GET;
                    unset($queryParams['p']);
                    $baseUrl = '?' . http_build_query($queryParams);
                ?>
                <div style="display: flex; align-items: center; gap: 6px; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color); flex-wrap: wrap;">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl . '&p=' . ($page - 1); ?>" class="btn btn-outline btn-sm">&laquo; Anterior</a>
                    <?php endif; ?>
                    <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1) echo '<span style="color: var(--text-dim); padding: 0 4px;">...</span>';
                        for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="<?php echo $baseUrl . '&p=' . $i; ?>" class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>" style="min-width: 36px; text-align: center; text-decoration: none;"><?php echo $i; ?></a>
                    <?php
                        endfor;
                        if ($end < $totalPages) echo '<span style="color: var(--text-dim); padding: 0 4px;">...</span>';
                    ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl . '&p=' . ($page + 1); ?>" class="btn btn-outline btn-sm">Próxima &raquo;</a>
                    <?php endif; ?>
                    <span style="color: var(--text-dim); font-size: 0.8rem; margin-left: 10px;">
                        <?php echo number_format($totalRows); ?> sessões | Página <?php echo $page; ?>/<?php echo $totalPages; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>
