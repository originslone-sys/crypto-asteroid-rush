<?php
// ============================================
// CRYPTO ASTEROID RUSH - Registrar Indicação
// Arquivo: api/referral-register.php
// ============================================

require_once "../config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$input = json_decode(file_get_contents('php://input'), true);

$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$referralCode = isset($input['referral_code']) ? trim(strtoupper($input['referral_code'])) : '';

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
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ============================================
    // 1. VERIFICAR SE USUÁRIO JÁ FOI INDICADO
    // ============================================
    $stmt = $pdo->prepare("SELECT id FROM referrals WHERE referred_wallet = ?");
    $stmt->execute([$wallet]);
    
    if ($stmt->fetch()) {
        // Usuário já foi indicado anteriormente, ignorar
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
        // Código não existe
        echo json_encode(['success' => false, 'error' => 'Código de referral não encontrado']);
        exit;
    }
    
    $referrerWallet = $referrer['wallet_address'];
    
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
    
    // Log para debug
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
