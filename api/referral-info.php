<?php
// ============================================
// CRYPTO ASTEROID RUSH - Informações de Afiliados
// Arquivo: api/referral-info.php
// ============================================

require_once "../config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Aceita GET ou POST
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wallet = isset($_GET['wallet']) ? trim(strtolower($_GET['wallet'])) : '';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
}

// Validar wallet
if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

/**
 * Gera código alfanumérico único de 6 caracteres
 */
function generateReferralCode($pdo) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Sem I, O, 0, 1 para evitar confusão
    $maxAttempts = 10;
    
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Verificar se código já existe
        $stmt = $pdo->prepare("SELECT id FROM referral_codes WHERE code = ?");
        $stmt->execute([$code]);
        
        if (!$stmt->fetch()) {
            return $code; // Código único encontrado
        }
    }
    
    // Fallback: usar hash da wallet + timestamp
    return strtoupper(substr(md5($wallet . time()), 0, 6));
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ============================================
    // 1. BUSCAR OU CRIAR CÓDIGO DE REFERRAL
    // ============================================
    $stmt = $pdo->prepare("SELECT code FROM referral_codes WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $codeRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($codeRow) {
        $referralCode = $codeRow['code'];
    } else {
        // Gerar novo código
        $referralCode = generateReferralCode($pdo);
        
        $stmt = $pdo->prepare("INSERT INTO referral_codes (wallet_address, code) VALUES (?, ?)");
        $stmt->execute([$wallet, $referralCode]);
    }
    
    // ============================================
    // 2. BUSCAR ESTATÍSTICAS DE INDICAÇÕES
    // ============================================
    
    // Total de indicados
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_wallet = ?");
    $stmt->execute([$wallet]);
    $totalReferred = (int)$stmt->fetchColumn();
    
    // Indicados que completaram (status = completed ou claimed)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_wallet = ? AND status IN ('completed', 'claimed')");
    $stmt->execute([$wallet]);
    $completedReferred = (int)$stmt->fetchColumn();
    
    // Comissões disponíveis para resgate (status = completed)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(commission_amount), 0) FROM referrals WHERE referrer_wallet = ? AND status = 'completed'");
    $stmt->execute([$wallet]);
    $availableCommission = (float)$stmt->fetchColumn();
    
    // Total já resgatado (status = claimed)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(commission_amount), 0) FROM referrals WHERE referrer_wallet = ? AND status = 'claimed'");
    $stmt->execute([$wallet]);
    $claimedCommission = (float)$stmt->fetchColumn();
    
    // Indicados pendentes (ainda jogando)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_wallet = ? AND status = 'pending'");
    $stmt->execute([$wallet]);
    $pendingReferred = (int)$stmt->fetchColumn();
    
    // ============================================
    // 3. BUSCAR LISTA DE INDICADOS
    // ============================================
    $stmt = $pdo->prepare("
        SELECT 
            id,
            referred_wallet,
            status,
            missions_completed,
            missions_required,
            commission_amount,
            completed_at,
            claimed_at,
            created_at
        FROM referrals 
        WHERE referrer_wallet = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$wallet]);
    $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar dados dos indicados
    $referralsList = array();
    foreach ($referrals as $ref) {
        $referralsList[] = array(
            'id' => (int)$ref['id'],
            'wallet' => $ref['referred_wallet'],
            'wallet_short' => substr($ref['referred_wallet'], 0, 6) . '...' . substr($ref['referred_wallet'], -4),
            'status' => $ref['status'],
            'missions_completed' => (int)$ref['missions_completed'],
            'missions_required' => (int)$ref['missions_required'],
            'progress_percent' => min(100, round(($ref['missions_completed'] / $ref['missions_required']) * 100)),
            'commission' => number_format((float)$ref['commission_amount'], 2, '.', ''),
            'completed_at' => $ref['completed_at'],
            'claimed_at' => $ref['claimed_at'],
            'created_at' => $ref['created_at']
        );
    }
    
    // ============================================
    // 4. RETORNAR RESPOSTA
    // ============================================
    echo json_encode([
        'success' => true,
        'referral_code' => $referralCode,
        'referral_link' => '?ref=' . $referralCode,
        'stats' => [
            'total_referred' => $totalReferred,
            'completed' => $completedReferred,
            'pending' => $pendingReferred,
            'available_commission' => number_format($availableCommission, 2, '.', ''),
            'claimed_commission' => number_format($claimedCommission, 2, '.', ''),
            'total_earned' => number_format($availableCommission + $claimedCommission, 2, '.', '')
        ],
        'referrals' => $referralsList
    ]);
    
} catch (Exception $e) {
    error_log("Erro em referral-info.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro no servidor: ' . $e->getMessage()]);
}
?>
