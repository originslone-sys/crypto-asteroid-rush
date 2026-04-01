<?php
/**
 * API Ranking Semanal - Top 50 destruidores de asteroides
 * Endpoint público (sem autenticação)
 * Período: Segunda 00:00 até Domingo 22:00 (America/Sao_Paulo)
 * v2.0 - Cache em arquivo + índice otimizado
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Rate limit simples por IP - máx 30 requisições por minuto
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = sys_get_temp_dir() . '/rate_wr_' . md5($clientIp);
$now = time();
$requests = [];

if (file_exists($rateLimitFile)) {
    $requests = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    $requests = array_filter($requests, fn($t) => $t > $now - 60);
}

if (count($requests) >= 30) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Muitas requisições. Tente novamente em 1 minuto.']);
    exit;
}

$requests[] = $now;
file_put_contents($rateLimitFile, json_encode($requests));

require_once __DIR__ . '/config.php';

// Cache: ranking muda pouco, cachear por 60 segundos
// Usa banco para cache compartilhado entre instâncias Cloud Run
$pdo = null;
try {
    $pdo = getDbConnection();

    // Criar tabela de cache se não existir (1x, depois fica em cache estático)
    static $cacheTableChecked = false;
    if (!$cacheTableChecked) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ranking_cache (
                    cache_key VARCHAR(50) NOT NULL PRIMARY KEY,
                    cache_value MEDIUMTEXT NOT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) { /* já existe */ }
        $cacheTableChecked = true;
    }

    // Tentar ler do cache
    $cacheStmt = $pdo->prepare("
        SELECT cache_value, updated_at FROM ranking_cache
        WHERE cache_key = 'weekly_ranking' AND updated_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
        LIMIT 1
    ");
    $cacheStmt->execute();
    $cached = $cacheStmt->fetch(PDO::FETCH_ASSOC);

    if ($cached) {
        echo $cached['cache_value'];
        exit;
    }
} catch (Exception $e) {
    // Se cache falhar, continua para gerar o ranking
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }
}

// Índice idx_ranking criado via migrate.php no deploy

// Calcular período da semana atual
$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

$weekStart = clone $now;
$weekStart->modify('monday this week');
$weekStart->setTime(0, 0, 0);

$weekEnd = clone $now;
$weekEnd->modify('sunday this week');
$weekEnd->setTime(22, 0, 0);

$challengeActive = $now < $weekEnd;

// Buscar top 50 da semana
$stmt = $pdo->prepare("
    SELECT
        u.display_name,
        SUM(gs.asteroids_destroyed) as total_asteroids,
        COUNT(gs.id) as total_missions,
        SUM(gs.common_asteroids) as total_common,
        SUM(gs.rare_asteroids) as total_rare,
        SUM(gs.epic_asteroids) as total_epic,
        SUM(gs.legendary_asteroids) as total_legendary
    FROM game_sessions gs
    INNER JOIN users u ON gs.google_uid = u.google_uid
    WHERE gs.status = 'completed'
      AND gs.started_at >= ?
      AND gs.started_at <= ?
    GROUP BY gs.google_uid
    HAVING total_asteroids > 0
    ORDER BY total_asteroids DESC
    LIMIT 50
");

$stmt->execute([
    $weekStart->format('Y-m-d H:i:s'),
    $weekEnd->format('Y-m-d H:i:s')
]);

$ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($ranking as &$row) {
    $row['display_name'] = trim($row['display_name'] ?? '') ?: 'Jogador';
    $row['total_asteroids'] = (int)$row['total_asteroids'];
    $row['total_missions'] = (int)$row['total_missions'];
    $row['total_common'] = (int)$row['total_common'];
    $row['total_rare'] = (int)$row['total_rare'];
    $row['total_epic'] = (int)$row['total_epic'];
    $row['total_legendary'] = (int)$row['total_legendary'];
}
unset($row);

// Buscar vencedores da semana anterior (cache 10 min no banco)
$previousWinners = [];

$prevCached = false;
try {
    $prevStmt = $pdo->prepare("
        SELECT cache_value FROM ranking_cache
        WHERE cache_key = 'prev_ranking' AND updated_at > DATE_SUB(NOW(), INTERVAL 600 SECOND)
        LIMIT 1
    ");
    $prevStmt->execute();
    $prevCache = $prevStmt->fetch(PDO::FETCH_ASSOC);
    if ($prevCache) {
        $previousWinners = json_decode($prevCache['cache_value'], true) ?: [];
        $prevCached = true;
    }
} catch (Exception $e) { /* continua sem cache */ }

if (!$prevCached) {
    $prevWeekStart = clone $weekStart;
    $prevWeekStart->modify('-7 days');
    $prevWeekEnd = clone $weekEnd;
    $prevWeekEnd->modify('-7 days');

    $stmtPrev = $pdo->prepare("
        SELECT
            u.display_name,
            SUM(gs.asteroids_destroyed) as total_asteroids
        FROM game_sessions gs
        INNER JOIN users u ON gs.google_uid = u.google_uid
        WHERE gs.status = 'completed'
          AND gs.started_at >= ?
          AND gs.started_at <= ?
        GROUP BY gs.google_uid
        HAVING total_asteroids > 0
        ORDER BY total_asteroids DESC
        LIMIT 5
    ");

    $stmtPrev->execute([
        $prevWeekStart->format('Y-m-d H:i:s'),
        $prevWeekEnd->format('Y-m-d H:i:s')
    ]);

    $previousWinners = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
    foreach ($previousWinners as &$row) {
        $row['display_name'] = trim($row['display_name'] ?? '') ?: 'Jogador';
        $row['total_asteroids'] = (int)$row['total_asteroids'];
    }
    unset($row);

    try {
        $pdo->prepare("
            INSERT INTO ranking_cache (cache_key, cache_value, updated_at) VALUES ('prev_ranking', ?, NOW())
            ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), updated_at = NOW()
        ")->execute([json_encode($previousWinners)]);
    } catch (Exception $e) { /* cache fail is ok */ }
}

$prizes = [100, 50, 30, 20, 10];

$response = json_encode([
    'success' => true,
    'challenge_active' => $challengeActive,
    'week_start' => $weekStart->format('Y-m-d H:i:s'),
    'week_end' => $weekEnd->format('Y-m-d H:i:s'),
    'server_time' => $now->format('Y-m-d H:i:s'),
    'ranking' => $ranking,
    'previous_winners' => $previousWinners,
    'prizes' => $prizes
]);

// Salvar cache no banco (compartilhado entre instâncias)
try {
    $pdo->prepare("
        INSERT INTO ranking_cache (cache_key, cache_value, updated_at) VALUES ('weekly_ranking', ?, NOW())
        ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), updated_at = NOW()
    ")->execute([$response]);
} catch (Exception $e) { /* cache fail is ok */ }

echo $response;
