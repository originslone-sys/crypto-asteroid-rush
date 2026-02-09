<?php
// ===============================================================
// UNOBIX - ADMIN AJAX API
// v7.0 - Consolidado: segurança, alertas, blacklist
//        Portado de admin-security.php
// ===============================================================

date_default_timezone_set('America/Sao_Paulo');

if (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
} elseif (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
}

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// CORS: restringir a mesma origem (admin panel)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://' . ($_SERVER['HTTP_HOST'] ?? ''),
    'http://' . ($_SERVER['HTTP_HOST'] ?? ''),
];
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: {$origin}");
} else {
    header("Access-Control-Allow-Origin: " . $allowedOrigins[0]);
}
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================================================
// AUTENTICAÇÃO — verificar sessão admin
// ===============================================================
session_start();
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado. Faça login no painel admin.']);
    exit;
}

$input = getRequestInput();
$action = $input["action"] ?? "";
$response = ["success" => false, "message" => "Ação inválida"];

// ===============================================================
// HELPER: Paginação
// ===============================================================
function getPagination($input, $defaultLimit = 50) {
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = min(200, max(10, (int)($input['limit'] ?? $defaultLimit)));
    $offset = ($page - 1) * $limit;
    return ['page' => $page, 'limit' => $limit, 'offset' => $offset];
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    switch ($action) {

        // ===============================================================
        //  SAQUES
        // ===============================================================
        
        case "list_withdrawals":
            $status = $input['status'] ?? 'all';
            $pg = getPagination($input, 100);
            
            $sql = "
                SELECT 
                    w.id, w.user_id, w.amount_brl,
                    w.wallet_address, w.status, w.transaction_hash,
                    w.admin_notes, w.created_at, w.processed_at,
                    u.google_uid, u.email, u.display_name
                FROM withdrawals w
                LEFT JOIN users u ON w.user_id = u.id
            ";
            
            $params = [];
            if ($status !== 'all') {
                $sql .= " WHERE w.status = ?";
                $params[] = $status;
            }
            
            // Contar total
            $countSql = str_replace("SELECT \n                    w.id, w.user_id, w.amount_brl,\n                    w.wallet_address, w.status, w.transaction_hash,\n                    w.admin_notes, w.created_at, w.processed_at,\n                    u.google_uid, u.email, u.display_name", "SELECT COUNT(*)", $sql);
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            
            $sql .= " ORDER BY w.created_at DESC LIMIT {$pg['limit']} OFFSET {$pg['offset']}";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $response = [
                "success" => true, 
                "data" => $stmt->fetchAll(),
                "pagination" => ['page' => $pg['page'], 'limit' => $pg['limit'], 'total' => $total]
            ];
            break;

        case "approve_withdrawal":
            $id = intval($input["id"] ?? 0);
            $txHash = $input["transaction_hash"] ?? $input["tx_hash"] ?? null;
            
            if ($id <= 0) throw new Exception("ID inválido");

            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if ($withdrawal["status"] !== "pending") throw new Exception("Saque já processado");

            $stmt = $pdo->prepare("
                UPDATE withdrawals 
                SET status = 'completed', processed_at = NOW(), transaction_hash = ?
                WHERE id = ?
            ");
            $stmt->execute([$txHash, $id]);

            $amount = $withdrawal['amount_brl'] ?? 0;
            $userId = $withdrawal['user_id'];
            
            $stmt = $pdo->prepare("UPDATE users SET total_withdrawn_brl = total_withdrawn_brl + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (google_uid, type, amount, amount_brl, description, status, created_at)
                VALUES (?, 'withdraw', ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([$user['google_uid'] ?? null, -abs($amount), -abs($amount), "Saque #{$id} aprovado"]);

            $pdo->commit();
            $response = ["success" => true, "message" => "Saque #{$id} aprovado com sucesso!"];
            break;

        case "reject_withdrawal":
            $id = intval($input["id"] ?? 0);
            $reason = $input["reason"] ?? "Rejeitado pelo administrador";
            
            if ($id <= 0) throw new Exception("ID inválido");

            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if ($withdrawal["status"] !== "pending") throw new Exception("Saque já processado");

            $userId = $withdrawal['user_id'];
            $amount = $withdrawal['amount_brl'] ?? 0;
            
            $stmt = $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            $stmt = $pdo->prepare("
                UPDATE withdrawals 
                SET status = 'rejected', processed_at = NOW(), admin_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $id]);

            $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (google_uid, type, amount, amount_brl, description, status, created_at)
                VALUES (?, 'withdraw_reject', ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([$user['google_uid'] ?? null, $amount, $amount, "Saque #{$id} rejeitado. Motivo: {$reason}"]);

            $pdo->commit();
            $response = ["success" => true, "message" => "Saque #{$id} rejeitado e saldo devolvido."];
            break;

        // ===============================================================
        //  ESTATÍSTICAS
        // ===============================================================
        
        case "get_stats":
            $stats = [];
            $stats["total_players"] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stats["google_players"] = $pdo->query("SELECT COUNT(*) FROM users WHERE google_uid IS NOT NULL")->fetchColumn();
            $stats["total_withdrawals"] = $pdo->query("SELECT COUNT(*) FROM withdrawals")->fetchColumn();
            $stats["pending_withdrawals"] = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
            $stats["total_balance_brl"] = $pdo->query("SELECT COALESCE(SUM(balance_brl), 0) FROM users")->fetchColumn();
            $stats["total_staked_brl"] = $pdo->query("SELECT COALESCE(SUM(staked_balance_brl), 0) FROM users")->fetchColumn();
            $stats["total_withdrawn_brl"] = $pdo->query("SELECT COALESCE(SUM(amount_brl), 0) FROM withdrawals WHERE status = 'completed'")->fetchColumn();
            $stats["total_sessions"] = $pdo->query("SELECT COUNT(*) FROM game_sessions")->fetchColumn();
            $stats["sessions_today"] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            $stats["hard_mode_sessions"] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE is_hard_mode = 1")->fetchColumn();
            
            // Stats de segurança (portado de admin-security.php)
            $stats["banned_players"] = $pdo->query("SELECT COUNT(*) FROM users WHERE is_banned = 1")->fetchColumn();
            $stats["flagged_sessions"] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged'")->fetchColumn();
            
            try {
                $stats["suspicious_24h"] = $pdo->query("SELECT COUNT(*) FROM suspicious_activity WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
            } catch (Exception $e) { $stats["suspicious_24h"] = 0; }
            
            try {
                $stats["blacklisted_ips"] = $pdo->query("SELECT COUNT(*) FROM ip_blacklist WHERE expires_at IS NULL OR expires_at > NOW()")->fetchColumn();
            } catch (Exception $e) { $stats["blacklisted_ips"] = 0; }
            
            $response = ["success" => true, "stats" => $stats];
            break;

        // ===============================================================
        //  JOGADORES
        // ===============================================================
        
        case "list_players":
            $pg = getPagination($input, 100);
            
            $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
            $stmt = $pdo->prepare("
                SELECT id, google_uid, email, display_name,
                    balance_brl, staked_balance_brl,
                    total_earned_brl, total_withdrawn_brl, total_played,
                    is_banned, ban_reason, created_at, last_login, updated_at
                FROM users
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$pg['limit'], $pg['offset']]);
            
            $response = [
                "success" => true, 
                "data" => $stmt->fetchAll(),
                "pagination" => ['page' => $pg['page'], 'limit' => $pg['limit'], 'total' => $total]
            ];
            break;

        case "search_player":
            $query = trim($input['query'] ?? '');
            if (strlen($query) < 3) throw new Exception("Busca deve ter pelo menos 3 caracteres");
            
            $stmt = $pdo->prepare("
                SELECT * FROM users 
                WHERE google_uid LIKE ? OR email LIKE ? OR display_name LIKE ?
                LIMIT 20
            ");
            $searchTerm = "%{$query}%";
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        case "list_transactions":
            $pg = getPagination($input, 100);
            
            $stmt = $pdo->prepare("
                SELECT t.id, t.google_uid, t.type, t.amount_brl,
                    t.description, t.status, t.created_at,
                    u.email, u.display_name
                FROM transactions t
                LEFT JOIN users u ON t.google_uid = u.google_uid
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$pg['limit'], $pg['offset']]);
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // ===============================================================
        //  SEGURANÇA — BAN / UNBAN
        // ===============================================================
        
        case "ban_player":
            $playerId = intval($input['player_id'] ?? 0);
            $googleUid = $input['google_uid'] ?? null;
            $reason = $input['reason'] ?? 'Banido pelo administrador';
            
            if ($playerId <= 0 && !$googleUid) throw new Exception("player_id ou google_uid é obrigatório");
            
            if ($googleUid) {
                $stmt = $pdo->prepare("UPDATE users SET is_banned = 1, ban_reason = ?, updated_at = NOW() WHERE google_uid = ?");
                $stmt->execute([$reason, $googleUid]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET is_banned = 1, ban_reason = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$reason, $playerId]);
            }
            
            $affected = $stmt->rowCount();
            $response = ["success" => $affected > 0, "message" => $affected > 0 ? "Jogador banido" : "Jogador não encontrado"];
            break;

        case "unban_player":
            $playerId = intval($input['player_id'] ?? 0);
            $googleUid = $input['google_uid'] ?? null;
            
            if ($playerId <= 0 && !$googleUid) throw new Exception("player_id ou google_uid é obrigatório");
            
            if ($googleUid) {
                $stmt = $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL, updated_at = NOW() WHERE google_uid = ?");
                $stmt->execute([$googleUid]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$playerId]);
            }
            
            $affected = $stmt->rowCount();
            $response = ["success" => $affected > 0, "message" => $affected > 0 ? "Jogador desbanido" : "Jogador não encontrado"];
            break;

        // ===============================================================
        //  SEGURANÇA — ATIVIDADES SUSPEITAS (NOVO)
        //  Portado de admin-security.php + novas ações
        // ===============================================================
        
        case "list_suspicious":
            $pg = getPagination($input, 50);
            $severity = $input['severity'] ?? 'all';
            $activityType = $input['activity_type'] ?? 'all';
            $dateFrom = $input['date_from'] ?? null;
            $dateTo = $input['date_to'] ?? null;
            $reviewed = $input['reviewed'] ?? 'all'; // 'all', '0', '1'
            
            $where = [];
            $params = [];
            
            if ($severity !== 'all') {
                $where[] = "sa.severity = ?";
                $params[] = $severity;
            }
            if ($activityType !== 'all') {
                $where[] = "sa.activity_type = ?";
                $params[] = $activityType;
            }
            if ($dateFrom) {
                $where[] = "sa.created_at >= ?";
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $where[] = "sa.created_at <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }
            if ($reviewed !== 'all') {
                $where[] = "sa.reviewed = ?";
                $params[] = (int)$reviewed;
            }
            
            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
            
            // Total
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM suspicious_activity sa {$whereClause}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            
            // Dados
            $params[] = $pg['limit'];
            $params[] = $pg['offset'];
            
            $stmt = $pdo->prepare("
                SELECT sa.id, sa.user_id, sa.activity_type, sa.details,
                    sa.ip_address, sa.severity, sa.reviewed, sa.reviewed_by,
                    sa.reviewed_at, sa.created_at,
                    u.google_uid, u.email, u.display_name
                FROM suspicious_activity sa
                LEFT JOIN users u ON sa.user_id = u.id
                {$whereClause}
                ORDER BY sa.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute($params);
            
            $response = [
                "success" => true, 
                "data" => $stmt->fetchAll(),
                "pagination" => ['page' => $pg['page'], 'limit' => $pg['limit'], 'total' => $total]
            ];
            break;

        case "list_flagged":
            $pg = getPagination($input, 50);
            
            $total = (int)$pdo->query("SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged'")->fetchColumn();
            
            $stmt = $pdo->prepare("
                SELECT gs.id, gs.google_uid, gs.mission_number,
                    gs.asteroids_destroyed, gs.earnings_brl, gs.is_hard_mode,
                    gs.game_duration, gs.ip_address, gs.created_at,
                    u.email, u.display_name, u.is_banned
                FROM game_sessions gs
                LEFT JOIN users u ON gs.google_uid = u.google_uid
                WHERE gs.status = 'flagged'
                ORDER BY gs.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$pg['limit'], $pg['offset']]);
            
            $response = [
                "success" => true, 
                "data" => $stmt->fetchAll(),
                "pagination" => ['page' => $pg['page'], 'limit' => $pg['limit'], 'total' => $total]
            ];
            break;

        // ===============================================================
        //  SEGURANÇA — GERENCIAR ALERTAS (NOVO)
        // ===============================================================
        
        case "delete_alert":
            $alertId = intval($input['alert_id'] ?? $input['id'] ?? 0);
            if ($alertId <= 0) throw new Exception("ID do alerta é obrigatório");
            
            $stmt = $pdo->prepare("DELETE FROM suspicious_activity WHERE id = ?");
            $stmt->execute([$alertId]);
            
            $response = [
                "success" => $stmt->rowCount() > 0,
                "message" => $stmt->rowCount() > 0 ? "Alerta excluído" : "Alerta não encontrado"
            ];
            break;

        case "review_alert":
            $alertId = intval($input['alert_id'] ?? $input['id'] ?? 0);
            if ($alertId <= 0) throw new Exception("ID do alerta é obrigatório");
            
            $reviewedBy = $_SESSION['admin_name'] ?? 'admin';
            
            $stmt = $pdo->prepare("
                UPDATE suspicious_activity 
                SET reviewed = 1, reviewed_by = ?, reviewed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reviewedBy, $alertId]);
            
            $response = [
                "success" => $stmt->rowCount() > 0,
                "message" => $stmt->rowCount() > 0 ? "Alerta marcado como revisado" : "Alerta não encontrado"
            ];
            break;

        case "unreview_alert":
            $alertId = intval($input['alert_id'] ?? $input['id'] ?? 0);
            if ($alertId <= 0) throw new Exception("ID do alerta é obrigatório");
            
            $stmt = $pdo->prepare("
                UPDATE suspicious_activity 
                SET reviewed = 0, reviewed_by = NULL, reviewed_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$alertId]);
            
            $response = [
                "success" => $stmt->rowCount() > 0,
                "message" => $stmt->rowCount() > 0 ? "Revisão removida" : "Alerta não encontrado"
            ];
            break;

        case "bulk_delete_alerts":
            $ids = $input['ids'] ?? [];
            $dateFrom = $input['date_from'] ?? null;
            $dateTo = $input['date_to'] ?? null;
            $severity = $input['severity'] ?? null;
            $onlyReviewed = (bool)($input['only_reviewed'] ?? false);
            
            $where = [];
            $params = [];
            
            if (!empty($ids) && is_array($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where[] = "id IN ({$placeholders})";
                $params = array_merge($params, array_map('intval', $ids));
            } else {
                // Filtros por data/severidade (só quando não são IDs específicos)
                if ($dateFrom) {
                    $where[] = "created_at >= ?";
                    $params[] = $dateFrom . ' 00:00:00';
                }
                if ($dateTo) {
                    $where[] = "created_at <= ?";
                    $params[] = $dateTo . ' 23:59:59';
                }
                if ($severity) {
                    $where[] = "severity = ?";
                    $params[] = $severity;
                }
                if ($onlyReviewed) {
                    $where[] = "reviewed = 1";
                }
            }
            
            if (empty($where)) throw new Exception("Nenhum filtro especificado para exclusão em massa");
            
            $whereClause = implode(" AND ", $where);
            $stmt = $pdo->prepare("DELETE FROM suspicious_activity WHERE {$whereClause}");
            $stmt->execute($params);
            
            $deleted = $stmt->rowCount();
            $response = ["success" => true, "message" => "{$deleted} alertas excluídos", "deleted" => $deleted];
            break;

        case "bulk_review_alerts":
            $ids = $input['ids'] ?? [];
            if (empty($ids) || !is_array($ids)) throw new Exception("IDs são obrigatórios");
            
            $reviewedBy = $_SESSION['admin_name'] ?? 'admin';
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_map('intval', $ids);
            $params[] = $reviewedBy;
            
            $stmt = $pdo->prepare("
                UPDATE suspicious_activity 
                SET reviewed = 1, reviewed_by = ?, reviewed_at = NOW()
                WHERE id IN ({$placeholders})
            ");
            // reviewed_by primeiro, depois IDs
            $stmt->execute(array_merge([$reviewedBy], array_map('intval', $ids)));
            
            $response = ["success" => true, "message" => $stmt->rowCount() . " alertas revisados", "reviewed" => $stmt->rowCount()];
            break;

        // ===============================================================
        //  SEGURANÇA — IP BLACKLIST (NOVO - portado)
        // ===============================================================
        
        case "blacklist_ip":
            $ip = trim($input['ip'] ?? '');
            $reason = $input['reason'] ?? 'Blacklist manual';
            $hours = isset($input['hours']) ? intval($input['hours']) : null;
            
            if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new Exception("IP inválido");
            
            // Garantir que tabela existe
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ip_blacklist (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL UNIQUE,
                    reason VARCHAR(255) DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME DEFAULT NULL,
                    INDEX idx_ip (ip_address),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $expiresAt = $hours ? date('Y-m-d H:i:s', strtotime("+{$hours} hours")) : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO ip_blacklist (ip_address, reason, expires_at)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)
            ");
            $stmt->execute([$ip, $reason, $expiresAt]);
            
            $response = ["success" => true, "message" => "IP {$ip} adicionado à blacklist"];
            break;

        case "unblacklist_ip":
            $ip = trim($input['ip'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new Exception("IP inválido");
            
            $stmt = $pdo->prepare("DELETE FROM ip_blacklist WHERE ip_address = ?");
            $stmt->execute([$ip]);
            
            $response = [
                "success" => $stmt->rowCount() > 0,
                "message" => $stmt->rowCount() > 0 ? "IP removido da blacklist" : "IP não encontrado"
            ];
            break;

        case "list_blacklist":
            try {
                $stmt = $pdo->query("
                    SELECT ip_address, reason, created_at, expires_at
                    FROM ip_blacklist
                    WHERE expires_at IS NULL OR expires_at > NOW()
                    ORDER BY created_at DESC
                    LIMIT 200
                ");
                $response = ["success" => true, "data" => $stmt->fetchAll()];
            } catch (Exception $e) {
                $response = ["success" => true, "data" => []];
            }
            break;

        // ===============================================================
        //  SEGURANÇA — BUSCA DETALHADA DE JOGADOR (portado)
        // ===============================================================
        
        case "player_detail":
            $query = trim($input['query'] ?? $input['google_uid'] ?? '');
            if (strlen($query) < 3) throw new Exception("Busca muito curta");

            $stmt = $pdo->prepare("
                SELECT * FROM users 
                WHERE google_uid = ? OR email LIKE ? OR display_name LIKE ?
                LIMIT 1
            ");
            $stmt->execute([$query, "%{$query}%", "%{$query}%"]);
            $player = $stmt->fetch();

            if (!$player) throw new Exception("Jogador não encontrado");

            // Sessões recentes
            $stmt = $pdo->prepare("
                SELECT id, mission_number, status, asteroids_destroyed,
                       earnings_brl, is_hard_mode, game_duration, created_at
                FROM game_sessions WHERE google_uid = ?
                ORDER BY created_at DESC LIMIT 20
            ");
            $stmt->execute([$player['google_uid']]);
            $sessions = $stmt->fetchAll();

            // Atividades suspeitas
            $stmt = $pdo->prepare("
                SELECT id, activity_type, details, ip_address, severity, 
                       reviewed, created_at
                FROM suspicious_activity WHERE user_id = ?
                ORDER BY created_at DESC LIMIT 20
            ");
            $stmt->execute([$player['id']]);
            $suspicious = $stmt->fetchAll();

            // Saques
            $stmt = $pdo->prepare("
                SELECT id, amount_brl, status, created_at, processed_at
                FROM withdrawals WHERE user_id = ?
                ORDER BY created_at DESC LIMIT 10
            ");
            $stmt->execute([$player['id']]);
            $withdrawals = $stmt->fetchAll();

            $response = [
                'success' => true,
                'player' => $player,
                'sessions' => $sessions,
                'suspicious' => $suspicious,
                'withdrawals' => $withdrawals
            ];
            break;

        // ===============================================================
        //  CONFIGURAÇÕES
        // ===============================================================
        
        case "get_config":
            $stmt = $pdo->query("SELECT setting_key, setting_value, description, is_public FROM game_settings ORDER BY setting_key");
            $configs = [];
            while ($row = $stmt->fetch()) {
                $configs[$row['setting_key']] = [
                    'value' => json_decode($row['setting_value'], true) ?? $row['setting_value'],
                    'description' => $row['description'],
                    'is_public' => (bool)$row['is_public']
                ];
            }
            $response = ["success" => true, "config" => $configs];
            break;

        // ===============================================================
        //  LIMPEZA
        // ===============================================================
        
        case "cleanup":
            $deleted = 0;
            
            // Limpar rate_limits antigas (> 2 horas)
            try {
                $stmt = $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
                $deleted += $stmt;
            } catch (Exception $e) {}
            
            // Limpar suspicious_activity revisadas com > 90 dias
            try {
                $stmt = $pdo->exec("DELETE FROM suspicious_activity WHERE reviewed = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
                $deleted += $stmt;
            } catch (Exception $e) {}
            
            // Limpar sessões abandonadas com > 30 dias
            try {
                $stmt = $pdo->exec("DELETE FROM game_sessions WHERE status = 'abandoned' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
                $deleted += $stmt;
            } catch (Exception $e) {}
            
            // Limpar ip_blacklist expiradas
            try {
                $stmt = $pdo->exec("DELETE FROM ip_blacklist WHERE expires_at IS NOT NULL AND expires_at < NOW()");
                $deleted += $stmt;
            } catch (Exception $e) {}
            
            // Limpar proxy_cache expirado
            try {
                $cacheHours = defined('PROXY_CHECK_CACHE_HOURS') ? PROXY_CHECK_CACHE_HOURS : 6;
                $stmt = $pdo->exec("DELETE FROM proxy_cache WHERE created_at < DATE_SUB(NOW(), INTERVAL {$cacheHours} HOUR)");
                $deleted += $stmt;
            } catch (Exception $e) {}
            
            // Tentar stored procedure se existir
            try {
                $pdo->exec("CALL sp_cleanup_old_data()");
            } catch (Exception $e) {
                // SP pode não existir - sem problema
            }
            
            $response = ["success" => true, "message" => "Limpeza concluída. {$deleted} registros removidos.", "deleted" => $deleted];
            break;

        default:
            $response = ["success" => false, "message" => "Ação não reconhecida: " . htmlspecialchars($action)];
            break;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[ADMIN-AJAX] " . $e->getMessage());
    $response = ["success" => false, "error" => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>
