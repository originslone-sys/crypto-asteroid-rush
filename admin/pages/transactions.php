<?php
// ============================================
// UNOBIX - Transações
// Arquivo: admin/pages/transactions.php
// v8.0 - Edição manual de status
// ============================================

$pageTitle = 'Transações';
$typeFilter = $_GET['type'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// ============================================
// AÇÕES POST - Editar status da transação
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'update_status') {
            $txId = (int)($_POST['tx_id'] ?? 0);
            $newStatus = trim($_POST['new_status'] ?? '');
            $validStatuses = ['pending', 'completed', 'failed'];

            if (!$txId || !in_array($newStatus, $validStatuses)) {
                $actionError = 'Parâmetros inválidos';
            } else {
                // Buscar transação atual
                $stmt = $pdo->prepare("SELECT t.*, p.id as user_db_id FROM transactions t LEFT JOIN users p ON t.google_uid = p.google_uid WHERE t.id = ?");
                $stmt->execute([$txId]);
                $tx = $stmt->fetch();

                if (!$tx) {
                    $actionError = 'Transação não encontrada';
                } elseif ($tx['status'] === $newStatus) {
                    $actionError = 'Status já é ' . $newStatus;
                } else {
                    $oldStatus = $tx['status'];

                    // Se é compra de créditos sendo confirmada manualmente
                    if ($tx['type'] === 'credit_purchase' && $newStatus === 'completed' && $oldStatus === 'pending') {
                        $pdo->beginTransaction();
                        try {
                            // Atualizar status da transação
                            $stmt = $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?");
                            $stmt->execute([$txId]);

                            // Buscar compra de créditos pelo external_id na descrição
                            $externalId = null;
                            if (preg_match('/\[([^\]]+)\]/', $tx['description'] ?? '', $matches)) {
                                $externalId = $matches[1];
                            }

                            if ($externalId && $tx['user_db_id']) {
                                // Verificar se credit_purchase existe e está pendente
                                $stmt = $pdo->prepare("SELECT * FROM credit_purchases WHERE external_id = ? AND status = 'pending' LIMIT 1");
                                $stmt->execute([$externalId]);
                                $purchase = $stmt->fetch();

                                if ($purchase) {
                                    $creditsToAdd = (int)$purchase['credits_amount'];

                                    // Creditar créditos ao usuário
                                    $stmt = $pdo->prepare("UPDATE users SET credits = credits + ?, updated_at = NOW() WHERE id = ?");
                                    $stmt->execute([$creditsToAdd, $tx['user_db_id']]);

                                    // Marcar compra como confirmada
                                    $stmt = $pdo->prepare("UPDATE credit_purchases SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
                                    $stmt->execute([$purchase['id']]);

                                    $actionSuccess = "Compra confirmada! {$creditsToAdd} créditos adicionados ao jogador.";
                                } else {
                                    $actionSuccess = "Status atualizado para completed. (Compra de créditos não encontrada ou já confirmada)";
                                }
                            } else {
                                $actionSuccess = "Status atualizado para completed.";
                            }

                            // Atualizar zettpay_transactions se existir
                            if ($externalId) {
                                try {
                                    $stmt = $pdo->prepare("UPDATE zettpay_transactions SET status = 'confirmed', confirmed_at = NOW() WHERE external_id = ? AND status = 'pending'");
                                    $stmt->execute([$externalId]);
                                } catch (Exception $e) {}
                            }

                            $pdo->commit();
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            throw $e;
                        }
                    } else {
                        // Atualização simples de status
                        $stmt = $pdo->prepare("UPDATE transactions SET status = ? WHERE id = ?");
                        $stmt->execute([$newStatus, $txId]);
                        $actionSuccess = "Status alterado de {$oldStatus} para {$newStatus}.";
                    }
                }
            }
        }
        if ($_POST['action'] === 'reconcile_deposits') {
            require_once __DIR__ . '/../../api/zettpay-client.php';
            ensureZettpayTable($pdo);

            // Função para extrair dados da transação de diferentes formatos de resposta
            function extractTransactionDataAdmin($responseData) {
                if (!is_array($responseData)) return null;
                if (isset($responseData['status'])) return $responseData;
                if (isset($responseData['data']) && is_array($responseData['data'])) {
                    $inner = $responseData['data'];
                    if (isset($inner['status'])) return $inner;
                    if (isset($inner[0]) && is_array($inner[0]) && isset($inner[0]['status'])) return $inner[0];
                }
                if (isset($responseData['transaction']) && is_array($responseData['transaction'])) return $responseData['transaction'];
                return null;
            }

            $stmt = $pdo->prepare("
                SELECT id, external_id, user_id, amount_brl, created_at
                FROM zettpay_transactions
                WHERE type = 'deposit' AND status = 'pending'
                  AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                  AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY created_at ASC LIMIT 20
            ");
            $stmt->execute();
            $pendingDeposits = $stmt->fetchAll();

            $confirmed = 0;
            $expired = 0;

            foreach ($pendingDeposits as $ptx) {
                try {
                    $apiResult = zettpayLookupDeposit($ptx['external_id']);
                    if (!$apiResult['success'] || empty($apiResult['data'])) continue;

                    $apiData = extractTransactionDataAdmin($apiResult['data']);
                    if (!$apiData) continue;
                    $apiStatus = strtolower($apiData['status'] ?? '');
                    $zettpayId = $apiData['provider_transaction_id'] ?? $apiData['id'] ?? '';

                    if (in_array($apiStatus, ['paid', 'completed', 'approved'])) {
                        // Confirmar depósito
                        $pdo->beginTransaction();
                        $lockStmt = $pdo->prepare("SELECT * FROM zettpay_transactions WHERE id = ? FOR UPDATE");
                        $lockStmt->execute([$ptx['id']]);
                        $locked = $lockStmt->fetch();
                        if ($locked['status'] === 'confirmed') { $pdo->rollBack(); continue; }

                        $userStmt = $pdo->prepare("SELECT id, google_uid, balance_brl FROM users WHERE id = ? FOR UPDATE");
                        $userStmt->execute([$ptx['user_id']]);
                        $usr = $userStmt->fetch();
                        if (!$usr) { $pdo->rollBack(); continue; }

                        $amt = (float)$ptx['amount_brl'];
                        $isCrd = strpos($ptx['external_id'], 'CRD-') === 0;

                        if ($isCrd) {
                            $cpStmt = $pdo->prepare("SELECT * FROM credit_purchases WHERE external_id = ? AND status = 'pending' LIMIT 1");
                            $cpStmt->execute([$ptx['external_id']]);
                            $cp = $cpStmt->fetch();
                            if ($cp) {
                                $pdo->prepare("UPDATE users SET credits = credits + ?, updated_at = NOW() WHERE id = ?")->execute([(int)$cp['credits_amount'], $usr['id']]);
                                $pdo->prepare("UPDATE credit_purchases SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?")->execute([$cp['id']]);
                            }
                        } else {
                            $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?, total_earned_brl = total_earned_brl + ?, updated_at = NOW() WHERE id = ?")->execute([$amt, $amt, $usr['id']]);
                        }

                        $pdo->prepare("UPDATE zettpay_transactions SET status = 'confirmed', zettpay_id = ?, confirmed_at = NOW() WHERE id = ?")->execute([$zettpayId, $ptx['id']]);
                        $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE google_uid = ? AND description LIKE ? AND status = 'pending' LIMIT 1")->execute([$usr['google_uid'], '%' . $ptx['external_id'] . '%']);
                        $pdo->commit();
                        $confirmed++;
                        secureLog("ADMIN_RECONCILE_CONFIRMED | external_id: {$ptx['external_id']} | user_id: {$usr['id']} | amount: R\${$amt}");

                    } elseif (in_array($apiStatus, ['expired', 'failed', 'cancelled', 'canceled'])) {
                        $fs = in_array($apiStatus, ['cancelled', 'canceled']) ? 'expired' : $apiStatus;
                        $pdo->prepare("UPDATE zettpay_transactions SET status = ?, error_message = ? WHERE id = ? AND status = 'pending'")->execute([$fs, "Reconciliação admin: {$apiStatus}", $ptx['id']]);
                        $userStmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
                        $userStmt->execute([$ptx['user_id']]);
                        $usr = $userStmt->fetch();
                        if ($usr) {
                            $pdo->prepare("UPDATE transactions SET status = 'failed' WHERE google_uid = ? AND description LIKE ? AND status = 'pending' LIMIT 1")->execute([$usr['google_uid'], '%' . $ptx['external_id'] . '%']);
                        }
                        $expired++;
                    }

                    usleep(300000);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    secureLog("ADMIN_RECONCILE_ERROR | external_id: {$ptx['external_id']} | " . $e->getMessage());
                }
            }

            $total = count($pendingDeposits);
            $actionSuccess = "Reconciliação concluída! Verificados: {$total} | Confirmados: {$confirmed} | Expirados: {$expired}";
            secureLog("ADMIN_RECONCILE_DONE | checked: {$total} | confirmed: {$confirmed} | expired: {$expired}");
        }
    } catch (Exception $e) {
        $actionError = $e->getMessage();
    }
}

