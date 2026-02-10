<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo
// api/game-start.php v6.0 - Barreira Pré-Jogo
// TODAS as verificações ANTES de criar sessão
// Retorna códigos específicos para feedback no frontend
// ============================================

require_once __DIR__ . "/config.php";

// Carregar rate limiter (opcional)
$_rateLimiterLoaded = false;
if (file_exists(__DIR__ . "/rate-limiter.php")) {
    try {
        require_once __DIR__ . "/rate-limiter.php";
        $_rateLimiterLoaded = class_exists('RateLimiter');
    } catch (Throwable $e) {
        error_log("rate-limiter load error: " . $e->getMessage());
    }
}

// Carregar proxy check (opcional)
$_proxyCheckLoaded = false;
if (file_exists(__DIR__ . "/proxy-check.php")) {
    try {
        require_once __DIR__ . "/proxy-check.php";
        $_proxyCheckLoaded = function_exists('checkProxyVPN');
    } catch (Throwable $e) {
        error_log("proxy-check load error: " . $e->getMessage());
    }
}

setCorsHeaders();

$input = getRequestInput();

if (empty($input['google_uid'])) {
    echo json_encode(['success' => false, 'error' => 'google_uid não fornecido', 'block_reason' => 'auth_required']);
    exit;
}

$googleUid = trim($input['google_uid']);

// Permitir UID truncado com ...
if (strpos($googleUid, '...') === false && !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido', 'block_reason' => 'auth_required']);
    exit;
}

