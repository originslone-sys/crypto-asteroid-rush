<?php
// ===============================================================
// UNOBIX - ADMIN SECURITY API
// v6.0 - Tabela users, google_uid, game_settings
// ===============================================================

date_default_timezone_set('America/Sao_Paulo');

if (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
} elseif (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
}

// Rate limiter (se existir)
if (file_exists(__DIR__ . "/rate-limiter.php")) {
    require_once __DIR__ . "/rate-limiter.php";
}

header('Content-Type: application/json; charset=utf-8');

// CORS restrito (mesma origem)
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
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================================================
// Autenticação via Bearer Token OU sessão admin
// ===============================================================

$isAuthenticated = false;

// Método 1: Sessão PHP (admin logado no painel)
session_start();
if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
    $isAuthenticated = true;
}

// Método 2: Bearer Token (API externa)
if (!$isAuthenticated) {
    $adminPassword = getenv('ADMIN_API_TOKEN') ?: getenv('ADMIN_PASSWORD') ?: '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $providedPassword = str_replace('Bearer ', '', $authHeader);
    
    if (!empty($adminPassword) && !empty($providedPassword) && $providedPassword === $adminPassword) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$input = getRequestInput();
$action = $input['action'] ?? '';

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    switch ($action) {

        // =======================================================
        // ESTATÍSTICAS DE SEGURANÇA
        // =======================================================
        case 'stats':
            $stats = [];
            
            // Jogadores banidos
            $stats['banned_players'] = $pdo->query("SELECT COUNT(*) FROM users WHERE is_banned = 1")->fetchColumn();
            
            // IPs na blacklist
            $stats['blacklisted_ips'] = $pdo->query("
                SELECT COUNT(*) FROM ip_blacklist 
                WHERE expires_at IS NULL OR expires_at > NOW()
            ")->fetchColumn();
            
            // Sessões flagged
            $stats['flagged_sessions'] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged'")->fetchColumn();
            
            // Atividades suspeitas (últimas 24h)
            $stats['suspicious_24h'] = $pdo->query("
                SELECT COUNT(*) FROM suspicious_activity 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetchColumn();
            
            // Sessões nas últimas 24h
            $stats['games_24h'] = $pdo->query("
                SELECT COUNT(*) FROM game_sessions
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetchColumn();
            
            // Ganhos nas últimas 24h (BRL)
            $stats['earnings_24h_brl'] = $pdo->query("
                SELECT COALESCE(SUM(earnings_brl), 0) FROM game_sessions
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetchColumn();
            
            // Hard mode stats
            $stats['hard_mode_total'] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE is_hard_mode = 1")->fetchColumn();
            $stats['hard_mode_24h'] = $pdo->query("
                SELECT COUNT(*) FROM game_sessions 
                WHERE is_hard_mode = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetchColumn();

            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        // =======================================================
        // BANIR JOGADOR (por Google UID)
        // Tabela: users (doc 3.1)
        // =======================================================
        case 'ban_player':
            $googleUid = $input['google_uid'] ?? null;
            $reason = $input['reason'] ?? 'Banimento manual';
            
            if (!$googleUid) {
                echo json_encode(['success' => false, 'error' => 'google_uid é obrigatório']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE google_uid = ?");
            $stmt->execute([$reason, $googleUid]);
            
            $affected = $stmt->rowCount();
            echo json_encode([
                'success' => $affected > 0, 
                'message' => $affected > 0 ? 'Jogador banido' : 'Jogador não encontrado'
            ]);
            break;

        // =======================================================
        // DESBANIR JOGADOR
        // =======================================================
        case 'unban_player':
            $googleUid = $input['google_uid'] ?? null;
            
            if (!$googleUid) {
                echo json_encode(['success' => false, 'error' => 'google_uid é obrigatório']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE google_uid = ?");
            $stmt->execute([$googleUid]);
            
            $affected = $stmt->rowCount();
            echo json_encode([
                'success' => $affected > 0,
                'message' => $affected > 0 ? 'Jogador desbanido' : 'Jogador não encontrado'
            ]);
            break;

        // =======================================================
        // BLACKLIST IP
        // =======================================================
        case 'blacklist_ip':
            $ip = $input['ip'] ?? '';
            $reason = $input['reason'] ?? 'Blacklist manual';
            $hours = isset($input['hours']) ? intval($input['hours']) : null;
            
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'error' => 'IP inválido']);
                exit;
            }
            
            $expiresAt = $hours ? "DATE_ADD(NOW(), INTERVAL {$hours} HOUR)" : "NULL";
            
            $stmt = $pdo->prepare("
                INSERT INTO ip_blacklist (ip_address, reason, expires_at)
                VALUES (?, ?, {$expiresAt})
                ON DUPLICATE KEY UPDATE reason = ?, expires_at = {$expiresAt}
            ");
            $stmt->execute([$ip, $reason, $reason]);
            
            echo json_encode(['success' => true, 'message' => 'IP adicionado à blacklist']);
            break;

        // =======================================================
        // REMOVER IP DA BLACKLIST
        // =======================================================
        case 'unblacklist_ip':
            $ip = $input['ip'] ?? '';
            
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'error' => 'IP inválido']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM ip_blacklist WHERE ip_address = ?");
            $stmt->execute([$ip]);
            
            echo json_encode([
                'success' => $stmt->rowCount() > 0,
                'message' => $stmt->rowCount() > 0 ? 'IP removido' : 'IP não encontrado'
            ]);
            break;

        // =======================================================
        // LISTAR JOGADORES BANIDOS
        // Tabela: users (doc 3.1)
        // =======================================================
        case 'list_banned':
            $stmt = $pdo->query("
                SELECT id, google_uid, email, display_name, ban_reason, created_at, updated_at
                FROM users
                WHERE is_banned = 1
                ORDER BY updated_at DESC
                LIMIT 100
            ");
            echo json_encode(['success' => true, 'banned' => $stmt->fetchAll()]);
            break;

        // =======================================================
        // LISTAR BLACKLIST IP
        // =======================================================
        case 'list_blacklist':
            $stmt = $pdo->query("
                SELECT ip_address, reason, created_at, expires_at
                FROM ip_blacklist
                WHERE expires_at IS NULL OR expires_at > NOW()
                ORDER BY created_at DESC
                LIMIT 100
            ");
            echo json_encode(['success' => true, 'blacklist' => $stmt->fetchAll()]);
            break;

        // =======================================================
        // LISTAR SESSÕES FLAGGED
        // Tabelas: game_sessions + users
        // =======================================================
        case 'list_flagged':
            $stmt = $pdo->query("
                SELECT 
                    gs.id, gs.google_uid, gs.mission_number,
                    gs.asteroids_destroyed, gs.earnings_brl,
                    gs.is_hard_mode,
                    gs.created_at,
                    u.email, u.display_name
                FROM game_sessions gs
                LEFT JOIN users u ON gs.google_uid = u.google_uid
                WHERE gs.status = 'flagged'
                ORDER BY gs.created_at DESC
                LIMIT 100
            ");
            echo json_encode(['success' => true, 'flagged' => $stmt->fetchAll()]);
            break;

        // =======================================================
        // LISTAR ATIVIDADES SUSPEITAS
        // Tabela: suspicious_activity (doc 3.8) + users
        // =======================================================
        case 'list_suspicious':
            $stmt = $pdo->query("
                SELECT 
                    sa.id, sa.user_id, sa.activity_type, sa.details,
                    sa.ip_address, sa.severity, sa.created_at,
                    u.google_uid, u.email, u.display_name
                FROM suspicious_activity sa
                LEFT JOIN users u ON sa.user_id = u.id
                ORDER BY sa.created_at DESC
                LIMIT 200
            ");
            echo json_encode(['success' => true, 'suspicious' => $stmt->fetchAll()]);
            break;

        // =======================================================
        // BUSCAR JOGADOR DETALHADO
        // =======================================================
        case 'search_player':
            $query = trim($input['query'] ?? $input['google_uid'] ?? '');
            
            if (strlen($query) < 3) {
                echo json_encode(['success' => false, 'error' => 'Busca muito curta']);
                exit;
            }

            // Buscar jogador na tabela users
            $stmt = $pdo->prepare("
                SELECT * FROM users 
                WHERE google_uid = ? OR email LIKE ? OR display_name LIKE ?
                LIMIT 1
            ");
            $stmt->execute([$query, "%{$query}%", "%{$query}%"]);
            $player = $stmt->fetch();

            if (!$player) {
                echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
                exit;
            }

            // Últimas sessões
            $stmt = $pdo->prepare("
                SELECT id, mission_number, status, asteroids_destroyed,
                       earnings_brl, is_hard_mode, created_at
                FROM game_sessions
                WHERE google_uid = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$player['google_uid']]);
            $sessions = $stmt->fetchAll();

            // Atividades suspeitas
            $stmt = $pdo->prepare("
                SELECT activity_type, details, ip_address, severity, created_at
                FROM suspicious_activity
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$player['id']]);
            $suspicious = $stmt->fetchAll();

            // Saques
            $stmt = $pdo->prepare("
                SELECT id, amount_brl, status, created_at, processed_at
                FROM withdrawals
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$player['id']]);
            $withdrawals = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'player' => $player,
                'sessions' => $sessions,
                'suspicious' => $suspicious,
                'withdrawals' => $withdrawals
            ]);
            break;

        // =======================================================
        // LIMPAR DADOS ANTIGOS
        // =======================================================
        case 'cleanup':
            $pdo->exec("CALL sp_cleanup_old_data()");
            echo json_encode(['success' => true, 'message' => 'Limpeza concluída']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
            break;
    }

} catch (Exception $e) {
    error_log("[ADMIN-SECURITY] " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}
?>
