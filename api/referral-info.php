<?php
// ============================================
// CRYPTO ASTEROID RUSH - Informações de Afiliados
// Arquivo: api/referral-info.php
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo json_encode(['success' => true]);
    exit;
}

// Entrada híbrida: JSON + POST + GET
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet = '';
if (isset($_GET['wallet'])) $wallet = trim(strtolower($_GET['wallet']));
if ($wallet === '' && isset($_POST['wallet'])) $wallet = trim(strtolower($_POST['wallet']));
if ($wallet === '' && isset($input['wallet'])) $wallet = trim(strtolower($input['wallet']));

// Validar wallet
if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
    echo json_encode(['success' => false, 'error' => 'Carteira inválida']);
    exit;
}

/**
 * Gera código alfanumérico único de 6 caracteres
 */
function generateReferralCode($pdo, $wallet) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Sem I, O, 0, 1
    $maxAttempts = 10;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        $stmt = $pdo->prepare("SELECT id FROM referral_codes WHERE code = ?");
        $stmt->execute([$code]);

        if (!$stmt->fetch()) {
            return $code;
        }
    }

    // Fallback (agora com $wallet válido)
    return strtoupper(substr(md5($wallet . time()), 0, 6));
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // ============================================
    // 1. BUSCAR OU CRIAR CÓDIGO DE REFERRAL
    // ============================================
    $stmt = $pdo->prepare("SELECT code FROM referral_codes WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $codeRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($codeRow) {
        $referralCode = $codeRow['code'];
    } else {
        $referralCode = generateReferralCode($pdo, $wallet);
        $stmt = $pdo->prepare("INSERT INTO referral_codes (wallet_address, code) VALUES (?, ?)");
        $stmt->execute([$wallet, $referralCode]);
    }

    // ============================================
    // 2. BUSCAR ESTATÍSTICAS DE INDICAÇÕES
    // ============================================
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_wallet = ?");
    $stmt->execute([$wallet]);
    $totalReferred = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_wallet = ? AND status IN ('completed', 'claimed')");
    $stmt->execute([$wallet]);
    $completedReferred = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(commission_amount), 0) FROM referrals WHERE referrer_wallet = ? AND status = 'completed'");
    $stmt->execute([$wallet]);
    $availableCommission = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(commission_amount), 0) FROM referrals WHERE referrer_wallet = ? AND status = 'claimed'");
    $stmt->execute([$wallet]);
    $claimedCommission = (float)$stmt->fetchColumn();

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

    $referralsList = [];
    foreach ($referrals as $ref) {
        $missionsRequired = (int)$ref['missions_required'];
        $missionsCompleted = (int)$ref['missions_completed'];
        $progressPercent = 0;

        if ($missionsRequired > 0) {
            $progressPercent = min(100, round(($missionsCompleted / $missionsRequired) * 100));
        }

        $referralsList[] = [
            'id' => (int)$ref['id'],
            'wallet' => $ref['referred_wallet'],
            'wallet_short' => substr($ref['referred_wallet'], 0, 6) . '...' . substr($ref['referred_wallet'], -4),
            'status' => $ref['status'],
            'missions_completed' => $missionsCompleted,
            'missions_required' => $missionsRequired,
            'progress_percent' => $progressPercent,
            'commission' => number_format((float)$ref['commission_amount'], 2, '.', ''),
            'completed_at' => $ref['completed_at'],
            'claimed_at' => $ref['claimed_at'],
            'created_at' => $ref['created_at']
        ];
    }

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
