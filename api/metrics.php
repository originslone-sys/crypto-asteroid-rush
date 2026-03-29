<?php
// ============================================
// UNOBIX - Performance Metrics
// api/metrics.php v1.0
// Mede tempo de resposta por endpoint
// ============================================

if (!defined('METRICS_START_TIME')) {
    define('METRICS_START_TIME', microtime(true));
}

// Amostragem: só grava 10% das requests para não sobrecarregar
if (!defined('METRICS_SAMPLE_RATE')) {
    define('METRICS_SAMPLE_RATE', 10); // porcentagem
}

if (!function_exists('metricsGetEndpoint')) {
    function metricsGetEndpoint() {
        $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        return basename($script, '.php');
    }
}

if (!function_exists('metricsRecord')) {
    /**
     * Registra métrica de performance no banco
     * Chamado automaticamente via shutdown function
     */
    function metricsRecord() {
        // Amostragem
        if (mt_rand(1, 100) > METRICS_SAMPLE_RATE) return;

        $elapsed = round((microtime(true) - METRICS_START_TIME) * 1000, 2); // ms
        $endpoint = metricsGetEndpoint();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $statusCode = http_response_code() ?: 200;

        // Ignorar endpoints de métricas e health check
        if (in_array($endpoint, ['metrics', 'metrics-dashboard', 'db-test'])) return;

        try {
            $pdo = getDatabaseConnection();
            if (!$pdo) return;

            // Tabela api_metrics criada via migrate.php no deploy

            $stmt = $pdo->prepare("
                INSERT INTO api_metrics (endpoint, method, response_time_ms, status_code, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $endpoint,
                $method,
                $elapsed,
                $statusCode,
                getClientIP()
            ]);

        } catch (Throwable $e) {
            // Nunca falhar a request por causa de métricas
            error_log("metrics error: " . $e->getMessage());
        }
    }
}

// Registrar shutdown function para gravar métrica ao final da request
register_shutdown_function('metricsRecord');

// ============================================
// FUNÇÕES DE CONSULTA (para admin)
// ============================================

if (!function_exists('metricsGetSummary')) {
    /**
     * Retorna resumo de métricas dos últimos N minutos
     */
    function metricsGetSummary($pdo, $minutes = 60) {
        $stmt = $pdo->prepare("
            SELECT
                endpoint,
                COUNT(*) as total_requests,
                ROUND(AVG(response_time_ms), 2) as avg_ms,
                ROUND(MIN(response_time_ms), 2) as min_ms,
                ROUND(MAX(response_time_ms), 2) as max_ms,
                ROUND(PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time_ms), 2) as p95_ms,
                SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as errors
            FROM api_metrics
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            GROUP BY endpoint
            ORDER BY avg_ms DESC
        ");

        // MySQL < 8.0.2 não tem PERCENTILE_CONT, usar fallback
        try {
            $stmt->execute([$minutes]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Fallback sem percentil
            $stmt = $pdo->prepare("
                SELECT
                    endpoint,
                    COUNT(*) as total_requests,
                    ROUND(AVG(response_time_ms), 2) as avg_ms,
                    ROUND(MIN(response_time_ms), 2) as min_ms,
                    ROUND(MAX(response_time_ms), 2) as max_ms,
                    ROUND(AVG(response_time_ms), 2) as p95_ms,
                    SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as errors
                FROM api_metrics
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                GROUP BY endpoint
                ORDER BY avg_ms DESC
            ");
            $stmt->execute([$minutes]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

if (!function_exists('metricsGetTimeline')) {
    /**
     * Retorna timeline de métricas agrupada por intervalo
     */
    function metricsGetTimeline($pdo, $endpoint = null, $minutes = 60, $bucketMinutes = 5) {
        $where = "WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)";
        $params = [$minutes];

        if ($endpoint) {
            $where .= " AND endpoint = ?";
            $params[] = $endpoint;
        }

        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(
                    DATE_SUB(created_at, INTERVAL MOD(MINUTE(created_at), ?) MINUTE),
                    '%Y-%m-%d %H:%i:00'
                ) as time_bucket,
                COUNT(*) as requests,
                ROUND(AVG(response_time_ms), 2) as avg_ms,
                ROUND(MAX(response_time_ms), 2) as max_ms
            {$where}
            GROUP BY time_bucket
            ORDER BY time_bucket ASC
        ");

        // Prepend bucket param
        array_unshift($params, $bucketMinutes);

        // MySQL needs FROM
        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(
                    DATE_SUB(created_at, INTERVAL MOD(MINUTE(created_at), ?) MINUTE),
                    '%Y-%m-%d %H:%i:00'
                ) as time_bucket,
                COUNT(*) as requests,
                ROUND(AVG(response_time_ms), 2) as avg_ms,
                ROUND(MAX(response_time_ms), 2) as max_ms
            FROM api_metrics
            {$where}
            GROUP BY time_bucket
            ORDER BY time_bucket ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('metricsCleanup')) {
    /**
     * Limpar métricas antigas (manter últimas 24h)
     */
    function metricsCleanup($pdo) {
        $pdo->exec("
            DELETE FROM api_metrics
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            LIMIT 10000
        ");
    }
}
