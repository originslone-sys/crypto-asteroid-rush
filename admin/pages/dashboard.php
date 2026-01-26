<?php
// ============================================
// CRYPTO ASTEROID RUSH - Dashboard
// Arquivo: admin/pages/dashboard.php
// ============================================

$pageTitle = 'Dashboard';

// Inicializar variáveis com valores padrão
$totalPlayers = 0;
$playersToday = 0;
$totalBalance = 0;
$totalGames = 0;
$gamesToday = 0;
$earningsToday = 0;
$pendingWithdrawals = 0;
$pendingAmount = 0;
$flaggedSessions = 0;
$bannedWallets = 0;
$recentActivities = [];
$error = null;

// Taxas BNB
$feesToday = 0;
$fees7Days = 0;
$fees30Days = 0;
$feesTotal = 0;

// Verificar se PDO existe
if (!isset($pdo)) {
    $error = "Variável PDO não está definida!";
} else {
    // Estatísticas gerais
    try {
        // Datas para consultas
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $monthAgo = date('Y-m-d', strtotime('-30 days'));
        
        // Total de jogadores
        $result = $pdo->query("SELECT COUNT(*) as c FROM players");
        $totalPlayers = $result ? $result->fetchColumn() : 0;
        
        // Jogadores hoje
        $result = $pdo->query("SELECT COUNT(*) as c FROM players WHERE DATE(created_at) = CURDATE()");
        $playersToday = $result ? $result->fetchColumn() : 0;
        
        // Total em saldos
        $result = $pdo->query("SELECT COALESCE(SUM(balance_usdt), 0) as s FROM players");
        $totalBalance = $result ? $result->fetchColumn() : 0;
        
        // Total de partidas
        $result = $pdo->query("SELECT COUNT(*) as c FROM game_sessions");
        $totalGames = $result ? $result->fetchColumn() : 0;
        
        // Partidas hoje
        $result = $pdo->query("SELECT COUNT(*) as c FROM game_sessions WHERE DATE(created_at) = CURDATE()");
        $gamesToday = $result ? $result->fetchColumn() : 0;
        
        // Ganhos distribuídos hoje
        $result = $pdo->query("SELECT COALESCE(SUM(earnings_usdt), 0) as s FROM game_sessions WHERE DATE(created_at) = CURDATE() AND status = 'completed'");
        $earningsToday = $result ? $result->fetchColumn() : 0;
        
        // Saques pendentes
        $result = $pdo->query("SELECT COUNT(*) as c FROM withdrawals WHERE status = 'pending'");
        $pendingWithdrawals = $result ? $result->fetchColumn() : 0;
        
        $result = $pdo->query("SELECT COALESCE(SUM(amount_usdt), 0) as s FROM withdrawals WHERE status = 'pending'");
        $pendingAmount = $result ? $result->fetchColumn() : 0;
        
        // Sessões flagged
        $result = $pdo->query("SELECT COUNT(*) as c FROM game_sessions WHERE status = 'flagged'");
        $flaggedSessions = $result ? $result->fetchColumn() : 0;
        
        // Wallets banidas
        $result = $pdo->query("SELECT COUNT(*) as c FROM players WHERE is_banned = 1");
        $bannedWallets = $result ? $result->fetchColumn() : 0;
        
        // ============================================
        // ESTATÍSTICAS DE TAXAS BNB
        // ============================================
        
        // Primeiro tenta buscar da tabela transactions (fee_bnb)
        $hasFeeColumn = false;
        try {
            $check = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'fee_bnb'");
            $hasFeeColumn = $check && $check->fetch();
        } catch (Exception $e) {}
        
        if ($hasFeeColumn) {
            // Taxas hoje
            $result = $pdo->query("SELECT COALESCE(SUM(fee_bnb), 0) as total FROM transactions WHERE DATE(created_at) = '$today' AND fee_bnb > 0");
            $feesToday = $result ? (float)$result->fetchColumn() : 0;
            
            // Taxas 7 dias
            $result = $pdo->query("SELECT COALESCE(SUM(fee_bnb), 0) as total FROM transactions WHERE created_at >= '$weekAgo' AND fee_bnb > 0");
            $fees7Days = $result ? (float)$result->fetchColumn() : 0;
            
            // Taxas 30 dias
            $result = $pdo->query("SELECT COALESCE(SUM(fee_bnb), 0) as total FROM transactions WHERE created_at >= '$monthAgo' AND fee_bnb > 0");
            $fees30Days = $result ? (float)$result->fetchColumn() : 0;
            
            // Taxas total
            $result = $pdo->query("SELECT COALESCE(SUM(fee_bnb), 0) as total FROM transactions WHERE fee_bnb > 0");
            $feesTotal = $result ? (float)$result->fetchColumn() : 0;
        }
        
        // Se não encontrou na transactions, busca de game_sessions (entry_fee_bnb)
        $hasEntryFeeColumn = false;
        try {
            $check = $pdo->query("SHOW COLUMNS FROM game_sessions LIKE 'entry_fee_bnb'");
            $hasEntryFeeColumn = $check && $check->fetch();
        } catch (Exception $e) {}
        
        if ($hasEntryFeeColumn) {
            if ($feesToday == 0) {
                $result = $pdo->query("SELECT COALESCE(SUM(entry_fee_bnb), 0) as total FROM game_sessions WHERE DATE(created_at) = '$today'");
                $feesToday = $result ? (float)$result->fetchColumn() : 0;
            }
            if ($fees7Days == 0) {
                $result = $pdo->query("SELECT COALESCE(SUM(entry_fee_bnb), 0) as total FROM game_sessions WHERE created_at >= '$weekAgo'");
                $fees7Days = $result ? (float)$result->fetchColumn() : 0;
            }
            if ($fees30Days == 0) {
                $result = $pdo->query("SELECT COALESCE(SUM(entry_fee_bnb), 0) as total FROM game_sessions WHERE created_at >= '$monthAgo'");
                $fees30Days = $result ? (float)$result->fetchColumn() : 0;
            }
            if ($feesTotal == 0) {
                $result = $pdo->query("SELECT COALESCE(SUM(entry_fee_bnb), 0) as total FROM game_sessions");
                $feesTotal = $result ? (float)$result->fetchColumn() : 0;
            }
        }
        
        // Últimas atividades
        $recentActivities = $pdo->query("
            SELECT * FROM (
                SELECT 'withdrawal' as type, wallet_address, amount_usdt as amount, status, created_at 
                FROM withdrawals ORDER BY created_at DESC LIMIT 5
            ) w
            UNION ALL
            SELECT * FROM (
                SELECT 'game' as type, wallet_address, earnings_usdt as amount, status, created_at 
                FROM game_sessions ORDER BY created_at DESC LIMIT 5
            ) g
            ORDER BY created_at DESC LIMIT 10
        ")->fetchAll();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
} // Fecha o else do if (!isset($pdo))
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-chart-line"></i> Dashboard</h1>
        <p class="page-subtitle">Visão geral do sistema</p>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> 
            <strong>Erro ao carregar dados:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <!-- Estatísticas principais -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo number_format($totalPlayers ?? 0); ?></div>
            <div class="label">Total de Jogadores</div>
            <div class="change positive">+<?php echo $playersToday ?? 0; ?> hoje</div>
        </div>
        
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-gamepad"></i></div>
            <div class="value"><?php echo number_format($totalGames ?? 0); ?></div>
            <div class="label">Partidas Jogadas</div>
            <div class="change positive">+<?php echo $gamesToday ?? 0; ?> hoje</div>
        </div>
        
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-dollar-sign"></i></div>
            <div class="value">$<?php echo number_format($totalBalance ?? 0, 2); ?></div>
            <div class="label">Total em Saldos</div>
            <div class="change">-$<?php echo number_format($earningsToday ?? 0, 4); ?> pago hoje</div>
        </div>
        
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-clock"></i></div>
            <div class="value"><?php echo $pendingWithdrawals ?? 0; ?></div>
            <div class="label">Saques Pendentes</div>
            <div class="change">$<?php echo number_format($pendingAmount ?? 0, 2); ?> total</div>
        </div>
    </div>
    
    <!-- Estatísticas de Taxas BNB -->
    <h3 style="color: var(--primary); margin: 30px 0 15px 0; font-family: 'Orbitron', sans-serif;">
        <i class="fas fa-coins"></i> Taxas BNB Coletadas
    </h3>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon success"><i class="fab fa-bitcoin"></i></div>
            <div class="value"><?php echo number_format($feesToday, 6); ?></div>
            <div class="label">BNB Hoje</div>
            <div class="change"><?php echo $gamesToday; ?> sessões</div>
        </div>
        
        <div class="stat-card">
            <div class="icon primary"><i class="fab fa-bitcoin"></i></div>
            <div class="value"><?php echo number_format($fees7Days, 6); ?></div>
            <div class="label">BNB 7 Dias</div>
        </div>
        
        <div class="stat-card">
            <div class="icon warning"><i class="fab fa-bitcoin"></i></div>
            <div class="value"><?php echo number_format($fees30Days, 6); ?></div>
            <div class="label">BNB 30 Dias</div>
        </div>
        
        <div class="stat-card">
            <div class="icon danger"><i class="fab fa-bitcoin"></i></div>
            <div class="value"><?php echo number_format($feesTotal, 6); ?></div>
            <div class="label">BNB Total</div>
            <div class="change"><?php echo number_format($totalGames); ?> sessões</div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-top: 30px;">
        <!-- Atividades recentes -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-history"></i> Atividades Recentes</h3>
            </div>
            <div class="panel-body">
                <?php if (empty($recentActivities)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>Nenhuma atividade</h3>
                        <p>As atividades aparecerão aqui</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Carteira</th>
                                    <th>Valor</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentActivities as $activity): ?>
                                <tr>
                                    <td>
                                        <?php if ($activity['type'] === 'withdrawal'): ?>
                                            <span class="badge badge-warning"><i class="fas fa-money-bill-wave"></i> Saque</span>
                                        <?php else: ?>
                                            <span class="badge badge-primary"><i class="fas fa-gamepad"></i> Jogo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="wallet-addr" onclick="copyToClipboard('<?php echo $activity['wallet_address']; ?>')">
                                            <?php echo substr($activity['wallet_address'], 0, 6) . '...' . substr($activity['wallet_address'], -4); ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--success);">$<?php echo number_format($activity['amount'], 6); ?></td>
                                    <td>
                                        <?php
                                        $status = $activity['status'];
                                        if (in_array($status, ['completed', 'approved'])) {
                                            $statusClass = 'success';
                                        } elseif (in_array($status, ['pending', 'active'])) {
                                            $statusClass = 'warning';
                                        } elseif (in_array($status, ['flagged', 'rejected'])) {
                                            $statusClass = 'danger';
                                        } else {
                                            $statusClass = 'primary';
                                        }
                                        ?>
                                        <span class="badge badge-<?php echo $statusClass; ?>"><?php echo ucfirst($activity['status']); ?></span>
                                    </td>
                                    <td><?php echo date('d/m H:i', strtotime($activity['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Alertas de segurança -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-shield-alt"></i> Alertas de Segurança</h3>
            </div>
            <div class="panel-body">
                <?php if ($flaggedSessions > 0): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong><?php echo $flaggedSessions; ?> sessões flagged</strong>
                            <p style="margin: 0; font-size: 0.9rem;">Possível tentativa de fraude</p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($bannedWallets > 0): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-ban"></i>
                        <div>
                            <strong><?php echo $bannedWallets; ?> wallets banidas</strong>
                            <p style="margin: 0; font-size: 0.9rem;">Contas bloqueadas</p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($pendingWithdrawals > 0): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong><?php echo $pendingWithdrawals; ?> saques pendentes</strong>
                            <p style="margin: 0; font-size: 0.9rem;">Aguardando aprovação</p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($flaggedSessions == 0 && $bannedWallets == 0 && $pendingWithdrawals == 0): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Tudo em ordem!</strong>
                            <p style="margin: 0; font-size: 0.9rem;">Nenhum alerta no momento</p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 20px;">
                    <a href="index.php?page=security" class="btn btn-outline" style="width: 100%;">
                        <i class="fas fa-shield-alt"></i> Ver Painel de Segurança
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
