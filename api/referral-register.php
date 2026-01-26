<?php
// ============================================
// CRYPTO ASTEROID RUSH - Registrar Indicação
// Arquivo: api/referral-register.php
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true]);
    exit;
}

// Entrada híbrida: JSON + POST + GET
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
if ($wallet === '') $wallet = isset($_POST['wallet']) ? trim(strtolower($_POST['wallet'])) : $wallet;
if ($wallet === '') $wallet = isset($_GET['wallet']) ? trim(strtolower($_GET['wallet'])) : $wallet;

$referralCode = isset($input['referral_code']) ? trim(strtoupper($input['referral_code'])) : '';
if ($referralCode === '') $referralCode = isset($_POST['referral_code']) ? trim(strtoupper($_POST['referral_code'])) : $referralCode;
if ($referralCode === '') $referralCode = isset($_GET['referral_code']) ? trim(strtoupper($_GET['referral_code'])) : $referralCode;

// Validar wallet
if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
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
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // ============================================
    // 1. VERIFICAR SE USUÁRIO JÁ FOI INDICADO
    // ============================================
    $stmt = $pdo->prepare("SELECT id FROM referrals WHERE referred_wallet = ?");
    $stmt->execute([$wallet]);

    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Usuário já possui indicação registrada']);
        exit;
    }

    // ============================================
    // 2. BUSCAR WALLET DO REFERRER PELO CÓDIGO
    // ============================================
    $stmt = $pdo->prepare("SELECT wallet_address FROM referral_codes WHERE code = ?");
    $stmt->execute([$referralCode]);
    $referrer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$referrer) {
        echo json_encode(['success' => false, 'error' => 'Código de referral não encontrado']);
        exit;
    }

    $referrerWallet = strtolower($referrer['wallet_address']);

    // ============================================
    // 3. VERIFICAR SE NÃO É AUTO-INDICAÇÃO
    // ============================================
    if ($referrerWallet === $wallet) {
        echo json_encode(['success' => false, 'error' => 'Não é possível usar seu próprio código']);
        exit;
    }

    // ============================================
    // 4. BUSCAR MISSÕES ATUAIS DO NOVO USUÁRIO
    // ============================================
    $stmt = $pdo->prepare("SELECT total_played FROM players WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    $missionsAtRegister = $player ? (int)$player['total_played'] : 0;

    // ============================================
    // 5. REGISTRAR INDICAÇÃO
    // ============================================
    $stmt = $pdo->prepare("
        INSERT INTO referrals
        (referrer_wallet, referred_wallet, referral_code, missions_at_register, missions_completed)
        VALUES (?, ?, ?, ?, 0)
    ");
    $stmt->execute([$referrerWallet, $wallet, $referralCode, $missionsAtRegister]);

    $referralId = $pdo->lastInsertId();

    error_log("Referral registrado: ID={$referralId}, Referrer={$referrerWallet}, Referred={$wallet}, Code={$referralCode}");

    echo json_encode([
        'success' => true,
        'message' => 'Indicação registrada com sucesso',
        'referral_id' => $referralId,
        'referrer' => substr($referrerWallet, 0, 6) . '...' . substr($referrerWallet, -4)
    ]);

} catch (Exception $e) {
    error_log("Erro em referral-register.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
?>
