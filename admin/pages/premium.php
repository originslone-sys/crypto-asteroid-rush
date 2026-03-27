<?php
// ============================================
// UNOBIX - Admin Premium Management
// Arquivo: admin/pages/premium.php
// v1.0 - Dashboard de assinaturas Premium
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
                    'premium_price_brl' => max(1, min(999, (float)($_POST['premium_price'] ?? 9.90))),
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

$premiumPrice = (float)($settings['premium_price_brl'] ?? 9.90);
$premiumDuration = (int)($settings['premium_duration_days'] ?? 30);
$premiumEnabled = ($settings['premium_enabled'] ?? 'true') === 'true';

// Stats
$totalPremium = 0;
$activePremium = 0;
$totalRevenue = 0;
$recentSubscriptions = [];

try {
    // Ensure tables exist
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'premium_subscriptions'")->fetch();

    if ($tableCheck) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM premium_subscriptions WHERE status IN ('active', 'expired')");
        $totalPremium = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM premium_subscriptions WHERE status = 'active'");
        $activePremium = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COALESCE(SUM(price_brl), 0) FROM premium_subscriptions WHERE status IN ('active', 'expired')");
        $totalRevenue = (float)$stmt->fetchColumn();

        $stmt = $pdo->query("
            SELECT ps.*, u.display_name, u.email, u.is_premium, u.premium_expires_at
            FROM premium_subscriptions ps
            LEFT JOIN users u ON ps.user_id = u.id
            ORDER BY ps.created_at DESC
            LIMIT 50
        ");
        $recentSubscriptions = $stmt->fetchAll();
    }

    // Active premium users
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_premium'")->fetch();
    if ($cols) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_premium = 1 AND premium_expires_at > NOW()");
        $activePremium = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {}
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="page-header-admin">
    <h1><i class="fas fa-crown" style="color: #ffd700;"></i> Premium</h1>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-crown" style="color: #ffd700;"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?php echo $activePremium; ?></div>
            <div class="stat-label">Premium Ativos</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-receipt" style="color: var(--primary);"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?php echo $totalPremium; ?></div>
            <div class="stat-label">Total Assinaturas</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-dollar-sign" style="color: var(--success);"></i></div>
        <div class="stat-info">
            <div class="stat-value">R$ <?php echo number_format($totalRevenue, 2, ',', '.'); ?></div>
            <div class="stat-label">Receita Premium</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-tag" style="color: var(--warning);"></i></div>
        <div class="stat-info">
            <div class="stat-value">R$ <?php echo number_format($premiumPrice, 2, ',', '.'); ?></div>
            <div class="stat-label">Preco Atual</div>
        </div>
    </div>
</div>

<!-- Settings -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-cog"></i> Configuracoes Premium</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="update_premium_settings">

            <div class="form-row" style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 15px;">
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label>Status</label>
                    <div style="margin-top: 8px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="premium_enabled" <?php echo $premiumEnabled ? 'checked' : ''; ?>>
                            <span>Premium Habilitado</span>
                        </label>
                    </div>
                </div>
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label>Preco (R$)</label>
                    <input type="number" name="premium_price" value="<?php echo $premiumPrice; ?>" step="0.01" min="1" max="999" class="form-control">
                </div>
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label>Duracao (dias)</label>
                    <input type="number" name="premium_duration" value="<?php echo $premiumDuration; ?>" min="1" max="365" class="form-control">
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
        </form>
    </div>
</div>

<!-- Manual Premium Management -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-user-cog"></i> Gerenciamento Manual</h3>
    </div>
    <div class="card-body">
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <form method="POST" style="flex: 1; min-width: 250px; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 8px;">
                <input type="hidden" name="action" value="extend_premium">
                <h4 style="margin-bottom: 10px; color: var(--success);"><i class="fas fa-plus-circle"></i> Estender Premium</h4>
                <div class="form-group" style="margin-bottom: 10px;">
                    <label>ID do Usuario</label>
                    <input type="number" name="user_id" required class="form-control" placeholder="Ex: 123">
                </div>
                <div class="form-group" style="margin-bottom: 10px;">
                    <label>Dias para Estender</label>
                    <input type="number" name="extend_days" value="30" min="1" max="365" class="form-control">
                </div>
                <button type="submit" class="btn btn-success"><i class="fas fa-crown"></i> Estender</button>
            </form>

            <form method="POST" style="flex: 1; min-width: 250px; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 8px;" onsubmit="return confirm('Tem certeza que deseja revogar o Premium?')">
                <input type="hidden" name="action" value="revoke_premium">
                <h4 style="margin-bottom: 10px; color: var(--danger);"><i class="fas fa-ban"></i> Revogar Premium</h4>
                <div class="form-group" style="margin-bottom: 10px;">
                    <label>ID do Usuario</label>
                    <input type="number" name="user_id" required class="form-control" placeholder="Ex: 123">
                </div>
                <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Revogar</button>
            </form>
        </div>
    </div>
</div>

<!-- Recent Subscriptions -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Assinaturas Recentes</h3>
    </div>
    <div class="card-body" style="overflow-x: auto;">
        <?php if (count($recentSubscriptions) > 0): ?>
            <table class="data-table">
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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentSubscriptions as $sub): ?>
                        <tr>
                            <td>#<?php echo $sub['id']; ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($sub['display_name'] ?? 'N/A'); ?></div>
                                <small style="color: var(--text-dim);">ID: <?php echo $sub['user_id']; ?></small>
                            </td>
                            <td>R$ <?php echo number_format($sub['price_brl'], 2, ',', '.'); ?></td>
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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-crown" style="opacity: 0.3;"></i>
                <p>Nenhuma assinatura Premium ainda.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
