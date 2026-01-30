<?php
// ============================================
// UNOBIX - Informações de Afiliados
// Arquivo: api/referral-info.php
// v2.1 - Fix: Tratamento de duplicatas
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

// ===============================
// Input (JSON + POST + GET)
// ===============================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$googleUid = $input['google_uid'] ?? ($_POST['google_uid'] ?? ($_GET['google_uid'] ?? ''));
$wallet = $input['wallet'] ?? ($_POST['wallet'] ?? ($_GET['wallet'] ?? ''));

$googleUid = trim($googleUid);
$wallet = trim(strtolower($wallet));

// Determinar identificador
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

/**
 * Gera código alfanumérico único de 6 caracteres
 */
function generateReferralCode($pdo) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Sem I, O, 0, 1
    $maxAttempts = 10;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        $stmt = $pdo->prepare("SELECT id FROM referral_codes WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);

        if (!$stmt->fetch()) {
            return $code;
        }
    }

    return strtoupper(substr(md5(uniqid('', true) . time()), 0, 6));
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Erro ao conectar ao banco');

    // Verificar tabelas
    $referralsExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
    $codesExists = $pdo->query("SHOW TABLES LIKE 'referral_codes'")->fetch();

    if (!$codesExists) {
        echo json_encode(['success' => false, 'error' => 'Sistema de referral não configurado']);
        exit;
    }

    // ============================================
    // 1. BUSCAR OU CRIAR CÓDIGO DE REFERRAL
    // ============================================
    
    // Buscar wallet do player se só temos google_uid
    if (empty($wallet) && !empty($googleUid)) {
        $stmt = $pdo->prepare("SELECT wallet_address FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $player = $stmt->fetch();
        if ($player && !empty($player['wallet_address'])) {
            $wallet = strtolower($player['wallet_address']);
        }
    }
    
    // Buscar google_uid do player se só temos wallet
    if (empty($googleUid) && !empty($wallet)) {
        $stmt = $pdo->prepare("SELECT google_uid FROM players WHERE wallet_address = ? LIMIT 1");
        $stmt->execute([$wallet]);
        $player = $stmt->fetch();
        if ($player && !empty($player['google_uid'])) {
            $googleUid = $player['google_uid'];
        }
    }
    
    // Buscar código existente - verificar AMBOS identificadores separadamente
    $codeRow = null;
    
    // Primeiro tenta por google_uid
    if (!empty($googleUid)) {
        $stmt = $pdo->prepare("SELECT id, code, google_uid, wallet_address FROM referral_codes WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $codeRow = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Se não encontrou, tenta por wallet
    if (!$codeRow && !empty($wallet)) {
        $stmt = $pdo->prepare("SELECT id, code, google_uid, wallet_address FROM referral_codes WHERE wallet_address = ? LIMIT 1");
        $stmt->execute([$wallet]);
        $codeRow = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($codeRow && !empty($codeRow['code'])) {
        $referralCode = $codeRow['code'];
        
        // Atualizar registro se faltam dados
        $needsUpdate = false;
        $updateFields = [];
        $updateValues = [];
        
        if (empty($codeRow['google_uid']) && !empty($googleUid)) {
            $updateFields[] = "google_uid = ?";
            $updateValues[] = $googleUid;
            $needsUpdate = true;
        }
        
        if (empty($codeRow['wallet_address']) && !empty($wallet)) {
            $updateFields[] = "wallet_address = ?";
            $updateValues[] = $wallet;
            $needsUpdate = true;
        }
        
        if ($needsUpdate) {
            $updateValues[] = $codeRow['id'];
            $pdo->prepare("UPDATE referral_codes SET " . implode(', ', $updateFields) . " WHERE id = ?")
                ->execute($updateValues);
        }
        
    } else {
        // Criar novo código
        $referralCode = generateReferralCode($pdo);
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO referral_codes (google_uid, wallet_address, code, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([
                !empty($googleUid) ? $googleUid : null, 
                !empty($wallet) ? $wallet : null, 
                $referralCode
            ]);
        } catch (PDOException $e) {
            // Se der erro de duplicata, buscar o código existente
            if ($e->getCode() == 23000) {
                // Tentar buscar novamente
                $stmt = $pdo->prepare("
                    SELECT code FROM referral_codes 
                    WHERE google_uid = ? OR wallet_address = ? 
                    LIMIT 1
                ");
                $stmt->execute([$googleUid, $wallet]);
                $existingCode = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingCode && !empty($existingCode['code'])) {
                    $referralCode = $existingCode['code'];
                } else {
                    throw $e; // Re-lançar se ainda não encontrou
                }
            } else {
                throw $e;
            }
        }
    }

    // ============================================
    // 2. BUSCAR ESTATÍSTICAS (se tabela referrals existe)
    // ============================================
    $totalReferred = 0;
    $completedReferred = 0;
    $pendingReferred = 0;
    $availableCommission = 0;
    $claimedCommission = 0;
    $referralsList = [];

    if ($referralsExists) {
        // Total de indicados
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM referrals 
            WHERE referrer_google_uid = ? OR referrer_wallet = ?
        ");
        $stmt->execute([$googleUid, $wallet]);
        $totalReferred = (int)$stmt->fetchColumn();

        // Completados
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM referrals 
            WHERE (referrer_google_uid = ? OR referrer_wallet = ?) 
            AND status IN ('completed', 'claimed')
        ");
        $stmt->execute([$googleUid, $wallet]);
        $completedReferred = (int)$stmt->fetchColumn();

        // Pendentes
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM referrals 
            WHERE (referrer_google_uid = ? OR referrer_wallet = ?) 
            AND status = 'pending'
        ");
        $stmt->execute([$googleUid, $wallet]);
        $pendingReferred = (int)$stmt->fetchColumn();

        // Comissão disponível
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(commission_amount), 0) FROM referrals 
            WHERE (referrer_google_uid = ? OR referrer_wallet = ?) 
            AND status = 'completed'
        ");
        $stmt->execute([$googleUid, $wallet]);
        $availableCommission = (float)$stmt->fetchColumn();

        // Comissão já resgatada
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(commission_amount), 0) FROM referrals 
            WHERE (referrer_google_uid = ? OR referrer_wallet = ?) 
            AND status = 'claimed'
        ");
        $stmt->execute([$googleUid, $wallet]);
        $claimedCommission = (float)$stmt->fetchColumn();

        // ============================================
        // 3. LISTA DE INDICADOS
        // ============================================
        $stmt = $pdo->prepare("
            SELECT
                id,
                referred_google_uid,
                referred_wallet,
                status,
                missions_completed,
                missions_required,
                commission_amount,
                completed_at,
                claimed_at,
                created_at
            FROM referrals
            WHERE referrer_google_uid = ? OR referrer_wallet = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$googleUid, $wallet]);
        $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($referrals as $ref) {
            $missionsRequired = (int)($ref['missions_required'] ?? 100);
            $missionsCompleted = (int)($ref['missions_completed'] ?? 0);
            $progress = $missionsRequired > 0 ? min(100, round(($missionsCompleted / $missionsRequired) * 100)) : 0;

            // Identificador do indicado (preferir google_uid, fallback para wallet)
            $referredId = $ref['referred_google_uid'] ?: $ref['referred_wallet'];
            $referredShort = $ref['referred_google_uid'] 
                ? substr($ref['referred_google_uid'], 0, 8) . '...'
                : substr($ref['referred_wallet'], 0, 6) . '...' . substr($ref['referred_wallet'], -4);

            $referralsList[] = [
                'id' => (int)$ref['id'],
                'referred_id' => $referredId,
                'referred_short' => $referredShort,
                'status' => $ref['status'],
                'status_label' => getStatusLabel($ref['status']),
                'missions_completed' => $missionsCompleted,
                'missions_required' => $missionsRequired,
                'progress_percent' => $progress,
                'commission_brl' => number_format((float)$ref['commission_amount'], 2, '.', ''),
                'completed_at' => $ref['completed_at'],
                'claimed_at' => $ref['claimed_at'],
                'created_at' => $ref['created_at'],
                'created_at_formatted' => date('d/m/Y H:i', strtotime($ref['created_at']))
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'referral_code' => $referralCode,
        'referral_link' => '?ref=' . $referralCode,
        'stats' => [
            'total_referred' => $totalReferred,
            'completed' => $completedReferred,
            'pending' => $pendingReferred,
            'available_commission_brl' => number_format($availableCommission, 2, '.', ''),
            'claimed_commission_brl' => number_format($claimedCommission, 2, '.', ''),
            'total_earned_brl' => number_format($availableCommission + $claimedCommission, 2, '.', '')
        ],
        'referrals' => $referralsList,
        'config' => [
            'missions_required' => 100,
            'commission_brl' => 1.00
        ]
    ]);

} catch (Exception $e) {
    error_log("Erro em referral-info.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
}

function getStatusLabel($status) {
    $labels = [
        'pending' => 'Em progresso',
        'completed' => 'Disponível para resgate',
        'claimed' => 'Resgatado',
        'cancelled' => 'Cancelado'
    ];
    return $labels[$status] ?? ucfirst($status);
}
