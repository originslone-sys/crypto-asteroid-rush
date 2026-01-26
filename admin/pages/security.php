<?php
// ============================================
// CRYPTO ASTEROID RUSH - Painel de Segurança
// Arquivo: admin/pages/security.php
// v2.0 - Com alertas de earnings destacados
// ============================================

$pageTitle = 'Segurança';

// Processar ações
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'ban_wallet':
                $wallet = strtolower(trim($_POST['wallet']));
                $reason = $_POST['reason'] ?? 'Atividade suspeita';
                $pdo->prepare("UPDATE players SET is_banned = 1, ban_reason = ? WHERE wallet_address = ?")
                    ->execute([$reason, $wallet]);
                $message = "Wallet banida com sucesso!";
                break;
                
            case 'unban_wallet':
                $wallet = strtolower(trim($_POST['wallet']));
                $pdo->prepare("UPDATE players SET is_banned = 0, ban_reason = NULL WHERE wallet_address = ?")
                    ->execute([$wallet]);
                $message = "Wallet desbanida!";
                break;
                
            case 'blacklist_ip':
                $ip = $_POST['ip'];
                $reason = $_POST['reason'] ?? 'Manual block';
                $hours = !empty($_POST['hours']) ? (int)$_POST['hours'] : null;
                $expiresAt = $hours ? date('Y-m-d H:i:s', strtotime("+{$hours} hours")) : null;
                
                $pdo->prepare("INSERT INTO ip_blacklist (ip_address, reason, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reason = ?, expires_at = ?")
                    ->execute([$ip, $reason, $expiresAt, $reason, $expiresAt]);
                $message = "IP bloqueado!";
                break;
                
            case 'unblock_ip':
                $ip = $_POST['ip'];
                $pdo->prepare("DELETE FROM ip_blacklist WHERE ip_address = ?")->execute([$ip]);
                $message = "IP desbloqueado!";
                break;
                
            case 'clear_alerts':
                $wallet = strtolower(trim($_POST['wallet']));
                $pdo->prepare("DELETE FROM suspicious_activity WHERE wallet_address = ? AND activity_type LIKE 'HIGH_EARNINGS%'")->execute([$wallet]);
                $pdo->prepare("UPDATE players SET is_flagged = 0 WHERE wallet_address = ?")->execute([$wallet]);
                $message = "Alertas limpos para esta wallet!";
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$tab = $_GET['tab'] ?? 'overview';

