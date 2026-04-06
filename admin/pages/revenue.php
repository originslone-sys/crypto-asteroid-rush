<?php
// ============================================
// UNOBIX - Métricas de Receita
// admin/pages/revenue.php
// Créditos · Premium · Exploração · Saques
// ============================================

$pageTitle = 'Receita';

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 365])) $days = 30;

try {
    // ── KPIs globais ─────────────────────────────────────────────────────────
    $kpi = [];

    // Créditos: soma de credit_purchases confirmadas (all time + período)
    $r = $pdo->query("SELECT
        COALESCE(SUM(price_brl),0) as total_all,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) THEN price_brl ELSE 0 END),0) as total_period,
        COUNT(*) as count_all,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) THEN 1 END) as count_period
        FROM credit_purchases WHERE status = 'confirmed'")->fetch();
    $kpi['credits'] = $r;

    // Premium: soma de premium_subscriptions confirmadas
    $r = $pdo->query("SELECT
        COALESCE(SUM(price_brl),0) as total_all,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) THEN price_brl ELSE 0 END),0) as total_period,
        COUNT(*) as count_all,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) THEN 1 END) as count_period
        FROM premium_subscriptions WHERE status = 'active'")->fetch();
    $kpi['premium'] = $r;

    // Exploração: soma de rentals ativos (pagos via PIX)
    $r = $pdo->query("SELECT
        COALESCE(SUM(rental_price_brl),0) as total_all,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) THEN rental_price_brl ELSE 0 END),0) as total_period,
        COUNT(*) as count_all,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) THEN 1 END) as count_period
        FROM exploration_rentals WHERE status = 'active'")->fetch();
    $kpi['exploration'] = $r;

    // Saques pagos
    $r = $pdo->query("SELECT
        COALESCE(SUM(amount_brl),0) as total_all,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) THEN amount_brl ELSE 0 END),0) as total_period,
        COUNT(*) as count_all,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) THEN 1 END) as count_period
        FROM withdrawals WHERE status = 'completed'")->fetch();
    $kpi['withdrawals'] = $r;

    $kpi['net_period']  = (float)$kpi['credits']['total_period']  + (float)$kpi['premium']['total_period']  + (float)$kpi['exploration']['total_period']  - (float)$kpi['withdrawals']['total_period'];
    $kpi['net_all']     = (float)$kpi['credits']['total_all']     + (float)$kpi['premium']['total_all']     + (float)$kpi['exploration']['total_all']     - (float)$kpi['withdrawals']['total_all'];

    // ── Receita diária (créditos + premium + saques) ──────────────────────────
    $dailyStmt = $pdo->prepare("
        SELECT day,
               SUM(credits_brl)      as credits_brl,
               SUM(premium_brl)      as premium_brl,
               SUM(exploration_brl)  as exploration_brl,
               SUM(withdrawal_brl)   as withdrawal_brl,
               SUM(credits_cnt)      as credits_cnt,
               SUM(premium_cnt)      as premium_cnt,
               SUM(exploration_cnt)  as exploration_cnt,
               SUM(withdrawal_cnt)   as withdrawal_cnt
        FROM (
            SELECT DATE(created_at) as day, price_brl as credits_brl, 0 as premium_brl, 0 as exploration_brl, 0 as withdrawal_brl,
                   1 as credits_cnt, 0 as premium_cnt, 0 as exploration_cnt, 0 as withdrawal_cnt
            FROM credit_purchases WHERE status = 'confirmed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            UNION ALL
            SELECT DATE(created_at), 0, price_brl, 0, 0, 0, 1, 0, 0
            FROM premium_subscriptions WHERE status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            UNION ALL
            SELECT DATE(created_at), 0, 0, rental_price_brl, 0, 0, 0, 1, 0
            FROM exploration_rentals WHERE status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            UNION ALL
            SELECT DATE(created_at), 0, 0, 0, amount_brl, 0, 0, 0, 1
            FROM withdrawals WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ) t
        GROUP BY day ORDER BY day ASC
    ");
    $dailyStmt->execute([$days, $days, $days, $days]);
    $dailyData = $dailyStmt->fetchAll();

    // ── Créditos por pacote ───────────────────────────────────────────────────
    $pkgStmt = $pdo->query("
        SELECT cp.name, cp.price_brl as pkg_price, cp.credits,
               COUNT(pur.id)          as sales_count,
               COALESCE(SUM(pur.price_brl), 0) as revenue,
               COALESCE(SUM(pur.credits_amount), 0) as credits_sold
        FROM credit_packages cp
        LEFT JOIN credit_purchases pur ON pur.package_id = cp.id AND pur.status = 'confirmed'
        GROUP BY cp.id ORDER BY revenue DESC
    ");
    $creditByPackage = $pkgStmt->fetchAll();

    // ── Premium por duração ───────────────────────────────────────────────────
    $premDurStmt = $pdo->query("
        SELECT duration_days,
               COUNT(*) as sales_count,
               COALESCE(SUM(price_brl), 0) as revenue,
               AVG(price_brl) as avg_price
        FROM premium_subscriptions WHERE status = 'active'
        GROUP BY duration_days ORDER BY sales_count DESC
    ");
    $premByDuration = $premDurStmt->fetchAll();

    // ── Premium por mês (últimos 12 meses) ───────────────────────────────────
    $premMonthStmt = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
               COUNT(*) as count,
               COALESCE(SUM(price_brl), 0) as revenue
        FROM premium_subscriptions
        WHERE status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month ORDER BY month ASC
    ");
    $premByMonth = $premMonthStmt->fetchAll();

    // ── Distribuição de status dos saques ────────────────────────────────────
    $wdStatusStmt = $pdo->query("
        SELECT status, COUNT(*) as cnt, COALESCE(SUM(amount_brl),0) as total
        FROM withdrawals GROUP BY status ORDER BY cnt DESC
    ");
    $wdByStatus = $wdStatusStmt->fetchAll();

    // ── Ticket médio diário de saques (últimos período) ───────────────────────
    $wdDailyStmt = $pdo->prepare("
        SELECT DATE(created_at) as day, COUNT(*) as cnt, COALESCE(SUM(amount_brl),0) as total
        FROM withdrawals WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY day ORDER BY day ASC
    ");
    $wdDailyStmt->execute([$days]);
    $wdDaily = $wdDailyStmt->fetchAll();

    // ── Últimas compras (crédito + premium) ──────────────────────────────────
    $recentStmt = $pdo->query("
        SELECT t.type, t.amount_brl, t.description, t.status, t.created_at,
               u.email, u.display_name
        FROM transactions t
        LEFT JOIN users u ON u.google_uid = t.google_uid
        WHERE t.type IN ('credit_purchase','premium_purchase','exploration_rent') AND t.status = 'completed'
        ORDER BY t.created_at DESC LIMIT 20
    ");
    $recentSales = $recentStmt->fetchAll();

    // ── Top compradores (crédito + premium) ──────────────────────────────────
    $topStmt = $pdo->prepare("
        SELECT u.email, u.display_name, u.is_premium,
               COUNT(*) as purchases,
               COALESCE(SUM(t.amount_brl), 0) as spent
        FROM transactions t
        JOIN users u ON u.google_uid = t.google_uid
        WHERE t.type IN ('credit_purchase','premium_purchase','exploration_rent') AND t.status = 'completed'
          AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY u.id ORDER BY spent DESC LIMIT 10
    ");
    $topStmt->execute([$days]);
    $topBuyers = $topStmt->fetchAll();

} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// Helpers para o gráfico
$chartDays        = array_column($dailyData, 'day');
$chartCredits     = array_column($dailyData, 'credits_brl');
$chartPremium     = array_column($dailyData, 'premium_brl');
$chartExploration = array_column($dailyData, 'exploration_brl');
$chartWd          = array_column($dailyData, 'withdrawal_brl');

function fmtBRL(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}
?>

<div class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 class="page-title"><i class="fas fa-chart-line"></i> Métricas de Receita</h1>
            <p class="page-subtitle">Créditos · Premium · Exploração · Saques pagos</p>
        </div>
        <!-- Filtro de período -->
        <div style="display:flex;gap:6px;">
            <?php foreach ([7=>'7d',30=>'30d',90=>'90d',365=>'1 ano'] as $d => $label): ?>
            <a href="?page=revenue&days=<?php echo $d; ?>"
               style="padding:6px 14px;border-radius:8px;font-size:0.82rem;text-decoration:none;font-weight:600;
                      background:<?php echo $days===$d?'var(--primary)':'rgba(255,255,255,0.06)'; ?>;
                      color:<?php echo $days===$d?'#000':'inherit'; ?>;">
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Erro: <?php echo htmlspecialchars($dbError); ?></div>
    <?php endif; ?>

    <!-- ══ KPI Cards ════════════════════════════════════════════════════════ -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px;">

        <!-- Receita Créditos -->
        <div class="panel" style="border-top:3px solid #00c8ff;">
            <div class="panel-body" style="padding:16px 18px;">
                <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                    <i class="fas fa-coins" style="color:#00c8ff;margin-right:4px;"></i>Receita Créditos
                </div>
                <div style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;color:#00c8ff;">
                    <?php echo fmtBRL((float)$kpi['credits']['total_period']); ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-dim);margin-top:4px;">
                    <?php echo (int)$kpi['credits']['count_period']; ?> vendas · <?php echo $days; ?>d
                </div>
                <div style="font-size:0.7rem;color:var(--text-dim);margin-top:2px;">
                    Total geral: <?php echo fmtBRL((float)$kpi['credits']['total_all']); ?>
                </div>
            </div>
        </div>

        <!-- Receita Premium -->
        <div class="panel" style="border-top:3px solid #ffd700;">
            <div class="panel-body" style="padding:16px 18px;">
                <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                    <i class="fas fa-crown" style="color:#ffd700;margin-right:4px;"></i>Receita Premium
                </div>
                <div style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;color:#ffd700;">
                    <?php echo fmtBRL((float)$kpi['premium']['total_period']); ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-dim);margin-top:4px;">
                    <?php echo (int)$kpi['premium']['count_period']; ?> assinaturas · <?php echo $days; ?>d
                </div>
                <div style="font-size:0.7rem;color:var(--text-dim);margin-top:2px;">
                    Total geral: <?php echo fmtBRL((float)$kpi['premium']['total_all']); ?>
                </div>
            </div>
        </div>

        <!-- Receita Exploração -->
        <div class="panel" style="border-top:3px solid #ff8800;">
            <div class="panel-body" style="padding:16px 18px;">
                <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                    <i class="fas fa-rocket" style="color:#ff8800;margin-right:4px;"></i>Receita Exploração
                </div>
                <div style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;color:#ff8800;">
                    <?php echo fmtBRL((float)$kpi['exploration']['total_period']); ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-dim);margin-top:4px;">
                    <?php echo (int)$kpi['exploration']['count_period']; ?> aluguéis · <?php echo $days; ?>d
                </div>
                <div style="font-size:0.7rem;color:var(--text-dim);margin-top:2px;">
                    Total geral: <?php echo fmtBRL((float)$kpi['exploration']['total_all']); ?>
                </div>
            </div>
        </div>

        <!-- Saques Pagos -->
        <div class="panel" style="border-top:3px solid #ff3366;">
            <div class="panel-body" style="padding:16px 18px;">
                <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                    <i class="fas fa-money-bill-wave" style="color:#ff3366;margin-right:4px;"></i>Saques Pagos
                </div>
                <div style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;color:#ff3366;">
                    <?php echo fmtBRL((float)$kpi['withdrawals']['total_period']); ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-dim);margin-top:4px;">
                    <?php echo (int)$kpi['withdrawals']['count_period']; ?> saques · <?php echo $days; ?>d
                </div>
                <div style="font-size:0.7rem;color:var(--text-dim);margin-top:2px;">
                    Total geral: <?php echo fmtBRL((float)$kpi['withdrawals']['total_all']); ?>
                </div>
            </div>
        </div>

        <!-- Lucro Líquido -->
        <?php $netColor = $kpi['net_period'] >= 0 ? '#05ffa1' : '#ff3366'; ?>
        <div class="panel" style="border-top:3px solid <?php echo $netColor; ?>;">
            <div class="panel-body" style="padding:16px 18px;">
                <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                    <i class="fas fa-balance-scale" style="color:<?php echo $netColor; ?>;margin-right:4px;"></i>Resultado Líquido
                </div>
                <div style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;color:<?php echo $netColor; ?>;">
                    <?php echo fmtBRL($kpi['net_period']); ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-dim);margin-top:4px;">
                    Entradas − Saques · <?php echo $days; ?>d
                </div>
                <div style="font-size:0.7rem;color:var(--text-dim);margin-top:2px;">
                    Total geral: <?php echo fmtBRL($kpi['net_all']); ?>
                </div>
            </div>
        </div>

        <!-- Ticket médio créditos -->
        <?php $avgTicket = $kpi['credits']['count_period'] > 0 ? (float)$kpi['credits']['total_period'] / (int)$kpi['credits']['count_period'] : 0; ?>
        <div class="panel" style="border-top:3px solid #00c8ff40;">
            <div class="panel-body" style="padding:16px 18px;">
                <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                    <i class="fas fa-receipt" style="color:#00c8ff;margin-right:4px;"></i>Ticket Médio Créditos
                </div>
                <div style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;">
                    <?php echo fmtBRL($avgTicket); ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-dim);margin-top:4px;">por compra nos últimos <?php echo $days; ?>d</div>
            </div>
        </div>

        <!-- Ticket médio saques -->
        <?php $avgWd = $kpi['withdrawals']['count_period'] > 0 ? (float)$kpi['withdrawals']['total_period'] / (int)$kpi['withdrawals']['count_period'] : 0; ?>
        <div class="panel" style="border-top:3px solid #ff336640;">
            <div class="panel-body" style="padding:16px 18px;">
                <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                    <i class="fas fa-hand-holding-usd" style="color:#ff3366;margin-right:4px;"></i>Ticket Médio Saque
                </div>
                <div style="font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;">
                    <?php echo fmtBRL($avgWd); ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-dim);margin-top:4px;">por saque nos últimos <?php echo $days; ?>d</div>
            </div>
        </div>

    </div>

    <!-- ══ Gráfico de barras diário ════════════════════════════════════════ -->
    <div class="panel" style="margin-bottom:20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-chart-bar"></i> Receita Diária — últimos <?php echo $days; ?> dias</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($dailyData)): ?>
                <p style="color:var(--text-dim);text-align:center;padding:30px 0;">Sem dados no período.</p>
            <?php else:
                $maxBar = max(array_map(fn($r) => (float)$r['credits_brl'] + (float)$r['premium_brl'] + (float)$r['exploration_brl'], $dailyData)) ?: 1;
            ?>
            <div style="overflow-x:auto;">
                <div style="min-width:<?php echo max(600, count($dailyData)*32); ?>px;padding:0 4px;">
                    <!-- Barras -->
                    <div style="display:flex;align-items:flex-end;gap:3px;height:140px;margin-bottom:6px;">
                        <?php foreach ($dailyData as $row):
                            $cBrl = (float)$row['credits_brl'];
                            $pBrl = (float)$row['premium_brl'];
                            $eBrl = (float)$row['exploration_brl'];
                            $wBrl = (float)$row['withdrawal_brl'];
                            $inflow = $cBrl + $pBrl + $eBrl;
                            $cH  = round(($cBrl / $maxBar) * 120);
                            $pH  = round(($pBrl / $maxBar) * 120);
                            $eH  = round(($eBrl / $maxBar) * 120);
                            $wH  = round(($wBrl / $maxBar) * 120);
                        ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:0;min-width:20px;"
                             title="<?php echo $row['day']; ?> | Créditos: <?php echo fmtBRL($cBrl); ?> | Premium: <?php echo fmtBRL($pBrl); ?> | Exploração: <?php echo fmtBRL($eBrl); ?> | Saques: <?php echo fmtBRL($wBrl); ?>">
                            <!-- Saques (separado, abaixo) -->
                            <div style="width:100%;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-end;height:<?php echo max(2,$wH); ?>px;background:rgba(255,51,102,0.5);border-radius:2px 2px 0 0;margin-bottom:1px;"></div>
                            <!-- Entradas empilhadas -->
                            <div style="width:100%;display:flex;flex-direction:column;justify-content:flex-end;height:<?php echo max($inflow>0?2:0, $cH+$pH+$eH); ?>px;">
                                <?php if ($pH > 0): ?>
                                <div style="height:<?php echo $pH; ?>px;background:#ffd700;border-radius:2px 2px 0 0;"></div>
                                <?php endif; ?>
                                <?php if ($eH > 0): ?>
                                <div style="height:<?php echo $eH; ?>px;background:#ff8800;<?php echo $pH==0?'border-radius:2px 2px 0 0;':''; ?>"></div>
                                <?php endif; ?>
                                <?php if ($cH > 0): ?>
                                <div style="height:<?php echo $cH; ?>px;background:#00c8ff;<?php echo ($pH==0&&$eH==0)?'border-radius:2px 2px 0 0;':''; ?>"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Labels de datas -->
                    <div style="display:flex;gap:3px;">
                        <?php foreach ($dailyData as $row): ?>
                        <div style="flex:1;text-align:center;font-size:0.55rem;color:var(--text-dim);min-width:20px;overflow:hidden;white-space:nowrap;">
                            <?php echo date('d/m', strtotime($row['day'])); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Legenda -->
            <div style="display:flex;gap:16px;margin-top:12px;font-size:0.78rem;">
                <span><span style="display:inline-block;width:10px;height:10px;background:#00c8ff;border-radius:2px;margin-right:4px;"></span>Créditos</span>
                <span><span style="display:inline-block;width:10px;height:10px;background:#ffd700;border-radius:2px;margin-right:4px;"></span>Premium</span>
                <span><span style="display:inline-block;width:10px;height:10px;background:#ff8800;border-radius:2px;margin-right:4px;"></span>Exploração</span>
                <span><span style="display:inline-block;width:10px;height:10px;background:rgba(255,51,102,0.5);border-radius:2px;margin-right:4px;"></span>Saques</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ Créditos por Pacote + Status de Saques ══════════════════════════ -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

        <!-- Créditos por pacote -->
        <div class="panel">
            <div class="panel-header"><h3 class="panel-title"><i class="fas fa-coins"></i> Créditos por Pacote</h3></div>
            <div class="panel-body" style="padding:0;">
                <?php if (empty($creditByPackage)): ?>
                    <p style="padding:16px;color:var(--text-dim);">Sem dados.</p>
                <?php else:
                    $maxPkgRev = max(array_column($creditByPackage, 'revenue')) ?: 1;
                ?>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="font-size:0.72rem;color:var(--text-dim);border-bottom:1px solid rgba(255,255,255,0.06);">
                            <th style="padding:10px 16px;text-align:left;">Pacote</th>
                            <th style="padding:10px 8px;text-align:right;">Preço</th>
                            <th style="padding:10px 8px;text-align:right;">Vendas</th>
                            <th style="padding:10px 16px;text-align:right;">Receita</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($creditByPackage as $pkg):
                        $barW = round(((float)$pkg['revenue'] / $maxPkgRev) * 100);
                    ?>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.04);position:relative;">
                            <td style="padding:10px 16px;font-size:0.85rem;">
                                <div style="position:absolute;left:0;top:0;height:100%;width:<?php echo $barW; ?>%;background:rgba(0,200,255,0.06);pointer-events:none;"></div>
                                <span style="position:relative;"><?php echo htmlspecialchars($pkg['name'] ?: 'Pacote #' . $pkg['package_id'] ?? '?'); ?></span><br>
                                <small style="color:var(--text-dim);font-size:0.7rem;"><?php echo (int)$pkg['credits']; ?> créditos</small>
                            </td>
                            <td style="padding:10px 8px;text-align:right;font-size:0.82rem;"><?php echo fmtBRL((float)$pkg['pkg_price']); ?></td>
                            <td style="padding:10px 8px;text-align:right;font-weight:600;"><?php echo (int)$pkg['sales_count']; ?></td>
                            <td style="padding:10px 16px;text-align:right;font-weight:700;color:#00c8ff;"><?php echo fmtBRL((float)$pkg['revenue']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status de saques -->
        <div class="panel">
            <div class="panel-header"><h3 class="panel-title"><i class="fas fa-money-bill-wave"></i> Status dos Saques (geral)</h3></div>
            <div class="panel-body">
                <?php if (empty($wdByStatus)): ?>
                    <p style="color:var(--text-dim);">Sem dados.</p>
                <?php else:
                    $totalWd = array_sum(array_column($wdByStatus, 'cnt')) ?: 1;
                    $statusColors = ['completed'=>'#05ffa1','pending'=>'#ffaa00','rejected'=>'#ff3366','processing'=>'#00c8ff','cancelled'=>'#888'];
                    $statusLabels = ['completed'=>'Pago','pending'=>'Pendente','rejected'=>'Rejeitado','processing'=>'Processando','cancelled'=>'Cancelado'];
                ?>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($wdByStatus as $ws):
                        $color = $statusColors[$ws['status']] ?? '#888';
                        $label = $statusLabels[$ws['status']] ?? $ws['status'];
                        $pct   = round((int)$ws['cnt'] / $totalWd * 100);
                    ?>
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:4px;">
                            <span style="color:<?php echo $color; ?>;font-weight:600;"><?php echo $label; ?></span>
                            <span><?php echo (int)$ws['cnt']; ?> · <?php echo fmtBRL((float)$ws['total']); ?></span>
                        </div>
                        <div style="height:6px;background:rgba(255,255,255,0.06);border-radius:3px;overflow:hidden;">
                            <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;border-radius:3px;transition:width .3s;"></div>
                        </div>
                        <div style="font-size:0.68rem;color:var(--text-dim);margin-top:2px;"><?php echo $pct; ?>% do total</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ══ Premium por Duração + Premium por Mês ══════════════════════════ -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

        <!-- Premium por duração -->
        <div class="panel">
            <div class="panel-header"><h3 class="panel-title"><i class="fas fa-crown"></i> Premium por Duração</h3></div>
            <div class="panel-body" style="padding:0;">
                <?php if (empty($premByDuration)): ?>
                    <p style="padding:16px;color:var(--text-dim);">Sem assinaturas confirmadas.</p>
                <?php else: ?>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="font-size:0.72rem;color:var(--text-dim);border-bottom:1px solid rgba(255,255,255,0.06);">
                            <th style="padding:10px 16px;text-align:left;">Duração</th>
                            <th style="padding:10px 8px;text-align:right;">Vendas</th>
                            <th style="padding:10px 8px;text-align:right;">Preço Médio</th>
                            <th style="padding:10px 16px;text-align:right;">Receita</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($premByDuration as $pd): ?>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                            <td style="padding:10px 16px;font-size:0.85rem;font-weight:600;"><?php echo (int)$pd['duration_days']; ?> dias</td>
                            <td style="padding:10px 8px;text-align:right;"><?php echo (int)$pd['sales_count']; ?></td>
                            <td style="padding:10px 8px;text-align:right;font-size:0.82rem;"><?php echo fmtBRL((float)$pd['avg_price']); ?></td>
                            <td style="padding:10px 16px;text-align:right;font-weight:700;color:#ffd700;"><?php echo fmtBRL((float)$pd['revenue']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Premium por mês (mini gráfico) -->
        <div class="panel">
            <div class="panel-header"><h3 class="panel-title"><i class="fas fa-calendar-alt"></i> Premium por Mês (12m)</h3></div>
            <div class="panel-body">
                <?php if (empty($premByMonth)): ?>
                    <p style="color:var(--text-dim);">Sem dados.</p>
                <?php else:
                    $maxPremMonth = max(array_column($premByMonth, 'revenue')) ?: 1;
                ?>
                <div style="display:flex;align-items:flex-end;gap:4px;height:90px;margin-bottom:6px;">
                    <?php foreach ($premByMonth as $pm):
                        $h = round(((float)$pm['revenue'] / $maxPremMonth) * 80);
                    ?>
                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:2px;"
                         title="<?php echo $pm['month']; ?>: <?php echo (int)$pm['count']; ?> vendas · <?php echo fmtBRL((float)$pm['revenue']); ?>">
                        <div style="width:100%;height:<?php echo max(3,$h); ?>px;background:#ffd700;border-radius:3px 3px 0 0;opacity:0.85;"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:4px;">
                    <?php foreach ($premByMonth as $pm): ?>
                    <div style="flex:1;text-align:center;font-size:0.6rem;color:var(--text-dim);"><?php echo substr($pm['month'],5); ?></div>
                    <?php endforeach; ?>
                </div>
                <!-- Tabela resumida -->
                <div style="margin-top:12px;display:flex;flex-direction:column;gap:4px;max-height:140px;overflow-y:auto;">
                    <?php foreach (array_reverse($premByMonth) as $pm): ?>
                    <div style="display:flex;justify-content:space-between;font-size:0.78rem;padding:4px 6px;background:rgba(0,0,0,0.15);border-radius:6px;">
                        <span style="color:var(--text-dim);"><?php echo $pm['month']; ?></span>
                        <span><?php echo (int)$pm['count']; ?> vendas</span>
                        <span style="font-weight:600;color:#ffd700;"><?php echo fmtBRL((float)$pm['revenue']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ══ Top Compradores ════════════════════════════════════════════════ -->
    <?php if (!empty($topBuyers)): ?>
    <div class="panel" style="margin-bottom:20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-trophy"></i> Top Compradores — últimos <?php echo $days; ?>d</h3>
        </div>
        <div class="panel-body" style="padding:0;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="font-size:0.72rem;color:var(--text-dim);border-bottom:1px solid rgba(255,255,255,0.06);">
                        <th style="padding:10px 16px;text-align:left;">#</th>
                        <th style="padding:10px 8px;text-align:left;">Jogador</th>
                        <th style="padding:10px 8px;text-align:center;">Premium</th>
                        <th style="padding:10px 8px;text-align:right;">Compras</th>
                        <th style="padding:10px 16px;text-align:right;">Total Gasto</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topBuyers as $i => $buyer): ?>
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                        <td style="padding:10px 16px;font-size:0.85rem;color:var(--text-dim);"><?php echo $i+1; ?></td>
                        <td style="padding:10px 8px;">
                            <div style="font-weight:600;font-size:0.85rem;"><?php echo htmlspecialchars($buyer['display_name'] ?? 'N/A'); ?></div>
                            <div style="font-size:0.72rem;color:var(--text-dim);">
                                <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=account-analysis&email=<?php echo urlencode($buyer['email']); ?>"
                                   style="color:var(--text-dim);text-decoration:none;"><?php echo htmlspecialchars($buyer['email']); ?></a>
                            </div>
                        </td>
                        <td style="padding:10px 8px;text-align:center;">
                            <?php if ($buyer['is_premium']): ?>
                            <span class="badge" style="background:rgba(255,215,0,0.2);color:#ffd700;font-size:0.7rem;">PREMIUM</span>
                            <?php else: echo '—'; endif; ?>
                        </td>
                        <td style="padding:10px 8px;text-align:right;"><?php echo (int)$buyer['purchases']; ?></td>
                        <td style="padding:10px 16px;text-align:right;font-weight:700;font-size:0.9rem;color:var(--success);"><?php echo fmtBRL((float)$buyer['spent']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Últimas Vendas ════════════════════════════════════════════════ -->
    <div class="panel" style="margin-bottom:20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-receipt"></i> Últimas Compras (créditos + premium + exploração)</h3>
        </div>
        <div class="panel-body" style="padding:0;">
            <?php if (empty($recentSales)): ?>
                <p style="padding:16px;color:var(--text-dim);">Nenhuma compra encontrada.</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="font-size:0.72rem;color:var(--text-dim);border-bottom:1px solid rgba(255,255,255,0.06);">
                        <th style="padding:10px 16px;text-align:left;">Jogador</th>
                        <th style="padding:10px 8px;text-align:left;">Tipo</th>
                        <th style="padding:10px 8px;text-align:left;">Descrição</th>
                        <th style="padding:10px 8px;text-align:right;">Valor</th>
                        <th style="padding:10px 16px;text-align:right;">Data</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentSales as $sale):
                    $isCredit  = $sale['type'] === 'credit_purchase';
                    $isExploration = $sale['type'] === 'exploration_rent';
                    $typeColor = $isCredit ? '#00c8ff' : ($isExploration ? '#ff8800' : '#ffd700');
                    $typeLabel = $isCredit ? 'Créditos' : ($isExploration ? 'Exploração' : 'Premium');
                ?>
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.04);">
                        <td style="padding:10px 16px;">
                            <div style="font-weight:600;font-size:0.82rem;"><?php echo htmlspecialchars($sale['display_name'] ?? '—'); ?></div>
                            <div style="font-size:0.7rem;color:var(--text-dim);"><?php echo htmlspecialchars($sale['email'] ?? ''); ?></div>
                        </td>
                        <td style="padding:10px 8px;">
                            <span style="padding:3px 8px;background:<?php echo $typeColor; ?>20;color:<?php echo $typeColor; ?>;border-radius:6px;font-size:0.72rem;font-weight:600;">
                                <?php echo $typeLabel; ?>
                            </span>
                        </td>
                        <td style="padding:10px 8px;font-size:0.78rem;color:var(--text-dim);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php echo htmlspecialchars($sale['description'] ?? ''); ?>
                        </td>
                        <td style="padding:10px 8px;text-align:right;font-weight:700;color:var(--success);"><?php echo fmtBRL((float)$sale['amount_brl']); ?></td>
                        <td style="padding:10px 16px;text-align:right;font-size:0.78rem;color:var(--text-dim);"><?php echo date('d/m/y H:i', strtotime($sale['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>
