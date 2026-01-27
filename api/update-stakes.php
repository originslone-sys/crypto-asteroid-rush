<?php
// ============================================
// CRYPTO ASTEROID RUSH - Cron: Atualizar stakes
// Arquivo: api/update-stakes.php
// Opção A: manter lógica, só robustecer para Railway (timezone + lock + concorrência)
// ============================================

require_once __DIR__ . '/config.php';

// Timezone (para strtotime/date ficarem iguais ao cPanel)
date_default_timezone_set('America/Sao_Paulo');

$pdo = getDatabaseConnection();
if (!$pdo) {
    error_log("Falha na conexão com banco para update-stakes");
    exit(1);
}

try {
    // Opcional, mas recomendado: alinhar NOW() do MySQL com BRT
    // (evita drift se o DB estiver em UTC)
    try { $pdo->exec("SET time_zone = '-03:00'"); } catch (Exception $e) {}

    // ============================================
    // LOCK global: impede execução concorrente
    // ============================================
    $lockName = 'cron_update_stakes';
    $lockStmt = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 0) AS got_lock");
    $gotLock = (int)($lockStmt ? $lockStmt->fetchColumn() : 0);

    if ($gotLock !== 1) {
        // Outro cron ainda rodando
        error_log("update-stakes: execução ignorada (lock já em uso).");
        exit(0);
    }

    $updatedCount = 0;

    // Buscar IDs dos stakes ativos (evita carregar * tudo em memória)
    $stmt = $pdo->query("SELECT id FROM stakes WHERE status = 'active'");
    $stakeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($stakeIds as $id) {
        // Trava 1 stake por vez para cálculo consistente
        $pdo->beginTransaction();

        $s = $pdo->prepare("SELECT * FROM stakes WHERE id = ? AND status = 'active' FOR UPDATE");
        $s->execute([$id]);
        $stake = $s->fetch(PDO::FETCH_ASSOC);

        if (!$stake) {
            $pdo->rollBack();
            continue;
        }

        // updated_at pode vir inválido dependendo do histórico
        $lastUpdate = strtotime($stake['updated_at'] ?? '');

        if ($lastUpdate === false || empty($stake['updated_at']) || $stake['updated_at'] === '0000-00-00 00:00:00') {
            // Segurança: não gera juros infinito
            // Apenas normaliza updated_at para "agora"
            $u = $pdo->prepare("UPDATE stakes SET updated_at = NOW() WHERE id = ?");
            $u->execute([$id]);
            $pdo->commit();
            continue;
        }

        $now = time();
        $hoursPassed = ($now - $lastUpdate) / 3600;

        // Proteção extra contra valores absurdos (ex.: updated_at muito antigo/bugado)
        if ($hoursPassed < 1) {
            $pdo->rollBack();
            continue;
        }
        if ($hoursPassed > (365 * 24 * 5)) { // cap 5 anos, só por segurança
            $hoursPassed = (365 * 24 * 5);
        }

        // Converter de unidades (int) para USDT (float) para cálculo
        $amountUnits = (int)($stake['amount'] ?? 0);
        $totalEarnedUnits = (int)($stake['total_earned'] ?? 0);

        if ($amountUnits <= 0) {
            // Nada para render
            $u = $pdo->prepare("UPDATE stakes SET updated_at = NOW() WHERE id = ?");
            $u->execute([$id]);
            $pdo->commit();
            continue;
        }

        $amountUsdt = $amountUnits / 100000000;

        // Calcular ganhos em USDT (mesma lógica)
        $hourlyRate = STAKE_APY / (365 * 24);
        $earningsUsdt = $amountUsdt * $hourlyRate * $hoursPassed;

        // Converter ganhos para unidades
        $earningsUnits = (int)round($earningsUsdt * 100000000);

        if ($earningsUnits <= 0) {
            // Atualiza apenas o updated_at para não recalcular sempre
            $u = $pdo->prepare("UPDATE stakes SET updated_at = NOW() WHERE id = ?");
            $u->execute([$id]);
            $pdo->commit();
            continue;
        }

        // Atualizar stake com ganhos compostos (em unidades)
        $newAmountUnits = $amountUnits + $earningsUnits;
        $newTotalEarnedUnits = $totalEarnedUnits + $earningsUnits;

        $u = $pdo->prepare("
            UPDATE stakes
            SET amount = :amount,
                total_earned = :total_earned,
                updated_at = NOW()
            WHERE id = :id
        ");
        $u->execute([
            ':amount' => $newAmountUnits,
            ':total_earned' => $newTotalEarnedUnits,
            ':id' => $id
        ]);

        $pdo->commit();

        $updatedCount++;
        error_log("Stake {$id} atualizado: +$" . number_format($earningsUsdt, 8, '.', '') . " USDT ({$hoursPassed}h)");
    }

    // Libera o lock
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");

    echo "Stakes atualizados com sucesso! Atualizados: {$updatedCount}\n";

} catch (Exception $e) {
    // Se der erro no meio, tenta liberar lock e rollback se necessário
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote('cron_update_stakes') . ")");
    } catch (Exception $e2) {}

    error_log("Erro ao atualizar stakes: " . $e->getMessage());
    exit(1);
}