try {
    $baseSql = "FROM transactions t
            LEFT JOIN users p ON t.google_uid = p.google_uid WHERE 1=1";
    $params = [];
    $filterSql = '';

    if ($typeFilter !== 'all') {
        $filterSql .= " AND t.type = ?";
        $params[] = $typeFilter;
    }
    if ($statusFilter !== 'all') {
        $filterSql .= " AND t.status = ?";
        $params[] = $statusFilter;
    }
    if ($search) {
        $filterSql .= " AND (t.google_uid LIKE ? OR p.display_name LIKE ? OR t.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Contagem para paginação
    $stmtCount = $pdo->prepare("SELECT COUNT(*) " . $baseSql . $filterSql);
    $stmtCount->execute($params);
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));

    $sql = "SELECT t.*, p.display_name " . $baseSql . $filterSql;
    $sql .= " ORDER BY t.created_at DESC LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    $stats = $pdo->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN amount_brl > 0 THEN amount_brl ELSE 0 END) as total_credit,
            SUM(CASE WHEN amount_brl < 0 THEN ABS(amount_brl) ELSE 0 END) as total_debit
        FROM transactions WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch();
} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php

// Mapeamento completo de tipos de transação
$typeLabels = [
    'game_reward'          => ['Ganho Jogo', 'success'],
    'game_earning'         => ['Ganho Jogo', 'success'],
    'withdraw'             => ['Saque', 'danger'],
    'withdrawal'           => ['Saque', 'danger'],
    'withdraw_reject'      => ['Saque Devolvido', 'warning'],
    'deposit'              => ['Depósito', 'success'],
    'credit_purchase'      => ['Compra Créditos', 'primary'],
    'stake'                => ['Stake', 'primary'],
    'unstake'              => ['Unstake', 'info'],
    'stake_reward'         => ['Rendimento', 'success'],
    'referral_bonus'       => ['Bônus Indicação', 'success'],
    'referral_commission'  => ['Comissão Indicação', 'success'],
    'welcome_bonus'        => ['Bônus Boas-vindas', 'success'],
    'admin_adjust'         => ['Ajuste Admin', 'warning'],
    'exploration_rent'     => ['Aluguel Nave', 'info'],
    'exploration_credits'  => ['Créditos Exploração', 'success'],
];

