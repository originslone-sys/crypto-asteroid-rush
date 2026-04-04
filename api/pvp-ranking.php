<?php
/**
 * API Ranking PvP Semanal - Top 50 jogadores PvP
 * Endpoint público (sem autenticação)
 * Período: Segunda 00:00 até Domingo 22:00 (America/Sao_Paulo)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Rate limit simples por IP
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = sys_get_temp_dir() . '/rate_pvpr_' . md5($clientIp);
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

$pdo = null;
try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    // Criar tabela de cache PvP se não existir
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pvp_ranking_cache (
                cache_key VARCHAR(50) NOT NULL PRIMARY KEY,
                cache_value MEDIUMTEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) { /* já existe */ }

    // Tentar ler do cache (60 segundos)
    $cacheStmt = $pdo->prepare("
        SELECT cache_value, updated_at FROM pvp_ranking_cache
        WHERE cache_key = 'pvp_weekly_ranking' AND updated_at > DATE_SUB(NOW(), INTERVAL 300 SECOND)
        LIMIT 1
    ");
    $cacheStmt->execute();
    $cached = $cacheStmt->fetch(PDO::FETCH_ASSOC);

    if ($cached) {
        echo $cached['cache_value'];
        exit;
    }

    // Calcular período da semana atual
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $weekStart = clone $now;
    $weekStart->modify('monday this week');
    $weekStart->setTime(0, 0, 0);
    $weekEnd = clone $now;
    $weekEnd->modify('sunday this week');
    $weekEnd->setTime(22, 0, 0);

    $challengeActive = $now < $weekEnd;

    // Buscar top 50 PvP da semana (por vitórias)
    $stmt = $pdo->prepare("
        SELECT
            u.display_name,
            COUNT(CASE WHEN pm.winner_google_uid = u.google_uid THEN 1 END) as wins,
            COUNT(CASE WHEN pm.winner_google_uid IS NOT NULL AND pm.winner_google_uid != u.google_uid THEN 1 END) as losses,
            COUNT(CASE WHEN pm.win_condition = 'draw' THEN 1 END) as draws,
            COUNT(pm.id) as total_matches,
            SUM(
                CASE
                    WHEN pm.player1_google_uid = u.google_uid THEN pm.player1_asteroids_destroyed
                    ELSE pm.player2_asteroids_destroyed
                END
            ) as total_asteroids,
            SUM(
                CASE
                    WHEN pm.player1_google_uid = u.google_uid THEN pm.player1_hits
                    ELSE pm.player2_hits
                END
            ) as total_hits
        FROM users u
        INNER JOIN pvp_matches pm ON (pm.player1_google_uid = u.google_uid OR pm.player2_google_uid = u.google_uid)
        WHERE pm.status = 'completed'
          AND pm.ended_at >= ?
          AND pm.ended_at <= ?
        GROUP BY u.google_uid
        HAVING total_matches > 0
        ORDER BY wins DESC, total_asteroids DESC
        LIMIT 50
    ");

    $stmt->execute([
        $weekStart->format('Y-m-d H:i:s'),
        $weekEnd->format('Y-m-d H:i:s')
    ]);

    $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ranking as &$row) {
        $row['display_name'] = trim($row['display_name'] ?? '') ?: 'Jogador';
        $row['wins'] = (int)$row['wins'];
        $row['losses'] = (int)$row['losses'];
        $row['draws'] = (int)$row['draws'];
        $row['total_matches'] = (int)$row['total_matches'];
        $row['total_asteroids'] = (int)$row['total_asteroids'];
        $row['total_hits'] = (int)$row['total_hits'];
        $row['win_rate'] = $row['total_matches'] > 0
            ? round(($row['wins'] / $row['total_matches']) * 100, 1)
            : 0;
    }
    unset($row);

    // Carregar prêmios configurados pelo admin (em BRL)
    $prizes = [];
    try {
        $prizeStmt = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key LIKE 'pvp_ranking_prize_%_brl' ORDER BY setting_key");
        $prizeMap = [];
        while ($row = $prizeStmt->fetch()) { $prizeMap[$row['setting_key']] = (float)$row['setting_value']; }
        $prizes = [
            $prizeMap['pvp_ranking_prize_1_brl'] ?? 50,
            $prizeMap['pvp_ranking_prize_2_brl'] ?? 30,
            $prizeMap['pvp_ranking_prize_3_brl'] ?? 20,
            $prizeMap['pvp_ranking_prize_4_brl'] ?? 10,
            $prizeMap['pvp_ranking_prize_5_brl'] ?? 5,
        ];
    } catch (Exception $e) {
        $prizes = [50, 30, 20, 10, 5];
    }

    $response = json_encode([
        'success' => true,
        'mode' => 'pvp',
        'challenge_active' => $challengeActive,
        'week_start' => $weekStart->format('Y-m-d H:i:s'),
        'week_end' => $weekEnd->format('Y-m-d H:i:s'),
        'server_time' => $now->format('Y-m-d H:i:s'),
        'ranking' => $ranking,
        'prizes' => $prizes
    ]);

    // Salvar cache
    try {
        $pdo->prepare("
            INSERT INTO pvp_ranking_cache (cache_key, cache_value, updated_at) VALUES ('pvp_weekly_ranking', ?, NOW())
            ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), updated_at = NOW()
        ")->execute([$response]);
    } catch (Exception $e) { /* cache fail ok */ }

    echo $response;

} catch (Throwable $e) {
    error_log("❌ PVP-RANKING ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
