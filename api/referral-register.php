<?php
// ============================================
// UNOBIX - Registrar Indicação
// Arquivo: api/referral-register.php
// v3.0 - Google Auth + users + sem wallet
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');
$referralCode = trim(strtoupper($input['referral_code'] ?? $input['ref'] ?? ''));

// Validar google_uid
if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

// Se não tem código de referral, nada a fazer
if (empty($referralCode)) {
    echo json_encode(['success' => true, 'message' => 'Sem código de referral']);
    exit;
}

// Validar formato (6 caracteres alfanuméricos)
if (!preg_match('/^[A-Z0-9]{6}$/', $referralCode)) {
    echo json_encode(['success' => false, 'error' => 'Código de referral inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro de conexão");

    // ============================================
    // 1. VERIFICAR SE É USUÁRIO NOVO (sem jogos)
    // Usuários com conta ativa não podem ser indicados
    // ============================================
    $stmt = $pdo->prepare("
        SELECT total_played FROM users
        WHERE google_uid = ?
        LIMIT 1
    ");
    $stmt->execute([$googleUid]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingUser && (int)$existingUser['total_played'] > 0) {
        echo json_encode(['success' => false, 'error' => 'Apenas novos usuários podem ser indicados']);
        exit;
    }

    // ============================================
    // 2. VERIFICAR SE USUÁRIO JÁ FOI INDICADO
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id FROM referrals
        WHERE referred_google_uid = ?
    ");
    $stmt->execute([$googleUid]);

    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Usuário já possui indicação registrada']);
        exit;
    }

    // ============================================
    // 3. BUSCAR REFERRER PELO CÓDIGO
    // ============================================
    $stmt = $pdo->prepare("
        SELECT google_uid FROM referral_codes
        WHERE code = ? AND is_active = 1
    ");
    $stmt->execute([$referralCode]);
    $referrer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$referrer) {
        echo json_encode(['success' => false, 'error' => 'Código de referral não encontrado']);
        exit;
    }

    $referrerGoogleUid = $referrer['google_uid'];

    // ============================================
    // 4. VERIFICAR SE NÃO É AUTO-INDICAÇÃO
    // ============================================
    if ($referrerGoogleUid === $googleUid) {
        echo json_encode(['success' => false, 'error' => 'Não é possível usar seu próprio código']);
        exit;
    }

    // ============================================
    // 5. BUSCAR MISSÕES ATUAIS DO NOVO USUÁRIO
    // ============================================
    $missionsAtRegister = 0;

    $stmt = $pdo->prepare("
        SELECT total_played FROM users
        WHERE google_uid = ?
        LIMIT 1
    ");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($player) {
        $missionsAtRegister = (int)$player['total_played'];
    }

    // ============================================
    // 6. LER SETTINGS DO ADMIN
    // ============================================
    $missionsRequired = 20;
    $commissionBrl = 5.000000;
    $settingsTable = $pdo->query("SHOW TABLES LIKE 'game_settings'")->fetch();
    if ($settingsTable) {
        $sStmt = $pdo->prepare("SELECT setting_key, setting_value FROM game_settings WHERE setting_key IN ('referral_missions_required', 'referral_bonus_brl')");
        $sStmt->execute();
        foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if ($s['setting_key'] === 'referral_missions_required') $missionsRequired = max(1, (int)$s['setting_value']);
            if ($s['setting_key'] === 'referral_bonus_brl') $commissionBrl = max(0, (float)$s['setting_value']);
        }
    }

    // ============================================
    // 7. REGISTRAR INDICAÇÃO
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO referrals (
            referrer_google_uid, referrer_wallet,
            referred_google_uid, referred_wallet,
            referral_code,
            missions_at_register,
            missions_completed,
            missions_required,
            status,
            commission_brl,
            created_at
        ) VALUES (?, '', ?, '', ?, ?, 0, ?, 'pending', ?, NOW())
    ");
    $stmt->execute([
        $referrerGoogleUid,
        $googleUid,
        $referralCode,
        $missionsAtRegister,
        $missionsRequired,
        $commissionBrl
    ]);

    $referralId = (int)$pdo->lastInsertId();

    // Incrementar uses_count no código
    $pdo->prepare("
        UPDATE referral_codes SET uses_count = uses_count + 1
        WHERE code = ?
    ")->execute([$referralCode]);

    secureLog("REFERRAL_REGISTERED | ID: {$referralId} | Referrer: {$referrerGoogleUid} | Referred: {$googleUid} | Code: {$referralCode}");

    $referrerDisplay = substr($referrerGoogleUid, 0, 8) . '...';

    echo json_encode([
        'success' => true,
        'message' => 'Indicação registrada com sucesso!',
        'referral_id' => $referralId,
        'referrer' => $referrerDisplay
    ]);

} catch (Exception $e) {
    error_log("Erro em referral-register.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
