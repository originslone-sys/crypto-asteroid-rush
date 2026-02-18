<?php
// ============================================
// UNOBIX - Cron: Atualizar Stakes
// Arquivo: api/update-stakes.php
// v3.0 - Tabela staking + users + APY 5%
//
// Execute via CRON: 0 * * * * (a cada hora)
// ============================================

require_once __DIR__ . '/config.php';

date_default_timezone_set('America/Sao_Paulo');

$pdo = getDatabaseConnection();
if (!$pdo) {
    error_log("update-stakes: Falha na conexão com banco");
    exit(1);
}

try {
    // Alinhar timezone do MySQL
    try { $pdo->exec("SET time_zone = '-03:00'"); } catch (Exception $e) {}

    // ============================================
    // LOCK global: impede execução concorrente
    // ============================================
    $lockName = 'cron_update_stakes';
    $lockStmt = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 0) AS got_lock");
    $gotLock = (int)($lockStmt ? $lockStmt->fetchColumn() : 0);

    if ($gotLock !== 1) {
        error_log("update-stakes: execução ignorada (lock já em uso)");
        exit(0);
    }

    $updatedCount = 0;
    $totalEarningsDistributed = 0;
    $dailyRate = STAKE_APY / 365;

    // Buscar IDs dos stakes ativos
    $stmt = $pdo->query("SELECT id FROM staking WHERE status = 'active'");
    $stakeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($stakeIds as $id) {
        $pdo->beginTransaction();

        try {
            // Lock individual do stake
            $s = $pdo->prepare("
                SELECT id, user_id, google_uid, amount, amount_brl, 
                       earnings, total_earned_brl, start_date, updated_at
                FROM staking 
                WHERE id = ? AND status = 'active' 
                FOR UPDATE
            ");
            $s->execute([$id]);
            $stake = $s->fetch(PDO::FETCH_ASSOC);

            if (!$stake) {
                $pdo->rollBack();
                continue;
            }

            // Verificar updated_at
            $lastUpdate = $stake['updated_at'] ?? $stake['start_date'] ?? null;
            if (!$lastUpdate || strtotime($lastUpdate) === false) {
                // Sem data válida — inicializar e pular
                $pdo->prepare("UPDATE staking SET updated_at = NOW() WHERE id = ?")
                    ->execute([$id]);
                $pdo->commit();
                continue;
            }

            $lastUpdateTime = strtotime($lastUpdate);
            $now = time();
            $hoursPassed = ($now - $lastUpdateTime) / 3600;

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
            // VALOR EM BRL
            // ============================================
            $amountBrl = (float)($stake['amount_brl'] ?? 0);
            if ($amountBrl == 0) {
                $amountBrl = (float)($stake['amount'] ?? 0);
            }

            if ($amountBrl <= 0) {
                $pdo->prepare("UPDATE staking SET updated_at = NOW() WHERE id = ?")
                    ->execute([$id]);
                $pdo->commit();
                continue;
            }

            // ============================================
            // CALCULAR GANHOS (juros compostos diários)
            // ============================================
            $daysElapsed = $hoursPassed / 24;
            $earningsBrl = $amountBrl * (pow(1 + $dailyRate, $daysElapsed) - 1);

            if ($earningsBrl < 0.0001) {
                // Valor muito pequeno — NÃO atualizar updated_at
                // para permitir acúmulo de tempo até atingir threshold
                $pdo->rollBack();
                continue;
            }

            // Rendimentos acumulados
            $currentEarnings = (float)($stake['total_earned_brl'] ?? $stake['earnings'] ?? 0);
            $newTotalEarnedBrl = $currentEarnings + $earningsBrl;

            // Atualizar stake (NÃO soma ao principal — rendimento é separado)
            $pdo->prepare("
                UPDATE staking
                SET earnings = :earnings,
                    total_earned_brl = :total_earned_brl,
                    updated_at = NOW()
                WHERE id = :id
            ")->execute([
                ':earnings' => round($newTotalEarnedBrl, 6),
                ':total_earned_brl' => round($newTotalEarnedBrl, 6),
                ':id' => $id
            ]);

            // ============================================
            // ATUALIZAR staked_balance_brl NO USERS
            // ============================================
            $userId = (int)$stake['user_id'];
            if ($userId > 0) {
                $pdo->prepare("
                    UPDATE users 
                    SET staked_balance_brl = (
                        SELECT COALESCE(SUM(amount_brl), 0) 
                        FROM staking 
                        WHERE user_id = ? AND status = 'active'
                    ),
                    last_stake_update = NOW()
                    WHERE id = ?
                ")->execute([$userId, $userId]);
            }

            $pdo->commit();

            $updatedCount++;
            $totalEarningsDistributed += $earningsBrl;

            error_log("Stake {$id} atualizado: +R$ " . number_format($earningsBrl, 6, '.', '') . " ({$hoursPassed}h)");

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Erro ao atualizar stake {$id}: " . $e->getMessage());
            continue;
        }
    }

    // Libera o lock
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");

    $message = "Stakes atualizados: {$updatedCount} | Total distribuído: R$ " . number_format($totalEarningsDistributed, 6);
    error_log($message);

    // Retornar JSON se chamado via HTTP
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'updated_count' => $updatedCount,
            'total_earnings_distributed_brl' => round($totalEarningsDistributed, 6),
            'apy' => STAKE_APY * 100 . '%'
        ]);
    } else {
        echo $message . "\n";
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote('cron_update_stakes') . ")");
    } catch (Exception $e2) {}

    error_log("Erro fatal ao atualizar stakes: " . $e->getMessage());
    exit(1);
}
