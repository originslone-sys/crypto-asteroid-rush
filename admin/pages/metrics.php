<?php
// ============================================
// UNOBIX - Métricas de Performance
// admin/pages/metrics.php v1.0
// Dashboard visual de performance da API
// ============================================

$pageTitle = 'Métricas';

// Período selecionado
$period = (int)($_GET['minutes'] ?? 60);
$period = max(5, min($period, 1440));

// Verificar se tabela existe
$metricsAvailable = false;
try {
    $check = $pdo->query("SHOW TABLES LIKE 'api_metrics'")->fetch();
    $metricsAvailable = (bool)$check;
} catch (Exception $e) {}

$summary = [];
$totals = ['total_requests' => 0, 'avg_ms' => 0, 'max_ms' => 0, 'total_errors' => 0, 'slow_requests' => 0];
$slowRequests = [];
$timeline = [];

if ($metricsAvailable) {
    try {
        // Resumo por endpoint
        $stmt = $pdo->prepare("
            SELECT
                endpoint,
                COUNT(*) as total_requests,
                ROUND(AVG(response_time_ms), 2) as avg_ms,
                ROUND(MIN(response_time_ms), 2) as min_ms,
                ROUND(MAX(response_time_ms), 2) as max_ms,
                SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as errors,
                SUM(CASE WHEN response_time_ms > 1000 THEN 1 ELSE 0 END) as slow
            FROM api_metrics
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            GROUP BY endpoint
            ORDER BY avg_ms DESC
        ");
        $stmt->execute([$period]);
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Totais gerais
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total_requests,
                ROUND(AVG(response_time_ms), 2) as avg_ms,
                ROUND(MAX(response_time_ms), 2) as max_ms,
                SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as total_errors,
                SUM(CASE WHEN response_time_ms > 1000 THEN 1 ELSE 0 END) as slow_requests
            FROM api_metrics
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$period]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: $totals;

        // Top 20 requests lentas
        $stmt = $pdo->prepare("
            SELECT endpoint, method, response_time_ms, status_code, ip_address, created_at
            FROM api_metrics
            WHERE response_time_ms > 500
            AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY response_time_ms DESC
            LIMIT 20
        ");
        $stmt->execute([$period]);
        $slowRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Timeline (buckets de 5 min)
        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(created_at, '%H:%i') as time_label,
                COUNT(*) as requests,
                ROUND(AVG(response_time_ms), 1) as avg_ms,
                ROUND(MAX(response_time_ms), 1) as max_ms
            FROM api_metrics
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            GROUP BY FLOOR(UNIX_TIMESTAMP(created_at) / 300)
            ORDER BY MIN(created_at) ASC
        ");
        $stmt->execute([$period]);
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $metricsError = $e->getMessage();
    }
}

$sampleMultiplier = defined('METRICS_SAMPLE_RATE') ? (100 / METRICS_SAMPLE_RATE) : 10;

// Helper: cor baseada no tempo de resposta
function msColor($ms) {
    if ($ms < 100) return '#00e5cc';   // verde
    if ($ms < 300) return '#00f0ff';   // azul
    if ($ms < 500) return '#ffa502';   // amarelo
    if ($ms < 1000) return '#ff6348';  // laranja
    return '#ff4757';                   // vermelho
}

function msLabel($ms) {
    if ($ms < 100) return 'Excelente';
    if ($ms < 300) return 'Bom';
    if ($ms < 500) return 'OK';
    if ($ms < 1000) return 'Lento';
    return 'Critico';
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-tachometer-alt"></i> Metricas de Performance</h1>
        <p class="page-subtitle">Monitoramento em tempo real da API</p>
    </div>

    <!-- Filtro de período -->
    <div style="margin-bottom: 20px; display: flex; gap: 8px; flex-wrap: wrap;">
        <?php
        $periods = [
            15 => '15 min',
            30 => '30 min',
            60 => '1 hora',
            180 => '3 horas',
            360 => '6 horas',
            720 => '12 horas',
            1440 => '24 horas'
        ];
        foreach ($periods as $min => $label):
        ?>
            <a href="?page=metrics&minutes=<?php echo $min; ?>"
               class="btn <?php echo $period == $min ? 'btn-primary' : 'btn-secondary'; ?>"
               style="padding: 6px 14px; font-size: 0.85rem; text-decoration: none;">
                <?php echo $label; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$metricsAvailable): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Nenhuma metrica coletada ainda. As metricas aparecerao automaticamente apos as primeiras requests da API.
            <br><small>Taxa de amostragem: <?php echo defined('METRICS_SAMPLE_RATE') ? METRICS_SAMPLE_RATE : 10; ?>% das requests sao gravadas.</small>
        </div>
    <?php else: ?>

    <!-- Cards de resumo -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-exchange-alt"></i></div>
            <div class="value"><?php echo number_format(($totals['total_requests'] ?? 0) * $sampleMultiplier); ?></div>
            <div class="label">Requests Estimadas</div>
            <div class="change"><?php echo number_format($totals['total_requests'] ?? 0); ?> amostradas (<?php echo defined('METRICS_SAMPLE_RATE') ? METRICS_SAMPLE_RATE : 10; ?>%)</div>
        </div>

        <div class="stat-card">
            <div class="icon" style="background: <?php echo msColor($totals['avg_ms']); ?>20; color: <?php echo msColor($totals['avg_ms']); ?>;">
                <i class="fas fa-clock"></i>
            </div>
            <div class="value" style="color: <?php echo msColor($totals['avg_ms']); ?>;"><?php echo number_format($totals['avg_ms'] ?? 0, 1); ?>ms</div>
            <div class="label">Tempo Medio</div>
            <div class="change"><?php echo msLabel($totals['avg_ms']); ?></div>
        </div>

        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-bolt"></i></div>
            <div class="value"><?php echo number_format($totals['max_ms'] ?? 0, 0); ?>ms</div>
            <div class="label">Pico Maximo</div>
            <div class="change">Request mais lenta no periodo</div>
        </div>

        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="value"><?php echo (int)($totals['total_errors'] ?? 0); ?></div>
            <div class="label">Erros (5xx)</div>
            <div class="change"><?php echo (int)($totals['slow_requests'] ?? 0); ?> requests > 1s</div>
        </div>
    </div>

    <!-- Gráfico Timeline -->
    <?php if (!empty($timeline)): ?>
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-chart-area"></i> Timeline - Tempo de Resposta</h3>
        </div>
        <div class="panel-body">
            <div style="display: flex; align-items: flex-end; gap: 2px; height: 200px; padding: 10px 0; overflow-x: auto;">
                <?php
                $maxMs = max(array_column($timeline, 'avg_ms'));
                if ($maxMs < 1) $maxMs = 1;
                foreach ($timeline as $point):
                    $heightPercent = min(100, ($point['avg_ms'] / $maxMs) * 100);
                    $color = msColor($point['avg_ms']);
                ?>
                    <div style="display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 30px;" title="<?php echo $point['time_label']; ?> - Avg: <?php echo $point['avg_ms']; ?>ms | Max: <?php echo $point['max_ms']; ?>ms | <?php echo $point['requests']; ?> req">
                        <div style="font-size: 0.6rem; color: var(--text-dim); margin-bottom: 4px;"><?php echo $point['avg_ms']; ?></div>
                        <div style="width: 100%; max-width: 40px; height: <?php echo max(4, $heightPercent); ?>%; background: <?php echo $color; ?>; border-radius: 4px 4px 0 0; opacity: 0.85; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.85'"></div>
                        <div style="font-size: 0.6rem; color: var(--text-dim); margin-top: 4px; white-space: nowrap;"><?php echo $point['time_label']; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 10px 0 0; border-top: 1px solid rgba(255,255,255,0.1); margin-top: 10px;">
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <span style="font-size: 0.75rem;"><span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #00e5cc; margin-right: 4px;"></span> &lt;100ms</span>
                    <span style="font-size: 0.75rem;"><span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #00f0ff; margin-right: 4px;"></span> &lt;300ms</span>
                    <span style="font-size: 0.75rem;"><span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #ffa502; margin-right: 4px;"></span> &lt;500ms</span>
                    <span style="font-size: 0.75rem;"><span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: #ff4757; margin-right: 4px;"></span> &gt;1s</span>
                </div>
                <span style="font-size: 0.75rem; color: var(--text-dim);">Valores em ms (media por bloco de 5 min)</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabela por Endpoint -->
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Performance por Endpoint</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($summary)): ?>
                <p style="color: var(--text-dim); text-align: center; padding: 20px;">Nenhuma metrica no periodo selecionado.</p>
            <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align: left;">Endpoint</th>
                            <th style="text-align: right;">Requests</th>
                            <th style="text-align: right;">Media</th>
                            <th style="text-align: right;">Min</th>
                            <th style="text-align: right;">Max</th>
                            <th style="text-align: right;">Erros</th>
                            <th style="text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary as $row): ?>
                        <tr>
                            <td>
                                <code style="background: rgba(0,240,255,0.1); padding: 2px 8px; border-radius: 4px; font-size: 0.85rem;">
                                    <?php echo htmlspecialchars($row['endpoint']); ?>
                                </code>
                            </td>
                            <td style="text-align: right;"><?php echo number_format($row['total_requests']); ?></td>
                            <td style="text-align: right; font-weight: 600; color: <?php echo msColor($row['avg_ms']); ?>;">
                                <?php echo number_format($row['avg_ms'], 1); ?>ms
                            </td>
                            <td style="text-align: right; color: var(--text-dim);"><?php echo number_format($row['min_ms'], 1); ?>ms</td>
                            <td style="text-align: right; color: <?php echo msColor($row['max_ms']); ?>;"><?php echo number_format($row['max_ms'], 0); ?>ms</td>
                            <td style="text-align: right; <?php echo ($row['errors'] > 0) ? 'color: var(--danger); font-weight: 600;' : 'color: var(--text-dim);'; ?>">
                                <?php echo (int)$row['errors']; ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; background: <?php echo msColor($row['avg_ms']); ?>20; color: <?php echo msColor($row['avg_ms']); ?>;">
                                    <?php echo msLabel($row['avg_ms']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Requests Lentas -->
    <?php if (!empty($slowRequests)): ?>
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-exclamation-circle" style="color: var(--danger);"></i> Requests Lentas (&gt;500ms)</h3>
        </div>
        <div class="panel-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align: left;">Endpoint</th>
                            <th>Metodo</th>
                            <th style="text-align: right;">Tempo</th>
                            <th>Status</th>
                            <th>IP</th>
                            <th>Data/Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slowRequests as $req): ?>
                        <tr>
                            <td>
                                <code style="background: rgba(255,71,87,0.1); padding: 2px 8px; border-radius: 4px; font-size: 0.85rem;">
                                    <?php echo htmlspecialchars($req['endpoint']); ?>
                                </code>
                            </td>
                            <td style="text-align: center;"><?php echo htmlspecialchars($req['method']); ?></td>
                            <td style="text-align: right; font-weight: 700; color: <?php echo msColor($req['response_time_ms']); ?>;">
                                <?php echo number_format($req['response_time_ms'], 0); ?>ms
                            </td>
                            <td style="text-align: center;">
                                <span style="padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; <?php echo ($req['status_code'] >= 500) ? 'background: rgba(255,71,87,0.2); color: var(--danger);' : 'background: rgba(0,229,204,0.1); color: var(--success);'; ?>">
                                    <?php echo (int)$req['status_code']; ?>
                                </span>
                            </td>
                            <td style="font-size: 0.8rem; color: var(--text-dim);"><?php echo htmlspecialchars($req['ip_address']); ?></td>
                            <td style="font-size: 0.8rem; color: var(--text-dim);"><?php echo $req['created_at']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Info box -->
    <div style="margin-top: 20px; padding: 15px; background: rgba(0,240,255,0.05); border: 1px solid rgba(0,240,255,0.15); border-radius: 10px;">
        <div style="font-size: 0.85rem; color: var(--text-dim);">
            <i class="fas fa-info-circle" style="color: var(--primary); margin-right: 6px;"></i>
            <strong>Como funciona:</strong> <?php echo defined('METRICS_SAMPLE_RATE') ? METRICS_SAMPLE_RATE : 10; ?>% das requests sao amostradas automaticamente.
            Os valores de "Requests Estimadas" sao multiplicados por <?php echo (int)$sampleMultiplier; ?>x.
            Metricas sao limpas apos 24 horas.
            <br>
            <i class="fas fa-palette" style="color: var(--primary); margin-right: 6px; margin-top: 4px;"></i>
            <strong>Cores:</strong>
            <span style="color: #00e5cc;">Verde</span> &lt;100ms |
            <span style="color: #00f0ff;">Azul</span> &lt;300ms |
            <span style="color: #ffa502;">Amarelo</span> &lt;500ms |
            <span style="color: #ff6348;">Laranja</span> &lt;1s |
            <span style="color: #ff4757;">Vermelho</span> &gt;1s
        </div>
    </div>

    <?php endif; // metricsAvailable ?>
</div>
