<?php
// ============================================
// UNOBIX - Dashboard de Métricas
// api/metrics-dashboard.php v1.0
// Endpoint para consultar métricas de performance
// ============================================

require_once __DIR__ . "/config.php";

// Desabilitar gravação de métricas para este endpoint (já excluído no metrics.php)
setCorsHeaders();

$input = getRequestInput();
$action = $input['action'] ?? 'summary';

// Autenticação: Bearer token ou admin session
$adminPassword = getenv('ADMIN_PASSWORD') ?: 'MUDE_ESTA_SENHA_123!';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$providedPassword = str_replace('Bearer ', '', $authHeader);

$isAdmin = ($providedPassword === $adminPassword);

// Também aceitar via session cookie do admin
if (!$isAdmin) {
    session_start();
    $isAdmin = !empty($_SESSION['admin_logged_in']);
}

if (!$isAdmin) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro de conexão");

    switch ($action) {

        // ============================================
        // RESUMO GERAL (últimos 60 min)
        // ============================================
        case 'summary':
            $minutes = (int)($input['minutes'] ?? 60);
            $minutes = max(5, min($minutes, 1440)); // 5 min a 24h

            $summary = metricsGetSummary($pdo, $minutes);

            // Total geral
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
            $stmt->execute([$minutes]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);

            // Extrapolação (métricas são amostradas a 10%)
            $sampleMultiplier = 100 / METRICS_SAMPLE_RATE;

            echo json_encode([
                'success' => true,
                'period_minutes' => $minutes,
                'sample_rate' => METRICS_SAMPLE_RATE . '%',
                'estimated_multiplier' => $sampleMultiplier,
                'totals' => [
                    'sampled_requests' => (int)$totals['total_requests'],
                    'estimated_requests' => (int)($totals['total_requests'] * $sampleMultiplier),
                    'avg_response_ms' => (float)$totals['avg_ms'],
                    'max_response_ms' => (float)$totals['max_ms'],
                    'errors' => (int)$totals['total_errors'],
                    'slow_requests_over_1s' => (int)$totals['slow_requests']
                ],
                'by_endpoint' => $summary
            ]);
            break;

        // ============================================
        // TIMELINE (gráfico temporal)
        // ============================================
        case 'timeline':
            $minutes = (int)($input['minutes'] ?? 60);
            $endpoint = $input['endpoint'] ?? null;
            $bucket = (int)($input['bucket'] ?? 5); // agrupamento em minutos

            $timeline = metricsGetTimeline($pdo, $endpoint, $minutes, $bucket);

            echo json_encode([
                'success' => true,
                'endpoint' => $endpoint ?: 'all',
                'period_minutes' => $minutes,
                'bucket_minutes' => $bucket,
                'data' => $timeline
            ]);
            break;

        // ============================================
        // REQUESTS LENTAS (> threshold)
        // ============================================
        case 'slow':
            $threshold = (float)($input['threshold_ms'] ?? 500);
            $limit = min((int)($input['limit'] ?? 50), 200);

            $stmt = $pdo->prepare("
                SELECT endpoint, method, response_time_ms, status_code, ip_address, created_at
                FROM api_metrics
                WHERE response_time_ms > ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY response_time_ms DESC
                LIMIT ?
            ");
            $stmt->execute([$threshold, $limit]);
            $slow = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'threshold_ms' => $threshold,
                'count' => count($slow),
                'requests' => $slow
            ]);
            break;

        // ============================================
        // CLEANUP (limpar métricas antigas)
        // ============================================
        case 'cleanup':
            metricsCleanup($pdo);
            echo json_encode(['success' => true, 'message' => 'Métricas antigas removidas']);
            break;

        // ============================================
        // STATUS RÁPIDO (para health check)
        // ============================================
        case 'health':
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as last_5min,
                    ROUND(AVG(response_time_ms), 2) as avg_ms
                FROM api_metrics
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $health = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'status' => ((float)$health['avg_ms'] < 500) ? 'healthy' : 'degraded',
                'last_5min' => [
                    'requests' => (int)$health['last_5min'],
                    'avg_ms' => (float)$health['avg_ms']
                ]
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida. Use: summary, timeline, slow, cleanup, health']);
    }

} catch (Throwable $e) {
    error_log("metrics-dashboard error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
}
