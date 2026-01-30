<?php
// ============================================
// UNOBIX - Configurações
// Arquivo: admin/pages/settings.php
// ATUALIZADO: BRL, sem BNB, Google Auth
// ============================================

$pageTitle = 'Configurações';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_settings':
                $settings = [
                    'stake_apy' => (float)$_POST['stake_apy'] / 100,
                    'min_stake_amount_brl' => (float)$_POST['min_stake'],
                    'min_withdraw_amount_brl' => (float)$_POST['min_withdraw'],
                    'referral_bonus_brl' => (float)$_POST['referral_bonus'],
                    'referral_missions_required' => (int)$_POST['referral_missions'],
                    'withdrawal_window_start' => (int)$_POST['withdrawal_start'],
                    'withdrawal_window_end' => (int)$_POST['withdrawal_end'],
                ];
                
                foreach ($settings as $key => $value) {
                    $pdo->prepare("INSERT INTO system_config (config_key, config_value, is_public, updated_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $value]);
                }
                
                $message = "Configurações salvas!";
                break;
                
            case 'update_game_values':
                $gameSettings = [
                    'asteroid_common_value_brl' => (float)$_POST['common_value'],
                    'asteroid_rare_value_brl' => (float)$_POST['rare_value'],
                    'asteroid_epic_value_brl' => (float)$_POST['epic_value'],
                    'asteroid_legendary_value_brl' => (float)$_POST['legendary_value'],
                    'hard_mode_chance' => (float)$_POST['hard_mode_chance'] / 100,
                    'max_daily_earnings_brl' => (float)$_POST['max_daily_earnings'],
                ];
                
                foreach ($gameSettings as $key => $value) {
                    $pdo->prepare("INSERT INTO system_config (config_key, config_value, is_public, updated_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $value]);
                }
                
                $message = "Valores do jogo atualizados!";
                break;
                
            case 'update_captcha':
                $captchaSettings = [
                    'hcaptcha_site_key' => $_POST['hcaptcha_site_key'],
                    'hcaptcha_secret_key' => $_POST['hcaptcha_secret_key'],
                    'captcha_enabled' => isset($_POST['captcha_enabled']) ? 'true' : 'false',
                ];
                
                foreach ($captchaSettings as $key => $value) {
                    $isPublic = $key === 'hcaptcha_site_key' || $key === 'captcha_enabled' ? 1 : 0;
                    $pdo->prepare("INSERT INTO system_config (config_key, config_value, is_public, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $isPublic, $value]);
                }
                
                $message = "Configurações de CAPTCHA salvas!";
                break;
                
            case 'manual_transaction':
                $googleUid = trim($_POST['google_uid']);
                $amount = (float)$_POST['amount'];
                $type = $_POST['type'];
                $description = $_POST['description'];
                
                $stmt = $pdo->prepare("SELECT id FROM players WHERE google_uid = ?");
                $stmt->execute([$googleUid]);
                if (!$stmt->fetch()) {
                    throw new Exception("Jogador não encontrado!");
                }
                
                if ($type === 'add') {
                    $pdo->prepare("UPDATE players SET balance_brl = balance_brl + ? WHERE google_uid = ?")
                        ->execute([$amount, $googleUid]);
                } else {
                    $pdo->prepare("UPDATE players SET balance_brl = GREATEST(0, balance_brl - ?) WHERE google_uid = ?")
                        ->execute([$amount, $googleUid]);
                }
                
                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status) VALUES (?, 'admin_adjust', ?, ?, 'completed')")
                    ->execute([$googleUid, $type === 'add' ? $amount : -$amount, $description]);
                
                $message = "Transação executada! " . ($type === 'add' ? '+' : '-') . "R$ " . number_format($amount, 2);
                break;
                
            case 'run_maintenance':
                $deleted = 0;
                $deleted += $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
                $pdo->exec("UPDATE game_sessions SET status = 'expired' WHERE status = 'active' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                $deleted += $pdo->exec("DELETE FROM ad_impressions WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
                $deleted += $pdo->exec("DELETE FROM ad_clicks WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
                
                $message = "Manutenção executada! $deleted registros limpos.";
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Carregar configurações
$settings = [];
try {
    $result = $pdo->query("SELECT config_key, config_value FROM system_config");
    while ($row = $result->fetch()) {
        $settings[$row['config_key']] = $row['config_value'];
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
    
    <?php if ($error): ?>
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
                               value="<?php echo isset($settings['stake_apy']) ? $settings['stake_apy'] * 100 : 5; ?>" 
                               step="0.1" min="0" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Stake Mínimo (R$)</label>
                        <input type="number" name="min_stake" class="form-control" 
                               value="<?php echo $settings['min_stake_amount_brl'] ?? 0.01; ?>" 
                               step="0.01" min="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Saque Mínimo (R$)</label>
                        <input type="number" name="min_withdraw" class="form-control" 
                               value="<?php echo $settings['min_withdraw_amount_brl'] ?? 1; ?>" 
                               step="0.1" min="0.1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Bônus Indicação (R$)</label>
                        <input type="number" name="referral_bonus" class="form-control" 
                               value="<?php echo $settings['referral_bonus_brl'] ?? 1; ?>" 
                               step="0.1" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Missões p/ Indicação</label>
                        <input type="number" name="referral_missions" class="form-control" 
                               value="<?php echo $settings['referral_missions_required'] ?? 100; ?>" 
                               min="1" max="1000">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Saque: Dia Início</label>
                            <input type="number" name="withdrawal_start" class="form-control" 
                                   value="<?php echo $settings['withdrawal_window_start'] ?? 20; ?>" min="1" max="28">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Saque: Dia Fim</label>
                            <input type="number" name="withdrawal_end" class="form-control" 
                                   value="<?php echo $settings['withdrawal_window_end'] ?? 25; ?>" min="1" max="31">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                </form>
            </div>
        </div>
        
        <!-- Valores do Jogo -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-meteor"></i> Valores do Jogo</h3>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_game_values">
                    
                    <div class="form-group">
                        <label class="form-label">Asteroide Comum (R$)</label>
                        <input type="number" name="common_value" class="form-control" 
                               value="<?php echo $settings['asteroid_common_value_brl'] ?? 0.0001; ?>" step="0.0001">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Asteroide Raro (R$)</label>
                        <input type="number" name="rare_value" class="form-control" 
                               value="<?php echo $settings['asteroid_rare_value_brl'] ?? 0.001; ?>" step="0.0001">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Asteroide Épico (R$)</label>
                        <input type="number" name="epic_value" class="form-control" 
                               value="<?php echo $settings['asteroid_epic_value_brl'] ?? 0.005; ?>" step="0.0001">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Asteroide Lendário (R$)</label>
                        <input type="number" name="legendary_value" class="form-control" 
                               value="<?php echo $settings['asteroid_legendary_value_brl'] ?? 0.01; ?>" step="0.0001">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Chance Hard Mode (%)</label>
                        <input type="number" name="hard_mode_chance" class="form-control" 
                               value="<?php echo isset($settings['hard_mode_chance']) ? $settings['hard_mode_chance'] * 100 : 40; ?>" min="0" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Máximo Diário (R$)</label>
                        <input type="number" name="max_daily_earnings" class="form-control" 
                               value="<?php echo $settings['max_daily_earnings_brl'] ?? 10; ?>" step="0.1">
                        <small style="color: var(--text-dim);">0 = sem limite</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                </form>
            </div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px;">
        <!-- CAPTCHA -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-shield-alt"></i> hCaptcha</h3>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_captcha">
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="captcha_enabled" <?php echo ($settings['captcha_enabled'] ?? 'true') === 'true' ? 'checked' : ''; ?> style="width: 20px; height: 20px;">
                            <span>CAPTCHA Habilitado</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Site Key</label>
                        <input type="text" name="hcaptcha_site_key" class="form-control" 
                               value="<?php echo htmlspecialchars($settings['hcaptcha_site_key'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Secret Key</label>
                        <input type="password" name="hcaptcha_secret_key" class="form-control" 
                               value="<?php echo htmlspecialchars($settings['hcaptcha_secret_key'] ?? ''); ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
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
                        <label class="form-label">Google UID</label>
                        <input type="text" name="google_uid" class="form-control" required placeholder="ID do jogador">
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label class="form-label">Tipo</label>
                            <select name="type" class="form-control">
                                <option value="add">Adicionar</option>
                                <option value="remove">Remover</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Valor (R$)</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Motivo</label>
                        <input type="text" name="description" class="form-control" required placeholder="Ex: Bônus, correção">
                    </div>
                    
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Confirmar?')">
                        <i class="fas fa-check"></i> Executar
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Manutenção -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-tools"></i> Manutenção</h3>
        </div>
        <div class="panel-body">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="run_maintenance">
                <button type="submit" class="btn btn-outline" onclick="return confirm('Executar manutenção?')">
                    <i class="fas fa-broom"></i> Executar Limpeza
                </button>
            </form>
            
            <div style="margin-top: 20px; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                <p style="color: var(--text-dim); margin: 5px 0;">PHP: <?php echo phpversion(); ?></p>
                <p style="color: var(--text-dim); margin: 5px 0;">MySQL: <?php echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); ?></p>
                <p style="color: var(--text-dim); margin: 5px 0;">Moeda: BRL | Auth: Google OAuth</p>
            </div>
        </div>
    </div>
</div>
