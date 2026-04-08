<?php
// ============================================
// UNOBIX - Histórico de Login do Admin
// Arquivo: admin/pages/admin-logins.php
// ============================================

$pageTitle = 'Logins do Admin';

try {
    // Criar tabela se não existir
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_login_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            region VARCHAR(100) DEFAULT NULL,
            country VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_ip (ip_address),
            INDEX idx_success (success)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Tabela de sessões ativas (tokens válidos)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_active_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL UNIQUE,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            region VARCHAR(100) DEFAULT NULL,
            country VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_expires (expires_at),
            INDEX idx_revoked (revoked)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Registrar sessão atual se não existir
    $currentSessionId = session_id();
    if ($currentSessionId) {
        $loginIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (strpos($loginIp, ',') !== false) $loginIp = trim(explode(',', $loginIp)[0]);
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $existing = $pdo->prepare("SELECT id FROM admin_active_sessions WHERE session_id = ?");
        $existing->execute([$currentSessionId]);
        if (!$existing->fetch()) {
            // Geolocalização
            $city = $region = $country = null;
            $geoCtx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $geoRes = @file_get_contents("http://ip-api.com/json/{$loginIp}?fields=city,regionName,country&lang=pt-BR", false, $geoCtx);
            if ($geoRes) {
                $geo = json_decode($geoRes, true);
                if (!empty($geo['city'])) {
                    $city = $geo['city'];
                    $region = $geo['regionName'] ?? null;
                    $country = $geo['country'] ?? null;
                }
            }

            $pdo->prepare("INSERT IGNORE INTO admin_active_sessions (session_id, username, ip_address, user_agent, city, region, country, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))")
                ->execute([$currentSessionId, $_SESSION['admin_name'] ?? 'admin', $loginIp, substr($ua, 0, 500), $city, $region, $country]);
        }
    }

    // Limpar sessões expiradas
    $pdo->exec("DELETE FROM admin_active_sessions WHERE expires_at < NOW() OR revoked = 1");

    // Buscar histórico de logins
    $page = max(1, (int)($_GET['lpage'] ?? 1));
    $perPage = 30;
    $offset = ($page - 1) * $perPage;

    $totalLogins = (int)$pdo->query("SELECT COUNT(*) FROM admin_login_log")->fetchColumn();
    $totalPages = ceil($totalLogins / $perPage);

    $logins = $pdo->prepare("
        SELECT * FROM admin_login_log
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $logins->execute([$perPage, $offset]);
    $logins = $logins->fetchAll();

    // Sessões ativas
    $activeSessions = $pdo->query("
        SELECT * FROM admin_active_sessions
        WHERE revoked = 0 AND expires_at > NOW()
        ORDER BY created_at DESC
    ")->fetchAll();

    // Stats
    $stats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(success = 1) as successful,
            SUM(success = 0) as failed,
            COUNT(DISTINCT ip_address) as unique_ips
        FROM admin_login_log
    ")->fetch();

    $failedRecent = (int)$pdo->query("
        SELECT COUNT(*) FROM admin_login_log
        WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetchColumn();

} catch (Exception $e) {
    $error = $e->getMessage();
}

function parseDevice($ua) {
    if (preg_match('/Mobile|Android|iPhone/i', $ua)) return ['fas fa-mobile-alt', 'Mobile'];
    if (preg_match('/Tablet|iPad/i', $ua)) return ['fas fa-tablet-alt', 'Tablet'];
    return ['fas fa-desktop', 'Desktop'];
}

function parseBrowser($ua) {
    if (preg_match('/Chrome/i', $ua) && !preg_match('/Edg/i', $ua)) return 'Chrome';
    if (preg_match('/Firefox/i', $ua)) return 'Firefox';
    if (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) return 'Safari';
    if (preg_match('/Edg/i', $ua)) return 'Edge';
    return 'Outro';
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-user-shield"></i> Histórico de Login</h1>
        <p class="page-subtitle">Monitore acessos ao painel administrativo</p>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-sign-in-alt"></i></div>
            <div class="value"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="label">Total de Logins</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check-circle"></i></div>
            <div class="value"><?php echo $stats['successful'] ?? 0; ?></div>
            <div class="label">Bem-sucedidos</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-times-circle"></i></div>
            <div class="value"><?php echo $stats['failed'] ?? 0; ?></div>
            <div class="label">Falhas</div>
            <?php if ($failedRecent > 0): ?>
                <div class="change" style="color: var(--danger);"><?php echo $failedRecent; ?> nas últimas 24h</div>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-globe"></i></div>
            <div class="value"><?php echo $stats['unique_ips'] ?? 0; ?></div>
            <div class="label">IPs Únicos</div>
        </div>
    </div>

    <!-- Sessões Ativas -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-broadcast-tower" style="color: #05ffa1;"></i> Sessões Ativas</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($activeSessions)): ?>
                <div class="empty-state">
                    <i class="fas fa-lock"></i>
                    <h3>Nenhuma sessão ativa</h3>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Dispositivo</th>
                                <th>IP</th>
                                <th>Localização</th>
                                <th>Iniciada</th>
                                <th>Expira</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeSessions as $s):
                            $device = parseDevice($s['user_agent']);
                            $browser = parseBrowser($s['user_agent']);
                            $isCurrent = ($s['session_id'] === session_id());
                        ?>
                            <tr style="<?php echo $isCurrent ? 'background: rgba(5,255,161,0.05);' : ''; ?>">
                                <td>
                                    <i class="<?php echo $device[0]; ?>" style="margin-right: 6px;"></i>
                                    <?php echo $device[1]; ?> — <?php echo $browser; ?>
                                    <?php if ($isCurrent): ?>
                                        <span class="badge badge-success" style="font-size: 0.65rem; margin-left: 6px;">ATUAL</span>
                                    <?php endif; ?>
                                </td>
                                <td><code style="font-size: 0.8rem;"><?php echo htmlspecialchars($s['ip_address']); ?></code></td>
                                <td>
                                    <?php
                                    $loc = array_filter([$s['city'], $s['region'], $s['country']]);
                                    echo $loc ? htmlspecialchars(implode(', ', $loc)) : '<span style="color: var(--text-dim);">—</span>';
                                    ?>
                                </td>
                                <td><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                                <td><?php echo date('d/m H:i', strtotime($s['expires_at'])); ?></td>
                                <td>
                                    <?php if (!$isCurrent): ?>
                                        <button onclick="revokeSession('<?php echo htmlspecialchars($s['session_id']); ?>')" class="btn btn-danger btn-sm" title="Desconectar">
                                            <i class="fas fa-plug"></i> Desconectar
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-dim); font-size: 0.8rem;">Sessão atual</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 12px;">
                    <button onclick="revokeAllSessions()" class="btn btn-danger btn-sm">
                        <i class="fas fa-power-off"></i> Desconectar Todas (exceto atual)
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Histórico de Logins -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-history"></i> Histórico de Logins</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($logins)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Nenhum login registrado</h3>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Usuário</th>
                                <th>IP</th>
                                <th>Dispositivo</th>
                                <th>Localização</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logins as $log):
                            $device = parseDevice($log['user_agent'] ?? '');
                            $browser = parseBrowser($log['user_agent'] ?? '');
                        ?>
                            <tr style="<?php echo !$log['success'] ? 'background: rgba(255,51,102,0.05);' : ''; ?>">
                                <td>
                                    <?php if ($log['success']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> OK</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><i class="fas fa-times"></i> Falha</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($log['username']); ?></strong></td>
                                <td><code style="font-size: 0.8rem;"><?php echo htmlspecialchars($log['ip_address']); ?></code></td>
                                <td>
                                    <i class="<?php echo $device[0]; ?>" style="margin-right: 4px;"></i>
                                    <?php echo $browser; ?>
                                </td>
                                <td>
                                    <?php
                                    $loc = array_filter([$log['city'], $log['region'], $log['country']]);
                                    echo $loc ? htmlspecialchars(implode(', ', $loc)) : '<span style="color: var(--text-dim);">—</span>';
                                    ?>
                                </td>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginação -->
                <?php if ($totalPages > 1): ?>
                <div style="display: flex; justify-content: center; gap: 6px; margin-top: 15px;">
                    <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
                        <a href="?page=admin-logins&lpage=<?php echo $i; ?>"
                           class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>"
                           style="min-width: 36px; text-align: center;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
async function revokeSession(sessionId) {
    if (!confirm('Desconectar esta sessão?')) return;
    try {
        const res = await adminAjax({ action: 'revoke_admin_session', session_id: sessionId });
        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(res.error || res.message, 'error');
        }
    } catch (e) {
        showToast('Erro: ' + e.message, 'error');
    }
}

async function revokeAllSessions() {
    if (!confirm('Desconectar TODAS as sessões exceto a atual?')) return;
    try {
        const res = await adminAjax({ action: 'revoke_all_admin_sessions' });
        if (res.success) {
            showToast(res.message, 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(res.error || res.message, 'error');
        }
    } catch (e) {
        showToast('Erro: ' + e.message, 'error');
    }
}
</script>
