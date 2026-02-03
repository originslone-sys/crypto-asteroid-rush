<?php
// ============================================
// UNOBIX - Registrar Indicação
// Arquivo: api/referral-register.php
// v2.0 - Google Auth + BRL
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// Entrada híbrida: JSON + POST + GET
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

// Suporte google_uid ou wallet
$googleUid = $input['google_uid'] ?? ($_POST['google_uid'] ?? ($_GET['google_uid'] ?? ''));
$wallet = $input['wallet'] ?? ($_POST['wallet'] ?? ($_GET['wallet'] ?? ''));
$referralCode = $input['referral_code'] ?? ($_POST['referral_code'] ?? ($_GET['referral_code'] ?? ''));

$googleUid = trim($googleUid);
$wallet = trim(strtolower($wallet));
$referralCode = trim(strtoupper($referralCode));

// Determinar identificador do novo usuário
$identifier = '';
$identifierType = '';

if (!empty($googleUid) && validateGoogleUid($googleUid)) {
    $identifier = $googleUid;
    $identifierType = 'google_uid';
} elseif (!empty($wallet) && validateWallet($wallet)) {
    $identifier = $wallet;
    $identifierType = 'wallet';
} else {
    echo json_encode(['success' => false, 'error' => 'Identificação inválida']);
    exit;
}

// Se não tem código de referral, não há o que fazer
if (empty($referralCode)) {
    echo json_encode(['success' => true, 'message' => 'Sem código de referral']);
    exit;
}

// Validar formato do código (6 caracteres alfanuméricos)
if (!preg_match('/^[A-Z0-9]{6}$/', $referralCode)) {
    echo json_encode(['success' => false, 'error' => 'Código de referral inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Erro de conexão");
    }

    // ============================================
    // 1. VERIFICAR SE USUÁRIO JÁ FOI INDICADO
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id FROM referrals 
        WHERE referred_google_uid = ? OR referred_wallet = ?
    ");
    $stmt->execute([$googleUid, $wallet]);

    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Usuário já possui indicação registrada']);
        exit;
    }

    // ============================================
    // 2. BUSCAR REFERRER PELO CÓDIGO
    // ============================================
    $stmt = $pdo->prepare("
        SELECT wallet_address, google_uid FROM referral_codes WHERE code = ?
    ");
    $stmt->execute([$referralCode]);
    $referrer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$referrer) {
        echo json_encode(['success' => false, 'error' => 'Código de referral não encontrado']);
        exit;
    }

    $referrerGoogleUid = $referrer['google_uid'] ?? null;
    $referrerWallet = strtolower($referrer['wallet_address'] ?? '');

    // ============================================
    // 3. VERIFICAR SE NÃO É AUTO-INDICAÇÃO
    // ============================================
    if (($referrerGoogleUid && $referrerGoogleUid === $googleUid) || 
        ($referrerWallet && $referrerWallet === $wallet)) {
        echo json_encode(['success' => false, 'error' => 'Não é possível usar seu próprio código']);
        exit;
    }

    // ============================================
    // 4. BUSCAR MISSÕES ATUAIS DO NOVO USUÁRIO
    // ============================================
    $missionsAtRegister = 0;
    
    $stmt = $pdo->prepare("
        SELECT total_played FROM players 
        WHERE google_uid = ? OR wallet_address = ?
        LIMIT 1
    ");
    $stmt->execute([$googleUid, $wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($player) {
        $missionsAtRegister = (int)$player['total_played'];
    }

    // ============================================
    // 5. REGISTRAR INDICAÇÃO
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO referrals (
            referrer_wallet, 
            referrer_google_uid,
            referred_wallet, 
            referred_google_uid,
            referral_code, 
            missions_at_register, 
            missions_completed,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 0, 'pending', NOW())
    ");
    $stmt->execute([
        $referrerWallet,
        $referrerGoogleUid,
        $wallet ?: null,
        $googleUid ?: null,
        $referralCode, 
        $missionsAtRegister
    ]);

    $referralId = $pdo->lastInsertId();

    secureLog("REFERRAL_REGISTERED | ID: {$referralId} | Referrer: {$referrerGoogleUid}/{$referrerWallet} | Referred: {$googleUid}/{$wallet} | Code: {$referralCode}");

    // Resposta com identificador parcial do referrer
    $referrerDisplay = $referrerGoogleUid 
        ? substr($referrerGoogleUid, 0, 8) . '...'
        : substr($referrerWallet, 0, 6) . '...' . substr($referrerWallet, -4);

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
