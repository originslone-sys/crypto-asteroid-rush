<?php
// ============================================
// UNOBIX - Gerenciamento de Saques
// Arquivo: admin/pages/withdrawals.php
// ============================================

$pageTitle = 'Saques';
$statusFilter = $_GET['status'] ?? 'pending';
$searchQuery = trim($_GET['q'] ?? '');

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'approve') {
            // Verificar se é PIX para redirecionar para ZettPay (via AJAX)
            $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'completed', processed_at = NOW() WHERE id = ?");
            $stmt->execute([(int)$_POST['withdrawal_id']]);
            $message = "Saque aprovado!";
        } elseif ($action === 'reject_all_pending') {
            // Cancelar todos os saques pendentes e estornar saldo
            $reason = trim($_POST['reason'] ?? 'Cancelamento em massa pelo administrador');
            $pending = $pdo->query("SELECT w.id, w.user_id, w.amount_brl, u.google_uid
                FROM withdrawals w LEFT JOIN users u ON u.id = w.user_id
                WHERE w.status = 'pending'")->fetchAll();
            $count = 0;
            foreach ($pending as $w) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE id = ?")
                        ->execute([$w['amount_brl'], $w['user_id']]);
                    $pdo->prepare("UPDATE withdrawals SET status = 'rejected', processed_at = NOW(), admin_notes = ? WHERE id = ?")
                        ->execute([$reason, $w['id']]);
                    if ($w['google_uid']) {
                        $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at) VALUES (?, 'withdraw_reject', ?, ?, 'completed', NOW())")
                            ->execute([$w['google_uid'], $w['amount_brl'], "Saque #{$w['id']} cancelado - saldo devolvido. Motivo: {$reason}"]);
                    }
                    $pdo->commit();
                    $count++;
                } catch (Exception $e) {
                    $pdo->rollBack();
                }
            }
            $message = "✓ {$count} saque(s) cancelado(s) e saldo estornado nas contas.";
        } elseif ($action === 'reject') {
            // Devolver saldo ao jogador
            $stmt = $pdo->prepare("SELECT w.user_id, w.amount_brl FROM withdrawals w WHERE w.id = ?");
            $stmt->execute([(int)$_POST['withdrawal_id']]);
            $w = $stmt->fetch();
            if ($w) {
                $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE id = ?")
                    ->execute([$w['amount_brl'], $w['user_id']]);
                $pdo->prepare("UPDATE withdrawals SET status = 'rejected', processed_at = NOW(), admin_notes = ? WHERE id = ?")
                    ->execute([$_POST['reason'] ?? 'Rejeitado pelo admin', (int)$_POST['withdrawal_id']]);
            }
            $message = "Saque rejeitado e saldo devolvido!";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

try {
    $sql = "SELECT w.*, u.display_name, u.email, u.balance_brl, u.total_played, u.is_banned
            FROM withdrawals w
            LEFT JOIN users u ON w.user_id = u.id";

    $conditions = [];
    $params = [];

    if ($statusFilter !== 'all') {
        $conditions[] = "w.status = :status";
        $params['status'] = $statusFilter;
    }

    if ($searchQuery !== '') {
        // Pesquisa por ID exato, nome, email ou dados PIX
        if (ctype_digit($searchQuery)) {
            $conditions[] = "w.id = :search_id";
            $params['search_id'] = (int)$searchQuery;
        } else {
            $conditions[] = "(u.display_name LIKE :search OR u.email LIKE :search2 OR w.admin_notes LIKE :search3)";
            $searchLike = '%' . $searchQuery . '%';
            $params['search'] = $searchLike;
            $params['search2'] = $searchLike;
            $params['search3'] = $searchLike;
        }
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY w.created_at DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $withdrawals = $stmt->fetchAll();
    
    $stats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'pending' THEN amount_brl ELSE 0 END) as pending_amount,
            SUM(CASE WHEN status = 'processing' THEN amount_brl ELSE 0 END) as processing_amount,
            SUM(CASE WHEN status = 'completed' THEN amount_brl ELSE 0 END) as completed_amount
        FROM withdrawals
    ")->fetch();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php
?>

<div class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 class="page-title"><i class="fas fa-money-bill-wave"></i> Gerenciamento de Saques</h1>
            <p class="page-subtitle">Aprovar, rejeitar e acompanhar solicitações</p>
        </div>
        <?php if (($stats['pending'] ?? 0) > 0): ?>
        <button onclick="document.getElementById('modalCancelAll').style.display='flex'"
                class="btn btn-danger btn-sm" style="margin-top:6px;">
            <i class="fas fa-ban"></i> Cancelar todos pendentes (<?php echo (int)$stats['pending']; ?>)
        </button>
        <?php endif; ?>
    </div>

    <!-- Modal de confirmação -->
    <div id="modalCancelAll" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:var(--card-bg,#1a1a2e);border:1px solid rgba(255,51,102,0.4);border-radius:14px;padding:28px;max-width:460px;width:90%;">
            <h3 style="font-family:'Orbitron',monospace;color:#ff3366;margin:0 0 12px;font-size:1rem;">
                <i class="fas fa-exclamation-triangle"></i> Cancelar todos os saques pendentes?
            </h3>
            <p style="font-size:0.85rem;color:var(--text-dim);margin-bottom:16px;line-height:1.5;">
                Isso vai <strong style="color:#fff;">rejeitar <?php echo (int)$stats['pending']; ?> saque(s)</strong>
                (total de <strong style="color:#ff3366;"><?php echo formatBRL($stats['pending_amount'] ?? 0); ?></strong>)
                e estornar o saldo de volta para cada conta. Essa ação não pode ser desfeita.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="reject_all_pending">
                <div style="margin-bottom:14px;">
                    <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:6px;">Motivo (aparece no histórico de cada usuário)</label>
                    <input type="text" name="reason" class="form-control"
                           value="Cancelamento administrativo — saldo devolvido à conta"
                           style="font-size:0.85rem;">
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="document.getElementById('modalCancelAll').style.display='none'"
                            class="btn btn-secondary">Voltar</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-ban"></i> Confirmar cancelamento
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <?php if (isset($message)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-clock"></i></div>
            <div class="value"><?php echo $stats['pending'] ?? 0; ?></div>
            <div class="label">Pendentes</div>
            <div class="change"><?php echo formatBRL($stats['pending_amount']); ?></div>
        </div>
        <div class="stat-card">
            <div class="icon" style="background: rgba(0,150,255,0.15); color: #0096ff;"><i class="fas fa-spinner"></i></div>
            <div class="value"><?php echo $stats['processing'] ?? 0; ?></div>
            <div class="label">Processando PIX</div>
            <div class="change"><?php echo formatBRL($stats['processing_amount'] ?? 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check"></i></div>
            <div class="value"><?php echo $stats['approved'] ?? 0; ?></div>
            <div class="label">Aprovados</div>
            <div class="change"><?php echo formatBRL($stats['completed_amount']); ?></div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-times"></i></div>
            <div class="value"><?php echo $stats['rejected'] ?? 0; ?></div>
            <div class="label">Rejeitados</div>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-body">
            <div class="tabs">
                <a href="?page=withdrawals&status=pending" class="tab <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Pendentes (<?php echo $stats['pending'] ?? 0; ?>)
                </a>
                <a href="?page=withdrawals&status=processing" class="tab <?php echo $statusFilter === 'processing' ? 'active' : ''; ?>">
                    <i class="fas fa-spinner"></i> Processando (<?php echo $stats['processing'] ?? 0; ?>)
                </a>
                <a href="?page=withdrawals&status=completed" class="tab <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>">
                    <i class="fas fa-check"></i> Aprovados
                </a>
                <a href="?page=withdrawals&status=rejected" class="tab <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">
                    <i class="fas fa-times"></i> Rejeitados
                </a>
                <a href="?page=withdrawals&status=all" class="tab <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> Todos
                </a>
            </div>
        </div>
    </div>
    
    <div class="panel" style="margin-bottom: 0; border-bottom: none; border-radius: 15px 15px 0 0;">
        <div class="panel-body" style="padding: 16px 20px;">
            <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="page" value="withdrawals">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <div style="flex: 1; min-width: 200px; position: relative;">
                    <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-dim);"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" class="form-control" placeholder="Pesquisar por nome, e-mail ou ID do saque..." style="padding-left: 36px;">
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Buscar</button>
                <?php if ($searchQuery !== ''): ?>
                    <a href="?page=withdrawals&status=<?php echo htmlspecialchars($statusFilter); ?>" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Limpar</a>
                <?php endif; ?>
            </form>
            <?php if ($searchQuery !== ''): ?>
                <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-dim);">
                    <i class="fas fa-filter"></i> Resultados para "<strong style="color: var(--primary);"><?php echo htmlspecialchars($searchQuery); ?></strong>" — <?php echo count($withdrawals); ?> encontrado(s)
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel" style="border-radius: 0 0 15px 15px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Solicitações</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($withdrawals)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>Nenhum saque encontrado</h3>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Jogador</th>
                                <th>Valor</th>
                                <th>Método</th>
                                <th>Dados</th>
                                <th>Saldo Atual</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td><strong>#<?php echo $w['id']; ?></strong></td>
                            <td>
                                <div><?php echo htmlspecialchars($w['display_name'] ?? 'Usuário'); ?></div>
                                <small style="color: var(--text-dim);"><?php echo htmlspecialchars($w['email'] ?? ''); ?></small>
                                <?php if ($w['is_banned']): ?>
                                    <span class="badge badge-danger">BANIDO</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--success); font-weight: bold;"><?php echo formatBRL($w['amount_brl']); ?></td>
                            <td>
                                <?php
                                $method = strtoupper($w['wallet_address'] ?? 'PIX');
                                $methodBadge = [
                                    'PIX' => 'success',
                                    'FAUCETPAY' => 'warning',
                                    'USDT BEP20' => 'primary'
                                ];
                                $badgeClass = $methodBadge[$method] ?? 'primary';
                                ?>
                                <span class="badge badge-<?php echo $badgeClass; ?>"><?php echo $method; ?></span>
                            </td>
                            <td style="max-width: 200px; overflow: hidden;">
                                <?php
                                $notes = $w['admin_notes'] ?? '';
                                $details = json_decode($notes, true);
                                if ($details && isset($details['details'])) {
                                    $detailText = htmlspecialchars($details['details']);
                                    echo "<small title=\"$detailText\" style=\"cursor: pointer;\" onclick=\"copyToClipboard('$detailText')\"><i class=\"fas fa-copy\" style=\"margin-right: 4px; color: var(--text-dim);\"></i>$detailText</small>";
                                } else {
                                    echo '<small>' . htmlspecialchars($notes ?: '-') . '</small>';
                                }
                                ?>
                            </td>
                            <td><?php echo formatBRL($w['balance_brl']); ?></td>
                            <td>
                                <?php
                                $statusClass = ['completed' => 'success', 'pending' => 'warning', 'processing' => 'primary', 'rejected' => 'danger'][$w['status']] ?? 'primary';
                                $statusText = ['completed' => 'Concluido', 'pending' => 'Pendente', 'processing' => 'Processando PIX', 'rejected' => 'Rejeitado'][$w['status']] ?? $w['status'];
                                ?>
                                <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                <?php if (!empty($w['zettpay_external_id'])): ?>
                                    <br><small style="color: var(--text-dim); font-size: 0.7rem;">ZettPay</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($w['created_at'])); ?></td>
                            <td>
                                <?php if ($w['status'] === 'pending'): ?>
                                <?php
                                    $isPix = strtoupper($w['wallet_address'] ?? '') === 'PIX';
                                ?>
                                <div class="btn-group">
                                    <?php if ($isPix): ?>
                                    <!-- PIX: Aprovar via ZettPay (automático) -->
                                    <button onclick="approveWithdrawalZettpay(<?php echo $w['id']; ?>, <?php echo $w['amount_brl']; ?>)" class="btn btn-success btn-sm" title="Aprovar via PIX ZettPay">
                                        <i class="fas fa-bolt"></i> PIX
                                    </button>
                                    <?php endif; ?>
                                    <!-- Aprovação manual (qualquer método) -->
                                    <button onclick="approveWithdrawalManual(<?php echo $w['id']; ?>, <?php echo $w['amount_brl']; ?>)" class="btn btn-success btn-sm" title="Aprovar manualmente (pagamento externo)">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button onclick="rejectWithdrawal(<?php echo $w['id']; ?>)" class="btn btn-danger btn-sm">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <?php elseif ($w['status'] === 'processing'): ?>
                                <div class="btn-group">
                                    <button onclick="checkCashoutStatus(<?php echo $w['id']; ?>)" class="btn btn-primary btn-sm" title="Verificar status na ZettPay">
                                        <i class="fas fa-sync-alt"></i> Verificar
                                    </button>
                                    <button onclick="forceCompleteWithdrawal(<?php echo $w['id']; ?>, <?php echo $w['amount_brl']; ?>)" class="btn btn-success btn-sm" title="Confirmar manualmente como pago">
                                        <i class="fas fa-check-double"></i> Confirmar
                                    </button>
                                    <button onclick="rejectWithdrawal(<?php echo $w['id']; ?>)" class="btn btn-danger btn-sm" title="Rejeitar e devolver saldo">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <?php else: ?>
                                    <span style="color: var(--text-dim);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Rejeitar -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-times"></i> Rejeitar Saque</h3>
            <button onclick="closeModal('rejectModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="withdrawal_id" id="rejectWithdrawalId">
            
            <div class="form-group">
                <label class="form-label">Motivo da Rejeição</label>
                <input type="text" name="reason" class="form-control" required placeholder="Ex: Dados inválidos">
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeModal('rejectModal')" class="btn btn-outline">Cancelar</button>
                <button type="submit" class="btn btn-danger">Rejeitar</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center; }
