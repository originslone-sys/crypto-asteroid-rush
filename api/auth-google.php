<?php
// ============================================
// UNOBIX - Autenticação Google (Firebase)
// Arquivo: api/auth-google.php
// v4.0 - Login com Google OAuth via Firebase
// ============================================

require_once __DIR__ . "/config.php";

setCORSHeaders();
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (is_array($input)) {
    $_POST = array_merge($_POST, $input);
}

$input = getRequestInput();
$action = $input['action'] ?? 'verify';

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    switch ($action) {
        
        // ==========================================
        // VERIFICAR/CRIAR USUÁRIO
        // ==========================================
        case 'verify':
        case 'login':
            $idToken = $input['id_token'] ?? $input['idToken'] ?? null;
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? null;
            $email = $input['email'] ?? null;
            $displayName = $input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? null;
            $photoUrl = $input['photo_url'] ?? $input['photoUrl'] ?? $input['photoURL'] ?? null;

            // Se temos idToken, validar com Firebase (opcional - pode implementar depois)
            // Por enquanto, confiamos no frontend para enviar dados corretos
            
            if (!$googleUid) {
                jsonResponse(false, ['error' => 'google_uid é obrigatório'], 400);
            }

            if (!validateGoogleUID($googleUid)) {
                jsonResponse(false, ['error' => 'google_uid inválido'], 400);
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
                    
                    if ($email && $email !== $player['email']) {
                        $updates[] = "email = ?";
                        $params[] = $email;
                    }
                    if ($displayName && $displayName !== $player['display_name']) {
                        $updates[] = "display_name = ?";
                        $params[] = $displayName;
                    }
                    if ($photoUrl && $photoUrl !== $player['photo_url']) {
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
                if ($player['is_banned']) {
                    jsonResponse(false, [
                        'error' => 'Conta suspensa',
                        'message' => $player['ban_reason'] ?? 'Entre em contato com o suporte.'
                    ], 403);
                }

                // Gerar token de sessão
                $sessionToken = generateSessionToken($googleUid, $player['id']);
                
                // Registrar sessão
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

                jsonResponse(true, [
                    'message' => 'Login realizado com sucesso',
                    'is_new_user' => false,
                    'session_token' => $sessionToken,
                    'player' => [
                        'id' => $player['id'],
                        'google_uid' => $googleUid,
                        'email' => $player['email'],
                        'display_name' => $player['display_name'],
                        'photo_url' => $player['photo_url'],
                        'balance_brl' => number_format((float)$player['balance_brl'], 2, '.', ''),
                        'total_played' => (int)$player['total_played']
                    ]
                ]);

            } else {
                // Novo usuário - criar conta
                // Gerar wallet_address placeholder (para compatibilidade com código legado)
                $placeholderWallet = '0x' . substr(hash('sha256', $googleUid . time()), 0, 40);
                
                $stmt = $pdo->prepare("
                    INSERT INTO players (google_uid, email, display_name, photo_url, wallet_address, balance_brl, created_at)
                    VALUES (?, ?, ?, ?, ?, 0.00, NOW())
                ");
                $stmt->execute([$googleUid, $email, $displayName, $photoUrl, $placeholderWallet]);
                
                $playerId = $pdo->lastInsertId();

                // Gerar token de sessão
                $sessionToken = generateSessionToken($googleUid, $playerId);
                
                // Registrar sessão
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

                // Log de novo usuário
                secureLog("Novo usuário: $googleUid | $email", 'new_users.log');

                jsonResponse(true, [
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
        // VERIFICAR SESSÃO
        // ==========================================
        case 'check_session':
            $sessionToken = $input['session_token'] ?? $input['sessionToken'] ?? null;
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

            if (!$sessionToken || !$googleUid) {
                jsonResponse(false, ['error' => 'session_token e google_uid são obrigatórios'], 400);
            }

            $stmt = $pdo->prepare("
                SELECT us.*, p.is_banned, p.ban_reason
                FROM user_sessions us
                JOIN players p ON p.google_uid = us.google_uid
                WHERE us.session_token = ? 
                AND us.google_uid = ?
                AND us.is_active = 1
                AND (us.expires_at IS NULL OR us.expires_at > NOW())
                LIMIT 1
            ");
            $stmt->execute([$sessionToken, $googleUid]);
            $session = $stmt->fetch();

            if (!$session) {
                jsonResponse(false, ['error' => 'Sessão inválida ou expirada'], 401);
            }

            if ($session['is_banned']) {
                jsonResponse(false, [
                    'error' => 'Conta suspensa',
                    'message' => $session['ban_reason'] ?? 'Entre em contato com o suporte.'
                ], 403);
            }

            // Atualizar last_activity
            $stmt = $pdo->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE id = ?");
            $stmt->execute([$session['id']]);

            jsonResponse(true, [
                'valid' => true,
                'google_uid' => $googleUid
            ]);
            break;

        // ==========================================
        // LOGOUT
        // ==========================================
        case 'logout':
            $sessionToken = $input['session_token'] ?? $input['sessionToken'] ?? null;
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

            if ($sessionToken) {
                $stmt = $pdo->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ?");
                $stmt->execute([$sessionToken]);
            } elseif ($googleUid) {
                // Logout de todas as sessões
                $stmt = $pdo->prepare("UPDATE user_sessions SET is_active = 0 WHERE google_uid = ?");
                $stmt->execute([$googleUid]);
            }

            jsonResponse(true, ['message' => 'Logout realizado com sucesso']);
            break;

        // ==========================================
        // OBTER PERFIL
        // ==========================================
        case 'profile':
            $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

            if (!$googleUid) {
                jsonResponse(false, ['error' => 'google_uid é obrigatório'], 400);
            }

            $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$googleUid]);
            $player = $stmt->fetch();

            if (!$player) {
                jsonResponse(false, ['error' => 'Usuário não encontrado'], 404);
            }

            // Estatísticas adicionais
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_sessions,
                    SUM(asteroids_destroyed) as total_asteroids,
                    MAX(asteroids_destroyed) as best_score
                FROM game_sessions 
                WHERE google_uid = ? OR wallet_address = ?
            ");
            $stmt->execute([$googleUid, $player['wallet_address']]);
            $stats = $stmt->fetch();

            jsonResponse(true, [
                'player' => [
                    'google_uid' => $player['google_uid'],
                    'email' => $player['email'],
                    'display_name' => $player['display_name'],
                    'photo_url' => $player['photo_url'],
                    'balance_brl' => number_format((float)$player['balance_brl'], 2, '.', ''),
                    'staked_balance_brl' => number_format((float)$player['staked_balance_brl'], 2, '.', ''),
                    'total_earned_brl' => number_format((float)$player['total_earned_brl'], 2, '.', ''),
                    'total_withdrawn_brl' => number_format((float)$player['total_withdrawn_brl'], 2, '.', ''),
                    'total_played' => (int)$player['total_played'],
                    'created_at' => $player['created_at']
                ],
                'stats' => [
                    'total_sessions' => (int)($stats['total_sessions'] ?? 0),
                    'total_asteroids' => (int)($stats['total_asteroids'] ?? 0),
                    'best_score' => (int)($stats['best_score'] ?? 0)
                ]
            ]);
            break;

        default:
            jsonResponse(false, ['error' => 'Ação inválida'], 400);
    }

} catch (Exception $e) {
    error_log("auth-google.php error: " . $e->getMessage());
    jsonResponse(false, ['error' => 'Erro no servidor: ' . $e->getMessage()], 500);
}
?>
