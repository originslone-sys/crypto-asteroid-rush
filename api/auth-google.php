<?php
// ============================================
// UNOBIX - Autenticação Google (Firebase)
// Arquivo: api/auth-google.php
// v4.1 - Auto-criar tabelas + melhor erro handling
// ============================================

require_once __DIR__ . "/config.php";

// Desabilitar exibição de erros HTML
ini_set('display_errors', 0);
error_reporting(E_ALL);

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (is_array($input)) {
    $_POST = array_merge($_POST, $input);
}

$input = array_merge($_GET, $_POST, $input ?? []);
$action = $input['action'] ?? 'login';

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Falha na conexão com o banco']);
        exit;
    }

    // Criar tabela user_sessions se não existir
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) NOT NULL,
            session_token VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            is_active TINYINT(1) DEFAULT 1,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_google_uid (google_uid),
            INDEX idx_token (session_token),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    switch ($action) {
        
        // ==========================================
        // VERIFICAR/CRIAR USUÁRIO
        // ==========================================
        case 'verify':
        case 'login':
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? null;
            $email = $input['email'] ?? null;
            $displayName = $input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? null;
            $photoUrl = $input['photo_url'] ?? $input['photoUrl'] ?? $input['photoURL'] ?? null;

            if (!$googleUid) {
                echo json_encode(['success' => false, 'error' => 'google_uid é obrigatório']);
                exit;
            }

            // Validação simples do google_uid
            if (strlen($googleUid) < 10 || strlen($googleUid) > 128) {
                echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
                exit;
            }

            // Verificar se usuário já existe
            $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $player = $stmt->fetch();

            if ($player) {
                // Usuário existe - atualizar dados se necessário
                if ($email || $displayName || $photoUrl) {
                    $updates = [];
                    $params = [];
                    
                    if ($email && $email !== ($player['email'] ?? '')) {
                        $updates[] = "email = ?";
                        $params[] = $email;
                    }
                    if ($displayName && $displayName !== ($player['display_name'] ?? '')) {
                        $updates[] = "display_name = ?";
                        $params[] = $displayName;
                    }
                    if ($photoUrl && $photoUrl !== ($player['photo_url'] ?? '')) {
                        $updates[] = "photo_url = ?";
                        $params[] = $photoUrl;
                    }
                    
                    if (!empty($updates)) {
                        $params[] = $googleUid;
                        $sql = "UPDATE players SET " . implode(', ', $updates) . " WHERE google_uid = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                    }
                }

                // Verificar se está banido
                if (!empty($player['is_banned']) && $player['is_banned']) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Conta suspensa',
                        'message' => $player['ban_reason'] ?? 'Entre em contato com o suporte.'
                    ]);
                    exit;
                }

                // Gerar token de sessão
                $sessionToken = hash('sha256', $googleUid . '|' . $player['id'] . '|' . time() . '|' . bin2hex(random_bytes(16)));
                
                // Registrar sessão (silencioso - não falha se der erro)
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_sessions (google_uid, session_token, ip_address, user_agent, expires_at)
                        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                    ");
                    $stmt->execute([
                        $googleUid,
                        $sessionToken,
                        getClientIP(),
                        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
                    ]);
                } catch (Exception $e) {
                    // Ignorar erro de sessão - não é crítico
                    error_log("Erro ao criar sessão: " . $e->getMessage());
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Login realizado com sucesso',
                    'is_new_user' => false,
                    'session_token' => $sessionToken,
                    'player' => [
                        'id' => $player['id'],
                        'google_uid' => $googleUid,
                        'email' => $player['email'] ?? '',
                        'display_name' => $player['display_name'] ?? '',
                        'photo_url' => $player['photo_url'] ?? '',
                        'balance_brl' => number_format((float)($player['balance_brl'] ?? 0), 2, '.', ''),
                        'total_played' => (int)($player['total_played'] ?? 0)
                    ]
                ]);

            } else {
                // Novo usuário - criar conta
                // Gerar wallet_address placeholder (para compatibilidade)
                $placeholderWallet = '0x' . substr(hash('sha256', $googleUid . time()), 0, 40);
                
                $stmt = $pdo->prepare("
                    INSERT INTO players (google_uid, email, display_name, photo_url, wallet_address, balance_brl, total_played, created_at)
                    VALUES (?, ?, ?, ?, ?, 0.00, 0, NOW())
                ");
                $stmt->execute([$googleUid, $email, $displayName, $photoUrl, $placeholderWallet]);
                
                $playerId = $pdo->lastInsertId();

                // Gerar token de sessão
                $sessionToken = hash('sha256', $googleUid . '|' . $playerId . '|' . time() . '|' . bin2hex(random_bytes(16)));
                
                // Registrar sessão (silencioso)
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_sessions (google_uid, session_token, ip_address, user_agent, expires_at)
                        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
                    ");
                    $stmt->execute([
                        $googleUid,
                        $sessionToken,
                        getClientIP(),
                        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
                    ]);
                } catch (Exception $e) {
                    error_log("Erro ao criar sessão: " . $e->getMessage());
                }

                // Log de novo usuário
                if (function_exists('secureLog')) {
                    secureLog("Novo usuário: $googleUid | $email", 'new_users.log');
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Conta criada com sucesso! Bem-vindo ao Unobix!',
                    'is_new_user' => true,
                    'session_token' => $sessionToken,
                    'player' => [
                        'id' => $playerId,
                        'google_uid' => $googleUid,
                        'email' => $email,
                        'display_name' => $displayName,
                        'photo_url' => $photoUrl,
                        'balance_brl' => '0.00',
                        'total_played' => 0
                    ]
                ]);
            }
            break;

        // ==========================================
        // LOGOUT
        // ==========================================
        case 'logout':
            $sessionToken = $input['session_token'] ?? $input['sessionToken'] ?? null;
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

            if ($sessionToken) {
                $pdo->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ?")->execute([$sessionToken]);
            } elseif ($googleUid) {
                $pdo->prepare("UPDATE user_sessions SET is_active = 0 WHERE google_uid = ?")->execute([$googleUid]);
            }

            echo json_encode(['success' => true, 'message' => 'Logout realizado com sucesso']);
            break;

        // ==========================================
        // OBTER PERFIL
        // ==========================================
        case 'profile':
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

            if (!$googleUid) {
                echo json_encode(['success' => false, 'error' => 'google_uid é obrigatório']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $player = $stmt->fetch();

            if (!$player) {
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'player' => [
                    'google_uid' => $player['google_uid'],
                    'email' => $player['email'] ?? '',
                    'display_name' => $player['display_name'] ?? '',
                    'photo_url' => $player['photo_url'] ?? '',
                    'balance_brl' => number_format((float)($player['balance_brl'] ?? 0), 2, '.', ''),
                    'total_earned_brl' => number_format((float)($player['total_earned_brl'] ?? 0), 2, '.', ''),
                    'total_played' => (int)($player['total_played'] ?? 0),
                    'created_at' => $player['created_at'] ?? ''
                ]
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }

} catch (Exception $e) {
    error_log("auth-google.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