.modal-overlay.active { display: flex; }
.modal-content { background: var(--bg-card); border: 1px solid var(--border); border-radius: 15px; padding: 25px; max-width: 400px; width: 100%; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-close { background: none; border: none; color: var(--text-dim); font-size: 1.5rem; cursor: pointer; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
</style>

<script>
function rejectWithdrawal(id) {
    document.getElementById('rejectWithdrawalId').value = id;
    document.getElementById('rejectModal').classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

async function approveWithdrawalZettpay(id, amount) {
    if (!confirm('Aprovar saque #' + id + ' de R$ ' + amount.toFixed(2).replace('.', ',') + ' via PIX ZettPay?')) {
        return;
    }

    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

    try {
        const response = await fetch('../api/admin-ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'approve_withdrawal_zettpay',
                id: id
            })
        });

        const data = await response.json();

        if (data.success) {
            alert(data.message || 'Saque enviado para processamento PIX!');
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Falha ao processar saque'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (error) {
        alert('Erro de conexão: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

async function approveWithdrawalManual(id, amount) {
    if (!confirm('Aprovar manualmente o saque #' + id + ' de R$ ' + amount.toFixed(2).replace('.', ',') + '?\n\nUse esta opção se o pagamento já foi feito por fora.')) {
        return;
    }

    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const response = await fetch('../api/admin-ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'approve_withdrawal', id: id })
        });

        const data = await response.json();

        if (data.success) {
            alert(data.message || 'Saque aprovado com sucesso!');
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Falha ao aprovar saque'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (error) {
        alert('Erro de conexão: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

async function checkCashoutStatus(id) {
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Consultando...';

    try {
        const response = await fetch('../api/admin-ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check_cashout_status', id: id })
        });

        const data = await response.json();

        if (data.success) {
            alert(data.message);
            if (data.status === 'completed' || data.status === 'rejected') {
                location.reload();
            } else {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        } else {
            alert('Erro: ' + (data.error || 'Falha ao consultar status'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (error) {
        alert('Erro de conexão: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

async function forceCompleteWithdrawal(id, amount) {
    if (!confirm('Confirmar manualmente o saque #' + id + ' de R$ ' + amount.toFixed(2).replace('.', ',') + ' como PAGO?\n\nUse esta opção quando o processamento travou mas o pagamento já foi realizado ou será feito manualmente.')) {
        return;
    }

    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const response = await fetch('../api/admin-ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'force_complete_withdrawal', id: id })
        });

        const data = await response.json();

        if (data.success) {
            alert(data.message || 'Saque confirmado como pago!');
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Falha ao confirmar saque'));
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (error) {
        alert('Erro de conexão: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('Copiado!');
    });
}
</script>
