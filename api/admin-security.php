<?php
// ============================================
// CRYPTO ASTEROID RUSH - Admin API
// Arquivo: api/admin-security.php
// 
// IMPORTANTE: Proteja este arquivo com autenticação!
// ============================================

// Configuração
if (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
} elseif (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
}

require_once __DIR__ . "/rate-limiter.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ============================================
// AUTENTICAÇÃO SIMPLES (mude a senha!)
// ============================================
define('ADMIN_PASSWORD', 'MUDE_ESTA_SENHA_123!');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$providedPassword = str_replace('Bearer ', '', $authHeader);

if ($providedPassword !== ADMIN_PASSWORD) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

// ============================================
// PROCESSAR REQUISIÇÃO
// ============================================

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    switch ($action) {
        
        // ============================================
        // ESTATÍSTICAS
        // ============================================
        case 'stats':
            $stats = RateLimiter::getStats($pdo);
            
            // Adicionar mais estatísticas
            $result = $pdo->query("
                SELECT COUNT(*) as count FROM game_sessions 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetch();
            $stats['games_24h'] = $result['count'];
            
            $result = $pdo->query("
                SELECT COUNT(*) as count FROM game_sessions 
                WHERE status = 'flagged'
            ")->fetch();
            $stats['flagged_sessions'] = $result['count'];
            
            $result = $pdo->query("
                SELECT COALESCE(SUM(earnings_usdt), 0) as total FROM game_sessions 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetch();
            $stats['earnings_24h'] = $result['total'];
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
        
        // ============================================
        // BANIR WALLET
        // ============================================
        case 'ban_wallet':
            $wallet = $input['wallet'] ?? '';
            $reason = $input['reason'] ?? 'Manual ban';
            
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
                echo json_encode(['success' => false, 'error' => 'Wallet inválida']);
                exit;
            }
            
            $result = RateLimiter::banWallet($pdo, $wallet, $reason);
            echo json_encode(['success' => $result, 'message' => $result ? 'Wallet banida' : 'Wallet não encontrada']);
            break;
        
        // ============================================
        // DESBANIR WALLET
        // ============================================
        case 'unban_wallet':
            $wallet = $input['wallet'] ?? '';
            
            if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
                echo json_encode(['success' => false, 'error' => 'Wallet inválida']);
                exit;
            }
            
            $result = RateLimiter::unbanWallet($pdo, $wallet);
            echo json_encode(['success' => $result, 'message' => $result ? 'Wallet desbanida' : 'Wallet não encontrada']);
            break;
        
        // ============================================
        // BLACKLIST IP
        // ============================================
        case 'blacklist_ip':
            $ip = $input['ip'] ?? '';
            $reason = $input['reason'] ?? 'Manual blacklist';
            $hours = $input['hours'] ?? null; // null = permanente
            
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'error' => 'IP inválido']);
                exit;
            }
            
            RateLimiter::blacklistIP($pdo, $ip, $reason, $hours);
            echo json_encode(['success' => true, 'message' => 'IP adicionado à blacklist']);
            break;
        
        // ============================================
        // REMOVER IP DA BLACKLIST
        // ============================================
        case 'unblacklist_ip':
            $ip = $input['ip'] ?? '';
            
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'error' => 'IP inválido']);
                exit;
            }
            
            $result = RateLimiter::unblacklistIP($pdo, $ip);
            echo json_encode(['success' => $result, 'message' => $result ? 'IP removido da blacklist' : 'IP não encontrado']);
            break;
        
        // ============================================
        // LISTAR WALLETS BANIDAS
        // ============================================
        case 'list_banned':
            $stmt = $pdo->query("
                SELECT wallet_address, ban_reason, created_at 
                FROM players 
                WHERE is_banned = 1 
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $banned = $stmt->fetchAll();
            echo json_encode(['success' => true, 'banned' => $banned]);
            break;
        
        // ============================================
        // LISTAR IPS NA BLACKLIST
        // ============================================
        case 'list_blacklist':
            $stmt = $pdo->query("
                SELECT ip_address, reason, created_at, expires_at 
                FROM ip_blacklist 
                WHERE expires_at IS NULL OR expires_at > NOW()
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $blacklist = $stmt->fetchAll();
            echo json_encode(['success' => true, 'blacklist' => $blacklist]);
            break;
        
        // ============================================
        // LISTAR SESSÕES FLAGGED
        // ============================================
        case 'list_flagged':
            $stmt = $pdo->query("
                SELECT 
                    gs.id,
                    gs.wallet_address,
                    gs.mission_number,
                    gs.asteroids_destroyed,
                    gs.earnings_usdt,
                    gs.client_score,
                    gs.client_earnings,
                    gs.validation_errors,
                    gs.created_at
                FROM game_sessions gs
                WHERE gs.status = 'flagged'
                ORDER BY gs.created_at DESC
                LIMIT 100
            ");
            $flagged = $stmt->fetchAll();
            echo json_encode(['success' => true, 'flagged' => $flagged]);
            break;
        
        // ============================================
        // LISTAR ATIVIDADES SUSPEITAS
        // ============================================
        case 'list_suspicious':
            $stmt = $pdo->query("
                SELECT 
                    wallet_address,
                    activity_type,
                    activity_data,
                    ip_address,
                    devtools_detected,
                    created_at
                FROM suspicious_activity
                ORDER BY created_at DESC
                LIMIT 200
            ");
            $suspicious = $stmt->fetchAll();
            echo json_encode(['success' => true, 'suspicious' => $suspicious]);
            break;
        
        // ============================================
        // LIMPAR DADOS ANTIGOS
        // ============================================
        case 'cleanup':
            // Limpar rate_limits antigos
            $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
            
            // Limpar suspicious_activity antigos (mais de 7 dias)
            $pdo->exec("DELETE FROM suspicious_activity WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            
            // Limpar blacklist expirada
            $pdo->exec("DELETE FROM ip_blacklist WHERE expires_at IS NOT NULL AND expires_at < NOW()");
            
            echo json_encode(['success' => true, 'message' => 'Limpeza concluída']);
            break;
        
        // ============================================
        // BUSCAR JOGADOR
        // ============================================
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
                SELECT id, mission_number, status, asteroids_destroyed, earnings_usdt, created_at
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
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
}
?>
