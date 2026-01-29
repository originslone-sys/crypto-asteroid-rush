<?php
// ============================================
// UNOBIX - Login (Compatibilidade)
// Arquivo: api/login.php
// DEPRECATED: Use auth-google.php para novas integrações
// Este arquivo mantém compatibilidade com wallet MetaMask
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = [];

// Verificar se é login Google ou Wallet
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';

// Se tiver google_uid, redirecionar para auth-google
if (!empty($googleUid)) {
    $input['action'] = 'login';
    require_once __DIR__ . "/auth-google.php";
    exit;
}

// MODO LEGADO: Login via Wallet (MetaMask)
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : '';
$referralCode = isset($input['referral_code']) ? trim(strtoupper($input['referral_code'])) : '';

// Validar wallet
if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

    // ============================================
    // 1. VERIFICAR SE É NOVO JOGADOR
    // ============================================
    $stmt = $pdo->prepare("SELECT * FROM players WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $existingPlayer = $stmt->fetch();

    $isNewPlayer = !$existingPlayer;

    // ============================================
    // 2. INSERIR OU ATUALIZAR JOGADOR
    // ============================================
    if ($isNewPlayer) {
        $pdo->prepare("
            INSERT INTO players (wallet_address, balance_brl, balance_usdt, total_played) 
            VALUES (?, 0, 0, 0)
        ")->execute([$wallet]);
    }

    // ============================================
    // 3. PROCESSAR REFERRAL (APENAS PARA NOVOS)
    // ============================================
    $referralRegistered = false;
    $referrerWallet = null;

    if ($isNewPlayer && !empty($referralCode)) {
        if (preg_match('/^[A-Z0-9]{6}$/', $referralCode)) {
            // Buscar referenciador
            $stmt = $pdo->prepare("
                SELECT wallet_address, google_uid FROM referral_codes WHERE code = ?
            ");
            $stmt->execute([$referralCode]);
            $ref = $stmt->fetch();

            if ($ref && !empty($ref['wallet_address'])) {
                $referrerWallet = strtolower($ref['wallet_address']);

                // Evitar auto-referral
                if ($referrerWallet !== $wallet) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO referrals (
                                referrer_wallet, referrer_google_uid,
                                referred_wallet, referral_code, 
                                status, created_at
                            ) VALUES (?, ?, ?, ?, 'pending', NOW())
                        ");
                        $stmt->execute([
                            $referrerWallet, 
                            $ref['google_uid'],
                            $wallet, 
                            $referralCode
                        ]);
                        $referralRegistered = true;
                    } catch (Exception $e) {
                        // Ignorar duplicata
                    }
                }
            }
        }
    }

    // ============================================
    // 4. RETORNAR DADOS DO JOGADOR
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, wallet_address, balance_brl, balance_usdt, total_played,
               google_uid, email, display_name
        FROM players WHERE wallet_address = ?
    ");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'player' => [
            'id' => (int)$player['id'],
            'wallet_address' => $player['wallet_address'],
            'google_uid' => $player['google_uid'],
            'email' => $player['email'],
            'display_name' => $player['display_name'],
            'balance_brl' => (float)($player['balance_brl'] ?? 0),
            'balance_usdt' => (float)($player['balance_usdt'] ?? 0),
            'total_played' => (int)($player['total_played'] ?? 0)
        ],
        'is_new_player' => $isNewPlayer,
        'referral_registered' => $referralRegistered,
        'referrer_wallet' => $referrerWallet,
        'legacy_mode' => true,
        'message' => 'Login via wallet é modo legado. Recomendamos usar Google Auth.'
    ]);

} catch (Exception $e) {
    error_log("Erro em login.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
