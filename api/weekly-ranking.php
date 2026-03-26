<?php
/**
 * API Ranking Semanal - Top 50 destruidores de asteroides
 * Endpoint público (sem autenticação)
 * Período: Segunda 00:00 até Domingo 22:00 (America/Sao_Paulo)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

// Calcular período da semana atual
// Segunda 00:00 até Domingo 22:00 (America/Sao_Paulo)
$now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$dayOfWeek = (int)$now->format('N'); // 1=segunda, 7=domingo

// Início: segunda-feira 00:00 desta semana
$weekStart = clone $now;
$weekStart->modify('monday this week');
$weekStart->setTime(0, 0, 0);

// Fim: domingo 22:00 desta semana
$weekEnd = clone $now;
$weekEnd->modify('sunday this week');
$weekEnd->setTime(22, 0, 0);

// Se já passou domingo 22h, o desafio encerrou
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

// Extrair apenas primeiro nome para privacidade
foreach ($ranking as &$row) {
    $fullName = $row['display_name'] ?? 'Jogador';
    $parts = explode(' ', trim($fullName));
    $row['display_name'] = $parts[0] ?: 'Jogador';
    $row['total_asteroids'] = (int)$row['total_asteroids'];
    $row['total_missions'] = (int)$row['total_missions'];
    $row['total_common'] = (int)$row['total_common'];
    $row['total_rare'] = (int)$row['total_rare'];
    $row['total_epic'] = (int)$row['total_epic'];
    $row['total_legendary'] = (int)$row['total_legendary'];
}
unset($row);

// Buscar vencedores da semana anterior (se o desafio atual já começou)
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
    $parts = explode(' ', trim($row['display_name'] ?? 'Jogador'));
    $row['display_name'] = $parts[0] ?: 'Jogador';
    $row['total_asteroids'] = (int)$row['total_asteroids'];
}
unset($row);

$prizes = [100, 50, 30, 20, 10];

echo json_encode([
    'success' => true,
    'challenge_active' => $challengeActive,
    'week_start' => $weekStart->format('Y-m-d H:i:s'),
    'week_end' => $weekEnd->format('Y-m-d H:i:s'),
    'server_time' => $now->format('Y-m-d H:i:s'),
    'ranking' => $ranking,
    'previous_winners' => $previousWinners,
    'prizes' => $prizes
]);
