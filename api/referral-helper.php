<?php
// ============================================
// UNOBIX - Helper de Referrals
// Arquivo: api/referral-helper.php
// v3.0 - Google Auth + users + 6 casas decimais
// ============================================

/**
 * Atualiza progresso de referral após completar uma missão
 * 
 * @param PDO $pdo
 * @param string $googleUid
 * @return array
 */
function updateReferralProgress($pdo, $googleUid = '') {
    $result = [
        'updated' => false,
        'completed' => false,
        'referrer_google_uid' => null
    ];

    try {
        $googleUid = trim($googleUid);
        if (empty($googleUid)) return $result;

        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if (!$tableExists) return $result;

        // Buscar referral pendente do usuário
        $stmt = $pdo->prepare("
            SELECT id, referrer_google_uid, missions_completed, missions_required
            FROM referrals
            WHERE referred_google_uid = ?
              AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$googleUid]);
        $referral = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$referral) return $result;

        $newMissions = (int)$referral['missions_completed'] + 1;
        $missionsRequired = (int)($referral['missions_required'] ?? 20);

        if ($newMissions >= $missionsRequired) {
            // Completou! Marcar como qualificado (disponível para claim)
            $stmt = $pdo->prepare("
                UPDATE referrals
                SET missions_completed = ?,
                    status = 'qualified',
                    qualified_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newMissions, $referral['id']]);

            $result['completed'] = true;
            $result['referrer_google_uid'] = $referral['referrer_google_uid'];

            if (function_exists('secureLog')) {
                secureLog("REFERRAL_COMPLETED | ID: {$referral['id']} | Referred: {$googleUid} | Referrer: {$referral['referrer_google_uid']}");
            }
        } else {
            // Apenas atualiza contador
            $stmt = $pdo->prepare("
                UPDATE referrals
                SET missions_completed = ?
                WHERE id = ?
            ");
            $stmt->execute([$newMissions, $referral['id']]);
        }

        $result['updated'] = true;

    } catch (Exception $e) {
        error_log("Erro em updateReferralProgress: " . $e->getMessage());
    }

    return $result;
}

/**
 * Busca informações de referral de um usuário (como indicado)
 * 
 * @param PDO $pdo
 * @param string $googleUid
 * @return array|null
 */
function getReferralInfo($pdo, $googleUid = '') {
    try {
        $googleUid = trim($googleUid);
        if (empty($googleUid)) return null;

        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if (!$tableExists) return null;

        $stmt = $pdo->prepare("
            SELECT r.*, rc.code as referrer_code
            FROM referrals r
            LEFT JOIN referral_codes rc ON rc.google_uid = r.referrer_google_uid
            WHERE r.referred_google_uid = ?
            LIMIT 1
        ");
        $stmt->execute([$googleUid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Erro em getReferralInfo: " . $e->getMessage());
        return null;
    }
}

/**
 * Busca estatísticas de referral de um usuário (como referenciador)
 * 
 * @param PDO $pdo
 * @param string $googleUid
 * @return array
 */
function getReferrerStats($pdo, $googleUid = '') {
    $stats = [
        'total_referred' => 0,
        'pending' => 0,
        'qualified' => 0,
        'claimed' => 0,
        'available_commission_brl' => 0,
        'total_earned_brl' => 0
    ];

    try {
        $googleUid = trim($googleUid);
        if (empty($googleUid)) return $stats;

        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if (!$tableExists) return $stats;

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) as qualified,
                SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) as claimed,
                COALESCE(SUM(CASE WHEN status = 'qualified' THEN commission_brl ELSE 0 END), 0) as available,
                COALESCE(SUM(CASE WHEN status IN ('qualified', 'claimed') THEN commission_brl ELSE 0 END), 0) as total_earned
            FROM referrals
            WHERE referrer_google_uid = ?
        ");
        $stmt->execute([$googleUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stats['total_referred'] = (int)$row['total'];
            $stats['pending'] = (int)$row['pending'];
            $stats['qualified'] = (int)$row['qualified'];
            $stats['claimed'] = (int)$row['claimed'];
            $stats['available_commission_brl'] = (float)$row['available'];
            $stats['total_earned_brl'] = (float)$row['total_earned'];
        }

    } catch (Exception $e) {
        error_log("Erro em getReferrerStats: " . $e->getMessage());
    }

    return $stats;
}

/**
 * Verifica se um código de referral é válido
 * 
 * @param PDO $pdo
 * @param string $code
 * @return array|false
 */
function validateReferralCode($pdo, $code) {
    try {
        $code = trim(strtoupper($code));
        
        if (!preg_match('/^[A-Z0-9]{6}$/', $code)) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT google_uid FROM referral_codes 
            WHERE code = ? AND is_active = 1
        ");
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        return false;
    }
}
