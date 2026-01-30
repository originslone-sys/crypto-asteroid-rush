<?php
// ============================================
// UNOBIX - Autenticação Google (Firebase)
// Arquivo: api/auth-google.php
// Unobix-only: google_uid como identidade, sem wallet placeholder
// ============================================

require_once __DIR__ . "/config.php";

ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function readJsonInput(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;
    return [];
}

$input = array_merge($_GET, $_POST, readJsonInput());
$action = $input['action'] ?? 'login';

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Falha na conexão com o banco']);
        exit;
    }

    // user_sessions já existe no seu banco, mas mantemos garantia
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) NOT NULL,
            session_token VARCHAR(255) NOT NULL,
            firebase_token TEXT NULL,
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_session_token (session_token),
            KEY idx_google_uid (google_uid),
            KEY idx_active (is_active),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    switch ($action) {
        case 'verify':
        case 'login': {
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? null;
            $email = $input['email'] ?? null;
            $displayName = $input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? null;
            $photoUrl = $input['photo_url'] ?? $input['photoUrl'] ?? $input['photoURL'] ?? null;
            $firebaseToken = $input['firebase_token'] ?? $input['idToken'] ?? null;

            $googleUid = is_string($googleUid) ? trim($googleUid) : '';
            if (!$googleUid || !validateGoogleUid($googleUid)) {
                echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
                exit;
            }

            // upsert player (sem wallet placeholder)
            // mantém colunas existentes, mas não obriga wallet
            $stmt = $pdo->prepare("
                INSERT INTO players (google_uid, email, display_name, photo_url, balance_brl, total_played, created_at, updated_at)
                VALUES (?, ?, ?, ?, 0.00, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    email = COALESCE(VALUES(email), email),
                    display_name = COALESCE(VALUES(display_name), display_name),
                    photo_url = COALESCE(VALUES(photo_url), photo_url),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $googleUid,
                $email ?: null,
                $displayName ?: null,
                $photoUrl ?: null,
            ]);

            // buscar player
            $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $player = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$player) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Falha ao carregar jogador']);
                exit;
            }

            if (!empty($player['is_banned'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Conta suspensa',
                    'message' => $player['ban_reason'] ?? 'Entre em contato com o suporte.'
                ]);
                exit;
            }

            // criar sessão
            $sessionToken = hash('sha256', $googleUid . '|' . ($player['id'] ?? 0) . '|' . microtime(true) . '|' . bin2hex(random_bytes(16)));

            $stmt = $pdo->prepare("
                INSERT INTO user_sessions (google_uid, session_token, firebase_token, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ");
            $stmt->execute([
                $googleUid,
                $sessionToken,
                $firebaseToken,
                getClientIP(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'session_token' => $sessionToken,
                'player' => [
                    'id' => (int)$player['id'],
                    'google_uid' => $googleUid,
                    'email' => $player['email'] ?? '',
                    'display_name' => $player['display_name'] ?? '',
                    'photo_url' => $player['photo_url'] ?? '',
                    'balance_brl' => number_format((float)($player['balance_brl'] ?? 0), 2, '.', ''),
                    'total_played' => (int)($player['total_played'] ?? 0)
                ]
            ]);
            break;
        }

        case 'logout': {
            $sessionToken = $input['session_token'] ?? $input['sessionToken'] ?? null;
            $sessionToken = is_string($sessionToken) ? trim($sessionToken) : '';

            if ($sessionToken) {
                $pdo->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ?")->execute([$sessionToken]);
            }

            echo json_encode(['success' => true, 'message' => 'Logout realizado com sucesso']);
            break;
        }

        case 'profile': {
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;
            $googleUid = is_string($googleUid) ? trim($googleUid) : '';

            if (!$googleUid || !validateGoogleUid($googleUid)) {
                echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $player = $stmt->fetch(PDO::FETCH_ASSOC);

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
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }

} catch (Throwable $e) {
    error_log("auth-google.php error: " . $e->getMessage());
    if (function_exists('secureLog')) secureLog("AUTH_GOOGLE_ERROR | " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
