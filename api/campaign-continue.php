<?php
// ============================================
// UNOBIX - Campaign Continue (após game over)
// api/campaign-continue.php
//
// POST { google_uid, session_token }
//
// Permite o jogador continuar uma sessão que ele acabou de perder
// (HP=0). Custa N créditos (campaign.cost.continue_after_death,
// default 2) e devolve um sinal de OK ao cliente para retomar o
// jogo com HP restaurado.
//
// Mantém a sessão como 'active' (não conta como derrota). A
// sessão precisa estar dentro do tempo de expiração.
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = getRequestInput();
$googleUid    = trim($input['google_uid']    ?? $input['googleUid']    ?? '');
$sessionToken = trim($input['session_token'] ?? $input['sessionToken'] ?? '');

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}
if (empty($sessionToken) || !preg_match('/^[a-f0-9]{32,64}$/', $sessionToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'session_token inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    // Valida sessão
    $stmt = $pdo->prepare("
        SELECT id, google_uid, status, expires_at
        FROM campaign_session WHERE session_token = ? LIMIT 1
    ");
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch();
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Sessão não encontrada']);
        exit;
    }
    if ($session['google_uid'] !== $googleUid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sessão não pertence ao usuário']);
        exit;
    }
    if ($session['status'] !== 'active') {
        echo json_encode(['success' => false, 'error' => 'Sessão já finalizada']);
        exit;
    }
    if (strtotime($session['expires_at']) < time()) {
        echo json_encode(['success' => false, 'error' => 'Sessão expirou']);
        exit;
    }

    // Custo de continue
    $cost = (int)getCampaignSetting($pdo, 'campaign.cost.continue_after_death', 2);
    if ($cost < 0) $cost = 0;

    // Debita créditos atomicamente
    $pdo->beginTransaction();
    try {
        if ($cost > 0) {
            $debit = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE google_uid = ? AND credits >= ?");
            $debit->execute([$cost, $googleUid, $cost]);
            if ($debit->rowCount() !== 1) {
                throw new Exception('Créditos insuficientes');
            }
        }
        // Marca os créditos extras na sessão (pra anti-cheat futuro)
        $pdo->prepare("
            UPDATE campaign_session SET credits_spent = credits_spent + ? WHERE session_token = ?
        ")->execute([$cost, $sessionToken]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    // Saldo atualizado
    $u = $pdo->prepare("SELECT credits FROM users WHERE google_uid = ? LIMIT 1");
    $u->execute([$googleUid]);
    $userRow = $u->fetch();

    secureLog("CAMPAIGN_CONTINUE | uid: $googleUid | cost: $cost | token: " . substr($sessionToken, 0, 8) . "...");

    echo json_encode([
        'success' => true,
        'data' => [
            'cost' => $cost,
            'remaining_credits' => (int)($userRow['credits'] ?? 0),
        ],
    ]);

} catch (Exception $e) {
    error_log('campaign-continue error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

function getCampaignSetting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}
