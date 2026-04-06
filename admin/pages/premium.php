<?php
// ============================================
// UNOBIX - Admin Premium Management
// Arquivo: admin/pages/premium.php
// v1.1 - Dashboard de assinaturas Premium
// ============================================

$pageTitle = 'Premium';
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'update_premium_settings':
                $premSettings = [
                    'premium_price_brl' => max(1, min(999, (float)($_POST['premium_price'] ?? 19.90))),
                    'premium_duration_days' => max(1, min(365, (int)($_POST['premium_duration'] ?? 30))),
                    'premium_enabled' => isset($_POST['premium_enabled']) ? 'true' : 'false',
                ];

                foreach ($premSettings as $key => $value) {
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $value]);
                }

                $message = "Configurações Premium salvas!";
                break;

            case 'revoke_premium':
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId > 0) {
                    $pdo->prepare("UPDATE users SET is_premium = 0, premium_expires_at = NULL WHERE id = ?")
                        ->execute([$userId]);
                    $pdo->prepare("UPDATE premium_subscriptions SET status = 'revoked' WHERE user_id = ? AND status = 'active'")
                        ->execute([$userId]);
                    $message = "Premium revogado para o usuário #{$userId}";
                }
                break;

            case 'activate_premium':
                $subId = (int)($_POST['subscription_id'] ?? 0);
                if ($subId > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM premium_subscriptions WHERE id = ? LIMIT 1");
                    $stmt->execute([$subId]);
                    $sub = $stmt->fetch();

                    if ($sub && $sub['status'] === 'pending') {
                        $durationDays = (int)($sub['duration_days'] ?: 30);
                        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));

                        $pdo->prepare("UPDATE users SET is_premium = 1, premium_expires_at = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$expiresAt, $sub['user_id']]);

                        $pdo->prepare("UPDATE premium_subscriptions SET status = 'active', activated_at = NOW(), expires_at = ? WHERE id = ?")
                            ->execute([$expiresAt, $subId]);

                        $message = "Assinatura #{$subId} ativada manualmente! Expira em: {$expiresAt}";
                    } else {
                        $error = "Assinatura não encontrada ou não está pendente.";
                    }
                }
                break;

            case 'extend_premium':
                $userId = (int)($_POST['user_id'] ?? 0);
                $days = max(1, (int)($_POST['extend_days'] ?? 30));
                if ($userId > 0) {
                    $stmt = $pdo->prepare("SELECT premium_expires_at FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();

                    $baseDate = (!empty($user['premium_expires_at']) && strtotime($user['premium_expires_at']) > time())
                        ? $user['premium_expires_at']
                        : date('Y-m-d H:i:s');

                    $newExpiry = date('Y-m-d H:i:s', strtotime($baseDate . " + {$days} days"));

                    $pdo->prepare("UPDATE users SET is_premium = 1, premium_expires_at = ? WHERE id = ?")
                        ->execute([$newExpiry, $userId]);

                    $message = "Premium estendido por {$days} dias para usuário #{$userId}. Expira em: {$newExpiry}";
                }
                break;
        }
    } catch (Exception $e) {
        $error = "Erro: " . $e->getMessage();
    }
}

