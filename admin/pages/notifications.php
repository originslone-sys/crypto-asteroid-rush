<?php
// ============================================
// UNOBIX - Admin: Notificações Globais
// admin/pages/notifications.php
// Criar, editar e gerenciar avisos para os usuários
// ============================================

$pageTitle = 'Notificações';
$message = '';
$messageType = '';

// ============================================
// GARANTIR TABELA
// ============================================
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'notifications'")->fetch();
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type ENUM('info','warning','success','promo') DEFAULT 'info',
                is_active TINYINT(1) DEFAULT 1,
                starts_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (is_active),
                INDEX idx_dates (starts_at, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
} catch (Exception $e) {}

// ============================================
// AÇÕES POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'create':
            $title = trim($_POST['title'] ?? '');
            $msg = trim($_POST['message'] ?? '');
            $type = $_POST['type'] ?? 'info';
            $startsAt = $_POST['starts_at'] ?? '';
            $expiresAt = $_POST['expires_at'] ?? '';

            $validTypes = ['info', 'warning', 'success', 'promo'];
            if (empty($title) || empty($msg)) {
                $message = "Título e mensagem são obrigatórios!";
                $messageType = 'danger';
            } elseif (!in_array($type, $validTypes)) {
                $message = "Tipo inválido!";
                $messageType = 'danger';
            } else {
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (title, message, type, is_active, starts_at, expires_at)
                        VALUES (?, ?, ?, 1, ?, ?)
                    ")->execute([
                        $title, $msg, $type,
                        !empty($startsAt) ? $startsAt : date('Y-m-d H:i:s'),
                        !empty($expiresAt) ? $expiresAt : null
                    ]);
                    $message = "Notificação criada!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        case 'update':
            $id = (int)($_POST['notification_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $msg = trim($_POST['message'] ?? '');
            $type = $_POST['type'] ?? 'info';
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $startsAt = $_POST['starts_at'] ?? '';
            $expiresAt = $_POST['expires_at'] ?? '';

            if ($id && !empty($title) && !empty($msg)) {
                try {
                    $pdo->prepare("
                        UPDATE notifications
                        SET title = ?, message = ?, type = ?, is_active = ?, starts_at = ?, expires_at = ?
                        WHERE id = ?
                    ")->execute([
                        $title, $msg, $type, $isActive,
                        !empty($startsAt) ? $startsAt : date('Y-m-d H:i:s'),
                        !empty($expiresAt) ? $expiresAt : null,
                        $id
                    ]);
                    $message = "Notificação atualizada!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        case 'toggle':
            $id = (int)($_POST['notification_id'] ?? 0);
            if ($id) {
                try {
                    $pdo->prepare("UPDATE notifications SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
                    $message = "Status alterado!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        case 'delete':
            $id = (int)($_POST['notification_id'] ?? 0);
            if ($id) {
                try {
                    $pdo->prepare("DELETE FROM notifications WHERE id = ?")->execute([$id]);
                    $message = "Notificação excluída!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;
    }
}

// ============================================
// CARREGAR DADOS
// ============================================
$editNotif = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ?");
        $stmt->execute([$editId]);
        $editNotif = $stmt->fetch();
    } catch (Exception $e) {}
}

$notifications = [];
try {
    $notifications = $pdo->query("
        SELECT *,
            CASE
                WHEN is_active = 0 THEN 'inactive'
                WHEN starts_at > NOW() THEN 'scheduled'
                WHEN expires_at IS NOT NULL AND expires_at <= NOW() THEN 'expired'
                ELSE 'live'
            END as computed_status
        FROM notifications
        ORDER BY created_at DESC
        LIMIT 50
    ")->fetchAll();
} catch (Exception $e) {}

$activeCount = 0;
try {
    $activeCount = (int)$pdo->query("
        SELECT COUNT(*) FROM notifications
        WHERE is_active = 1 AND starts_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW())
    ")->fetchColumn();
} catch (Exception $e) {}

$typeConfig = [
    'info'    => ['label' => 'Informação', 'color' => '#00c8ff', 'icon' => 'fas fa-info-circle'],
    'warning' => ['label' => 'Aviso',      'color' => '#ffaa00', 'icon' => 'fas fa-exclamation-triangle'],
    'success' => ['label' => 'Sucesso',    'color' => '#00ff88', 'icon' => 'fas fa-check-circle'],
    'promo'   => ['label' => 'Promoção',   'color' => '#cc44ff', 'icon' => 'fas fa-gift']
];

$statusConfig = [
    'live'      => ['label' => 'Ativa',     'badge' => 'success'],
    'scheduled' => ['label' => 'Agendada',  'badge' => 'info'],
    'expired'   => ['label' => 'Expirada',  'badge' => 'warning'],
    'inactive'  => ['label' => 'Inativa',   'badge' => 'secondary']
];
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-bell"></i> Notificações</h1>
        <p class="page-subtitle">Enviar avisos e anúncios para o painel dos usuários</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin-bottom:25px;">
        <div class="stat-card" style="border-color:rgba(0,255,136,0.3);">
            <div class="value" style="color:#00ff88;"><?php echo $activeCount; ?></div>
            <div class="label">Ativas Agora</div>
        </div>
        <div class="stat-card">
            <div class="value"><?php echo count($notifications); ?></div>
            <div class="label">Total</div>
        </div>
    </div>

    <?php if ($editNotif): ?>
    <!-- ============================================ -->
    <!-- EDITAR NOTIFICAÇÃO -->
    <!-- ============================================ -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1rem;"><i class="fas fa-edit"></i> Editar Notificação #<?php echo $editNotif['id']; ?></h3>
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=notifications" class="btn btn-secondary" style="text-decoration:none;font-size:0.8rem;">
                <i class="fas fa-times"></i> Cancelar
            </a>
        </div>
        <div style="padding:20px;">
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="notification_id" value="<?php echo $editNotif['id']; ?>">

                <div style="display:grid;grid-template-columns:1fr 200px;gap:15px;margin-bottom:15px;">
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Título *</label>
                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($editNotif['title']); ?>" required style="font-size:0.9rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Tipo</label>
                        <select name="type" class="form-control" style="font-size:0.85rem;">
                            <?php foreach ($typeConfig as $key => $cfg): ?>
                                <option value="<?php echo $key; ?>" <?php echo $editNotif['type'] === $key ? 'selected' : ''; ?>>
                                    <?php echo $cfg['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:15px;">
                    <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Mensagem *</label>
                    <textarea name="message" class="form-control" rows="3" required style="font-size:0.9rem;resize:vertical;"><?php echo htmlspecialchars($editNotif['message']); ?></textarea>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:15px;align-items:end;">
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Início</label>
                        <input type="datetime-local" name="starts_at" class="form-control"
                            value="<?php echo date('Y-m-d\TH:i', strtotime($editNotif['starts_at'])); ?>" style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Expiração (vazio = sem limite)</label>
                        <input type="datetime-local" name="expires_at" class="form-control"
                            value="<?php echo $editNotif['expires_at'] ? date('Y-m-d\TH:i', strtotime($editNotif['expires_at'])) : ''; ?>" style="font-size:0.85rem;">
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;padding-bottom:4px;">
                        <input type="checkbox" name="is_active" id="edit_active" <?php echo $editNotif['is_active'] ? 'checked' : ''; ?>>
                        <label for="edit_active" style="font-size:0.85rem;cursor:pointer;">Ativa</label>
                    </div>
                </div>

                <div style="margin-top:15px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================ -->
    <!-- CRIAR NOTIFICAÇÃO -->
    <!-- ============================================ -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><h3 style="margin:0;font-size:1rem;"><i class="fas fa-plus"></i> Nova Notificação</h3></div>
        <div style="padding:20px;">
            <form method="POST">
                <input type="hidden" name="action" value="create">

                <div style="display:grid;grid-template-columns:1fr 200px;gap:15px;margin-bottom:15px;">
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Título *</label>
                        <input type="text" name="title" class="form-control" placeholder="Ex: Manutenção programada" required style="font-size:0.9rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Tipo</label>
                        <select name="type" class="form-control" style="font-size:0.85rem;">
                            <?php foreach ($typeConfig as $key => $cfg): ?>
                                <option value="<?php echo $key; ?>"><?php echo $cfg['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:15px;">
                    <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Mensagem *</label>
                    <textarea name="message" class="form-control" rows="3" placeholder="Mensagem que aparecerá no painel do usuário..." required style="font-size:0.9rem;resize:vertical;"></textarea>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Início (vazio = agora)</label>
                        <input type="datetime-local" name="starts_at" class="form-control" style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Expiração (vazio = sem limite)</label>
                        <input type="datetime-local" name="expires_at" class="form-control" style="font-size:0.85rem;">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="font-size:0.85rem;"><i class="fas fa-paper-plane"></i> Publicar Notificação</button>
            </form>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- LISTA DE NOTIFICAÇÕES -->
    <!-- ============================================ -->
    <div class="card">
        <div class="card-header"><h3 style="margin:0;font-size:1rem;">Notificações (<?php echo count($notifications); ?>)</h3></div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Tipo</th>
                        <th>Título</th>
                        <th>Mensagem</th>
                        <th>Período</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($notifications)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;padding:40px;color:var(--text-dim);">
                                <i class="fas fa-bell-slash" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                                Nenhuma notificação criada
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($notifications as $n):
                            $tc = $typeConfig[$n['type']] ?? $typeConfig['info'];
                            $sc = $statusConfig[$n['computed_status']] ?? $statusConfig['inactive'];
                        ?>
                            <tr style="<?php echo !$n['is_active'] ? 'opacity:0.5;' : ''; ?>border-left:3px solid <?php echo $tc['color']; ?>;">
                                <td><strong>#<?php echo $n['id']; ?></strong></td>
                                <td>
                                    <span style="color:<?php echo $tc['color']; ?>;font-size:0.85rem;">
                                        <i class="<?php echo $tc['icon']; ?>"></i> <?php echo $tc['label']; ?>
                                    </span>
                                </td>
                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;">
                                    <?php echo htmlspecialchars($n['title']); ?>
                                </td>
                                <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.85rem;color:var(--text-dim);">
                                    <?php echo htmlspecialchars($n['message']); ?>
                                </td>
                                <td style="font-size:0.75rem;">
                                    <div><?php echo date('d/m/y H:i', strtotime($n['starts_at'])); ?></div>
                                    <?php if ($n['expires_at']): ?>
                                        <div style="color:var(--text-dim);">até <?php echo date('d/m/y H:i', strtotime($n['expires_at'])); ?></div>
                                    <?php else: ?>
                                        <div style="color:var(--text-dim);">sem expiração</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $sc['badge']; ?>"><?php echo $sc['label']; ?></span>
                                </td>
                                <td style="white-space:nowrap;">
                                    <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=notifications&edit=<?php echo $n['id']; ?>" class="btn btn-primary" style="padding:5px 10px;font-size:0.8rem;text-decoration:none;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="notification_id" value="<?php echo $n['id']; ?>">
                                        <button type="submit" class="btn btn-<?php echo $n['is_active'] ? 'warning' : 'success'; ?>" style="padding:5px 10px;font-size:0.8rem;" title="<?php echo $n['is_active'] ? 'Desativar' : 'Ativar'; ?>">
                                            <i class="fas fa-<?php echo $n['is_active'] ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir notificação #<?php echo $n['id']; ?>?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="notification_id" value="<?php echo $n['id']; ?>">
                                        <button type="submit" class="btn btn-danger" style="padding:5px 10px;font-size:0.8rem;"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
