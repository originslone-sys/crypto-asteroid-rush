<?php
// ============================================
// UNOBIX - Autenticação Google
// api/auth-google.php v7.0
// Usa config.php
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $input = getRequestInput();
    $action = $input['action'] ?? 'login';
    
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        jsonResponse(['success' => false, 'error' => 'Erro de conexão com banco'], 500);
    }
    
    switch ($action) {
        case 'verify':
        case 'login':
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');
            $email = trim($input['email'] ?? '');
            $displayName = trim($input['display_name'] ?? $input['displayName'] ?? $input['name'] ?? '');
            
            if (empty($googleUid)) {
                jsonResponse(['success' => false, 'error' => 'google_uid obrigatório'], 400);
            }
            
            if (!validateGoogleUid($googleUid)) {
                jsonResponse(['success' => false, 'error' => 'google_uid inválido'], 400);
            }
            
            $googleUid = substr($googleUid, 0, 128);
            $email = substr($email, 0, 255);
            $displayName = substr($displayName, 0, 100);

            // Verificar limite de 1 conta por IP
            $clientIP = getClientIP();

            // Verificar VPN/Proxy via proxycheck.io
            ensureProxyCacheTable($pdo);
            $proxyCheck = checkProxyVPN($clientIP, $pdo);
            if ($proxyCheck['is_proxy']) {
                secureLog("VPN_PROXY_BLOCKED_LOGIN | IP: $clientIP | Type: {$proxyCheck['type']} | Provider: {$proxyCheck['provider']} | UID: " . substr($googleUid, 0, 15));
                jsonResponse([
                    'success' => false,
                    'error' => 'Detectamos que você está usando VPN ou Proxy. Por segurança, desative a VPN/Proxy e tente novamente.',
                    'error_code' => 'VPN_PROXY_DETECTED',
                    'proxy_type' => $proxyCheck['type']
                ], 403);
            }

            $stmt = $pdo->prepare("
                SELECT google_uid, display_name
                FROM users
                WHERE last_login_ip = ?
                AND google_uid != ?
                AND last_login_ip IS NOT NULL
                AND last_login_ip != '0.0.0.0'
                LIMIT 1
            ");
            $stmt->execute([$clientIP, $googleUid]);
            $existingAccount = $stmt->fetch();

            if ($existingAccount) {
                secureLog("IP_ACCOUNT_LIMIT | IP: $clientIP | Attempted: " . substr($googleUid, 0, 15) . " | Existing: " . substr($existingAccount['google_uid'], 0, 15));
                jsonResponse([
                    'success' => false,
                    'error' => 'Já existe uma conta conectada neste dispositivo/rede. Apenas uma conta por IP é permitida.',
                    'error_code' => 'IP_ACCOUNT_LIMIT',
                    'existing_account' => substr($existingAccount['display_name'] ?? 'Outra conta', 0, 3) . '***'
                ], 403);
            }

            // UPSERT
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    google_uid, email, display_name,
                    balance_brl, total_played, total_earned_brl,
                    last_login_ip,
                    created_at, updated_at, last_login
                ) VALUES (?, ?, ?, 0, 0, 0, ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    email = COALESCE(VALUES(email), email),
                    display_name = COALESCE(VALUES(display_name), display_name),
                    last_login_ip = VALUES(last_login_ip),
                    last_login = NOW(),
                    updated_at = NOW()
            ");
            $stmt->execute([$googleUid, $email, $displayName, $clientIP]);
            
            // Detectar se é novo usuário (INSERT vs UPDATE)
            $isNewUser = ($stmt->rowCount() === 1);
            
            // Buscar usuário
            $user = findPlayer($pdo, $googleUid);
            
            if (!$user) {
                jsonResponse(['success' => false, 'error' => 'Usuário não encontrado'], 500);
            }
            
            // Verificar ban
            if (!empty($user['is_banned'])) {
                jsonResponse([
                    'success' => false, 
                    'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violação dos termos'),
                    'banned' => true
                ], 403);
            }
            
            // ============================================
            // REFERRAL: registrar indicação se novo usuário com código
            // ============================================
            $referralCode = trim($input['referral_code'] ?? $input['ref'] ?? '');
            if ($isNewUser && !empty($referralCode)) {
                try {
                    require_once __DIR__ . '/referral-helper.php';
                    $refOwner = validateReferralCode($pdo, $referralCode);
                    
                    if ($refOwner && $refOwner['google_uid'] !== $googleUid) {
                        // Verificar se já não foi registrado
                        $checkStmt = $pdo->prepare("
                            SELECT id FROM referrals WHERE referred_google_uid = ? LIMIT 1
                        ");
                        $checkStmt->execute([$googleUid]);
                        
                        if (!$checkStmt->fetch()) {
                            $stmt = $pdo->prepare("
                                INSERT INTO referrals (
                                    referrer_google_uid, referrer_wallet,
                                    referred_google_uid, referred_wallet,
                                    referral_code, missions_at_register,
                                    missions_completed, missions_required,
                                    status, commission_brl, created_at
                                ) VALUES (?, '', ?, '', ?, 0, 0, 100, 'pending', 1.000000, NOW())
                            ");
                            $stmt->execute([
                                $refOwner['google_uid'],
                                $googleUid,
                                strtoupper($referralCode)
                            ]);
                            
                            // Incrementar uses_count
                            $pdo->prepare("
                                UPDATE referral_codes SET uses_count = uses_count + 1
                                WHERE code = ?
                            ")->execute([strtoupper($referralCode)]);
                            
                            secureLog("REFERRAL_AUTO_REGISTERED | Referrer: {$refOwner['google_uid']} | Referred: {$googleUid} | Code: {$referralCode}");
                        }
                    }
                } catch (Exception $e) {
                    // Não falhar o login por erro de referral
                    error_log("Referral auto-register error: " . $e->getMessage());
                }
            }
            
            // Gerar session token
            $sessionToken = hash('sha256', 
                $googleUid . '|' . $user['id'] . '|' . microtime(true) . '|' . bin2hex(random_bytes(16))
            );
            
            secureLog("AUTH_LOGIN | User: {$user['id']} | UID: " . substr($googleUid, 0, 15) . ($isNewUser ? ' | NEW_USER' : ''));
            
            jsonResponse([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'session_token' => $sessionToken,
                'is_new_user' => $isNewUser,
                'user' => [
                    'id' => (int)$user['id'],
                    'google_uid' => $user['google_uid'],
                    'email' => $user['email'] ?? '',
                    'display_name' => $user['display_name'] ?? '',
                    'balance_brl' => (float)($user['balance_brl'] ?? 0),
                    'total_played' => (int)($user['total_played'] ?? 0),
                    'total_earned_brl' => (float)($user['total_earned_brl'] ?? 0),
                    'created_at' => $user['created_at'] ?? '',
                    'last_login' => $user['last_login'] ?? ''
                ]
            ]);
            break;
            
        case 'logout':
            jsonResponse(['success' => true, 'message' => 'Logout realizado']);
            break;
            
        case 'profile':
        case 'balance':
            $googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? '');
            
            if (empty($googleUid)) {
                jsonResponse(['success' => false, 'error' => 'google_uid necessário'], 400);
            }
            
            $user = findPlayer($pdo, $googleUid);
            
            if (!$user) {
                jsonResponse(['success' => false, 'error' => 'Usuário não encontrado'], 404);
            }
            
            jsonResponse([
                'success' => true,
                'user' => [
                    'google_uid' => $user['google_uid'],
                    'email' => $user['email'] ?? '',
                    'display_name' => $user['display_name'] ?? '',
                    'balance_brl' => (float)($user['balance_brl'] ?? 0),
                    'total_played' => (int)($user['total_played'] ?? 0),
                    'total_earned_brl' => (float)($user['total_earned_brl'] ?? 0)
                ],
                'balance_brl' => (float)($user['balance_brl'] ?? 0)
            ]);
            break;
            
        default:
            jsonResponse(['success' => false, 'error' => 'Ação inválida: ' . $action], 400);
    }
    
} catch (PDOException $e) {
    secureLog("AUTH_ERROR: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erro no banco de dados'], 500);
    
} catch (Throwable $e) {
    secureLog("AUTH_EXCEPTION: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erro interno'], 500);
}
