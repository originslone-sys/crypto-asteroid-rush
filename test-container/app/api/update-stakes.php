<?php
// ============================================
// UNOBIX - Cron: Atualizar Stakes
// Arquivo: api/update-stakes.php
// v2.0 - APY 5% + BRL
// 
// Execute via CRON:
// Railway (UTC): 0 * * * *  (a cada hora)
// ============================================

require_once __DIR__ . '/config.php';

date_default_timezone_set('America/Sao_Paulo');

$pdo = getDatabaseConnection();
if (!$pdo) {
    error_log("Falha na conexão com banco para update-stakes");
    exit(1);
}

try {
    // Alinhar timezone do MySQL com BRT
    try { $pdo->exec("SET time_zone = '-03:00'"); } catch (Exception $e) {}

    // ============================================
    // LOCK global: impede execução concorrente
    // ============================================
    $lockName = 'cron_update_stakes';
    $lockStmt = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 0) AS got_lock");
    $gotLock = (int)($lockStmt ? $lockStmt->fetchColumn() : 0);

    if ($gotLock !== 1) {
        error_log("update-stakes: execução ignorada (lock já em uso).");
        exit(0);
    }

    $updatedCount = 0;
    $totalEarningsDistributed = 0;

    // Buscar IDs dos stakes ativos
    $stmt = $pdo->query("SELECT id FROM stakes WHERE status = 'active'");
    $stakeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($stakeIds as $id) {
        $pdo->beginTransaction();

        $s = $pdo->prepare("SELECT * FROM stakes WHERE id = ? AND status = 'active' FOR UPDATE");
        $s->execute([$id]);
        $stake = $s->fetch(PDO::FETCH_ASSOC);

        if (!$stake) {
            $pdo->rollBack();
            continue;
        }

        // Verificar updated_at
        $lastUpdate = strtotime($stake['updated_at'] ?? '');

        if ($lastUpdate === false || empty($stake['updated_at']) || $stake['updated_at'] === '0000-00-00 00:00:00') {
            $u = $pdo->prepare("UPDATE stakes SET updated_at = NOW() WHERE id = ?");
            $u->execute([$id]);
            $pdo->commit();
            continue;
        }

        $now = time();
        $hoursPassed = ($now - $lastUpdate) / 3600;

        // Mínimo 1 hora para atualizar
        if ($hoursPassed < 1) {
            $pdo->rollBack();
            continue;
        }

        // Cap de segurança: máximo 5 anos
        if ($hoursPassed > (365 * 24 * 5)) {
            $hoursPassed = (365 * 24 * 5);
        }

        // ============================================
        // PRIORIZAR VALORES EM BRL
        // ============================================
        $amountBrl = (float)($stake['amount_brl'] ?? 0);
        $totalEarnedBrl = (float)($stake['total_earned_brl'] ?? 0);

        // Fallback: converter de unidades antigas se BRL = 0
        if ($amountBrl == 0 && isset($stake['amount'])) {
            $amountBrl = (float)$stake['amount'] / 100000000;
        }

        if ($amountBrl <= 0) {
            $u = $pdo->prepare("UPDATE stakes SET updated_at = NOW() WHERE id = ?");
            $u->execute([$id]);
            $pdo->commit();
            continue;
        }

        // ============================================
        // CALCULAR GANHOS COM APY 5%
        // ============================================
        $hourlyRate = STAKE_APY / (365 * 24);  // 5% / 8760 horas
        $earningsBrl = $amountBrl * $hourlyRate * $hoursPassed;

        if ($earningsBrl < 0.0001) {
            // Valor muito pequeno, apenas atualiza timestamp
            $u = $pdo->prepare("UPDATE stakes SET updated_at = NOW() WHERE id = ?");
            $u->execute([$id]);
            $pdo->commit();
            continue;
        }

        // Atualizar stake com ganhos compostos
        $newAmountBrl = $amountBrl + $earningsBrl;
        $newTotalEarnedBrl = $totalEarnedBrl + $earningsBrl;

        // Também atualizar em unidades para compatibilidade
        $newAmountUnits = (int)round($newAmountBrl * 100000000);
        $newTotalEarnedUnits = (int)round($newTotalEarnedBrl * 100000000);

        $u = $pdo->prepare("
            UPDATE stakes
            SET amount = :amount_units,
                amount_brl = :amount_brl,
                total_earned = :total_earned_units,
                total_earned_brl = :total_earned_brl,
                updated_at = NOW()
            WHERE id = :id
        ");
        $u->execute([
            ':amount_units' => $newAmountUnits,
            ':amount_brl' => round($newAmountBrl, 4),
            ':total_earned_units' => $newTotalEarnedUnits,
            ':total_earned_brl' => round($newTotalEarnedBrl, 4),
            ':id' => $id
        ]);

        // ============================================
        // ATUALIZAR staked_balance_brl NO PLAYER
        // ============================================
        if (!empty($stake['google_uid']) || !empty($stake['wallet_address'])) {
            $pdo->prepare("
                UPDATE players 
                SET staked_balance_brl = (
                    SELECT COALESCE(SUM(amount_brl), 0) 
                    FROM stakes 
                    WHERE (google_uid = ? OR wallet_address = ?) 
                    AND status = 'active'
                ),
                last_stake_update = NOW()
                WHERE google_uid = ? OR wallet_address = ?
            ")->execute([
                $stake['google_uid'], 
                $stake['wallet_address'],
                $stake['google_uid'], 
                $stake['wallet_address']
            ]);
        }

        $pdo->commit();

        $updatedCount++;
        $totalEarningsDistributed += $earningsBrl;
        
        error_log("Stake {$id} atualizado: +R$ " . number_format($earningsBrl, 4, '.', '') . " ({$hoursPassed}h)");
    }

    // Libera o lock
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");

    $message = "Stakes atualizados: {$updatedCount} | Total distribuído: R$ " . number_format($totalEarningsDistributed, 4);
    error_log($message);
    echo $message . "\n";

    // Retornar JSON se chamado via HTTP
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'updated_count' => $updatedCount,
            'total_earnings_distributed_brl' => round($totalEarningsDistributed, 4),
            'apy' => STAKE_APY * 100 . '%'
        ]);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote('cron_update_stakes') . ")");
    } catch (Exception $e2) {}

    error_log("Erro ao atualizar stakes: " . $e->getMessage());
    exit(1);
}
