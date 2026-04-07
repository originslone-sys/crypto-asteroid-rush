<?php
// ============================================
// UNOBIX - PvP: Autorizar jogador para matchmaking
// api/pvp-authorize.php
// Valida créditos, gera token JWT para o Game Server
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

if (empty($input['google_uid'])) {
    echo json_encode(['success' => false, 'error' => 'google_uid não fornecido']);
    exit;
}

$googleUid = trim($input['google_uid']);

if (strpos($googleUid, '...') === false && !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
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

    // Buscar usuário
    $user = findPlayer($pdo, $googleUid);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado. Faça login primeiro.']);
        exit;
    }

    if (!empty($user['is_banned'])) {
        echo json_encode(['success' => false, 'error' => 'Conta suspensa: ' . ($user['ban_reason'] ?? 'Violação dos termos')]);
        exit;
    }

    $userId = (int)$user['id'];
    $realGoogleUid = $user['google_uid'];
    $displayName = $user['display_name'] ?? 'Jogador';

    // Verificar se já tem partida PvP ativa
    $stmt = $pdo->prepare("
        SELECT id, match_id, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) as elapsed
        FROM pvp_matches
        WHERE (player1_google_uid = ? OR player2_google_uid = ?)
          AND status IN ('waiting', 'countdown', 'active')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$realGoogleUid, $realGoogleUid]);
    $activeMatch = $stmt->fetch();

    if ($activeMatch) {
        $elapsed = (int)$activeMatch['elapsed'];
        $maxTime = PVP_GAME_DURATION + PVP_DISCONNECT_TIMEOUT + 30;

        if ($elapsed >= $maxTime) {
            // Partida travada, auto-cancelar
            $pdo->prepare("UPDATE pvp_matches SET status = 'cancelled', ended_at = NOW() WHERE id = ?")->execute([$activeMatch['id']]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Você já tem uma partida PvP em andamento!',
                'error_code' => 'ACTIVE_PVP_MATCH',
                'match_id' => $activeMatch['match_id']
            ]);
            exit;
        }
    }

    // Carregar entry fee e prize do game_settings (admin pode alterar)
    $entryFee = PVP_ENTRY_FEE_CREDITS;
    $winnerPrizeBrl = 0;
    try {
        $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key IN ('pvp_entry_fee_credits', 'pvp_winner_prize_brl', 'pvp_enabled')");
        while ($row = $settingsStmt->fetch()) {
            if ($row['setting_key'] === 'pvp_entry_fee_credits') $entryFee = (int)$row['setting_value'];
            if ($row['setting_key'] === 'pvp_winner_prize_brl') $winnerPrizeBrl = (float)$row['setting_value'];
            if ($row['setting_key'] === 'pvp_enabled' && $row['setting_value'] === 'false') {
                echo json_encode(['success' => false, 'error' => 'Modo PvP está desativado no momento.']);
                exit;
            }
        }
    } catch (Exception $e) { /* usa constantes padrão */ }

    // Verificar créditos (sem debitar — débito ocorre ao encontrar partida via pvp-debit.php)
    $lockedCredits = (int)($user['credits'] ?? 0);
    if ($entryFee > 0 && $lockedCredits < $entryFee) {
        echo json_encode([
            'success' => false,
            'error' => 'Créditos insuficientes! Você precisa de ' . $entryFee . ' crédito(s) para o modo PvP.',
            'error_code' => 'NO_CREDITS',
            'credits' => $lockedCredits,
            'credits_required' => $entryFee
        ]);
        exit;
    }

    $remainingCredits = $lockedCredits;

    // Gerar token JWT para o Game Server
    $now = time();
    $payload = [
        'sub' => $realGoogleUid,
        'uid' => $userId,
        'name' => $displayName,
        'credits' => $remainingCredits,
        'ip' => $clientIP,
        'iat' => $now,
        'exp' => $now + 120 // Token válido por 2 minutos (tempo para matchmaking)
    ];

    $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payloadEncoded = base64_encode(json_encode($payload));
    $signature = base64_encode(hash_hmac('sha256', "$header.$payloadEncoded", PVP_JWT_SECRET, true));
    $token = "$header.$payloadEncoded.$signature";

    secureLog("PVP_AUTHORIZE | User: $userId | Credits: $remainingCredits | IP: $clientIP");

    echo json_encode([
        'success' => true,
        'pvp_token' => $token,
        'google_uid' => $realGoogleUid,
        'display_name' => $displayName,
        'credits' => $remainingCredits,
        'entry_fee' => $entryFee,
        'winner_prize_brl' => $winnerPrizeBrl,
        'game_duration' => PVP_GAME_DURATION,
        'lives' => PVP_LIVES
    ]);

} catch (Throwable $e) {
    error_log("❌ PVP-AUTHORIZE ERROR: " . $e->getMessage() . " | Line: " . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
