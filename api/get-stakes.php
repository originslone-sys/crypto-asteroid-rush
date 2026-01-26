<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/config.php';

// Entrada híbrida: JSON + POST + GET (Opção A)
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet =
    (isset($input['wallet']) ? trim($input['wallet']) : '') ?:
    (isset($_POST['wallet']) ? trim($_POST['wallet']) : '') ?:
    (isset($_GET['wallet']) ? trim($_GET['wallet']) : '');

$wallet = strtolower($wallet);

if (empty($wallet) || !validateWallet($wallet)) {
    // preserva formato original (só 'error')
    echo json_encode(['error' => 'Wallet inválida']);
    exit;
}

$pdo = getDatabaseConnection();
if (!$pdo) {
    echo json_encode(['error' => 'Erro de conexão com o banco']);
    exit;
}

try {
    // Buscar stakes ativos do usuário
    $stmt = $pdo->prepare("
        SELECT * FROM stakes
        WHERE wallet_address = :wallet AND status = 'active'
        ORDER BY created_at DESC
    ");
    $stmt->execute([':wallet' => $wallet]);
    $stakes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStaked = 0;
    $totalEarned = 0;
    $totalEarnedToday = 0;
    $stakesFormatted = [];

    foreach ($stakes as $stake) {
        // Converter de unidades para USDT
        $amountUsdt = (float)$stake['amount'] / 100000000;
        $totalEarnedUsdt = (float)$stake['total_earned'] / 100000000;

        // Garantir que created_at seja uma data válida
        $createdAt = $stake['created_at'];
        if (empty($createdAt) || $createdAt == '0000-00-00 00:00:00' || $createdAt == '0000-00-00') {
            $createdAt = date('Y-m-d H:i:s');
        } else {
            $timestamp = strtotime($createdAt);
            if ($timestamp === false) {
                $createdAt = date('Y-m-d H:i:s');
            } else {
                $createdAt = date('Y-m-d H:i:s', $timestamp);
            }
        }

        $startTime = strtotime($createdAt);
        $now = time();
        $hoursPassed = max(0, ($now - $startTime) / 3600);
        $hourlyRate = STAKE_APY / (365 * 24);

        // Calcular ganhos acumulados (em USDT)
        $earnedUsdt = $amountUsdt * $hourlyRate * $hoursPassed;
        $totalEarnedForThisStake = $totalEarnedUsdt + $earnedUsdt;

        // Calcular ganhos nas últimas 24 horas
        $hoursToday = min($hoursPassed, 24);
        $earnedTodayUsdt = $amountUsdt * $hourlyRate * $hoursToday;

        // Taxa horária em USDT
        $hourlyRateUsdt = $amountUsdt * $hourlyRate;

        $stakeFormatted = [
            'id' => $stake['id'],
            'amount' => $amountUsdt,
            'apy' => STAKE_APY,
            'start_time' => $createdAt,
            'total_earned' => $totalEarnedForThisStake,
            'earned_today' => $earnedTodayUsdt,
            'hourly_rate' => $hourlyRateUsdt,
            'created_at_formatted' => date('d/m/Y H:i', strtotime($createdAt))
        ];

        $stakesFormatted[] = $stakeFormatted;
        $totalStaked += $amountUsdt;
        $totalEarned += $totalEarnedForThisStake;
        $totalEarnedToday += $earnedTodayUsdt;
    }

    echo json_encode([
        'success' => true,
        'stakes' => $stakesFormatted,
        'total_staked' => $totalStaked,
        'total_earned' => $totalEarned,
        'today_earnings' => $totalEarnedToday,
        'apy' => STAKE_APY * 100
    ]);

} catch(PDOException $e) {
    error_log("Erro ao buscar stakes: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar dados',
        'stakes' => [],
        'total_staked' => 0,
        'total_earned' => 0,
        'today_earnings' => 0,
        'apy' => STAKE_APY * 100
    ]);
}
?>
