<?php
// ============================================
// CRYPTO ASTEROID RUSH - Admin Referrals
// Arquivo: admin/pages/referrals.php
// ============================================

$pageTitle = 'Sistema de Afiliados';

// Inicializar variáveis
$totalReferrals = 0;
$pendingReferrals = 0;
$completedReferrals = 0;
$claimedReferrals = 0;
$cancelledReferrals = 0;
$totalCommissionsPaid = 0;
$totalCommissionsPending = 0;
$referralsList = [];
$error = null;
$successMessage = null;

// Processar ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($pdo)) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $referralId = isset($_POST['referral_id']) ? (int)$_POST['referral_id'] : 0;
    
    try {
        switch ($action) {
            case 'cancel':
                $reason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : 'Cancelado pelo admin';
                $stmt = $pdo->prepare("
                    UPDATE referrals 
                    SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ?
                    WHERE id = ? AND status IN ('pending', 'completed')
                ");
                $stmt->execute([$reason, $referralId]);
                if ($stmt->rowCount() > 0) {
                    $successMessage = "Referral #{$referralId} cancelado com sucesso.";
                }
                break;
                
            case 'complete':
                $stmt = $pdo->prepare("
                    UPDATE referrals 
                    SET status = 'completed', completed_at = NOW(), missions_completed = missions_required
                    WHERE id = ? AND status = 'pending'
                ");
                $stmt->execute([$referralId]);
                if ($stmt->rowCount() > 0) {
                    $successMessage = "Referral #{$referralId} marcado como completado.";
                }
                break;
                
            case 'reactivate':
                $stmt = $pdo->prepare("
                    UPDATE referrals 
                    SET status = 'pending', cancelled_at = NULL, cancel_reason = NULL
                    WHERE id = ? AND status = 'cancelled'
                ");
                $stmt->execute([$referralId]);
                if ($stmt->rowCount() > 0) {
                    $successMessage = "Referral #{$referralId} reativado.";
                }
                break;
        }
    } catch (Exception $e) {
        $error = "Erro ao processar ação: " . $e->getMessage();
    }
}

// Filtros
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterSearch = isset($_GET['search']) ? trim($_GET['search']) : '';

// Verificar se PDO existe
if (!isset($pdo)) {
    $error = "Conexão com banco de dados não disponível.";
} else {
    try {
        // Verificar se tabelas existem
        $tableExists = $pdo->query("SHOW TABLES LIKE 'referrals'")->fetch();
        
        if (!$tableExists) {
            $error = "Tabela 'referrals' não existe. Execute o script SQL para criar as tabelas.";
        } else {
            // Estatísticas gerais
            $result = $pdo->query("SELECT COUNT(*) FROM referrals");
            $totalReferrals = $result ? (int)$result->fetchColumn() : 0;
            
            $result = $pdo->query("SELECT COUNT(*) FROM referrals WHERE status = 'pending'");
            $pendingReferrals = $result ? (int)$result->fetchColumn() : 0;
            
            $result = $pdo->query("SELECT COUNT(*) FROM referrals WHERE status = 'completed'");
            $completedReferrals = $result ? (int)$result->fetchColumn() : 0;
            
            $result = $pdo->query("SELECT COUNT(*) FROM referrals WHERE status = 'claimed'");
            $claimedReferrals = $result ? (int)$result->fetchColumn() : 0;
            
            $result = $pdo->query("SELECT COUNT(*) FROM referrals WHERE status = 'cancelled'");
            $cancelledReferrals = $result ? (int)$result->fetchColumn() : 0;
            
            $result = $pdo->query("SELECT COALESCE(SUM(commission_amount), 0) FROM referrals WHERE status = 'claimed'");
            $totalCommissionsPaid = $result ? (float)$result->fetchColumn() : 0;
            
            $result = $pdo->query("SELECT COALESCE(SUM(commission_amount), 0) FROM referrals WHERE status = 'completed'");
            $totalCommissionsPending = $result ? (float)$result->fetchColumn() : 0;
            
            // Buscar lista de referrals com filtros
            $whereConditions = [];
            $params = [];
            
            if ($filterStatus && in_array($filterStatus, ['pending', 'completed', 'claimed', 'cancelled'])) {
                $whereConditions[] = "r.status = ?";
                $params[] = $filterStatus;
            }
            
            if ($filterSearch) {
                $whereConditions[] = "(r.referrer_wallet LIKE ? OR r.referred_wallet LIKE ? OR r.referral_code LIKE ?)";
                $searchParam = '%' . $filterSearch . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $sql = "
                SELECT 
                    r.*,
                    (SELECT total_played FROM players WHERE wallet_address = r.referred_wallet) as referred_total_played
                FROM referrals r
                {$whereClause}
                ORDER BY r.created_at DESC
                LIMIT 100
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $referralsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (Exception $e) {
        $error = "Erro ao carregar dados: " . $e->getMessage();
    }
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-users"></i> Sistema de Afiliados</h1>
        <p class="page-subtitle">Gerencie indicações e comissões</p>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?>
        </div>
    <?php endif; ?>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo number_format($totalReferrals); ?></div>
            <div class="label">Total de Indicações</div>
        </div>
        
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-clock"></i></div>
            <div class="value"><?php echo number_format($pendingReferrals); ?></div>
            <div class="label">Pendentes</div>
            <div class="change">Jogando missões</div>
        </div>
        
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-check-circle"></i></div>
            <div class="value"><?php echo number_format($completedReferrals); ?></div>
            <div class="label">Aguardando Resgate</div>
            <div class="change">$<?php echo number_format($totalCommissionsPending, 2); ?></div>
        </div>
        
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="value">$<?php echo number_format($totalCommissionsPaid, 2); ?></div>
            <div class="label">Comissões Pagas</div>
            <div class="change"><?php echo $claimedReferrals; ?> resgatadas</div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-filter"></i> Filtros</h3>
        </div>
        <div class="panel-body">
            <form method="GET" action="" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                <input type="hidden" name="page" value="referrals">
                
                <div class="form-group" style="margin: 0;">
                    <select name="status" class="form-control" style="min-width: 150px;">
                        <option value="">Todos os Status</option>
                        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completado</option>
                        <option value="claimed" <?php echo $filterStatus === 'claimed' ? 'selected' : ''; ?>>Resgatado</option>
                        <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelado</option>
                    </select>
                </div>
                
                <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;">
                    <input type="text" name="search" class="form-control" placeholder="Buscar por wallet ou código..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filtrar
                </button>
                
                <?php if ($filterStatus || $filterSearch): ?>
                    <a href="index.php?page=referrals" class="btn btn-outline">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <!-- Tabela de Referrals -->
    <div class="panel" style="margin-top: 20px;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Lista de Indicações</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($referralsList)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-friends"></i>
                    <h3>Nenhuma indicação encontrada</h3>
                    <p>As indicações aparecerão aqui quando usuários usarem links de referral.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Indicador</th>
                                <th>Indicado</th>
                                <th>Código</th>
                                <th>Progresso</th>
                                <th>Status</th>
                                <th>Comissão</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($referralsList as $ref): ?>
                            <tr>
                                <td>#<?php echo $ref['id']; ?></td>
                                <td>
                                    <span class="wallet-addr" onclick="copyToClipboard('<?php echo $ref['referrer_wallet']; ?>')" title="<?php echo $ref['referrer_wallet']; ?>">
                                        <?php echo substr($ref['referrer_wallet'], 0, 6) . '...' . substr($ref['referrer_wallet'], -4); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="wallet-addr" onclick="copyToClipboard('<?php echo $ref['referred_wallet']; ?>')" title="<?php echo $ref['referred_wallet']; ?>">
                                        <?php echo substr($ref['referred_wallet'], 0, 6) . '...' . substr($ref['referred_wallet'], -4); ?>
                                    </span>
                                </td>
                                <td><code style="color: var(--primary);"><?php echo $ref['referral_code']; ?></code></td>
                                <td>
                                    <?php 
                                    $progress = min(100, round(($ref['missions_completed'] / $ref['missions_required']) * 100));
                                    $progressColor = $progress >= 100 ? 'var(--success)' : 'var(--primary)';
                                    ?>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="flex: 1; height: 6px; background: var(--card-bg); border-radius: 3px; overflow: hidden;">
                                            <div style="width: <?php echo $progress; ?>%; height: 100%; background: <?php echo $progressColor; ?>; border-radius: 3px;"></div>
                                        </div>
                                        <span style="font-size: 0.8rem; color: var(--text-dim);">
                                            <?php echo $ref['missions_completed']; ?>/<?php echo $ref['missions_required']; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = [
                                        'pending' => 'warning',
                                        'completed' => 'success',
                                        'claimed' => 'primary',
                                        'cancelled' => 'danger'
                                    ];
                                    $statusText = [
                                        'pending' => 'Pendente',
                                        'completed' => 'Completado',
                                        'claimed' => 'Resgatado',
                                        'cancelled' => 'Cancelado'
                                    ];
                                    $class = isset($statusClass[$ref['status']]) ? $statusClass[$ref['status']] : 'primary';
                                    $text = isset($statusText[$ref['status']]) ? $statusText[$ref['status']] : $ref['status'];
                                    ?>
                                    <span class="badge badge-<?php echo $class; ?>"><?php echo $text; ?></span>
                                    <?php if ($ref['status'] === 'cancelled' && $ref['cancel_reason']): ?>
                                        <br><small style="color: var(--text-dim);" title="<?php echo htmlspecialchars($ref['cancel_reason']); ?>">
                                            <?php echo substr($ref['cancel_reason'], 0, 20); ?>...
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--success); font-weight: 600;">
                                    $<?php echo number_format($ref['commission_amount'], 2); ?>
                                </td>
                                <td style="color: var(--text-dim); font-size: 0.85rem;">
                                    <?php echo date('d/m/Y H:i', strtotime($ref['created_at'])); ?>
                                    <?php if ($ref['completed_at']): ?>
                                        <br><small style="color: var(--success);">✓ <?php echo date('d/m H:i', strtotime($ref['completed_at'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px;">
                                        <?php if ($ref['status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-success" onclick="confirmComplete(<?php echo $ref['id']; ?>)" title="Marcar como completado">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="showCancelModal(<?php echo $ref['id']; ?>)" title="Cancelar">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php elseif ($ref['status'] === 'completed'): ?>
                                            <button class="btn btn-sm btn-danger" onclick="showCancelModal(<?php echo $ref['id']; ?>)" title="Cancelar">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php elseif ($ref['status'] === 'cancelled'): ?>
                                            <button class="btn btn-sm btn-warning" onclick="confirmReactivate(<?php echo $ref['id']; ?>)" title="Reativar">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        <?php else: ?>
                                            <span style="color: var(--text-dim); font-size: 0.8rem;">-</span>
                                        <?php endif; ?>
                                    </div>
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

<!-- Modal de Cancelamento -->
<div id="cancelModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 15px; padding: 30px; max-width: 400px; width: 90%;">
        <h3 style="color: var(--danger); margin-bottom: 20px;"><i class="fas fa-exclamation-triangle"></i> Cancelar Referral</h3>
        <form method="POST" id="cancelForm">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="referral_id" id="cancelReferralId">
            
            <div class="form-group">
                <label>Motivo do cancelamento:</label>
                <textarea name="cancel_reason" class="form-control" rows="3" placeholder="Ex: Fraude detectada, conta duplicada, etc."></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" class="btn btn-outline" onclick="closeCancelModal()" style="flex: 1;">Voltar</button>
                <button type="submit" class="btn btn-danger" style="flex: 1;"><i class="fas fa-times"></i> Cancelar Referral</button>
            </div>
        </form>
    </div>
</div>

<!-- Forms ocultos para ações -->
<form id="completeForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="complete">
    <input type="hidden" name="referral_id" id="completeReferralId">
</form>

<form id="reactivateForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="reactivate">
    <input type="hidden" name="referral_id" id="reactivateReferralId">
</form>

<script>
function showCancelModal(id) {
    document.getElementById('cancelReferralId').value = id;
    document.getElementById('cancelModal').style.display = 'flex';
}

function closeCancelModal() {
    document.getElementById('cancelModal').style.display = 'none';
}

function confirmComplete(id) {
    if (confirm('Tem certeza que deseja marcar este referral como COMPLETADO?\n\nIsso irá liberar a comissão de $1.00 para o indicador.')) {
        document.getElementById('completeReferralId').value = id;
        document.getElementById('completeForm').submit();
    }
}

function confirmReactivate(id) {
    if (confirm('Tem certeza que deseja REATIVAR este referral?\n\nO status voltará para "pendente".')) {
        document.getElementById('reactivateReferralId').value = id;
        document.getElementById('reactivateForm').submit();
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        // Feedback visual pode ser adicionado aqui
    });
}

// Fechar modal ao clicar fora
document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCancelModal();
    }
});
</script>

<style>
.btn-sm {
    padding: 5px 10px;
    font-size: 0.8rem;
}
.wallet-addr {
    cursor: pointer;
    font-family: monospace;
    color: var(--primary);
}
.wallet-addr:hover {
    text-decoration: underline;
}
</style>