$clientIP = getClientIP();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }
    
    // ============================================================
    // ██████  BARREIRA PRÉ-JOGO — VERIFICAÇÕES NA ORDEM CORRETA
    // Cada verificação retorna block_reason específico para o frontend
    // ============================================================
    
    // ──────────────────────────────────────────────
    // CHECK 1: USUÁRIO EXISTE?
    // ──────────────────────────────────────────────
    $user = findPlayer($pdo, $googleUid);
    
    if (!$user) {
        echo json_encode([
            'success' => false,
            'error' => 'Usuário não encontrado. Faça login primeiro.',
            'block_reason' => 'auth_required'
        ]);
        exit;
    }
    
    $userId = (int)$user['id'];
    $realGoogleUid = $user['google_uid'];
    
    // ──────────────────────────────────────────────
    // CHECK 2: CONTA BANIDA?
    // ──────────────────────────────────────────────
    if (!empty($user['is_banned'])) {
        secureLog("BLOCK_BANNED | User: {$userId} | UID: " . substr($realGoogleUid, 0, 15));
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violação dos termos de uso'),
            'block_reason' => 'banned',
            'ban_reason' => $user['ban_reason'] ?? 'Violação dos termos de uso'
        ]);
        exit;
    }
    
    // ──────────────────────────────────────────────
    // CHECK 3: IP NA BLACKLIST?
    // ──────────────────────────────────────────────
    if ($_rateLimiterLoaded) {
        try {
            $limiter = new RateLimiter($pdo, null, $realGoogleUid);
            
            $blacklistCheck = $limiter->checkIPBlacklist();
            if (isset($blacklistCheck['allowed']) && !$blacklistCheck['allowed']) {
                secureLog("BLOCK_BLACKLIST | IP: {$clientIP} | User: {$userId}");
                echo json_encode([
                    'success' => false,
                    'error' => 'Seu acesso foi bloqueado temporariamente.',
                    'block_reason' => 'ip_blocked'
                ]);
                exit;
            }
        } catch (Throwable $e) {
            error_log("blacklist check error (non-blocking): " . $e->getMessage());
        }
    }
    
    // ──────────────────────────────────────────────
    // CHECK 4: VPN / PROXY / TOR?
    // ──────────────────────────────────────────────
    if ($_proxyCheckLoaded) {
        try {
            $proxyResult = checkProxyVPN($pdo);
            if (isset($proxyResult['allowed']) && !$proxyResult['allowed']) {
                $proxyType = $proxyResult['type'] ?? 'proxy';
                secureLog("BLOCK_VPN | IP: {$clientIP} | Type: {$proxyType} | User: {$userId}");
                
                // Mensagem específica por tipo
                $vpnMessage = 'Detectamos que você está usando VPN ou proxy. Desative para jogar.';
                if (!empty($proxyResult['is_tor'])) {
                    $vpnMessage = 'Conexão via TOR não é permitida. Use uma conexão direta para jogar.';
                } elseif (!empty($proxyResult['is_vpn'])) {
                    $vpnMessage = 'Detectamos que você está usando VPN. Desative para jogar.';
                }
                
                echo json_encode([
                    'success' => false,
                    'error' => $vpnMessage,
                    'block_reason' => 'vpn_detected',
                    'proxy_type' => $proxyType
                ]);
                exit;
            }
        } catch (Throwable $e) {
            error_log("proxy-check error (non-blocking): " . $e->getMessage());
        }
    }
    
    // ──────────────────────────────────────────────
    // CHECK 5: SESSÃO ATIVA SIMULTÂNEA NO MESMO IP?
    // (outro usuário jogando do mesmo IP)
    // ──────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT gs.id, gs.google_uid, u.display_name
        FROM game_sessions gs
        LEFT JOIN users u ON u.google_uid = gs.google_uid
        WHERE gs.ip_address = ?
        AND gs.status = 'active'
        AND gs.google_uid != ?
        AND gs.started_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        LIMIT 1
    ");
    $stmt->execute([$clientIP, $realGoogleUid, GAME_DURATION + GAME_TOLERANCE]);
    $concurrentSession = $stmt->fetch();
    
    if ($concurrentSession) {
        secureLog("BLOCK_CONCURRENT | IP: {$clientIP} | User: {$userId} | Other session: {$concurrentSession['id']}");
        echo json_encode([
            'success' => false,
            'error' => 'Já existe uma sessão ativa neste dispositivo. Aguarde a sessão atual terminar.',
            'block_reason' => 'concurrent_session',
            'wait_seconds' => GAME_DURATION
        ]);
        exit;
    }
    
    // ──────────────────────────────────────────────
    // CHECK 6: SESSÃO ATIVA DO PRÓPRIO USUÁRIO?
    // (evitar duplo-click / múltiplas abas)
    // ──────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT id, started_at 
        FROM game_sessions 
        WHERE google_uid = ? 
        AND status = 'active'
        AND started_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        LIMIT 1
    ");
    $stmt->execute([$realGoogleUid, GAME_DURATION + GAME_TOLERANCE]);
    $ownActiveSession = $stmt->fetch();
    
    if ($ownActiveSession) {
        $startedAt = strtotime($ownActiveSession['started_at']);
        $elapsed = time() - $startedAt;
        $remaining = max(0, GAME_DURATION - $elapsed);
        
        if ($remaining > 10) {
            // Sessão ainda tem tempo — bloquear
            secureLog("BLOCK_OWN_ACTIVE | User: {$userId} | Session: {$ownActiveSession['id']} | Remaining: {$remaining}s");
            echo json_encode([
                'success' => false,
                'error' => 'Você já tem uma sessão em andamento. Termine a sessão atual primeiro.',
                'block_reason' => 'own_active_session',
                'active_session_id' => (int)$ownActiveSession['id'],
                'remaining_seconds' => $remaining
            ]);
            exit;
        }
        // Sessão quase expirada (<10s) — expirar e continuar
    }
    
    // ──────────────────────────────────────────────
    // CHECK 7: INTERVALO ENTRE JOGOS (COOLDOWN)
    // ──────────────────────────────────────────────
    if ($_rateLimiterLoaded) {
        try {
            $intervalCheck = $limiter->checkGameInterval();
            if (isset($intervalCheck['allowed']) && !$intervalCheck['allowed']) {
                $waitSeconds = $intervalCheck['wait_seconds'] ?? 180;
                secureLog("BLOCK_COOLDOWN | User: {$userId} | Wait: {$waitSeconds}s");
                echo json_encode([
                    'success' => false,
                    'error' => "Aguarde antes de iniciar outra missão.",
                    'block_reason' => 'cooldown',
                    'wait_seconds' => $waitSeconds
                ]);
                exit;
            }
        } catch (Throwable $e) {
            error_log("rate-limiter interval error (non-blocking): " . $e->getMessage());
        }
    }
    
    // ──────────────────────────────────────────────
    // CHECK 8: LIMITE DE MISSÕES POR HORA (POR IP)
    // ──────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM game_sessions 
        WHERE ip_address = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$clientIP]);
    $ipCheck = $stmt->fetch();
    
    if ($ipCheck['count'] >= MAX_MISSIONS_PER_HOUR) {
        // Calcular quanto tempo falta para liberar
        $stmt2 = $pdo->prepare("
            SELECT created_at FROM game_sessions 
            WHERE ip_address = ? 
            ORDER BY created_at ASC 
            LIMIT 1
        ");
        $stmt2->execute([$clientIP]);
        $oldest = $stmt2->fetch();
        $waitSeconds = $oldest ? max(0, 3600 - (time() - strtotime($oldest['created_at']))) : 3600;
        
        secureLog("BLOCK_HOURLY_LIMIT | IP: {$clientIP} | User: {$userId} | Count: {$ipCheck['count']}");
        echo json_encode([
            'success' => false,
            'error' => 'Limite de ' . MAX_MISSIONS_PER_HOUR . ' missões por hora atingido. Descanse um pouco!',
            'block_reason' => 'hourly_limit',
            'wait_seconds' => $waitSeconds,
            'missions_played' => (int)$ipCheck['count'],
            'missions_limit' => MAX_MISSIONS_PER_HOUR,
            'missions_remaining' => 0
        ]);
        exit;
    }
    
    // ──────────────────────────────────────────────
    // CHECK 9: LIMITE DE MISSÕES POR HORA (POR USUÁRIO)
    // ──────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM game_sessions 
        WHERE google_uid = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$realGoogleUid]);
    $userCheck = $stmt->fetch();
    
    if ($userCheck['count'] >= MAX_MISSIONS_PER_HOUR) {
        secureLog("BLOCK_USER_HOURLY | User: {$userId} | Count: {$userCheck['count']}");
        echo json_encode([
            'success' => false,
            'error' => 'Limite de ' . MAX_MISSIONS_PER_HOUR . ' missões por hora atingido.',
            'block_reason' => 'hourly_limit',
            'wait_seconds' => 3600,
            'missions_played' => (int)$userCheck['count'],
            'missions_limit' => MAX_MISSIONS_PER_HOUR,
            'missions_remaining' => 0
        ]);
        exit;
    }
    
    $missionsRemaining = MAX_MISSIONS_PER_HOUR - max($ipCheck['count'], $userCheck['count']) - 1;
    
    // ──────────────────────────────────────────────
    // CHECK 10: ATIVIDADE SUSPEITA RECENTE?
    // (muitas flags = aviso ou bloqueio temporário)
    // ──────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN severity IN ('high', 'critical') THEN 1 ELSE 0 END) as critical_count
        FROM suspicious_activity
        WHERE user_id = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$userId]);
    $suspiciousCheck = $stmt->fetch();
    $criticalCount = (int)($suspiciousCheck['critical_count'] ?? 0);
    $totalSuspicious = (int)($suspiciousCheck['total'] ?? 0);
    
    // 3+ critical em 1h = bloqueio temporário (não ban permanente)
    if ($criticalCount >= 3) {
        secureLog("BLOCK_SUSPICIOUS | User: {$userId} | Critical: {$criticalCount} | Total: {$totalSuspicious}");
        echo json_encode([
            'success' => false,
            'error' => 'Sua conta está temporariamente restrita por atividade incomum. Tente novamente em 1 hora.',
            'block_reason' => 'suspicious_activity',
            'wait_seconds' => 3600
        ]);
        exit;
    }
    
    // ============================================================
    // ██████  TODAS AS VERIFICAÇÕES PASSARAM — CRIAR SESSÃO
    // ============================================================
    
    // Expirar sessões ativas do usuário (limpeza)
    $pdo->prepare("
        UPDATE game_sessions 
        SET status = 'abandoned', ended_at = NOW() 
        WHERE google_uid = ? AND status = 'active'
    ")->execute([$realGoogleUid]);
    
    // Calcular número da missão
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM game_sessions WHERE google_uid = ?");
    $stmt->execute([$realGoogleUid]);
    $result = $stmt->fetch();
    $missionNumber = (int)($result['total'] ?? 0) + 1;
    
    // Determinar hard mode (servidor decide)
    $isHardMode = isHardModeMission();
    
    // Gerar tokens de segurança
    $sessionToken = hash('sha256', $realGoogleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
    $sessionSeed = generateSessionSeed();
    
    // Criar sessão
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            google_uid, 
            session_token,
            session_uuid,
            mission_number, 
            is_hard_mode,
            ip_address, 
            user_agent, 
            earnings_brl, 
            asteroids_destroyed,
            common_asteroids,
            rare_asteroids,
            epic_asteroids,
            legendary_asteroids,
            started_at, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, NOW(), NOW())
    ");
    
    $stmt->execute([
        $realGoogleUid, 
        $sessionToken,
        $sessionSeed,
        $missionNumber, 
        $isHardMode ? 1 : 0,
        $clientIP, 
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
    ]);
    
    $sessionId = (int)$pdo->lastInsertId();
    
    // Registrar no rate limiter
    if ($_rateLimiterLoaded) {
        try {
            $limiter = new RateLimiter($pdo, null, $realGoogleUid);
            $limiter->logAction('game_start');
        } catch (Throwable $e) {
            error_log("rate-limiter logAction error: " . $e->getMessage());
        }
    }
    
    secureLog("GAME_START | Session: $sessionId | User: $userId | Mission: $missionNumber | HardMode: " . ($isHardMode ? 'YES' : 'NO') . " | IP: {$clientIP}");
    
    // Incluir aviso se há atividade suspeita (mas não bloqueante)
    $warnings = [];
    if ($totalSuspicious > 0) {
        $warnings[] = 'Atividade incomum detectada na sua conta. Jogue normalmente para evitar restrições.';
    }
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'session_seed' => $sessionSeed,
        'google_uid' => $realGoogleUid,
        'user_id' => $userId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'game_duration' => GAME_DURATION,
        'initial_lives' => INITIAL_LIVES,
        'missions_remaining' => $missionsRemaining,
        'warnings' => $warnings,
        'limits' => [
            'max_asteroids' => MAX_ASTEROIDS_PER_GAME,
            'max_legendary' => MAX_LEGENDARY_PER_GAME,
            'max_epic' => MAX_EPIC_PER_GAME,
            'max_rare' => MAX_RARE_PER_GAME
        ]
    ]);
    
} catch (Throwable $e) {
    error_log("❌ GAME-START ERROR: " . $e->getMessage() . " | Line: " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Erro interno. Tente novamente.',
        'block_reason' => 'server_error'
    ]);
}
