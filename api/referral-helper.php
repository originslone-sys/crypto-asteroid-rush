<?php
// ============================================
// UNOBIX - Helper de Referrals
// Arquivo: api/referral-helper.php
// v2.0 - Google Auth + BRL
// ============================================

/**
 * Atualiza progresso de referral após completar uma missão
 * 
 * @param PDO $pdo
 * @param string $googleUid
 * @param string $wallet
 * @return array
 */
function updateReferralProgress($pdo, $googleUid = '', $wallet = '') {
    $result = [
        'updated' => false,
        'completed' => false,
        'referrer_google_uid' => null,
        'referrer_wallet' => null
    ];

    try {
        $googleUid = trim($googleUid);
        $wallet = trim(strtolower($wallet));

        if (empty($googleUid) && empty($wallet)) {
            return $result;
        }

        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if (!$tableExists) {
            return $result;
        }

        // Buscar referral pendente do usuário
        $stmt = $pdo->prepare("
            SELECT id, referrer_wallet, referrer_google_uid, missions_completed, missions_required
            FROM referrals
            WHERE (referred_google_uid = ? OR referred_wallet = ?) 
            AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$googleUid, $wallet]);
        $referral = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$referral) {
            return $result;
        }

        $newMissions = (int)$referral['missions_completed'] + 1;
        $missionsRequired = (int)($referral['missions_required'] ?? 100);

        if ($newMissions >= $missionsRequired) {
            // Completou! Marcar como disponível para claim
            $stmt = $pdo->prepare("
                UPDATE referrals
                SET missions_completed = ?, status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newMissions, $referral['id']]);

            $result['completed'] = true;
            $result['referrer_google_uid'] = $referral['referrer_google_uid'];
            $result['referrer_wallet'] = $referral['referrer_wallet'];

            // Log
            if (function_exists('secureLog')) {
                secureLog("REFERRAL_COMPLETED | ID: {$referral['id']} | Referred: {$googleUid}/{$wallet} | Referrer: {$referral['referrer_google_uid']}/{$referral['referrer_wallet']}");
            } else {
                error_log("Referral completado: ID={$referral['id']}, Referred={$googleUid}/{$wallet}");
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
 * @param string $wallet
 * @return array|null
 */
function getReferralInfo($pdo, $googleUid = '', $wallet = '') {
    try {
        $googleUid = trim($googleUid);
        $wallet = trim(strtolower($wallet));

        if (empty($googleUid) && empty($wallet)) {
            return null;
        }

        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if (!$tableExists) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT r.*, rc.code as referrer_code
            FROM referrals r
            LEFT JOIN referral_codes rc ON rc.wallet_address = r.referrer_wallet 
                                        OR rc.google_uid = r.referrer_google_uid
            WHERE r.referred_google_uid = ? OR r.referred_wallet = ?
            LIMIT 1
        ");
        $stmt->execute([$googleUid, $wallet]);
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
 * @param string $wallet
 * @return array
 */
function getReferrerStats($pdo, $googleUid = '', $wallet = '') {
    $stats = [
        'total_referred' => 0,
        'pending' => 0,
        'completed' => 0,
        'claimed' => 0,
        'available_commission_brl' => 0,
        'total_earned_brl' => 0
    ];

    try {
        $googleUid = trim($googleUid);
        $wallet = trim(strtolower($wallet));

        if (empty($googleUid) && empty($wallet)) {
            return $stats;
        }

        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if (!$tableExists) {
            return $stats;
        }

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) as claimed,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN commission_amount ELSE 0 END), 0) as available,
                COALESCE(SUM(CASE WHEN status IN ('completed', 'claimed') THEN commission_amount ELSE 0 END), 0) as total_earned
            FROM referrals
            WHERE referrer_google_uid = ? OR referrer_wallet = ?
        ");
        $stmt->execute([$googleUid, $wallet]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stats['total_referred'] = (int)$row['total'];
            $stats['pending'] = (int)$row['pending'];
            $stats['completed'] = (int)$row['completed'];
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
            SELECT google_uid, wallet_address FROM referral_codes WHERE code = ?
        ");
        $stmt->execute([$code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        return false;
    }
}