// Load settings
$settings = [];
try {
    $result = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key LIKE 'premium_%'");
    while ($row = $result->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$premiumPrice = (float)($settings['premium_price_brl'] ?? 19.90);
$premiumDuration = (int)($settings['premium_duration_days'] ?? 30);
$premiumEnabled = ($settings['premium_enabled'] ?? 'true') === 'true';

// Stats
$totalPremium = 0;
$activePremium = 0;
$totalRevenue = 0;
$recentSubscriptions = [];
$premiumSearch = trim($_GET['search'] ?? '');

try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'premium_subscriptions'")->fetch();

    if ($tableCheck) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM premium_subscriptions WHERE status IN ('active', 'expired')");
        $totalPremium = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM premium_subscriptions WHERE status = 'active'");
        $activePremium = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(price_brl), 0) FROM premium_subscriptions WHERE status IN ('active', 'expired')");
        $totalRevenue = (float)$stmt->fetchColumn();

        if ($premiumSearch) {
            $stmt = $pdo->prepare("
                SELECT ps.*, u.display_name, u.email, u.is_premium, u.premium_expires_at
                FROM premium_subscriptions ps
                LEFT JOIN users u ON ps.user_id = u.id
                WHERE u.email LIKE ? OR u.display_name LIKE ?
                ORDER BY ps.created_at DESC
                LIMIT 50
            ");
            $stmt->execute(["%{$premiumSearch}%", "%{$premiumSearch}%"]);
        } else {
            $stmt = $pdo->query("
                SELECT ps.*, u.display_name, u.email, u.is_premium, u.premium_expires_at
                FROM premium_subscriptions ps
                LEFT JOIN users u ON ps.user_id = u.id
                ORDER BY ps.created_at DESC
                LIMIT 50
            ");
        }
        $recentSubscriptions = $stmt->fetchAll();
    }

    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_premium'")->fetch();
    if ($cols) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_premium = 1 AND premium_expires_at > NOW()");
        $activePremium = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {}
?>

<div class="main-content">

<?php if ($message): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="page-header">
    <h1 class="page-title"><i class="fas fa-crown" style="color: #ffd700;"></i> Premium</h1>
    <p class="page-subtitle">Gerenciamento de assinaturas Premium</p>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="icon warning"><i class="fas fa-crown"></i></div>
        <div class="value"><?php echo $activePremium; ?></div>
        <div class="label">Premium Ativos</div>
    </div>
    <div class="stat-card">
        <div class="icon primary"><i class="fas fa-receipt"></i></div>
        <div class="value"><?php echo $totalPremium; ?></div>
        <div class="label">Total Assinaturas</div>
    </div>
    <div class="stat-card">
        <div class="icon success"><i class="fas fa-dollar-sign"></i></div>
        <div class="value">R$ <?php echo number_format($totalRevenue, 2, ',', '.'); ?></div>
        <div class="label">Receita Premium</div>
    </div>
    <div class="stat-card">
        <div class="icon warning"><i class="fas fa-tag"></i></div>
        <div class="value">R$ <?php echo number_format($premiumPrice, 2, ',', '.'); ?></div>
        <div class="label">Preco Atual</div>
    </div>
</div>

<!-- Settings -->
<div class="panel">
    <div class="panel-header">
        <h3 class="panel-title"><i class="fas fa-cog"></i> Configuracoes Premium</h3>
    </div>
    <div class="panel-body">
        <form method="POST">
            <input type="hidden" name="action" value="update_premium_settings">

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div style="margin-top: 8px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="premium_enabled" <?php echo $premiumEnabled ? 'checked' : ''; ?>>
                            <span>Premium Habilitado</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Preco (R$)</label>
                    <input type="number" name="premium_price" value="<?php echo $premiumPrice; ?>" step="0.01" min="1" max="999" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Duracao (dias)</label>
                    <input type="number" name="premium_duration" value="<?php echo $premiumDuration; ?>" min="1" max="365" class="form-control">
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
        </form>
    </div>
</div>

<!-- Manual Premium Management -->
<div class="panel">
    <div class="panel-header">
        <h3 class="panel-title"><i class="fas fa-user-cog"></i> Gerenciamento Manual</h3>
    </div>
    <div class="panel-body">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <form method="POST" style="padding: 20px; background: rgba(0,0,0,0.2); border-radius: 12px; border: 1px solid var(--border-color);">
                <input type="hidden" name="action" value="extend_premium">
                <h4 style="margin-bottom: 15px; color: var(--success); font-family: 'Orbitron', sans-serif; font-size: 0.95rem;"><i class="fas fa-plus-circle"></i> Estender Premium</h4>
                <div class="form-group">
                    <label class="form-label">ID do Usuario</label>
                    <input type="number" name="user_id" required class="form-control" placeholder="Ex: 123">
                </div>
                <div class="form-group">
                    <label class="form-label">Dias para Estender</label>
                    <input type="number" name="extend_days" value="30" min="1" max="365" class="form-control">
                </div>
                <button type="submit" class="btn btn-success"><i class="fas fa-crown"></i> Estender</button>
            </form>

            <form method="POST" style="padding: 20px; background: rgba(0,0,0,0.2); border-radius: 12px; border: 1px solid var(--border-color);" onsubmit="return confirm('Tem certeza que deseja revogar o Premium?')">
                <input type="hidden" name="action" value="revoke_premium">
                <h4 style="margin-bottom: 15px; color: var(--danger); font-family: 'Orbitron', sans-serif; font-size: 0.95rem;"><i class="fas fa-ban"></i> Revogar Premium</h4>
                <div class="form-group">
                    <label class="form-label">ID do Usuario</label>
                    <input type="number" name="user_id" required class="form-control" placeholder="Ex: 123">
                </div>
                <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Revogar</button>
            </form>
        </div>
    </div>
</div>

<!-- Recent Subscriptions -->
<div class="panel">
    <div class="panel-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <h3 class="panel-title" style="margin:0;"><i class="fas fa-list"></i> Assinaturas <?php echo $premiumSearch ? '- Resultado para "' . htmlspecialchars($premiumSearch) . '"' : 'Recentes'; ?></h3>
        <form method="GET" style="display:flex;gap:6px;align-items:center;">
            <input type="hidden" name="page" value="premium">
            <input type="text" name="search" value="<?php echo htmlspecialchars($premiumSearch); ?>" placeholder="Buscar por email ou nome..." class="form-control" style="width:250px;padding:6px 12px;font-size:0.82rem;">
            <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:0.82rem;"><i class="fas fa-search"></i></button>
            <?php if ($premiumSearch): ?>
                <a href="?page=premium" class="btn" style="padding:6px 12px;font-size:0.82rem;background:rgba(255,255,255,0.06);"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="panel-body">
        <?php if (count($recentSubscriptions) > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario</th>
                            <th>Valor</th>
                            <th>Duracao</th>
                            <th>Status</th>
                            <th>Ativado em</th>
                            <th>Expira em</th>
                            <th>Criado em</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSubscriptions as $sub): ?>
                            <tr>
                                <td>#<?php echo $sub['id']; ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($sub['display_name'] ?? 'N/A'); ?></div>
                                    <div style="font-size:0.72rem;color:var(--text-dim);"><?php echo htmlspecialchars($sub['email'] ?? ''); ?></div>
                                </td>
                                <td style="font-weight: 600; color: var(--success);">R$ <?php echo number_format($sub['price_brl'], 2, ',', '.'); ?></td>
                                <td><?php echo $sub['duration_days']; ?> dias</td>
                                <td>
                                    <?php
                                    $statusClass = match($sub['status']) {
                                        'active' => 'badge-success',
                                        'pending' => 'badge-warning',
                                        'expired' => 'badge-info',
                                        'revoked' => 'badge-danger',
                                        default => ''
                                    };
                                    $statusText = match($sub['status']) {
                                        'active' => 'Ativo',
                                        'pending' => 'Pendente',
                                        'expired' => 'Expirado',
                                        'revoked' => 'Revogado',
                                        default => $sub['status']
                                    };
                                    ?>
                                    <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                </td>
                                <td><?php echo $sub['activated_at'] ? date('d/m/Y H:i', strtotime($sub['activated_at'])) : '-'; ?></td>
                                <td><?php echo $sub['expires_at'] ? date('d/m/Y H:i', strtotime($sub['expires_at'])) : '-'; ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($sub['created_at'])); ?></td>
                                <td>
                                    <?php if ($sub['status'] === 'pending'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Ativar premium para <?php echo htmlspecialchars($sub['display_name'] ?? 'N/A'); ?>?')">
                                            <input type="hidden" name="action" value="activate_premium">
                                            <input type="hidden" name="subscription_id" value="<?php echo $sub['id']; ?>">
                                            <button type="submit" class="btn btn-success" style="padding: 4px 12px; font-size: 0.8rem;"><i class="fas fa-check"></i> Ativar</button>
                                        </form>
                                    <?php elseif ($sub['status'] === 'active'): ?>
                                        <span style="color: var(--success); font-size: 0.85rem;"><i class="fas fa-check-circle"></i></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-crown"></i>
                <h3>Nenhuma assinatura</h3>
                <p>Nenhuma assinatura Premium ainda.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.main-content -->