// Tipos que são saída de dinheiro (devem aparecer como negativo)
$debitTypes = ['withdraw', 'withdrawal', 'stake'];
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-exchange-alt"></i> Transações</h1>
    </div>

    <?php if (isset($actionSuccess)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($actionSuccess); ?></div>
    <?php endif; ?>
    <?php if (isset($actionError)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($actionError); ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-list"></i></div>
            <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="label">Total (30d)</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-arrow-down"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_credit']); ?></div>
            <div class="label">Entradas</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-arrow-up"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_debit']); ?></div>
            <div class="label">Saídas</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="transactions">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar jogador ou descrição..." class="form-control" style="width: 250px;">
                <select name="type" class="form-control">
                    <option value="all">Todos</option>
                    <option value="game_reward" <?php echo $typeFilter === 'game_reward' ? 'selected' : ''; ?>>Ganhos Jogo</option>
                    <option value="withdraw" <?php echo $typeFilter === 'withdraw' ? 'selected' : ''; ?>>Saques</option>
                    <option value="deposit" <?php echo $typeFilter === 'deposit' ? 'selected' : ''; ?>>Depósitos</option>
                    <option value="credit_purchase" <?php echo $typeFilter === 'credit_purchase' ? 'selected' : ''; ?>>Compra Créditos</option>
                    <option value="referral_commission" <?php echo $typeFilter === 'referral_commission' ? 'selected' : ''; ?>>Indicações</option>
                    <option value="withdraw_reject" <?php echo $typeFilter === 'withdraw_reject' ? 'selected' : ''; ?>>Saques Devolvidos</option>
                    <option value="admin_adjust" <?php echo $typeFilter === 'admin_adjust' ? 'selected' : ''; ?>>Ajustes Admin</option>
                    <option value="exploration_rent" <?php echo $typeFilter === 'exploration_rent' ? 'selected' : ''; ?>>Aluguel Nave</option>
                    <option value="exploration_credits" <?php echo $typeFilter === 'exploration_credits' ? 'selected' : ''; ?>>Créditos Exploração</option>
                    <option value="stake" <?php echo $typeFilter === 'stake' ? 'selected' : ''; ?>>Stakes</option>
                    <option value="unstake" <?php echo $typeFilter === 'unstake' ? 'selected' : ''; ?>>Unstakes</option>
                </select>
                <select name="status" class="form-control">
                    <option value="all">Todos Status</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pendentes</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completadas</option>
                    <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Falhadas</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </form>
            <form method="POST" style="margin-left:auto;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Verificando...';">
                <input type="hidden" name="action" value="reconcile_deposits">
                <button type="submit" class="btn btn-warning btn-sm" title="Verifica depósitos pendentes na API ZettPay e confirma os que já foram pagos">
                    <i class="fas fa-sync-alt"></i> Reconciliar Depósitos
                </button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-body">
            <?php if (empty($transactions)): ?>
                <div class="empty-state"><i class="fas fa-exchange-alt"></i><h3>Nenhuma transação</h3></div>
            <?php else: ?>
            <div class="table-container">
                <table class="table-compact">
                    <thead>
                        <tr><th>ID</th><th>Jogador</th><th>Tipo</th><th>Valor</th><th>Descrição</th><th>Status</th><th>Data</th><th>Ações</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transactions as $t):
                        $label = $typeLabels[$t['type']] ?? [$t['type'], 'primary'];
                        $amountBrl = (float)($t['amount_brl'] ?? 0);
                        // Corrigir exibição: saques que foram armazenados positivos por engano
                        if (in_array($t['type'], $debitTypes) && $amountBrl > 0) {
                            $amountBrl = -$amountBrl;
                        }
                        $isPositive = $amountBrl >= 0;
                        $statusClass = $t['status'] === 'completed' ? 'success' : ($t['status'] === 'failed' ? 'danger' : 'warning');
                    ?>
                    <tr>
                        <td style="color: var(--text-dim);">#<?php echo $t['id']; ?></td>
                        <td><?php echo htmlspecialchars($t['display_name'] ?? 'Usuário'); ?></td>
                        <td><span class="badge badge-<?php echo $label[1]; ?>"><?php echo $label[0]; ?></span></td>
                        <td style="color: <?php echo $isPositive ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: 600; white-space: nowrap;">
                            <?php echo $isPositive ? '+' : ''; ?><?php echo formatBRL($amountBrl); ?>
                        </td>
                        <td style="color: var(--text-dim); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($t['description'] ?? '-'); ?></td>
                        <td><span class="badge badge-<?php echo $statusClass; ?>"><?php echo $t['status']; ?></span></td>
                        <td style="white-space: nowrap; color: var(--text-dim);"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                        <td>
                            <button class="btn btn-outline btn-sm btn-edit-status"
                                data-id="<?php echo $t['id']; ?>"
                                data-status="<?php echo htmlspecialchars($t['status']); ?>"
                                data-type="<?php echo htmlspecialchars($t['type']); ?>"
                                data-player="<?php echo htmlspecialchars($t['display_name'] ?? 'Usuário'); ?>"
                                title="Editar status">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <?php
                    $queryParams = $_GET;
                    unset($queryParams['p']);
                    $baseUrl = '?' . http_build_query($queryParams);
                ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl . '&p=' . ($page - 1); ?>" class="btn btn-outline btn-sm">&laquo; Anterior</a>
                    <?php endif; ?>

                    <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        if ($start > 1) echo '<span class="pagination-dots">...</span>';
                        for ($i = $start; $i <= $end; $i++):
                    ?>
                        <a href="<?php echo $baseUrl . '&p=' . $i; ?>" class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $i; ?></a>
                    <?php
                        endfor;
                        if ($end < $totalPages) echo '<span class="pagination-dots">...</span>';
                    ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl . '&p=' . ($page + 1); ?>" class="btn btn-outline btn-sm">Próxima &raquo;</a>
                    <?php endif; ?>

                    <span style="color: var(--text-dim); font-size: 0.8rem; margin-left: 10px;">
                        <?php echo number_format($totalRows); ?> transações | Página <?php echo $page; ?>/<?php echo $totalPages; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Editar Status -->
<div class="modal-overlay" id="modalEditStatus" style="display:none;">
    <div class="modal-content" style="max-width: 420px;">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Editar Status</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="formEditStatus">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="tx_id" id="editTxId">
            <div class="modal-body">
                <p style="margin-bottom: 12px; color: var(--text-dim);">
                    Transação <strong id="editTxLabel"></strong> - <span id="editTxPlayer"></span>
                </p>
                <div id="editCreditWarning" style="display:none; background: rgba(255,193,7,0.15); border: 1px solid rgba(255,193,7,0.3); border-radius: 8px; padding: 10px; margin-bottom: 12px;">
                    <small style="color: #ffc107;"><i class="fas fa-exclamation-triangle"></i> Compra de créditos: ao confirmar, os créditos serão adicionados automaticamente ao jogador.</small>
                </div>
                <label style="display:block; margin-bottom: 6px; font-weight: 600;">Novo Status</label>
                <select name="new_status" id="editNewStatus" class="form-control" style="width: 100%;">
                    <option value="pending">Pendente</option>
                    <option value="completed">Completado</option>
                    <option value="failed">Falhou</option>
                </select>
            </div>
            <div class="modal-footer" style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 15px; border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeEditModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<style>
.table-compact th,
.table-compact td {
    padding: 8px 10px;
    font-size: 0.85rem;
}
.pagination {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid var(--border-color);
    flex-wrap: wrap;
}
.pagination .btn { min-width: 36px; text-align: center; text-decoration: none; }
.pagination-dots { color: var(--text-dim); padding: 0 4px; }
.btn-edit-status {
    padding: 4px 8px;
    font-size: 0.8rem;
}
.modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.6); z-index: 9999;
    display: flex; align-items: center; justify-content: center;
}
.modal-content {
    background: var(--bg-card, #1a1a2e); border-radius: 12px; padding: 20px;
    width: 90%; border: 1px solid var(--border-color);
}
.modal-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;
}
.modal-header h3 { margin: 0; font-size: 1.1rem; }
.modal-close {
    background: none; border: none; color: var(--text-dim); font-size: 1.5rem; cursor: pointer;
}
.modal-close:hover { color: var(--text-color); }
</style>

<script>
document.querySelectorAll('.btn-edit-status').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const status = this.dataset.status;
        const type = this.dataset.type;
        const player = this.dataset.player;

        document.getElementById('editTxId').value = id;
        document.getElementById('editTxLabel').textContent = '#' + id;
        document.getElementById('editTxPlayer').textContent = player;
        document.getElementById('editNewStatus').value = status;

        // Mostrar aviso se for compra de créditos
        const warning = document.getElementById('editCreditWarning');
        warning.style.display = (type === 'credit_purchase') ? 'block' : 'none';

        document.getElementById('modalEditStatus').style.display = 'flex';
    });
});

function closeEditModal() {
    document.getElementById('modalEditStatus').style.display = 'none';
}

document.getElementById('modalEditStatus').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

document.getElementById('formEditStatus').addEventListener('submit', function(e) {
    const status = document.getElementById('editNewStatus').value;
    if (status === 'completed') {
        if (!confirm('Confirma alterar o status para COMPLETADO?')) {
            e.preventDefault();
        }
    }
});
</script>
