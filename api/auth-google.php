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

            $clientIP = getClientIP();

            // ── Verificar limite de contas por IP (novos E existentes) ──
            $existingUser = $pdo->prepare("SELECT id FROM users WHERE google_uid = ? LIMIT 1");
            $existingUser->execute([$googleUid]);
            $isExisting = (bool)$existingUser->fetchColumn();

            // Bloquear novos cadastros se desativado pelo admin
            if (!$isExisting) {
                $regSt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'registrations_enabled' LIMIT 1");
                $regVal = $regSt ? $regSt->fetchColumn() : false;
                if ($regVal !== false && ($regVal === 'false' || $regVal === '0')) {
                    jsonResponse([
                        'success' => false,
                        'error'   => 'O cadastro de novas contas está temporariamente desativado. Tente novamente mais tarde.',
                        'registrations_disabled' => true
                    ], 403);
                }
            }

            if ($clientIP) {
                $blockSetting = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'block_multiple_ip_accounts' LIMIT 1");
                $blockEnabled = $blockSetting ? (int)$blockSetting->fetchColumn() : 1;

                if ($blockEnabled) {
                    if (!$isExisting) {
                        // Novo usuário: checar registration_ip
                        $ipCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE registration_ip = ? LIMIT 1");
                        $ipCheck->execute([$clientIP]);
                        if ((int)$ipCheck->fetchColumn() > 0) {
                            jsonResponse([
                                'success'  => false,
                                'error'    => 'Já existe uma conta cadastrada neste dispositivo/rede. Cada IP permite apenas uma conta.',
                                'ip_limit' => true
                            ], 403);
                        }
                    } else {
                        // Usuário existente: checar se outro google_uid usou este IP nas últimas 24h
                        $ipActive = $pdo->prepare("
                            SELECT COUNT(*) FROM users
                            WHERE last_login_ip = ?
                              AND google_uid != ?
                              AND last_login >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            LIMIT 1
                        ");
                        $ipActive->execute([$clientIP, $googleUid]);
                        if ((int)$ipActive->fetchColumn() > 0) {
                            jsonResponse([
                                'success'  => false,
                                'error'    => 'Outra conta está ativa neste dispositivo/rede. Cada IP permite apenas uma conta por vez.',
                                'ip_limit' => true
                            ], 403);
                        }
                    }
                }
            }

            // UPSERT
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    google_uid, email, display_name,
                    balance_brl, total_played, total_earned_brl,
                    last_login_ip, registration_ip,
                    created_at, updated_at, last_login
                ) VALUES (?, ?, ?, 0, 0, 0, ?, ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    email = COALESCE(VALUES(email), email),
                    display_name = COALESCE(VALUES(display_name), display_name),
                    last_login_ip = VALUES(last_login_ip),
                    last_login = NOW(),
                    updated_at = NOW()
            ");
            $stmt->execute([$googleUid, $email, $displayName, $clientIP, $clientIP]);
            
            // Detectar se é novo usuário (INSERT vs UPDATE)
            $isNewUser = ($stmt->rowCount() === 1);

            // Buscar usuário
            $user = findPlayer($pdo, $googleUid);

            // Bônus de boas-vindas: creditar créditos grátis para novos usuários
            if ($isNewUser && $user && defined('WELCOME_BONUS_CREDITS') && WELCOME_BONUS_CREDITS > 0) {
                try {
                    $pdo->prepare("
                        UPDATE users SET credits = credits + ? WHERE google_uid = ?
                    ")->execute([WELCOME_BONUS_CREDITS, $googleUid]);

                    $pdo->prepare("
                        INSERT INTO transactions (
                            google_uid, type, amount, amount_brl,
                            description, status, created_at
                        ) VALUES (?, 'welcome_bonus', ?, 0, ?, 'completed', NOW())
                    ")->execute([
                        $googleUid,
                        WELCOME_BONUS_CREDITS,
                        'Bônus de boas-vindas: ' . WELCOME_BONUS_CREDITS . ' créditos grátis'
                    ]);

                    // Atualizar objeto local
                    $user['credits'] = (int)($user['credits'] ?? 0) + WELCOME_BONUS_CREDITS;

                    secureLog("WELCOME_BONUS | UID: " . substr($googleUid, 0, 15) . " | Credits: " . WELCOME_BONUS_CREDITS);
                } catch (Exception $e) {
                    error_log("Welcome bonus error: " . $e->getMessage());
                }
            }
            
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
            // REFERRAL: registrar indicação APENAS para novos usuários
            // Usuários existentes que clicam em link de indicação NÃO são adicionados
            // ============================================
            $referralRegistered = false;
            $referralCode = trim($input['referral_code'] ?? $input['ref'] ?? '');
            if (!empty($referralCode) && $isNewUser) {
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
                            // Ler settings do admin (com fallback)
                            $missionsReq = 20;
                            $commissionBrl = 5.000000;
                            $settingsTable = $pdo->query("SHOW TABLES LIKE 'game_settings'")->fetch();
                            if ($settingsTable) {
                                $sStmt = $pdo->prepare("SELECT setting_key, setting_value FROM game_settings WHERE setting_key IN ('referral_missions_required', 'referral_bonus_brl')");
                                $sStmt->execute();
                                foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                                    if ($s['setting_key'] === 'referral_missions_required') $missionsReq = max(1, (int)$s['setting_value']);
                                    if ($s['setting_key'] === 'referral_bonus_brl') $commissionBrl = max(0, (float)$s['setting_value']);
                                }
                            }

                            // Buscar missions_at_register do usuário
                            $mStmt = $pdo->prepare("SELECT total_played FROM users WHERE google_uid = ? LIMIT 1");
                            $mStmt->execute([$googleUid]);
                            $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
                            $missionsAtRegister = $mRow ? (int)$mRow['total_played'] : 0;

                            $stmt = $pdo->prepare("
                                INSERT INTO referrals (
                                    referrer_google_uid, referrer_wallet,
                                    referred_google_uid, referred_wallet,
                                    referral_code, missions_at_register,
                                    missions_completed, missions_required,
                                    status, commission_brl, created_at
                                ) VALUES (?, '', ?, '', ?, ?, 0, ?, 'pending', ?, NOW())
                            ");
                            $stmt->execute([
                                $refOwner['google_uid'],
                                $googleUid,
                                strtoupper($referralCode),
                                $missionsAtRegister,
                                $missionsReq,
                                $commissionBrl
                            ]);
                            
                            // Incrementar uses_count
                            $pdo->prepare("
                                UPDATE referral_codes SET uses_count = uses_count + 1
                                WHERE code = ?
                            ")->execute([strtoupper($referralCode)]);
                            
                            $referralRegistered = true;
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
                'referral_registered' => $referralRegistered,
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
