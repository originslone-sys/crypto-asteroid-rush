<?php
// ============================================
// CRYPTO ASTEROID RUSH - Login / Registro
// Arquivo: api/login.php
// ATUALIZADO: Suporte a sistema de afiliados
// ============================================

require_once "../config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$input = json_decode(file_get_contents('php://input'), true);
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$referralCode = isset($input['referral_code']) ? trim(strtoupper($input['referral_code'])) : '';

// Validar wallet
if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ============================================
    // 1. VERIFICAR SE É NOVO JOGADOR
    // ============================================
    $stmt = $pdo->prepare("SELECT id FROM players WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $existingPlayer = $stmt->fetch();
    
    $isNewPlayer = !$existingPlayer;
    
    // ============================================
    // 2. INSERIR OU ATUALIZAR JOGADOR
    // ============================================
    if ($isNewPlayer) {
        $pdo->prepare("INSERT INTO players (wallet_address, balance_usdt, total_played) VALUES (?, 0.0, 0)")
            ->execute([$wallet]);
    }
    
    // ============================================
    // 3. PROCESSAR REFERRAL (APENAS PARA NOVOS JOGADORES)
    // ============================================
    $referralRegistered = false;
    $referrerWallet = null;
    
    if ($isNewPlayer && !empty($referralCode)) {
        // Validar formato do código (6 caracteres alfanuméricos)
        if (preg_match('/^[A-Z0-9]{6}$/', $referralCode)) {
            
            // Verificar se tabelas de referral existem
            $tableExists = $pdo->query("SHOW TABLES LIKE 'referral_codes'")->fetch();
            
            if ($tableExists) {
                // Buscar wallet do referrer pelo código
                $stmt = $pdo->prepare("SELECT wallet_address FROM referral_codes WHERE code = ?");
                $stmt->execute([$referralCode]);
                $referrer = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($referrer && $referrer['wallet_address'] !== $wallet) {
                    $referrerWallet = $referrer['wallet_address'];
                    
                    // Verificar se usuário já foi indicado
                    $stmt = $pdo->prepare("SELECT id FROM referrals WHERE referred_wallet = ?");
                    $stmt->execute([$wallet]);
                    
                    if (!$stmt->fetch()) {
                        // Registrar indicação
                        $stmt = $pdo->prepare("
                            INSERT INTO referrals 
                            (referrer_wallet, referred_wallet, referral_code, missions_at_register, missions_completed) 
                            VALUES (?, ?, ?, 0, 0)
                        ");
                        $stmt->execute([$referrerWallet, $wallet, $referralCode]);
                        
                        $referralRegistered = true;
                        
                        // Log
                        error_log("Referral registrado via login: Referrer={$referrerWallet}, Referred={$wallet}, Code={$referralCode}");
                    }
                }
            }
        }
    }
    
    // ============================================
    // 4. RETORNAR RESPOSTA
    // ============================================
    $response = [
        'success' => true,
        'is_new_player' => $isNewPlayer
    ];
    
    if ($referralRegistered) {
        $response['referral_registered'] = true;
        $response['referrer'] = substr($referrerWallet, 0, 6) . '...' . substr($referrerWallet, -4);
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Erro em login.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
?>
