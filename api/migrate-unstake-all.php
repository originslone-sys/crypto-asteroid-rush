<?php
// ============================================
// MIGRAÇÃO: Devolver todo saldo em stake ao saldo principal
// Executar UMA VEZ para remover feature de staking
// Uso: php api/migrate-unstake-all.php
// ============================================

require_once __DIR__ . '/config.php';

echo "=== MIGRAÇÃO: Unstake de todos os usuários ===\n\n";

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    // Verificar se tabela staking existe
    $tableExists = $pdo->query("SHOW TABLES LIKE 'staking'")->fetch();
    if (!$tableExists) {
        echo "Tabela 'staking' não existe. Nada a migrar.\n";
        exit(0);
    }

    // Buscar todos os stakes ativos
    $activeStakes = $pdo->query("
        SELECT s.*, u.balance_brl, u.staked_balance_brl, u.google_uid as user_google_uid
        FROM staking s
        JOIN users u ON u.id = s.user_id
        WHERE s.status = 'active'
        ORDER BY s.user_id
    ")->fetchAll();

    $totalStakes = count($activeStakes);
    echo "Stakes ativos encontrados: {$totalStakes}\n\n";

    if ($totalStakes === 0) {
        // Mesmo sem stakes ativos, zerar staked_balance_brl de todos
        $pdo->exec("UPDATE users SET staked_balance_brl = 0 WHERE staked_balance_brl > 0");
        echo "Nenhum stake ativo. staked_balance_brl zerado para todos.\n";
        exit(0);
    }

    $now = time();
    $dailyRate = defined('STAKE_APY') ? STAKE_APY / 365 : 0.05 / 365;
    $usersProcessed = 0;
    $totalPrincipalReturned = 0;
    $totalEarningsReturned = 0;

    $pdo->beginTransaction();

    foreach ($activeStakes as $stake) {
        $stakeId = (int)$stake['id'];
        $userId = (int)$stake['user_id'];

        // Valor principal
        $amountBrl = (float)($stake['amount_brl'] ?? 0);
        if ($amountBrl == 0) {
            $amountBrl = (float)($stake['amount'] ?? 0);
        }

        // Rendimentos já contabilizados
        $earnedBrl = (float)($stake['total_earned_brl'] ?? 0);
        if ($earnedBrl == 0) {
            $earnedBrl = (float)($stake['earnings'] ?? 0);
        }

        // Calcular rendimento pendente
        $startDate = $stake['start_date'] ?? $stake['created_at'];
        $lastUpdate = $stake['updated_at'] ?? $startDate;
        $lastUpdateTime = strtotime($lastUpdate);
        if (!$lastUpdateTime) $lastUpdateTime = strtotime($startDate);

        $daysSinceUpdate = max(0, ($now - $lastUpdateTime) / 86400);
        $pendingReward = $amountBrl * (pow(1 + $dailyRate, $daysSinceUpdate) - 1);

        $stakeEarnings = $earnedBrl + $pendingReward;
        $stakeTotal = $amountBrl + $stakeEarnings;

        // Marcar stake como completado
        $stmt = $pdo->prepare("
            UPDATE staking
            SET status = 'completed',
                end_date = NOW(),
                earnings = :earnings,
                total_earned_brl = :total_earned_brl,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':earnings' => round($stakeEarnings, 6),
            ':total_earned_brl' => round($stakeEarnings, 6),
            ':id' => $stakeId
        ]);

        // Creditar saldo do usuário
        $stmt = $pdo->prepare("
            UPDATE users
            SET balance_brl = balance_brl + :total,
                staked_balance_brl = GREATEST(0, staked_balance_brl - :principal),
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':total' => round($stakeTotal, 6),
            ':principal' => round($amountBrl, 6),
            ':id' => $userId
        ]);

        // Registrar transação
        $googleUid = $stake['google_uid'] ?? $stake['user_google_uid'];
        $description = sprintf(
            'Migração: Unstake automático R$ %s + rendimentos R$ %s (remoção do staking)',
            number_format($amountBrl, 6, ',', '.'),
            number_format($stakeEarnings, 6, ',', '.')
        );

        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                google_uid, type, amount, amount_brl,
                description, status, created_at
            ) VALUES (?, 'unstake', ?, ?, ?, 'completed', NOW())
        ");
        $stmt->execute([
            $googleUid,
            round($stakeTotal, 6),
            round($stakeTotal, 6),
            $description
        ]);

        $totalPrincipalReturned += $amountBrl;
        $totalEarningsReturned += $stakeEarnings;
        $usersProcessed++;

        echo sprintf(
            "  Stake #%d | User #%d | Principal: R$ %.6f | Rendimento: R$ %.6f | Total devolvido: R$ %.6f\n",
            $stakeId, $userId, $amountBrl, $stakeEarnings, $stakeTotal
        );
    }

    // Garantir que staked_balance_brl está zerado para TODOS
    $pdo->exec("UPDATE users SET staked_balance_brl = 0 WHERE staked_balance_brl > 0");

    $pdo->commit();

    echo sprintf(
        "\n=== MIGRAÇÃO CONCLUÍDA ===\n" .
        "Stakes processados: %d\n" .
        "Principal devolvido: R$ %.6f\n" .
        "Rendimentos devolvidos: R$ %.6f\n" .
        "Total devolvido: R$ %.6f\n",
        $usersProcessed,
        $totalPrincipalReturned,
        $totalEarningsReturned,
        $totalPrincipalReturned + $totalEarningsReturned
    );

    secureLog(sprintf(
        "MIGRATION_UNSTAKE_ALL | Stakes: %d | Principal: R$ %.6f | Earnings: R$ %.6f | Total: R$ %.6f",
        $usersProcessed, $totalPrincipalReturned, $totalEarningsReturned,
        $totalPrincipalReturned + $totalEarningsReturned
    ));

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
