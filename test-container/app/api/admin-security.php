<?php
// ===============================================================
// UNOBIX - ADMIN SECURITY API
// v4.0 - Suporte a Google UID e novos sistemas
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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================================================
// Autenticação via Bearer Token
// ===============================================================
$adminPassword = getenv('ADMIN_PASSWORD') ?: 'MUDE_ESTA_SENHA_123!';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$providedPassword = str_replace('Bearer ', '', $authHeader);

if ($providedPassword !== $adminPassword) {
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
            $stats['banned_players'] = $pdo->query("SELECT COUNT(*) FROM players WHERE is_banned = 1")->fetchColumn();
            
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
            
            // CAPTCHA stats
            $stats['captcha_verified_24h'] = $pdo->query("
                SELECT COUNT(*) FROM captcha_log 
                WHERE is_success = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetchColumn();
            $stats['captcha_failed_24h'] = $pdo->query("
                SELECT COUNT(*) FROM captcha_log 
                WHERE is_success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetchColumn();

            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        // =======================================================
        // BANIR JOGADOR (por Google UID ou Wallet)
        // =======================================================
        case 'ban_player':
            $googleUid = $input['google_uid'] ?? null;
            $wallet = strtolower(trim($input['wallet'] ?? ''));
            $reason = $input['reason'] ?? 'Banimento manual';
            
            if (!$googleUid && !$wallet) {
                echo json_encode(['success' => false, 'error' => 'google_uid ou wallet é obrigatório']);
                exit;
            }
            
            if ($googleUid) {
                $stmt = $pdo->prepare("UPDATE players SET is_banned = 1, ban_reason = ? WHERE google_uid = ?");
                $stmt->execute([$reason, $googleUid]);
            } else {
                if (!validateWallet($wallet)) {
                    echo json_encode(['success' => false, 'error' => 'Wallet inválida']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE players SET is_banned = 1, ban_reason = ? WHERE wallet_address = ?");
                $stmt->execute([$reason, $wallet]);
            }
            
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
            $wallet = strtolower(trim($input['wallet'] ?? ''));
            
            if (!$googleUid && !$wallet) {
                echo json_encode(['success' => false, 'error' => 'google_uid ou wallet é obrigatório']);
                exit;
            }
            
            if ($googleUid) {
                $stmt = $pdo->prepare("UPDATE players SET is_banned = 0, ban_reason = NULL WHERE google_uid = ?");
                $stmt->execute([$googleUid]);
            } else {
                $stmt = $pdo->prepare("UPDATE players SET is_banned = 0, ban_reason = NULL WHERE wallet_address = ?");
                $stmt->execute([$wallet]);
            }
            
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
        // =======================================================
        case 'list_banned':
            $stmt = $pdo->query("
                SELECT id, google_uid, email, display_name, wallet_address, ban_reason, created_at, updated_at
                FROM players
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
        // =======================================================
        case 'list_flagged':
            $stmt = $pdo->query("
                SELECT 
                    gs.id, gs.google_uid, gs.wallet_address, gs.mission_number,
                    gs.asteroids_destroyed, gs.earnings_brl, gs.earnings_usdt,
                    gs.is_hard_mode, gs.captcha_verified,
                    gs.validation_errors, gs.created_at,
                    p.email, p.display_name
                FROM game_sessions gs
                LEFT JOIN players p ON (
                    (gs.google_uid IS NOT NULL AND p.google_uid = gs.google_uid) OR
                    (gs.google_uid IS NULL AND p.wallet_address = gs.wallet_address)
                )
                WHERE gs.status = 'flagged'
                ORDER BY gs.created_at DESC
                LIMIT 100
            ");
            echo json_encode(['success' => true, 'flagged' => $stmt->fetchAll()]);
            break;

        // =======================================================
        // LISTAR ATIVIDADES SUSPEITAS
        // =======================================================
        case 'list_suspicious':
            $stmt = $pdo->query("
                SELECT 
                    sa.wallet_address, sa.activity_type, sa.activity_data,
                    sa.ip_address, sa.devtools_detected, sa.created_at,
                    p.google_uid, p.email, p.display_name
                FROM suspicious_activity sa
                LEFT JOIN players p ON p.wallet_address = sa.wallet_address
                ORDER BY sa.created_at DESC
                LIMIT 200
            ");
            echo json_encode(['success' => true, 'suspicious' => $stmt->fetchAll()]);
            break;

        // =======================================================
        // BUSCAR JOGADOR DETALHADO
        // =======================================================
        case 'search_player':
            $query = trim($input['query'] ?? $input['wallet'] ?? $input['google_uid'] ?? '');
            
            if (strlen($query) < 3) {
                echo json_encode(['success' => false, 'error' => 'Busca muito curta']);
                exit;
            }

            // Buscar jogador
            $stmt = $pdo->prepare("
                SELECT * FROM players 
                WHERE google_uid = ? OR wallet_address = ? OR email LIKE ? OR display_name LIKE ?
                LIMIT 1
            ");
            $stmt->execute([$query, $query, "%{$query}%", "%{$query}%"]);
            $player = $stmt->fetch();

            if (!$player) {
                echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
                exit;
            }

            // Últimas sessões
            $stmt = $pdo->prepare("
                SELECT id, mission_number, status, asteroids_destroyed,
                       earnings_brl, earnings_usdt, is_hard_mode, captcha_verified, created_at
                FROM game_sessions
                WHERE google_uid = ? OR wallet_address = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$player['google_uid'], $player['wallet_address']]);
            $sessions = $stmt->fetchAll();

            // Atividades suspeitas
            $stmt = $pdo->prepare("
                SELECT activity_type, activity_data, ip_address, created_at
                FROM suspicious_activity
                WHERE wallet_address = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$player['wallet_address']]);
            $suspicious = $stmt->fetchAll();

            // Saques
            $stmt = $pdo->prepare("
                SELECT id, amount_brl, amount_usdt, payment_method, status, created_at
                FROM withdrawals
                WHERE google_uid = ? OR wallet_address = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$player['google_uid'], $player['wallet_address']]);
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
