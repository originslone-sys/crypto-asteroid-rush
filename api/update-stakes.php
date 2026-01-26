<?php
require_once 'config.php';

$pdo = getDatabaseConnection();
if (!$pdo) {
    error_log("Falha na conexão com banco para update-stakes");
    exit;
}

try {
    // Buscar todos os stakes ativos
    $stmt = $pdo->query("SELECT * FROM stakes WHERE status = 'active'");
    $stakes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stakes as $stake) {
        // Calcular horas desde última atualização
        $lastUpdate = strtotime($stake['updated_at']);
        $now = time();
        $hoursPassed = ($now - $lastUpdate) / 3600;
        
        if ($hoursPassed >= 1) {
            // Converter de unidades para USDT para cálculo
            $amountUsdt = $stake['amount'] / 100000000;
            
            // Calcular ganhos em USDT
            $hourlyRate = STAKE_APY / (365 * 24);
            $earningsUsdt = $amountUsdt * $hourlyRate * $hoursPassed;
            
            // Converter ganhos para unidades
            $earningsUnits = (int)round($earningsUsdt * 100000000);
            
            // Atualizar stake com ganhos compostos (em unidades)
            $newAmountUnits = $stake['amount'] + $earningsUnits;
            $newTotalEarnedUnits = $stake['total_earned'] + $earningsUnits;
            
            $stmt = $pdo->prepare("
                UPDATE stakes 
                SET amount = :amount, 
                    total_earned = :total_earned,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':amount' => $newAmountUnits,
                ':total_earned' => $newTotalEarnedUnits,
                ':id' => $stake['id']
            ]);
            
            // Log em USDT para facilitar leitura
            error_log("Stake {$stake['id']} atualizado: +$$earningsUsdt USDT");
        }
    }
    
    echo "Stakes atualizados com sucesso!";
    
} catch(PDOException $e) {
    error_log("Erro ao atualizar stakes: " . $e->getMessage());
}
?>