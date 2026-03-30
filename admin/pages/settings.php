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
                    'min_withdraw_amount_brl' => (float)$_POST['min_withdraw'],
                    'referral_bonus_brl' => (float)$_POST['referral_bonus'],
                    'referral_missions_required' => (int)$_POST['referral_missions'],
                ];
                
                foreach ($settings as $key => $value) {
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
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
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
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
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $isPublic, $value]);
                }
                
                $message = "Configurações de CAPTCHA salvas!";
                break;
                
            case 'manual_transaction':
                $googleUid = trim($_POST['google_uid']);
                $amount = (float)$_POST['amount'];
                $type = $_POST['type'];
                $description = $_POST['description'];
                
                $stmt = $pdo->prepare("SELECT id FROM users WHERE google_uid = ?");
                $stmt->execute([$googleUid]);
                if (!$stmt->fetch()) {
                    throw new Exception("Jogador não encontrado!");
                }
                
                if ($type === 'add') {
                    $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE google_uid = ?")
                        ->execute([$amount, $googleUid]);
                } else {
                    $pdo->prepare("UPDATE users SET balance_brl = GREATEST(0, balance_brl - ?) WHERE google_uid = ?")
                        ->execute([$amount, $googleUid]);
                }
                
                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status) VALUES (?, 'admin_adjust', ?, ?, 'completed')")
                    ->execute([$googleUid, $type === 'add' ? $amount : -$amount, $description]);
                
                $message = "Transação executada! " . ($type === 'add' ? '+' : '-') . "R$ " . number_format($amount, 2);
                break;
                
            case 'update_game_modes':
                $modeSettings = [
                    'mode_normal_enabled' => isset($_POST['mode_normal_enabled']) ? 'true' : 'false',
                    'mode_normal_credits' => max(1, (int)($_POST['mode_normal_credits'] ?? 1)),
                    'mode_normal_speed' => (float)($_POST['mode_normal_speed'] ?? 1.3),
                    'mode_normal_max_asteroids' => max(5, (int)($_POST['mode_normal_max_asteroids'] ?? 14)),
                    'mode_normal_spawn_interval' => max(50, (int)($_POST['mode_normal_spawn_interval'] ?? 400)),
                    'mode_normal_spawn_common' => max(0, min(100, (float)($_POST['mode_normal_spawn_common'] ?? 90))),
                    'mode_normal_spawn_rare' => max(0, min(100, (float)($_POST['mode_normal_spawn_rare'] ?? 7))),
                    'mode_normal_spawn_epic' => max(0, min(100, (float)($_POST['mode_normal_spawn_epic'] ?? 2))),
                    'mode_normal_spawn_legendary' => max(0, min(100, (float)($_POST['mode_normal_spawn_legendary'] ?? 1))),
                    'mode_hard_enabled' => isset($_POST['mode_hard_enabled']) ? 'true' : 'false',
                    'mode_hard_credits' => max(1, (int)($_POST['mode_hard_credits'] ?? 2)),
                    'mode_hard_speed' => (float)($_POST['mode_hard_speed'] ?? 1.7),
                    'mode_hard_max_asteroids' => max(5, (int)($_POST['mode_hard_max_asteroids'] ?? 18)),
                    'mode_hard_spawn_interval' => max(50, (int)($_POST['mode_hard_spawn_interval'] ?? 200)),
                    'mode_hard_spawn_common' => max(0, min(100, (float)($_POST['mode_hard_spawn_common'] ?? 80))),
                    'mode_hard_spawn_rare' => max(0, min(100, (float)($_POST['mode_hard_spawn_rare'] ?? 15))),
                    'mode_hard_spawn_epic' => max(0, min(100, (float)($_POST['mode_hard_spawn_epic'] ?? 4))),
                    'mode_hard_spawn_legendary' => max(0, min(100, (float)($_POST['mode_hard_spawn_legendary'] ?? 1))),
                    'mode_extreme_enabled' => isset($_POST['mode_extreme_enabled']) ? 'true' : 'false',
                    'mode_extreme_credits' => max(1, (int)($_POST['mode_extreme_credits'] ?? 3)),
                    'mode_extreme_speed' => (float)($_POST['mode_extreme_speed'] ?? 2.4),
                    'mode_extreme_max_asteroids' => max(5, (int)($_POST['mode_extreme_max_asteroids'] ?? 22)),
                    'mode_extreme_spawn_interval' => max(50, (int)($_POST['mode_extreme_spawn_interval'] ?? 100)),
                    'mode_extreme_spawn_common' => max(0, min(100, (float)($_POST['mode_extreme_spawn_common'] ?? 70))),
                    'mode_extreme_spawn_rare' => max(0, min(100, (float)($_POST['mode_extreme_spawn_rare'] ?? 20))),
                    'mode_extreme_spawn_epic' => max(0, min(100, (float)($_POST['mode_extreme_spawn_epic'] ?? 8))),
                    'mode_extreme_spawn_legendary' => max(0, min(100, (float)($_POST['mode_extreme_spawn_legendary'] ?? 2))),
                    'mode_training_enabled' => isset($_POST['mode_training_enabled']) ? 'true' : 'false',
                ];

                foreach ($modeSettings as $key => $value) {
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $value]);
                }

                $message = "Configurações de modos de jogo salvas!";
                break;

            case 'run_maintenance':
                $deleted = 0;
                $deleted += $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
                $pdo->exec("UPDATE game_sessions SET status = 'expired' WHERE status = 'active' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                $deleted += $pdo->exec("DELETE FROM ad_impressions WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
                $deleted += $pdo->exec("DELETE FROM ad_clicks WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

                $message = "Manutenção executada! $deleted registros limpos.";
                break;

            case 'update_auto_withdraw':
                $awSettings = [
                    'auto_withdraw_enabled' => isset($_POST['aw_enabled']) ? 'true' : 'false',
                    'auto_withdraw_interval' => max(5, min(60, (int)($_POST['aw_interval'] ?? 10))),
                    'auto_withdraw_batch_size' => max(1, min(50, (int)($_POST['aw_batch_size'] ?? 5))),
                ];

                foreach ($awSettings as $key => $value) {
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $value]);
                }

                $message = "Configurações de saque automático salvas!";
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Carregar configurações
$settings = [];
try {
    $result = $pdo->query("SELECT setting_key, setting_value FROM game_settings");
    while ($row = $result->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
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
                        <label class="form-label">Saque Mínimo (R$)</label>
                        <input type="number" name="min_withdraw" class="form-control" 
                               value="<?php echo $settings['min_withdraw_amount_brl'] ?? 5; ?>" 
                               step="0.1" min="0.1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Bônus Indicação (R$)</label>
                        <input type="number" name="referral_bonus" class="form-control" 
                               value="<?php echo $settings['referral_bonus_brl'] ?? 5; ?>" 
                               step="0.1" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Missões p/ Indicação</label>
                        <input type="number" name="referral_missions" class="form-control" 
                               value="<?php echo $settings['referral_missions_required'] ?? 20; ?>" 
                               min="1" max="1000">
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
                               value="<?php echo $settings['asteroid_common_value_brl'] ?? 0; ?>" step="0.01">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Asteroide Raro (R$)</label>
                        <input type="number" name="rare_value" class="form-control"
                               value="<?php echo $settings['asteroid_rare_value_brl'] ?? 0.02; ?>" step="0.01">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Asteroide Épico (R$)</label>
                        <input type="number" name="epic_value" class="form-control"
                               value="<?php echo $settings['asteroid_epic_value_brl'] ?? 0.05; ?>" step="0.01">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Asteroide Lendário (R$)</label>
                        <input type="number" name="legendary_value" class="form-control"
                               value="<?php echo $settings['asteroid_legendary_value_brl'] ?? 0.20; ?>" step="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Chance Hard Mode (%)</label>
                        <input type="number" name="hard_mode_chance" class="form-control" 
                               value="<?php echo isset($settings['hard_mode_chance']) ? $settings['hard_mode_chance'] * 100 : 50; ?>" min="0" max="100">
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
    
    <!-- Saque Automático -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px;">
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-robot"></i> Saque Automático (PIX)</h3>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_auto_withdraw">

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="aw_enabled" <?php echo ($settings['auto_withdraw_enabled'] ?? 'false') === 'true' ? 'checked' : ''; ?> style="width: 20px; height: 20px;">
                            <span>Processamento Automático Ativado</span>
                        </label>
                        <small style="color: var(--text-dim); display: block; margin-top: 5px;">Quando ativado, saques pendentes são processados automaticamente via PIX</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Intervalo (minutos)</label>
                        <input type="number" name="aw_interval" class="form-control"
                               value="<?php echo $settings['auto_withdraw_interval'] ?? 10; ?>"
                               min="5" max="60" step="1">
                        <small style="color: var(--text-dim);">A cada quantos minutos o cron verifica saques pendentes (5-60 min)</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Saques por execução</label>
                        <input type="number" name="aw_batch_size" class="form-control"
                               value="<?php echo $settings['auto_withdraw_batch_size'] ?? 5; ?>"
                               min="1" max="50" step="1">
                        <small style="color: var(--text-dim);">Quantos saques processar por vez (1-50)</small>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                </form>

                <div style="margin-top: 20px; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 10px;">
                    <p style="color: var(--text-dim); font-size: 0.85rem; margin-bottom: 8px;"><strong style="color: var(--primary);">Como funciona:</strong></p>
                    <ul style="color: var(--text-dim); font-size: 0.8rem; padding-left: 18px; line-height: 1.8;">
                        <li>O cron processa saques pendentes automaticamente via PIX</li>
                        <li><span style="color: var(--success);">Chave válida</span>: saque enviado para ZettPay</li>
                        <li><span style="color: var(--danger);">Chave inválida</span>: saque cancelado e saldo devolvido</li>
                        <li><span style="color: var(--warning);">Falha temporária</span>: mantém pendente para próxima tentativa</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-info-circle"></i> Status do Saque Automático</h3>
            </div>
            <div class="panel-body">
                <?php
                $awEnabled = ($settings['auto_withdraw_enabled'] ?? 'false') === 'true';
                $awInterval = $settings['auto_withdraw_interval'] ?? 10;
                $awBatch = $settings['auto_withdraw_batch_size'] ?? 5;

                $pendingCount = 0;
                try {
                    $pendingCount = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
                } catch (Exception $e) {}

                $processingCount = 0;
                try {
                    $processingCount = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'processing'")->fetchColumn();
                } catch (Exception $e) {}

                $last24h = ['processed' => 0, 'rejected' => 0];
                try {
                    $stmt24 = $pdo->query("SELECT
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as processed,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                        FROM withdrawals WHERE processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) OR (status = 'rejected' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))");
                    $last24h = $stmt24->fetch() ?: $last24h;
                } catch (Exception $e) {}
                ?>

                <div style="display: grid; gap: 12px;">
                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--text-dim);">Status</span>
                        <?php if ($awEnabled): ?>
                            <span class="badge badge-success">Ativo</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Desativado</span>
                        <?php endif; ?>
                    </div>

                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Intervalo</span>
                        <span style="color: var(--text-light);">A cada <?php echo $awInterval; ?> min</span>
                    </div>

                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Batch</span>
                        <span style="color: var(--text-light);"><?php echo $awBatch; ?> por vez</span>
                    </div>

                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Saques Pendentes</span>
                        <span style="color: <?php echo $pendingCount > 0 ? 'var(--warning)' : 'var(--success)'; ?>; font-weight: 600;"><?php echo $pendingCount; ?></span>
                    </div>

                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Em Processamento</span>
                        <span style="color: var(--primary); font-weight: 600;"><?php echo $processingCount; ?></span>
                    </div>

                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Completados (24h)</span>
                        <span style="color: var(--success); font-weight: 600;"><?php echo (int)($last24h['processed'] ?? 0); ?></span>
                    </div>

                    <div style="padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Rejeitados (24h)</span>
                        <span style="color: var(--danger); font-weight: 600;"><?php echo (int)($last24h['rejected'] ?? 0); ?></span>
                    </div>
                </div>

                <button type="button" class="btn btn-warning btn-block" style="margin-top: 16px;" id="btnProcessNow" onclick="processWithdrawalsNow()">
                    <i class="fas fa-bolt"></i> Processar Agora
                </button>
                <div id="processResult" style="margin-top: 12px; display: none; padding: 12px; border-radius: 8px; background: rgba(0,0,0,0.3); font-size: 0.85rem;"></div>

                <script>
                async function processWithdrawalsNow() {
                    var btn = document.getElementById('btnProcessNow');
                    var resultDiv = document.getElementById('processResult');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
                    resultDiv.style.display = 'none';

                    try {
                        var res = await fetch('../api/auto-withdraw.php?force=1', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ force: true }),
                            credentials: 'same-origin'
                        });
                        var data = await res.json();

                        resultDiv.style.display = 'block';
                        if (data.success && data.results) {
                            var r = data.results;
                            var html = '<div style="color: var(--success); margin-bottom: 8px;"><strong>Concluído!</strong></div>';
                            html += '<div>Encontrados: <strong>' + r.total_found + '</strong></div>';
                            html += '<div style="color: var(--success);">Processados: <strong>' + r.processed + '</strong></div>';
                            if (r.failed_invalid_key > 0) html += '<div style="color: var(--danger);">Chave inválida: <strong>' + r.failed_invalid_key + '</strong> (saldo devolvido)</div>';
                            if (r.failed_api > 0) html += '<div style="color: var(--warning);">Falha temporária: <strong>' + r.failed_api + '</strong> (tentará novamente)</div>';
                            if (r.skipped > 0) html += '<div>Pulados: <strong>' + r.skipped + '</strong></div>';
                            if (r.errors && r.errors.length > 0) {
                                html += '<div style="margin-top: 8px; color: var(--text-dim); font-size: 0.8rem;">';
                                r.errors.forEach(function(e) { html += '<div>' + e + '</div>'; });
                                html += '</div>';
                            }
                            resultDiv.innerHTML = html;
                        } else {
                            resultDiv.innerHTML = '<div style="color: var(--warning);">' + (data.message || 'Nenhum saque processado') + '</div>';
                        }
                    } catch (e) {
                        resultDiv.style.display = 'block';
                        resultDiv.innerHTML = '<div style="color: var(--danger);">Erro: ' + e.message + '</div>';
                    }

                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-bolt"></i> Processar Agora';
                }
                </script>
            </div>
        </div>
    </div>

    <!-- Modos de Jogo v2 -->
    <div style="display: grid; grid-template-columns: 1fr; gap: 30px; margin-top: 30px;">
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-layer-group"></i> Modos de Jogo (v2)</h3>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_game_modes">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                        <!-- Normal -->
                        <div style="padding: 16px; border: 2px solid rgba(46,204,113,0.3); border-radius: 12px; background: rgba(46,204,113,0.05);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="mode_normal_enabled" <?php echo ($settings['mode_normal_enabled'] ?? 'true') === 'true' ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                                    <span style="color: #2ecc71; font-weight: 700; font-size: 1rem;">NORMAL</span>
                                </label>
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Créditos</label>
                                <input type="number" name="mode_normal_credits" class="form-control" value="<?php echo $settings['mode_normal_credits'] ?? 1; ?>" min="1" max="10">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Velocidade (x)</label>
                                <input type="number" name="mode_normal_speed" class="form-control" value="<?php echo $settings['mode_normal_speed'] ?? 1.3; ?>" step="0.1" min="0.5" max="5.0">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Max Asteroides</label>
                                <input type="number" name="mode_normal_max_asteroids" class="form-control" value="<?php echo $settings['mode_normal_max_asteroids'] ?? 14; ?>" min="5" max="200">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Spawn Interval (ms)</label>
                                <input type="number" name="mode_normal_spawn_interval" class="form-control" value="<?php echo $settings['mode_normal_spawn_interval'] ?? 400; ?>" min="50" max="2000" step="50">
                            </div>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(46,204,113,0.2);">
                                <label class="form-label" style="font-size: 0.8rem; color: #2ecc71;">Spawn Rates (%)</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px;">
                                    <div>
                                        <label style="font-size: 0.7rem; color: #888;">Common %</label>
                                        <input type="number" name="mode_normal_spawn_common" class="form-control" value="<?php echo $settings['mode_normal_spawn_common'] ?? 90; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #3498db;">Rare %</label>
                                        <input type="number" name="mode_normal_spawn_rare" class="form-control" value="<?php echo $settings['mode_normal_spawn_rare'] ?? 7; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #9b59b6;">Epic %</label>
                                        <input type="number" name="mode_normal_spawn_epic" class="form-control" value="<?php echo $settings['mode_normal_spawn_epic'] ?? 2; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #f1c40f;">Legendary %</label>
                                        <input type="number" name="mode_normal_spawn_legendary" class="form-control" value="<?php echo $settings['mode_normal_spawn_legendary'] ?? 1; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Hard -->
                        <div style="padding: 16px; border: 2px solid rgba(243,156,18,0.3); border-radius: 12px; background: rgba(243,156,18,0.05);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="mode_hard_enabled" <?php echo ($settings['mode_hard_enabled'] ?? 'true') === 'true' ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                                    <span style="color: #f39c12; font-weight: 700; font-size: 1rem;">DIFÍCIL</span>
                                </label>
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Créditos</label>
                                <input type="number" name="mode_hard_credits" class="form-control" value="<?php echo $settings['mode_hard_credits'] ?? 2; ?>" min="1" max="10">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Velocidade (x)</label>
                                <input type="number" name="mode_hard_speed" class="form-control" value="<?php echo $settings['mode_hard_speed'] ?? 1.7; ?>" step="0.1" min="0.5" max="5.0">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Max Asteroides</label>
                                <input type="number" name="mode_hard_max_asteroids" class="form-control" value="<?php echo $settings['mode_hard_max_asteroids'] ?? 18; ?>" min="5" max="200">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Spawn Interval (ms)</label>
                                <input type="number" name="mode_hard_spawn_interval" class="form-control" value="<?php echo $settings['mode_hard_spawn_interval'] ?? 200; ?>" min="50" max="2000" step="50">
                            </div>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(243,156,18,0.2);">
                                <label class="form-label" style="font-size: 0.8rem; color: #f39c12;">Spawn Rates (%)</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px;">
                                    <div>
                                        <label style="font-size: 0.7rem; color: #888;">Common %</label>
                                        <input type="number" name="mode_hard_spawn_common" class="form-control" value="<?php echo $settings['mode_hard_spawn_common'] ?? 80; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #3498db;">Rare %</label>
                                        <input type="number" name="mode_hard_spawn_rare" class="form-control" value="<?php echo $settings['mode_hard_spawn_rare'] ?? 15; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #9b59b6;">Epic %</label>
                                        <input type="number" name="mode_hard_spawn_epic" class="form-control" value="<?php echo $settings['mode_hard_spawn_epic'] ?? 4; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #f1c40f;">Legendary %</label>
                                        <input type="number" name="mode_hard_spawn_legendary" class="form-control" value="<?php echo $settings['mode_hard_spawn_legendary'] ?? 1; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Extreme -->
                        <div style="padding: 16px; border: 2px solid rgba(231,76,60,0.3); border-radius: 12px; background: rgba(231,76,60,0.05);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="mode_extreme_enabled" <?php echo ($settings['mode_extreme_enabled'] ?? 'true') === 'true' ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                                    <span style="color: #e74c3c; font-weight: 700; font-size: 1rem;">EXTREME</span>
                                </label>
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Créditos</label>
                                <input type="number" name="mode_extreme_credits" class="form-control" value="<?php echo $settings['mode_extreme_credits'] ?? 3; ?>" min="1" max="10">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Velocidade (x)</label>
                                <input type="number" name="mode_extreme_speed" class="form-control" value="<?php echo $settings['mode_extreme_speed'] ?? 2.4; ?>" step="0.1" min="0.5" max="5.0">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Max Asteroides</label>
                                <input type="number" name="mode_extreme_max_asteroids" class="form-control" value="<?php echo $settings['mode_extreme_max_asteroids'] ?? 22; ?>" min="5" max="200">
                            </div>
                            <div class="form-group" style="margin-bottom: 8px;">
                                <label class="form-label" style="font-size: 0.8rem;">Spawn Interval (ms)</label>
                                <input type="number" name="mode_extreme_spawn_interval" class="form-control" value="<?php echo $settings['mode_extreme_spawn_interval'] ?? 100; ?>" min="50" max="2000" step="50">
                            </div>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(231,76,60,0.2);">
                                <label class="form-label" style="font-size: 0.8rem; color: #e74c3c;">Spawn Rates (%)</label>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px;">
                                    <div>
                                        <label style="font-size: 0.7rem; color: #888;">Common %</label>
                                        <input type="number" name="mode_extreme_spawn_common" class="form-control" value="<?php echo $settings['mode_extreme_spawn_common'] ?? 70; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #3498db;">Rare %</label>
                                        <input type="number" name="mode_extreme_spawn_rare" class="form-control" value="<?php echo $settings['mode_extreme_spawn_rare'] ?? 20; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #9b59b6;">Epic %</label>
                                        <input type="number" name="mode_extreme_spawn_epic" class="form-control" value="<?php echo $settings['mode_extreme_spawn_epic'] ?? 8; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.7rem; color: #f1c40f;">Legendary %</label>
                                        <input type="number" name="mode_extreme_spawn_legendary" class="form-control" value="<?php echo $settings['mode_extreme_spawn_legendary'] ?? 2; ?>" min="0" max="100" step="0.1" style="font-size: 0.85rem;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Training -->
                        <div style="padding: 16px; border: 2px dashed rgba(149,165,166,0.3); border-radius: 12px; background: rgba(149,165,166,0.03);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="mode_training_enabled" <?php echo ($settings['mode_training_enabled'] ?? 'true') === 'true' ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                                    <span style="color: #95a5a6; font-weight: 700; font-size: 1rem;">TREINO</span>
                                </label>
                            </div>
                            <p style="color: var(--text-dim); font-size: 0.8rem; margin: 0;">
                                Sem custo de créditos.<br>
                                Sem ganhos reais.<br>
                                Não salva no banco de dados.<br>
                                Apenas prática.
                            </p>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 16px;"><i class="fas fa-save"></i> Salvar Modos</button>
                </form>

                <div style="margin-top: 16px; padding: 12px; background: rgba(0,0,0,0.2); border-radius: 8px;">
                    <p style="color: var(--text-dim); font-size: 0.8rem; margin: 0;">
                        <strong style="color: var(--primary);">Nota:</strong> Estas configurações controlam os modos do game-v2.html.
                        O game.html original (legado) continua funcionando com o sistema antigo.
                    </p>
                </div>
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
