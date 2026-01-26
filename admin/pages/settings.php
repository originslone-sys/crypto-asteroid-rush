<?php
// ============================================
// CRYPTO ASTEROID RUSH - Configurações
// Arquivo: admin/pages/settings.php
// ============================================

$pageTitle = 'Configurações';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_settings':
                // Verificar se tabela settings existe
                $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    setting_key VARCHAR(100) UNIQUE,
                    setting_value TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                
                $settings = [
                    'stake_apy' => (float)$_POST['stake_apy'] / 100,
                    'min_stake_amount' => (float)$_POST['min_stake'],
                    'min_withdraw_amount' => (float)$_POST['min_withdraw'],
                    'entry_fee_bnb' => (float)$_POST['entry_fee'],
                ];
                
                foreach ($settings as $key => $value) {
                    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                        ->execute([$key, $value, $value]);
                }
                
                $message = "Configurações salvas!";
                break;
                
            case 'manual_transaction':
                $wallet = strtolower(trim($_POST['wallet']));
                $amount = (float)$_POST['amount'];
                $type = $_POST['type'];
                $description = $_POST['description'];
                
                // Verificar se jogador existe
                $stmt = $pdo->prepare("SELECT id FROM players WHERE wallet_address = ?");
                $stmt->execute([$wallet]);
                if (!$stmt->fetch()) {
                    $pdo->prepare("INSERT INTO players (wallet_address, balance_usdt, total_played) VALUES (?, 0, 0)")
                        ->execute([$wallet]);
                }
                
                if ($type === 'add') {
                    $pdo->prepare("UPDATE players SET balance_usdt = balance_usdt + ? WHERE wallet_address = ?")
                        ->execute([$amount, $wallet]);
                } else {
                    $pdo->prepare("UPDATE players SET balance_usdt = GREATEST(0, balance_usdt - ?) WHERE wallet_address = ?")
                        ->execute([$amount, $wallet]);
                }
                
                $pdo->prepare("INSERT INTO transactions (wallet_address, type, amount, description, status) VALUES (?, 'admin_adjust', ?, ?, 'completed')")
                    ->execute([$wallet, $type === 'add' ? $amount : -$amount, $description]);
                
                $message = "Transação executada! " . ($type === 'add' ? '+' : '-') . "$" . number_format($amount, 6);
                break;
                
            case 'run_maintenance':
                // Executar manutenção
                $deleted = 0;
                
                // Limpar rate_limits antigos
                $stmt = $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
                $deleted += $stmt;
                
                // Limpar sessões ativas abandonadas
                $stmt = $pdo->exec("UPDATE game_sessions SET status = 'expired' WHERE status = 'active' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                
                // Otimizar tabelas
                $pdo->exec("OPTIMIZE TABLE players, transactions, game_sessions, withdrawals");
                
                $message = "Manutenção executada! $deleted registros limpos.";
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Carregar configurações atuais
try {
    $settings = [];
    $result = $pdo->query("SELECT setting_key, setting_value FROM settings");
    if ($result) {
        while ($row = $result->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-cog"></i> Configurações do Sistema</h1>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
        <!-- Configurações Gerais -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-sliders-h"></i> Configurações Gerais</h3>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_settings">
                    
                    <div class="form-group">
                        <label class="form-label">APY do Staking (%)</label>
                        <input type="number" name="stake_apy" class="form-control" 
                               value="<?php echo isset($settings['stake_apy']) ? $settings['stake_apy'] * 100 : 12; ?>" 
                               step="0.1" min="0" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Stake Mínimo (USDT)</label>
                        <input type="number" name="min_stake" class="form-control" 
                               value="<?php echo $settings['min_stake_amount'] ?? 0.0001; ?>" 
                               step="0.0001" min="0.0001">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Saque Mínimo (USDT)</label>
                        <input type="number" name="min_withdraw" class="form-control" 
                               value="<?php echo $settings['min_withdraw_amount'] ?? 1; ?>" 
                               step="0.1" min="0.1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Taxa de Entrada (BNB)</label>
                        <input type="number" name="entry_fee" class="form-control" 
                               value="<?php echo $settings['entry_fee_bnb'] ?? 0.00001; ?>" 
                               step="0.00001" min="0">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar Configurações
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Transação Manual -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-hand-holding-usd"></i> Transação Manual</h3>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="action" value="manual_transaction">
                    
                    <div class="form-group">
                        <label class="form-label">Carteira (0x...)</label>
                        <input type="text" name="wallet" class="form-control" required
                               placeholder="0x..." pattern="^0x[a-fA-F0-9]{40}$">
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Tipo</label>
                            <select name="type" class="form-control" required>
                                <option value="add">Adicionar Saldo</option>
                                <option value="remove">Remover Saldo</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Valor (USDT)</label>
                            <input type="number" name="amount" class="form-control" required
                                   step="0.000001" min="0.000001">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Motivo</label>
                        <input type="text" name="description" class="form-control" required
                               placeholder="Ex: Correção, bônus, etc.">
                    </div>
                    
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Confirmar transação manual?')">
                        <i class="fas fa-check-circle"></i> Executar Transação
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Manutenção -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-tools"></i> Manutenção do Sistema</h3>
        </div>
        <div class="panel-body">
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="run_maintenance">
                    <button type="submit" class="btn btn-outline" onclick="return confirm('Executar manutenção? Isso limpará dados antigos.')">
                        <i class="fas fa-broom"></i> Executar Limpeza
                    </button>
                </form>
                
                <a href="../api/maintenance.php" target="_blank" class="btn btn-outline">
                    <i class="fas fa-external-link-alt"></i> Manutenção Completa
                </a>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                <h4 style="color: var(--primary); margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Informações do Sistema</h4>
                <p style="color: var(--text-dim); margin: 5px 0;">PHP: <?php echo phpversion(); ?></p>
                <p style="color: var(--text-dim); margin: 5px 0;">Servidor: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?></p>
                <p style="color: var(--text-dim); margin: 5px 0;">Banco: MySQL <?php echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); ?></p>
            </div>
        </div>
    </div>
</div>
