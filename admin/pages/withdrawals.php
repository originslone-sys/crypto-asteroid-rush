<?php
// ============================================
// UNOBIX - Gerenciamento de Saques
// Arquivo: admin/pages/withdrawals.php
// ============================================

$pageTitle = 'Saques';
$statusFilter = $_GET['status'] ?? 'pending';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'approved', processed_at = NOW(), processed_by = 'admin' WHERE id = ?");
            $stmt->execute([(int)$_POST['withdrawal_id']]);
            $message = "Saque aprovado!";
        } elseif ($action === 'reject') {
            // Devolver saldo ao jogador
            $stmt = $pdo->prepare("SELECT google_uid, amount_brl FROM withdrawals WHERE id = ?");
            $stmt->execute([(int)$_POST['withdrawal_id']]);
            $w = $stmt->fetch();
            if ($w) {
                $pdo->prepare("UPDATE players SET balance_brl = balance_brl + ? WHERE google_uid = ?")
                    ->execute([$w['amount_brl'], $w['google_uid']]);
                $pdo->prepare("UPDATE withdrawals SET status = 'rejected', processed_at = NOW(), reject_reason = ? WHERE id = ?")
                    ->execute([$_POST['reason'] ?? 'Rejeitado pelo admin', (int)$_POST['withdrawal_id']]);
            }
            $message = "Saque rejeitado e saldo devolvido!";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

try {
    $sql = "SELECT w.*, p.display_name, p.email, p.balance_brl, p.total_played, p.is_banned
            FROM withdrawals w 
            LEFT JOIN players p ON w.google_uid = p.google_uid";
    
    if ($statusFilter !== 'all') {
        $sql .= " WHERE w.status = :status";
    }
    $sql .= " ORDER BY w.created_at DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    if ($statusFilter !== 'all') {
        $stmt->execute(['status' => $statusFilter]);
    } else {
        $stmt->execute();
    }
    $withdrawals = $stmt->fetchAll();
    
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'pending' THEN amount_brl ELSE 0 END) as pending_amount,
            SUM(CASE WHEN status = 'approved' THEN amount_brl ELSE 0 END) as approved_amount
        FROM withdrawals
    ")->fetch();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

function formatBRL($value) {
    return 'R$ ' . number_format($value ?? 0, 2, ',', '.');
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-money-bill-wave"></i> Gerenciamento de Saques</h1>
        <p class="page-subtitle">Aprovar, rejeitar e acompanhar solicitações</p>
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
            <div class="icon success"><i class="fas fa-check"></i></div>
            <div class="value"><?php echo $stats['approved'] ?? 0; ?></div>
            <div class="label">Aprovados</div>
            <div class="change"><?php echo formatBRL($stats['approved_amount']); ?></div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-times"></i></div>
            <div class="value"><?php echo $stats['rejected'] ?? 0; ?></div>
            <div class="label">Rejeitados</div>
        </div>
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-list"></i></div>
            <div class="value"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="label">Total</div>
        </div>
    </div>
    
    <div class="panel">
        <div class="panel-body">
            <div class="tabs">
                <a href="?page=withdrawals&status=pending" class="tab <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Pendentes (<?php echo $stats['pending'] ?? 0; ?>)
                </a>
                <a href="?page=withdrawals&status=approved" class="tab <?php echo $statusFilter === 'approved' ? 'active' : ''; ?>">
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
    
    <div class="panel">
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
                            <td><span class="badge badge-primary"><?php echo strtoupper($w['payment_method'] ?? 'PIX'); ?></span></td>
                            <td style="max-width: 200px; overflow: hidden;">
                                <small><?php echo htmlspecialchars($w['payment_details'] ?? '-'); ?></small>
                            </td>
                            <td><?php echo formatBRL($w['balance_brl']); ?></td>
                            <td>
                                <?php
                                $statusClass = ['approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger'][$w['status']] ?? 'primary';
                                $statusText = ['approved' => 'Aprovado', 'pending' => 'Pendente', 'rejected' => 'Rejeitado'][$w['status']] ?? $w['status'];
                                ?>
                                <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($w['created_at'])); ?></td>
                            <td>
                                <?php if ($w['status'] === 'pending'): ?>
                                <div class="btn-group">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="withdrawal_id" value="<?php echo $w['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Aprovar saque de <?php echo formatBRL($w['amount_brl']); ?>?')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <button onclick="rejectWithdrawal(<?php echo $w['id']; ?>)" class="btn btn-danger btn-sm">
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
</script>
