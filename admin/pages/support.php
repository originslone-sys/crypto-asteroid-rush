<?php
// ============================================
// UNOBIX - Admin: Tickets de Suporte
// Arquivo: admin/pages/support.php
// ============================================

$pageTitle = 'Suporte';
$message = '';
$messageType = '';

// ============================================
// GARANTIR TABELAS
// ============================================
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'support_tickets'")->fetch();
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE support_tickets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                google_uid VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                category ENUM('account','payment','game','bug','other') DEFAULT 'other',
                status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
                priority ENUM('low','normal','high') DEFAULT 'normal',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user (google_uid),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE support_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                sender_type ENUM('user','admin') NOT NULL,
                message TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ticket (ticket_id),
                FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
} catch (Exception $e) {}

// ============================================
// AÇÕES POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ticketId = (int)($_POST['ticket_id'] ?? 0);

    switch ($action) {
        case 'reply':
            $replyMsg = trim($_POST['message'] ?? '');
            if ($ticketId && strlen($replyMsg) >= 2) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_type, message) VALUES (?, 'admin', ?)");
                    $stmt->execute([$ticketId, $replyMsg]);

                    $stmt = $pdo->prepare("UPDATE support_tickets SET status = 'in_progress' WHERE id = ? AND status = 'open'");
                    $stmt->execute([$ticketId]);

                    $message = "Resposta enviada com sucesso!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro ao enviar resposta: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        case 'change_status':
            $newStatus = $_POST['status'] ?? '';
            $validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
            if ($ticketId && in_array($newStatus, $validStatuses)) {
                try {
                    $stmt = $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");
                    $stmt->execute([$newStatus, $ticketId]);
                    $message = "Status atualizado!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        case 'change_priority':
            $newPriority = $_POST['priority'] ?? '';
            $validPriorities = ['low', 'normal', 'high'];
            if ($ticketId && in_array($newPriority, $validPriorities)) {
                try {
                    $stmt = $pdo->prepare("UPDATE support_tickets SET priority = ? WHERE id = ?");
                    $stmt->execute([$newPriority, $ticketId]);
                    $message = "Prioridade atualizada!";
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
// FILTROS
// ============================================
$statusFilter = $_GET['status'] ?? 'all';
$categoryFilter = $_GET['category'] ?? 'all';
$viewTicketId = (int)($_GET['view'] ?? 0);

// ============================================
// ESTATÍSTICAS
// ============================================
$stats = [
    'total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0
];
try {
    $statsQuery = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(status = 'open') as open_count,
            SUM(status = 'in_progress') as in_progress_count,
            SUM(status = 'resolved') as resolved_count,
            SUM(status = 'closed') as closed_count
        FROM support_tickets
    ")->fetch();

    $stats['total'] = (int)$statsQuery['total'];
    $stats['open'] = (int)$statsQuery['open_count'];
    $stats['in_progress'] = (int)$statsQuery['in_progress_count'];
    $stats['resolved'] = (int)$statsQuery['resolved_count'];
    $stats['closed'] = (int)$statsQuery['closed_count'];
} catch (Exception $e) {}

// ============================================
// VISUALIZAR TICKET ESPECÍFICO
// ============================================
$viewTicket = null;
$viewMessages = [];
$viewUser = null;

if ($viewTicketId) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, u.google_uid as user_google_uid,
                   COALESCE(u.display_name, u.google_uid) as user_name,
                   u.email as user_email,
                   u.balance_brl, u.total_earned_brl, u.total_played, u.credits,
                   u.is_banned, u.created_at as user_since
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.id = ?
        ");
        $stmt->execute([$viewTicketId]);
        $viewTicket = $stmt->fetch();

        if ($viewTicket) {
            $stmt = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
            $stmt->execute([$viewTicketId]);
            $viewMessages = $stmt->fetchAll();
        }
    } catch (Exception $e) {}
}

// ============================================
// LISTAR TICKETS
// ============================================
$tickets = [];
if (!$viewTicketId) {
    try {
        $where = "1=1";
        $params = [];

        if ($statusFilter !== 'all') {
            $where .= " AND t.status = ?";
            $params[] = $statusFilter;
        }
        if ($categoryFilter !== 'all') {
            $where .= " AND t.category = ?";
            $params[] = $categoryFilter;
        }

        $stmt = $pdo->prepare("
            SELECT t.*,
                   COALESCE(u.display_name, u.google_uid) as user_name,
                   u.email as user_email,
                   (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) as msg_count,
                   (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.sender_type = 'admin') as admin_replies
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.user_id
            WHERE $where
            ORDER BY
                CASE t.priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 END,
                CASE t.status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 WHEN 'closed' THEN 4 END,
                t.updated_at DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();
    } catch (Exception $e) {}
}

$statusLabels = [
    'open' => ['Aberto', 'info'],
    'in_progress' => ['Em Andamento', 'warning'],
    'resolved' => ['Resolvido', 'success'],
    'closed' => ['Fechado', 'secondary']
];

$priorityLabels = [
    'low' => ['Baixa', 'secondary'],
    'normal' => ['Normal', 'info'],
    'high' => ['Alta', 'danger']
];

$categoryLabels = [
    'account' => 'Conta',
    'payment' => 'Pagamento',
    'game' => 'Jogo',
    'bug' => 'Bug',
    'other' => 'Outro'
];
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title">Suporte</h1>
        <p class="page-subtitle">Gerenciar tickets de suporte dos usuarios</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin-bottom:25px;">
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=support" class="stat-card" style="text-decoration:none;">
            <div class="value"><?php echo $stats['total']; ?></div>
            <div class="label">Total</div>
        </a>
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=support&status=open" class="stat-card" style="text-decoration:none;border-color:rgba(0,200,255,0.3);">
            <div class="value" style="color:#00c8ff;"><?php echo $stats['open']; ?></div>
            <div class="label">Abertos</div>
        </a>
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=support&status=in_progress" class="stat-card" style="text-decoration:none;border-color:rgba(255,170,0,0.3);">
            <div class="value" style="color:#ffaa00;"><?php echo $stats['in_progress']; ?></div>
            <div class="label">Em Andamento</div>
        </a>
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=support&status=resolved" class="stat-card" style="text-decoration:none;border-color:rgba(0,255,136,0.3);">
            <div class="value" style="color:#00ff88;"><?php echo $stats['resolved']; ?></div>
            <div class="label">Resolvidos</div>
        </a>
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=support&status=closed" class="stat-card" style="text-decoration:none;">
            <div class="value" style="color:#999;"><?php echo $stats['closed']; ?></div>
            <div class="label">Fechados</div>
        </a>
    </div>

    <?php if ($viewTicket): ?>
    <!-- ============================================ -->
    <!-- VISUALIZAR TICKET -->
    <!-- ============================================ -->
    <div style="margin-bottom:15px;">
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=support<?php echo $statusFilter !== 'all' ? '&status='.$statusFilter : ''; ?>" class="btn btn-secondary" style="text-decoration:none;">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;">
        <!-- Coluna principal: mensagens -->
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <h3 style="margin:0;font-size:1.1rem;">#<?php echo $viewTicket['id']; ?> - <?php echo htmlspecialchars($viewTicket['subject']); ?></h3>
                    <small style="color:var(--text-dim);">
                        <?php echo $categoryLabels[$viewTicket['category']] ?? $viewTicket['category']; ?>
                        - Aberto em <?php echo date('d/m/Y H:i', strtotime($viewTicket['created_at'])); ?>
                    </small>
                </div>
                <span class="badge badge-<?php echo $statusLabels[$viewTicket['status']][1]; ?>">
                    <?php echo $statusLabels[$viewTicket['status']][0]; ?>
                </span>
            </div>

            <div style="padding:20px;max-height:500px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;" id="msgContainer">
                <?php foreach ($viewMessages as $msg): ?><div style="padding:8px 12px;border-radius:12px;word-wrap:break-word;<?php if ($msg['sender_type'] === 'admin'): ?>background:rgba(108,92,231,0.08);border:1px solid rgba(108,92,231,0.15);border-left:3px solid #a78bfa;<?php else: ?>background:rgba(0,200,255,0.06);border:1px solid rgba(0,200,255,0.12);border-left:3px solid #00c8ff;<?php endif; ?>"><div style="font-size:0.6rem;font-weight:600;text-transform:uppercase;margin-bottom:2px;letter-spacing:0.3px;color:<?php echo $msg['sender_type'] === 'admin' ? '#a78bfa' : '#00c8ff'; ?>;display:inline-block;"><?php echo $msg['sender_type'] === 'admin' ? 'Admin' : 'Usuario'; ?></div><span style="font-size:0.6rem;color:var(--text-dim);margin-left:8px;"><?php echo date('d/m/Y H:i', strtotime($msg['created_at'])); ?></span><div style="font-size:0.88rem;line-height:1.4;white-space:pre-wrap;margin-top:2px;"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div></div><?php endforeach; ?>
            </div>

            <!-- Formulário de resposta admin -->
            <div style="padding:20px;border-top:1px solid var(--border-color);">
                <form method="POST" style="display:flex;gap:10px;">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                    <textarea name="message" placeholder="Digite sua resposta..." required
                        style="flex:1;padding:12px;background:rgba(0,0,0,0.3);border:1px solid var(--border-color);border-radius:8px;color:#fff;font-family:'Exo 2',sans-serif;font-size:0.92rem;resize:none;min-height:60px;"></textarea>
                    <button type="submit" class="btn btn-primary" style="align-self:flex-end;">
                        <i class="fas fa-paper-plane"></i> Enviar
                    </button>
                </form>
            </div>
        </div>

        <!-- Coluna lateral: info do usuario e ações -->
        <div>
            <!-- Ações do ticket -->
            <div class="card" style="margin-bottom:15px;">
                <div class="card-header"><h4 style="margin:0;font-size:0.95rem;">Acoes</h4></div>
                <div style="padding:15px;">
                    <form method="POST" style="margin-bottom:12px;">
                        <input type="hidden" name="action" value="change_status">
                        <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Status</label>
                        <div style="display:flex;gap:6px;">
                            <select name="status" class="form-control" style="font-size:0.85rem;">
                                <option value="open" <?php echo $viewTicket['status'] === 'open' ? 'selected' : ''; ?>>Aberto</option>
                                <option value="in_progress" <?php echo $viewTicket['status'] === 'in_progress' ? 'selected' : ''; ?>>Em Andamento</option>
                                <option value="resolved" <?php echo $viewTicket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolvido</option>
                                <option value="closed" <?php echo $viewTicket['status'] === 'closed' ? 'selected' : ''; ?>>Fechado</option>
                            </select>
                            <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:0.8rem;">OK</button>
                        </div>
                    </form>

                    <form method="POST">
                        <input type="hidden" name="action" value="change_priority">
                        <input type="hidden" name="ticket_id" value="<?php echo $viewTicket['id']; ?>">
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Prioridade</label>
                        <div style="display:flex;gap:6px;">
                            <select name="priority" class="form-control" style="font-size:0.85rem;">
                                <option value="low" <?php echo $viewTicket['priority'] === 'low' ? 'selected' : ''; ?>>Baixa</option>
                                <option value="normal" <?php echo $viewTicket['priority'] === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                <option value="high" <?php echo $viewTicket['priority'] === 'high' ? 'selected' : ''; ?>>Alta</option>
                            </select>
                            <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:0.8rem;">OK</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Info do usuário -->
            <div class="card">
                <div class="card-header"><h4 style="margin:0;font-size:0.95rem;">Usuario</h4></div>
                <div style="padding:15px;font-size:0.85rem;">
                    <div style="margin-bottom:8px;">
                        <span style="color:var(--text-dim);">Nome:</span><br>
                        <strong><?php echo htmlspecialchars($viewTicket['user_name'] ?? 'N/A'); ?></strong>
                    </div>
                    <div style="margin-bottom:8px;">
                        <span style="color:var(--text-dim);">Email:</span><br>
                        <strong><?php echo htmlspecialchars($viewTicket['user_email'] ?? 'N/A'); ?></strong>
                    </div>
                    <div style="margin-bottom:8px;">
                        <span style="color:var(--text-dim);">Saldo:</span><br>
                        <strong style="color:var(--success);">R$ <?php echo number_format((float)($viewTicket['balance_brl'] ?? 0), 2, ',', '.'); ?></strong>
                    </div>
                    <div style="margin-bottom:8px;">
                        <span style="color:var(--text-dim);">Total Ganho:</span><br>
                        <strong>R$ <?php echo number_format((float)($viewTicket['total_earned_brl'] ?? 0), 2, ',', '.'); ?></strong>
                    </div>
                    <div style="margin-bottom:8px;">
                        <span style="color:var(--text-dim);">Partidas:</span><br>
                        <strong><?php echo (int)($viewTicket['total_played'] ?? 0); ?></strong>
                    </div>
                    <div style="margin-bottom:8px;">
                        <span style="color:var(--text-dim);">Creditos:</span><br>
                        <strong><?php echo (int)($viewTicket['credits'] ?? 0); ?></strong>
                    </div>
                    <div style="margin-bottom:8px;">
                        <span style="color:var(--text-dim);">Membro desde:</span><br>
                        <strong><?php echo $viewTicket['user_since'] ? date('d/m/Y', strtotime($viewTicket['user_since'])) : 'N/A'; ?></strong>
                    </div>
                    <?php if ($viewTicket['is_banned'] ?? false): ?>
                        <div class="badge badge-danger" style="margin-top:5px;">BANIDO</div>
                    <?php endif; ?>
                    <div style="margin-top:12px;">
                        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=players&search=<?php echo urlencode($viewTicket['user_email'] ?? ''); ?>" class="btn btn-secondary" style="font-size:0.8rem;padding:6px 12px;text-decoration:none;display:inline-block;">
                            <i class="fas fa-external-link-alt"></i> Ver Perfil
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll to bottom of messages
        const mc = document.getElementById('msgContainer');
        if (mc) mc.scrollTop = mc.scrollHeight;
    </script>

    <?php else: ?>
    <!-- ============================================ -->
    <!-- LISTA DE TICKETS -->
    <!-- ============================================ -->

    <!-- Filtros -->
    <div class="card" style="margin-bottom:20px;">
        <div style="padding:15px;display:flex;gap:15px;flex-wrap:wrap;align-items:center;">
            <div>
                <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Status</label>
                <select onchange="window.location.href='<?php echo $ADMIN_INDEX_URL; ?>?page=support&status='+this.value+'&category=<?php echo $categoryFilter; ?>'" class="form-control" style="font-size:0.85rem;min-width:150px;">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Todos</option>
                    <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Abertos</option>
                    <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>Em Andamento</option>
                    <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolvidos</option>
                    <option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>>Fechados</option>
                </select>
            </div>
            <div>
                <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Categoria</label>
                <select onchange="window.location.href='<?php echo $ADMIN_INDEX_URL; ?>?page=support&status=<?php echo $statusFilter; ?>&category='+this.value" class="form-control" style="font-size:0.85rem;min-width:150px;">
                    <option value="all" <?php echo $categoryFilter === 'all' ? 'selected' : ''; ?>>Todas</option>
                    <option value="account" <?php echo $categoryFilter === 'account' ? 'selected' : ''; ?>>Conta</option>
                    <option value="payment" <?php echo $categoryFilter === 'payment' ? 'selected' : ''; ?>>Pagamento</option>
                    <option value="game" <?php echo $categoryFilter === 'game' ? 'selected' : ''; ?>>Jogo</option>
                    <option value="bug" <?php echo $categoryFilter === 'bug' ? 'selected' : ''; ?>>Bug</option>
                    <option value="other" <?php echo $categoryFilter === 'other' ? 'selected' : ''; ?>>Outro</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Tabela de tickets -->
    <div class="card">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Usuario</th>
                        <th>Assunto</th>
                        <th>Categoria</th>
                        <th>Status</th>
                        <th>Prioridade</th>
                        <th>Msgs</th>
                        <th>Atualizado</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:40px;color:var(--text-dim);">
                                <i class="fas fa-inbox" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                                Nenhum ticket encontrado
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): ?>
                            <tr style="<?php echo $t['priority'] === 'high' ? 'border-left:3px solid #ff4757;' : ''; ?>">
                                <td><strong>#<?php echo $t['id']; ?></strong></td>
                                <td>
                                    <div style="font-size:0.85rem;"><?php echo htmlspecialchars($t['user_name'] ?? 'N/A'); ?></div>
                                    <div style="font-size:0.7rem;color:var(--text-dim);"><?php echo htmlspecialchars($t['user_email'] ?? ''); ?></div>
                                </td>
                                <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?php echo htmlspecialchars($t['subject']); ?>
                                </td>
                                <td><span class="badge badge-info" style="font-size:0.7rem;"><?php echo $categoryLabels[$t['category']] ?? $t['category']; ?></span></td>
                                <td><span class="badge badge-<?php echo $statusLabels[$t['status']][1]; ?>"><?php echo $statusLabels[$t['status']][0]; ?></span></td>
                                <td><span class="badge badge-<?php echo $priorityLabels[$t['priority']][1]; ?>"><?php echo $priorityLabels[$t['priority']][0]; ?></span></td>
                                <td>
                                    <?php echo (int)$t['msg_count']; ?>
                                    <?php if ((int)$t['admin_replies'] === 0 && $t['status'] === 'open'): ?>
                                        <span style="color:#ff4757;font-size:0.7rem;" title="Sem resposta admin">&#9679;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.8rem;"><?php echo date('d/m/y H:i', strtotime($t['updated_at'])); ?></td>
                                <td>
                                    <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=support&view=<?php echo $t['id']; ?>" class="btn btn-primary" style="padding:5px 12px;font-size:0.8rem;text-decoration:none;">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
