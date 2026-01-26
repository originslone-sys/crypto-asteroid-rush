<?php
// ============================================
// CRYPTO ASTEROID RUSH - Helper de Referrals
// Arquivo: api/referral-helper.php
// ============================================

function updateReferralProgress($pdo, $wallet) {
    $result = array(
        'updated' => false,
        'completed' => false,
        'referrer' => null
    );

    try {
        $wallet = trim(strtolower($wallet));

        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if (!$tableExists) {
            return $result;
        }

        $stmt = $pdo->prepare("
            SELECT id, referrer_wallet, missions_completed, missions_required
            FROM referrals
            WHERE referred_wallet = ? AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$wallet]);
        $referral = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$referral) {
            return $result;
        }

        $newMissions = (int)$referral['missions_completed'] + 1;
        $missionsRequired = (int)$referral['missions_required'];

        if ($newMissions >= $missionsRequired) {
            $stmt = $pdo->prepare("
                UPDATE referrals
                SET missions_completed = ?, status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newMissions, $referral['id']]);

            $result['completed'] = true;
            $result['referrer'] = $referral['referrer_wallet'];

            error_log("Referral completado: ID={$referral['id']}, Referred={$wallet}, Referrer={$referral['referrer_wallet']}");
        } else {
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

function getReferralInfo($pdo, $wallet) {
    try {
        $wallet = trim(strtolower($wallet));

        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        if (!$tableExists) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT r.*, rc.code as referrer_code
            FROM referrals r
            LEFT JOIN referral_codes rc ON rc.wallet_address = r.referrer_wallet
            WHERE r.referred_wallet = ?
            LIMIT 1
        ");
        $stmt->execute([$wallet]);
        return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        return null;
    }
}

function getReferrerStats($pdo, $wallet) {
    $stats = array(
        'total_referred' => 0,
        'pending' => 0,
        'completed' => 0,
        'claimed' => 0,
        'available_commission' => 0,
        'total_earned' => 0
    );

    try {
        $wallet = trim(strtolower($wallet));

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
            WHERE referrer_wallet = ?
        ");
        $stmt->execute([$wallet]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stats['total_referred'] = (int)$row['total'];
            $stats['pending'] = (int)$row['pending'];
            $stats['completed'] = (int)$row['completed'];
            $stats['claimed'] = (int)$row['claimed'];
            $stats['available_commission'] = (float)$row['available'];
            $stats['total_earned'] = (float)$row['total_earned'];
        }

    } catch (Exception $e) {
        error_log("Erro em getReferrerStats: " . $e->getMessage());
    }

    return $stats;
}
?>
