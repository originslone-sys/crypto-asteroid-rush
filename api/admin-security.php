<?php
// ===============================================================
// CRYPTO ASTEROID RUSH - ADMIN SECURITY API
// Compatível com Railway (PHP 8 + MySQL 8)
// ===============================================================

// ===============================================================
// Ajustes de ambiente (Railway)
// ===============================================================
date_default_timezone_set('America/Sao_Paulo');

if (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
} elseif (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
}

require_once __DIR__ . "/rate-limiter.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================================================
// Autenticação via Bearer Token (mantida, mas adaptada para .env)
// ===============================================================
$adminPassword = getenv('ADMIN_PASSWORD') ?: 'MUDE_ESTA_SENHA_123!';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$providedPassword = str_replace('Bearer ', '', $authHeader);

if ($providedPassword !== $adminPassword) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

// ===============================================================
// Entrada híbrida (JSON / POST / GET) — preserva compatibilidade
// ===============================================================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = array_merge($_POST, $_GET);
$action = $input['action'] ?? '';

// ===============================================================
// Conexão PDO (Railway compatível)
// ===============================================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $pdo->exec("SET time_zone = '-03:00'");
} catch (Exception $e) {
    error_log("[ADMIN-SECURITY] Falha de conexão: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Falha ao conectar ao banco']);
    exit;
}

// ===============================================================
// Processamento de ações (mantido integralmente)
// ===============================================================
try {
    switch ($action) {

        // =======================================================
        // Estatísticas (mantida lógica original)
        // =======================================================
        case 'stats':
            $stats = RateLimiter::getStats($pdo);

            $games24h = $pdo->query("
                SELECT COUNT(*) as count FROM game_sessions
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetch();
            $stats['games_24h'] = $games24h['count'];

            $flagged = $pdo->query("
                SELECT COUNT(*) as count FROM game_sessions
                WHERE status = 'flagged'
            ")->fetch();
            $stats['flagged_sessions'] = $flagged['count'];

            $earnings24h = $pdo->query("
                SELECT COALESCE(SUM(earnings_usdt), 0) as total
                FROM game_sessions
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetch();
            $stats['earnings_24h'] = $earnings24h['total'];

            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        // =======================================================
        // BANIR WALLET
        // =======================================================
        case 'ban_wallet':
            $wallet = strtolower(trim($input['wallet'] ?? ''));
            $reason = $input['reason'] ?? 'Manual ban';
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
                echo json_encode(['success' => false, 'error' => 'Wallet inválida']);
                exit;
            }
            $result = RateLimiter::banWallet($pdo, $wallet, $reason);
            echo json_encode(['success' => $result, 'message' => $result ? 'Wallet banida' : 'Falha ao banir']);
            break;

        // =======================================================
        // DESBANIR WALLET
        // =======================================================
        case 'unban_wallet':
            $wallet = strtolower(trim($input['wallet'] ?? ''));
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
                echo json_encode(['success' => false, 'error' => 'Wallet inválida']);
                exit;
            }
            $result = RateLimiter::unbanWallet($pdo, $wallet);
            echo json_encode(['success' => $result, 'message' => $result ? 'Wallet desbanida' : 'Não encontrada']);
            break;

        // =======================================================
        // BLACKLIST IP
        // =======================================================
        case 'blacklist_ip':
            $ip = $input['ip'] ?? '';
            $reason = $input['reason'] ?? 'Manual blacklist';
            $hours = $input['hours'] ?? null;
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'error' => 'IP inválido']);
                exit;
            }
            RateLimiter::blacklistIP($pdo, $ip, $reason, $hours);
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
            $result = RateLimiter::unblacklistIP($pdo, $ip);
            echo json_encode(['success' => $result, 'message' => $result ? 'IP removido' : 'IP não encontrado']);
            break;

        // =======================================================
        // LISTAR WALLETS BANIDAS
        // =======================================================
        case 'list_banned':
            $stmt = $pdo->query("
                SELECT wallet_address, ban_reason, created_at
                FROM players
                WHERE is_banned = 1
                ORDER BY created_at DESC
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
                    id, wallet_address, mission_number,
                    asteroids_destroyed, earnings_usdt, client_score,
                    client_earnings, validation_errors, created_at
                FROM game_sessions
                WHERE status = 'flagged'
                ORDER BY created_at DESC
                LIMIT 100
            ");
            echo json_encode(['success' => true, 'flagged' => $stmt->fetchAll()]);
            break;

        // =======================================================
        // LISTAR ATIVIDADES SUSPEITAS
        // =======================================================
        case 'list_suspicious':
            $stmt = $pdo->query("
                SELECT wallet_address, activity_type, activity_data,
                       ip_address, devtools_detected, created_at
                FROM suspicious_activity
                ORDER BY created_at DESC
                LIMIT 200
            ");
            echo json_encode(['success' => true, 'suspicious' => $stmt->fetchAll()]);
            break;

        // =======================================================
        // LIMPAR DADOS ANTIGOS
        // =======================================================
        case 'cleanup':
            $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
            $pdo->exec("DELETE FROM suspicious_activity WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $pdo->exec("DELETE FROM ip_blacklist WHERE expires_at IS NOT NULL AND expires_at < NOW()");
            echo json_encode(['success' => true, 'message' => 'Limpeza concluída']);
            break;

        // =======================================================
        // BUSCAR JOGADOR
        // =======================================================
        case 'search_player':
            $wallet = strtolower(trim($input['wallet'] ?? ''));
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
                echo json_encode(['success' => false, 'error' => 'Wallet inválida']);
                exit;
            }

            // Dados do jogador
            $stmt = $pdo->prepare("SELECT * FROM players WHERE wallet_address = ?");
            $stmt->execute([$wallet]);
            $player = $stmt->fetch();

            if (!$player) {
                echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
                exit;
            }

            // Últimas sessões
            $stmt = $pdo->prepare("
                SELECT id, mission_number, status, asteroids_destroyed,
                       earnings_usdt, created_at
                FROM game_sessions
                WHERE wallet_address = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$wallet]);
            $sessions = $stmt->fetchAll();

            // Atividades suspeitas
            $stmt = $pdo->prepare("
                SELECT activity_type, activity_data, created_at
                FROM suspicious_activity
                WHERE wallet_address = ?
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$wallet]);
            $suspicious = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'player' => $player,
                'sessions' => $sessions,
                'suspicious' => $suspicious
            ]);
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