try {
    $stats = [];
    $stats['flagged'] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE status = 'flagged'")->fetchColumn();
    $stats['banned'] = $pdo->query("SELECT COUNT(*) FROM players WHERE is_banned = 1")->fetchColumn();
    
    $tableExists = $pdo->query("SHOW TABLES LIKE 'ip_blacklist'")->fetch();
    $stats['blocked_ips'] = $tableExists ? $pdo->query("SELECT COUNT(*) FROM ip_blacklist WHERE expires_at IS NULL OR expires_at > NOW()")->fetchColumn() : 0;
    
    $tableExists = $pdo->query("SHOW TABLES LIKE 'suspicious_activity'")->fetch();
    $stats['suspicious_24h'] = $tableExists ? $pdo->query("SELECT COUNT(*) FROM suspicious_activity WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() : 0;
    
    // NOVO: Alertas de earnings
    $stats['earnings_alerts'] = $tableExists ? $pdo->query("SELECT COUNT(*) FROM suspicious_activity WHERE activity_type LIKE 'HIGH_EARNINGS%' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() : 0;
    
    // NOVO: Alertas críticos
    $stats['critical_alerts'] = $tableExists ? $pdo->query("SELECT COUNT(*) FROM suspicious_activity WHERE activity_type = 'HIGH_EARNINGS_CRITICAL' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() : 0;
    
    // Dados por tab
    if ($tab === 'flagged') {
        $flaggedSessions = $pdo->query("
            SELECT gs.*, p.is_banned, gs.alert_level 
            FROM game_sessions gs 
            LEFT JOIN players p ON gs.wallet_address = p.wallet_address 
            WHERE gs.status = 'flagged' 
            ORDER BY gs.created_at DESC 
            LIMIT 50
        ")->fetchAll();
    } elseif ($tab === 'banned') {
        $bannedWallets = $pdo->query("SELECT * FROM players WHERE is_banned = 1 ORDER BY updated_at DESC")->fetchAll();
    } elseif ($tab === 'blacklist') {
        $tableExists = $pdo->query("SHOW TABLES LIKE 'ip_blacklist'")->fetch();
        $blacklistedIPs = $tableExists ? $pdo->query("SELECT * FROM ip_blacklist WHERE expires_at IS NULL OR expires_at > NOW() ORDER BY created_at DESC")->fetchAll() : [];
    } elseif ($tab === 'suspicious') {
        $suspiciousActivity = $pdo->query("
            SELECT sa.*, p.is_banned 
            FROM suspicious_activity sa 
            LEFT JOIN players p ON sa.wallet_address = p.wallet_address 
            ORDER BY sa.created_at DESC 
            LIMIT 100
        ")->fetchAll();
    } elseif ($tab === 'earnings') {
        // NOVO: Tab específica para alertas de earnings
        $earningsAlerts = $pdo->query("
            SELECT 
                sa.*,
                p.is_banned,
                p.is_flagged,
                p.balance_usdt,
                (SELECT COUNT(*) FROM suspicious_activity sa2 
                 WHERE sa2.wallet_address = sa.wallet_address 
                 AND sa2.activity_type LIKE 'HIGH_EARNINGS%' 
                 AND sa2.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as alert_count_24h
            FROM suspicious_activity sa 
            LEFT JOIN players p ON sa.wallet_address = p.wallet_address 
            WHERE sa.activity_type LIKE 'HIGH_EARNINGS%'
            ORDER BY sa.created_at DESC 
            LIMIT 100
        ")->fetchAll();
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<style>
.alert-critical { background: linear-gradient(135deg, #dc3545 0%, #a71d2a 100%); color: white; }
.alert-suspect { background: linear-gradient(135deg, #fd7e14 0%, #dc6502 100%); color: white; }
.alert-warning { background: linear-gradient(135deg, #ffc107 0%, #d39e00 100%); color: #000; }
.badge-critical { background: #dc3545; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; }
.badge-suspect { background: #fd7e14; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; }
.badge-alert { background: #ffc107; color: #000; padding: 3px 8px; border-radius: 4px; font-size: 11px; }
.earnings-highlight { font-weight: bold; font-size: 14px; }
.earnings-critical { color: #dc3545; }
.earnings-suspect { color: #fd7e14; }
.earnings-alert { color: #ffc107; }
.alert-details { background: rgba(0,0,0,0.1); padding: 8px; border-radius: 4px; margin-top: 5px; font-size: 12px; }
.pulse { animation: pulse 2s infinite; }
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}
</style>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-shield-alt"></i> Painel de Segurança</h1>
        <p class="page-subtitle">Monitoramento anti-cheat, alertas de earnings e gestão de bans</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card <?php echo $stats['critical_alerts'] > 0 ? 'alert-critical pulse' : ''; ?>">
            <div class="icon danger"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="value"><?php echo $stats['critical_alerts']; ?></div>
            <div class="label">Alertas Críticos (24h)</div>
        </div>
        <div class="stat-card <?php echo $stats['earnings_alerts'] > 0 ? 'alert-suspect' : ''; ?>">
            <div class="icon warning"><i class="fas fa-dollar-sign"></i></div>
            <div class="value"><?php echo $stats['earnings_alerts']; ?></div>
            <div class="label">Alertas Earnings (24h)</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-flag"></i></div>
            <div class="value"><?php echo $stats['flagged']; ?></div>
            <div class="label">Sessões Flagged</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-ban"></i></div>
            <div class="value"><?php echo $stats['banned']; ?></div>
            <div class="label">Wallets Banidas</div>
        </div>
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-network-wired"></i></div>
            <div class="value"><?php echo $stats['blocked_ips']; ?></div>
            <div class="label">IPs Bloqueados</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-eye"></i></div>
            <div class="value"><?php echo $stats['suspicious_24h']; ?></div>
            <div class="label">Suspeitas (24h)</div>
        </div>
    </div>
    
    <!-- Tabs -->
    <div class="panel">
        <div class="panel-body">
            <div class="tabs">
                <a href="?page=security&tab=overview" class="tab <?php echo $tab === 'overview' ? 'active' : ''; ?>">Visão Geral</a>
                <a href="?page=security&tab=earnings" class="tab <?php echo $tab === 'earnings' ? 'active' : ''; ?>" style="<?php echo $stats['earnings_alerts'] > 0 ? 'background:#fd7e14;color:white;' : ''; ?>">
                    💰 Earnings (<?php echo $stats['earnings_alerts']; ?>)
                </a>
                <a href="?page=security&tab=flagged" class="tab <?php echo $tab === 'flagged' ? 'active' : ''; ?>">Flagged (<?php echo $stats['flagged']; ?>)</a>
                <a href="?page=security&tab=banned" class="tab <?php echo $tab === 'banned' ? 'active' : ''; ?>">Banidos</a>
                <a href="?page=security&tab=blacklist" class="tab <?php echo $tab === 'blacklist' ? 'active' : ''; ?>">IPs</a>
                <a href="?page=security&tab=suspicious" class="tab <?php echo $tab === 'suspicious' ? 'active' : ''; ?>">Todas Suspeitas</a>
            </div>
        </div>
    </div>
    
    <?php if ($tab === 'overview'): ?>
    <!-- Visão Geral -->
    <div class="panel">
        <div class="panel-header"><h3 class="panel-title">🎯 Limites de Alerta Configurados</h3></div>
        <div class="panel-body">
            <table style="width:100%;">
                <tr>
                    <td><span class="badge-alert">ALERTA</span></td>
                    <td>Earnings > <strong>$0.06</strong></td>
                    <td>Registra alerta, monitora</td>
                </tr>
                <tr>
                    <td><span class="badge-suspect">SUSPEITO</span></td>
                    <td>Earnings > <strong>$0.10</strong></td>
                    <td>Registra + flag no jogador</td>
                </tr>
                <tr>
                    <td><span class="badge-critical">CRÍTICO</span></td>
                    <td>Earnings > <strong>$0.20</strong></td>
                    <td>Bloqueia ganhos + flag sessão</td>
                </tr>
                <tr>
                    <td><span class="badge-critical">AUTO-BAN</span></td>
                    <td>5+ alertas em 24h</td>
                    <td>Ban automático da wallet</td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-header"><h3 class="panel-title">⚡ Ações Rápidas</h3></div>
        <div class="panel-body">
            <form method="POST" class="form-inline" style="margin-bottom: 15px;">
                <input type="hidden" name="action" value="ban_wallet">
                <input type="text" name="wallet" class="form-control" placeholder="Wallet 0x..." required pattern="^0x[a-fA-F0-9]{40}$" style="width:300px;">
                <input type="text" name="reason" class="form-control" placeholder="Motivo" required>
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i> Banir Wallet</button>
            </form>
            <form method="POST" class="form-inline">
                <input type="hidden" name="action" value="blacklist_ip">
                <input type="text" name="ip" class="form-control" placeholder="IP" required style="width:150px;">
                <input type="text" name="reason" class="form-control" placeholder="Motivo" required>
                <input type="number" name="hours" class="form-control" placeholder="Horas (vazio=permanente)" style="width:180px;">
                <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-lock"></i> Bloquear IP</button>
            </form>
        </div>
    </div>
    
    <?php elseif ($tab === 'earnings' && !empty($earningsAlerts)): ?>
    <!-- Alertas de Earnings -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title">💰 Alertas de Earnings Suspeitos</h3>
        </div>
        <div class="panel-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Wallet</th>
                            <th>Tipo</th>
                            <th>Earnings</th>
                            <th>Alertas 24h</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($earningsAlerts as $alert): 
                        $data = json_decode($alert['activity_data'], true);
                        $earnings = $data['client_earnings'] ?? 0;
                        $alertType = str_replace('HIGH_EARNINGS_', '', $alert['activity_type']);
                    ?>
                    <tr>
                        <td><?php echo date('d/m H:i', strtotime($alert['created_at'])); ?></td>
                        <td>
                            <span class="wallet-addr" title="<?php echo $alert['wallet_address']; ?>">
                                <?php echo substr($alert['wallet_address'], 0, 10); ?>...
                            </span>
                            <?php if ($alert['is_flagged']): ?>
                                <span class="badge badge-warning" title="Flagged">⚠️</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($alertType === 'CRITICAL'): ?>
                                <span class="badge-critical">CRÍTICO</span>
                            <?php elseif ($alertType === 'SUSPECT'): ?>
                                <span class="badge-suspect">SUSPEITO</span>
                            <?php else: ?>
                                <span class="badge-alert">ALERTA</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="earnings-highlight earnings-<?php echo strtolower($alertType); ?>">
                                $<?php echo number_format($earnings, 4); ?>
                            </span>
                            <?php if (isset($data['calculated_earnings'])): ?>
                                <br><small>Calc: $<?php echo number_format($data['calculated_earnings'], 4); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?php echo $alert['alert_count_24h'] >= 5 ? 'badge-critical' : ($alert['alert_count_24h'] >= 3 ? 'badge-suspect' : ''); ?>">
                                <?php echo $alert['alert_count_24h']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($alert['is_banned']): ?>
                                <span class="badge badge-danger">BANIDO</span>
                            <?php elseif ($alert['is_flagged']): ?>
                                <span class="badge badge-warning">FLAGGED</span>
                            <?php else: ?>
                                <span class="badge badge-success">ATIVO</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$alert['is_banned']): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="ban_wallet">
                                    <input type="hidden" name="wallet" value="<?php echo $alert['wallet_address']; ?>">
                                    <input type="hidden" name="reason" value="Earnings suspeitos: $<?php echo number_format($earnings, 4); ?>">
                                    <button class="btn btn-danger btn-sm" title="Banir"><i class="fas fa-ban"></i></button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="clear_alerts">
                                <input type="hidden" name="wallet" value="<?php echo $alert['wallet_address']; ?>">
                                <button class="btn btn-secondary btn-sm" title="Limpar alertas" onclick="return confirm('Limpar todos os alertas desta wallet?')"><i class="fas fa-eraser"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php if (isset($data['stats'])): ?>
                    <tr>
                        <td colspan="7">
                            <div class="alert-details">
                                📊 Stats: Common: <?php echo $data['stats']['common'] ?? 0; ?> | 
                                Rare: <?php echo $data['stats']['rare'] ?? 0; ?> | 
                                Epic: <?php echo $data['stats']['epic'] ?? 0; ?> | 
                                Legendary: <?php echo $data['stats']['legendary'] ?? 0; ?>
                                <?php if (isset($data['duration'])): ?>
                                    | ⏱️ Duração: <?php echo $data['duration']; ?>s
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($tab === 'flagged' && !empty($flaggedSessions)): ?>
    <!-- Sessões Flagged -->
    <div class="panel">
        <div class="panel-body">
            <div class="table-container">
                <table>
                    <thead><tr><th>ID</th><th>Wallet</th><th>Missão</th><th>Score</th><th>Earnings</th><th>Alerta</th><th>Data</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($flaggedSessions as $s): ?>
                    <tr>
                        <td>#<?php echo $s['id']; ?></td>
                        <td><span class="wallet-addr"><?php echo substr($s['wallet_address'],0,10); ?>...</span></td>
                        <td>#<?php echo $s['mission_number']; ?></td>
                        <td><?php echo $s['asteroids_destroyed']; ?></td>
                        <td>$<?php echo number_format($s['earnings_usdt'], 4); ?></td>
                        <td>
                            <?php if ($s['alert_level'] === 'critical'): ?>
                                <span class="badge-critical">CRÍTICO</span>
                            <?php elseif ($s['alert_level'] === 'suspect'): ?>
                                <span class="badge-suspect">SUSPEITO</span>
                            <?php elseif ($s['alert_level'] === 'alert'): ?>
                                <span class="badge-alert">ALERTA</span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                        <td>
                            <?php if (!$s['is_banned']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="ban_wallet">
                                <input type="hidden" name="wallet" value="<?php echo $s['wallet_address']; ?>">
                                <input type="hidden" name="reason" value="Sessão flagged #<?php echo $s['id']; ?>">
                                <button class="btn btn-danger btn-sm"><i class="fas fa-ban"></i></button>
                            </form>
                            <?php else: ?>
                                <span class="badge badge-danger">BANIDO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($tab === 'banned' && !empty($bannedWallets)): ?>
    <!-- Wallets Banidas -->
    <div class="panel">
        <div class="panel-body">
            <div class="table-container">
                <table>
                    <thead><tr><th>Wallet</th><th>Motivo</th><th>Saldo</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($bannedWallets as $w): ?>
                    <tr>
                        <td><span class="wallet-addr"><?php echo substr($w['wallet_address'],0,14); ?>...</span></td>
                        <td><?php echo htmlspecialchars($w['ban_reason'] ?? '-'); ?></td>
                        <td>$<?php echo number_format($w['balance_usdt'], 4); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="unban_wallet">
                                <input type="hidden" name="wallet" value="<?php echo $w['wallet_address']; ?>">
                                <button class="btn btn-success btn-sm"><i class="fas fa-unlock"></i> Desbanir</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($tab === 'blacklist' && !empty($blacklistedIPs)): ?>
    <!-- IPs Bloqueados -->
    <div class="panel">
        <div class="panel-body">
            <div class="table-container">
                <table>
                    <thead><tr><th>IP</th><th>Motivo</th><th>Expira</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($blacklistedIPs as $ip): ?>
                    <tr>
                        <td><code><?php echo $ip['ip_address']; ?></code></td>
                        <td><?php echo htmlspecialchars($ip['reason'] ?? '-'); ?></td>
                        <td><?php echo $ip['expires_at'] ? date('d/m H:i', strtotime($ip['expires_at'])) : 'Permanente'; ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="unblock_ip">
                                <input type="hidden" name="ip" value="<?php echo $ip['ip_address']; ?>">
                                <button class="btn btn-success btn-sm"><i class="fas fa-unlock"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($tab === 'suspicious' && !empty($suspiciousActivity)): ?>
    <!-- Todas Atividades Suspeitas -->
    <div class="panel">
        <div class="panel-body">
            <div class="table-container">
                <table>
                    <thead><tr><th>Wallet</th><th>Tipo</th><th>IP</th><th>DevTools</th><th>Data</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($suspiciousActivity as $sa): ?>
                    <tr>
                        <td><span class="wallet-addr"><?php echo substr($sa['wallet_address'],0,10); ?>...</span></td>
                        <td>
                            <?php if (strpos($sa['activity_type'], 'HIGH_EARNINGS') !== false): ?>
                                <span class="badge badge-warning"><?php echo $sa['activity_type']; ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary"><?php echo $sa['activity_type']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo $sa['ip_address'] ?? '-'; ?></code></td>
                        <td><?php echo $sa['devtools_detected'] ? '<span class="badge badge-danger">Sim</span>' : '-'; ?></td>
                        <td><?php echo date('d/m H:i', strtotime($sa['created_at'])); ?></td>
                        <td>
                            <?php if (!$sa['is_banned']): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="ban_wallet">
                                <input type="hidden" name="wallet" value="<?php echo $sa['wallet_address']; ?>">
                                <input type="hidden" name="reason" value="Atividade: <?php echo $sa['activity_type']; ?>">
                                <button class="btn btn-danger btn-sm"><i class="fas fa-ban"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <div class="panel">
        <div class="panel-body">
            <div class="empty-state">
                <i class="fas fa-check-circle" style="font-size:48px;color:#28a745;"></i>
                <h3>Tudo limpo!</h3>
                <p>Nenhum registro encontrado nesta categoria.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function banWallet(wallet) {
    const reason = prompt('Motivo do ban:');
    if (reason) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="ban_wallet">
            <input type="hidden" name="wallet" value="${wallet}">
            <input type="hidden" name="reason" value="${reason}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>