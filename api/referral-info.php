<?php
// ============================================
// UNOBIX - Informações de Afiliados
// Arquivo: api/referral-info.php
// v3.0 - Google Auth + users + 6 casas decimais
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();

$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? $input['uid'] ?? '');

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
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
    $codesExists = $pdo->query("SHOW TABLES LIKE 'referral_codes'")->fetch();
    if (!$codesExists) {
        echo json_encode(['success' => false, 'error' => 'Sistema de referral não configurado']);
        exit;
    }

    $referralsExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();

    // ============================================
    // 1. BUSCAR OU CRIAR CÓDIGO DE REFERRAL
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, code, google_uid
        FROM referral_codes
        WHERE google_uid = ?
        LIMIT 1
    ");
    $stmt->execute([$googleUid]);
    $codeRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($codeRow && !empty($codeRow['code'])) {
        $referralCode = $codeRow['code'];
    } else {
        // Criar novo código
        $referralCode = generateReferralCode($pdo);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO referral_codes (google_uid, wallet_address, code, uses_count, max_uses, is_active, created_at)
                VALUES (?, '', ?, 0, 0, 1, NOW())
            ");
            $stmt->execute([$googleUid, $referralCode]);
        } catch (PDOException $e) {
            // Erro de duplicata — buscar existente
            if ($e->getCode() == 23000) {
                $stmt = $pdo->prepare("
                    SELECT code FROM referral_codes
                    WHERE google_uid = ?
                    LIMIT 1
                ");
                $stmt->execute([$googleUid]);
                $existingCode = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingCode && !empty($existingCode['code'])) {
                    $referralCode = $existingCode['code'];
                } else {
                    throw $e;
                }
            } else {
                throw $e;
            }
        }
    }

    // ============================================
    // 2. BUSCAR ESTATÍSTICAS
    // ============================================
    $totalReferred = 0;
    $qualifiedReferred = 0;
    $pendingReferred = 0;
    $availableCommission = 0;
    $claimedCommission = 0;
    $referralsList = [];

    if ($referralsExists) {
        // Total de indicados
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM referrals
            WHERE referrer_google_uid = ?
        ");
        $stmt->execute([$googleUid]);
        $totalReferred = (int)$stmt->fetchColumn();

        // Qualificados (disponível para resgate)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM referrals
            WHERE referrer_google_uid = ?
              AND status = 'qualified'
        ");
        $stmt->execute([$googleUid]);
        $qualifiedReferred = (int)$stmt->fetchColumn();

        // Pendentes (indicado ainda jogando)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM referrals
            WHERE referrer_google_uid = ?
              AND status = 'pending'
        ");
        $stmt->execute([$googleUid]);
        $pendingReferred = (int)$stmt->fetchColumn();

        // Comissão disponível para resgate
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(commission_brl), 0) FROM referrals
            WHERE referrer_google_uid = ?
              AND status = 'qualified'
        ");
        $stmt->execute([$googleUid]);
        $availableCommission = (float)$stmt->fetchColumn();

        // Comissão já resgatada
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(commission_brl), 0) FROM referrals
            WHERE referrer_google_uid = ?
              AND status = 'claimed'
        ");
        $stmt->execute([$googleUid]);
        $claimedCommission = (float)$stmt->fetchColumn();

        // ============================================
        // 3. LISTA DE INDICADOS
        // ============================================
        $stmt = $pdo->prepare("
            SELECT
                id,
                referred_google_uid,
                status,
                missions_completed,
                missions_required,
                commission_brl,
                qualified_at,
                commission_paid_at,
                created_at
            FROM referrals
            WHERE referrer_google_uid = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$googleUid]);
        $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($referrals as $ref) {
            $missionsRequired = (int)($ref['missions_required'] ?? 100);
            $missionsCompleted = (int)($ref['missions_completed'] ?? 0);
            $progress = $missionsRequired > 0
                ? min(100, round(($missionsCompleted / $missionsRequired) * 100))
                : 0;

            $referredShort = substr($ref['referred_google_uid'], 0, 8) . '...';

            $referralsList[] = [
                'id' => (int)$ref['id'],
                'referred_id' => $ref['referred_google_uid'],
                'referred_short' => $referredShort,
                'status' => $ref['status'],
                'status_label' => getStatusLabel($ref['status']),
                'missions_completed' => $missionsCompleted,
                'missions_required' => $missionsRequired,
                'progress_percent' => $progress,
                'commission_brl' => number_format((float)$ref['commission_brl'], 6, '.', ''),
                'qualified_at' => $ref['qualified_at'],
                'commission_paid_at' => $ref['commission_paid_at'],
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
            'qualified' => $qualifiedReferred,
            'pending' => $pendingReferred,
            'available_commission_brl' => number_format($availableCommission, 6, '.', ''),
            'claimed_commission_brl' => number_format($claimedCommission, 6, '.', ''),
            'total_earned_brl' => number_format($availableCommission + $claimedCommission, 6, '.', '')
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
        'qualified' => 'Disponível para resgate',
        'claimed' => 'Resgatado',
        'cancelled' => 'Cancelado'
    ];
    return $labels[$status] ?? ucfirst($status);
}
