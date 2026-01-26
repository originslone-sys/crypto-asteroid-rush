<?php
// ============================================
// CRYPTO ASTEROID RUSH - Login / Registro
// Arquivo: api/login.php
// ATUALIZADO: Suporte a sistema de afiliados
// ============================================

require_once __DIR__ . "/config.php";

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
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao conectar ao banco']);
        exit;
    }

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

        // 3.1 Validar formato do código (ex: ABC123)
        if (!preg_match('/^[A-Z0-9]{6}$/', $referralCode)) {
            echo json_encode(['success' => false, 'error' => 'Código de referral inválido']);
            exit;
        }

        // 3.2 Buscar carteira do referenciador
        $stmt = $pdo->prepare("SELECT wallet_address FROM referrals WHERE referral_code = ?");
        $stmt->execute([$referralCode]);
        $ref = $stmt->fetch();

        if ($ref && !empty($ref['wallet_address'])) {
            $referrerWallet = strtolower($ref['wallet_address']);

            // Evitar auto-referral
            if ($referrerWallet !== $wallet) {

                // 3.3 Registrar referral do jogador
                $stmt = $pdo->prepare("INSERT INTO referral_players (player_wallet, referrer_wallet, referral_code, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$wallet, $referrerWallet, $referralCode]);

                // 3.4 Incrementar contador de referrals do referenciador
                $pdo->prepare("UPDATE referrals SET total_referrals = total_referrals + 1 WHERE wallet_address = ?")
                    ->execute([$referrerWallet]);

                $referralRegistered = true;
            }
        }
    }

    // ============================================
    // 4. RETORNAR DADOS DO JOGADOR
    // ============================================
    $stmt = $pdo->prepare("SELECT id, wallet_address, balance_usdt, total_played FROM players WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $player = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'player' => $player,
        'is_new_player' => $isNewPlayer,
        'referral_registered' => $referralRegistered,
        'referrer_wallet' => $referrerWallet
    ]);

} catch (Exception $e) {
    error_log("Erro em login.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
