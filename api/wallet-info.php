<?php
// api/wallet-info.php - Informacoes da carteira do jogador
// SIMPLIFICADO: total_earned = soma de todas as ENTRADAS (creditos)

require_once "../config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$wallet = isset($_GET['wallet']) ? $_GET['wallet'] : '';
if (!preg_match('/^0x[a-f0-9]{40}$/i', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira invalida']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $walletLower = strtolower($wallet);
    
    // Busca jogador
    $stmt = $pdo->prepare("SELECT balance_usdt, total_played FROM players WHERE wallet_address = ?");
    $stmt->execute([$walletLower]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$player) {
        // Se nao existir, cria jogador
        $pdo->prepare("INSERT INTO players (wallet_address, balance_usdt, total_played) VALUES (?, 0.0, 0)")
            ->execute([$walletLower]);
        
        echo json_encode([
            'success' => true,
            'balance' => 0.0,
            'total_played' => 0,
            'total_earned' => 0.0,
            'total_withdrawn' => 0.0,
            'pending_withdrawal' => 0.0
        ]);
        exit;
    }
    
    // ============================================
    // TOTAL EARNED = SOMA DE TODAS AS ENTRADAS
    // ============================================
    $totalEarned = 0.0;
    
    // 1. Ganhos de missoes (game_sessions)
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'game_sessions'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(earnings_usdt), 0) as total 
            FROM game_sessions 
            WHERE wallet_address = ?
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalEarned += (float)$result['total'];
    }
    
    // 2. Comissoes de afiliados (transactions)
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM transactions 
            WHERE wallet_address = ? AND type = 'referral_commission'
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalEarned += (float)$result['total'];
    }
    
    // 3. Rendimentos de staking (stakes.total_earned) - em unidades, dividir por 100000000
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'stakes'")->fetch();
    if ($tableCheck) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_earned), 0) as total 
            FROM stakes 
            WHERE wallet_address = ?
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stakingEarned = (float)$result['total'] / 100000000;
        $totalEarned += $stakingEarned;
    }
    
    // ============================================
    // TOTAL WITHDRAWN (saques aprovados)
    // ============================================
    $totalWithdrawn = 0.0;
    $pendingWithdrawal = 0.0;
    
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'withdrawals'")->fetch();
    if ($tableCheck) {
        // Saques aprovados/completados
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_usdt), 0) as total 
            FROM withdrawals 
            WHERE wallet_address = ? AND status IN ('approved', 'completed')
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalWithdrawn = (float)$result['total'];
        
        // Saques pendentes
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_usdt), 0) as total 
            FROM withdrawals 
            WHERE wallet_address = ? AND status = 'pending'
        ");
        $stmt->execute([$walletLower]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pendingWithdrawal = (float)$result['total'];
    }
    
    // ============================================
    // RETORNAR DADOS
    // ============================================
    echo json_encode([
        'success' => true,
        'balance' => (float)$player['balance_usdt'],
        'total_played' => (int)$player['total_played'],
        'total_earned' => $totalEarned,
        'total_withdrawn' => $totalWithdrawn,
        'pending_withdrawal' => $pendingWithdrawal
    ]);
    
} catch (Exception $e) {
    error_log("Erro em wallet-info.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}
?>