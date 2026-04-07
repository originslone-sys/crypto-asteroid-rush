<?php
// ============================================
// UNOBIX - Fila de Saques (Público, Read-Only)
// api/withdrawal-queue.php
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

header('Content-Type: application/json; charset=utf-8');

// Rate limit simples por IP - TEMPORARIAMENTE DESATIVADO
// $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// $rateLimitFile = sys_get_temp_dir() . '/rate_wq_' . md5($clientIp);
// $now = time();
// $requests = [];
// if (file_exists($rateLimitFile)) {
//     $requests = json_decode(file_get_contents($rateLimitFile), true) ?: [];
//     $requests = array_filter($requests, fn($t) => $t > $now - 60);
// }
// if (count($requests) >= 30) {
//     http_response_code(429);
//     echo json_encode(['success' => false, 'error' => 'Muitas requisições. Tente novamente em 1 minuto.']);
//     exit;
// }
// $requests[] = $now;
// file_put_contents($rateLimitFile, json_encode($requests));

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $status = $_GET['status'] ?? 'all';
    $limit = 100;
    $offset = ($page - 1) * $limit;

    $allowedStatuses = ['pending', 'processing', 'completed', 'rejected', 'under_review', 'all'];
    if (!in_array($status, $allowedStatuses)) {
        $status = 'all';
    }

    // Contadores por status
    $counts = [];
    $countStmt = $pdo->query("
        SELECT
            SUM(status = 'pending') as pending,
            SUM(status = 'processing') as processing,
            SUM(status = 'under_review') as under_review,
            SUM(status = 'completed') as completed,
            SUM(status = 'rejected') as rejected,
            COUNT(*) as total
        FROM withdrawals
    ");
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

    // Velocidade média: completados nas últimas 24h
    $speedStmt = $pdo->query("
        SELECT COUNT(*) as completed_24h
        FROM withdrawals
        WHERE status = 'completed'
        AND processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $speedData = $speedStmt->fetch(PDO::FETCH_ASSOC);
    $completed24h = (int)$speedData['completed_24h'];

    // Velocidade média por hora
    $avgPerHour = round($completed24h / 24, 1);

    // Estimativa de tempo para processar pendentes
    $pendingCount = (int)$counts['pending'];
    $estimatedHours = null;
    if ($avgPerHour > 0 && $pendingCount > 0) {
        $estimatedHours = round($pendingCount / $avgPerHour, 1);
    }

    // Buscar solicitações com filtro
    $whereClause = '';
    $params = [];
    $searchId = (int)($_GET['search_id'] ?? 0);

    if ($searchId > 0) {
        $whereClause = 'WHERE w.id = ?';
        $params[] = $searchId;
    } elseif ($status !== 'all') {
        $whereClause = 'WHERE w.status = ?';
        $params[] = $status;
    }

    $countQuery = $pdo->prepare("SELECT COUNT(*) FROM withdrawals w {$whereClause}");
    $countQuery->execute($params);
    $totalFiltered = (int)$countQuery->fetchColumn();
    $totalPages = max(1, ceil($totalFiltered / $limit));

    $query = $pdo->prepare("
        SELECT
            w.id,
            u.display_name,
            w.amount_brl,
            w.status,
            w.created_at,
            w.processed_at,
            w.wallet_address as method
        FROM withdrawals w
        LEFT JOIN users u ON u.id = w.user_id
        {$whereClause}
        ORDER BY w.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $query->execute($params);
    $withdrawals = $query->fetchAll(PDO::FETCH_ASSOC);

    // Ofuscar dados sensíveis
    $items = [];
    foreach ($withdrawals as $w) {
        $name = $w['display_name'] ?? 'Usuário';
        $parts = explode(' ', trim($name));
        $firstName = $parts[0] ?? 'Usuário';
        if (mb_strlen($firstName) > 2) {
            $maskedName = mb_substr($firstName, 0, 2) . str_repeat('*', mb_strlen($firstName) - 2);
        } else {
            $maskedName = $firstName . '**';
        }

        $items[] = [
            'id' => (int)$w['id'],
            'name' => $maskedName,
            'amount' => number_format((float)$w['amount_brl'], 2, ',', '.'),
            'status' => $w['status'],
            'method' => $w['method'] ?: 'PIX',
            'created_at' => $w['created_at'],
            'processed_at' => $w['processed_at']
        ];
    }

    // Se buscou por ID, calcular posição na fila (apenas entre pendentes)
    $queuePosition = null;
    if ($searchId > 0 && !empty($items)) {
        $foundItem = $items[0];
        if ($foundItem['status'] === 'pending') {
            $posStmt = $pdo->prepare("
                SELECT COUNT(*) + 1 as position
                FROM withdrawals
                WHERE status = 'pending'
                AND created_at < (SELECT created_at FROM withdrawals WHERE id = ?)
            ");
            $posStmt->execute([$searchId]);
            $queuePosition = (int)$posStmt->fetchColumn();
        }
    }

    $response = [
        'success' => true,
        'withdrawals_enabled' => (function() use ($pdo) {
            $st = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'withdrawals_enabled' LIMIT 1");
            $v = $st ? $st->fetchColumn() : false;
            return !($v !== false && ($v === 'false' || $v === '0'));
        })(),
        'counts' => [
            'pending' => (int)$counts['pending'],
            'processing' => (int)$counts['processing'],
            'under_review' => (int)$counts['under_review'],
            'completed' => (int)$counts['completed'],
            'rejected' => (int)$counts['rejected'],
            'total' => (int)$counts['total']
        ],
        'monitor' => [
            'completed_24h' => $completed24h,
            'avg_per_hour' => $avgPerHour,
            'estimated_hours' => $estimatedHours,
            'pending_count' => $pendingCount
        ],
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'total_pages' => $totalPages,
            'total_items' => $totalFiltered,
            'per_page' => $limit
        ]
    ];

    if ($queuePosition !== null) {
        $response['queue_position'] = $queuePosition;
        $response['search_id'] = $searchId;
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log("withdrawal-queue error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
