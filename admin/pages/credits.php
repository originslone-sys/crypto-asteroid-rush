<?php
// ============================================
// UNOBIX - Gerenciamento de Créditos
// Arquivo: admin/pages/credits.php
// ============================================

$pageTitle = 'Créditos';

try {
    // Criar tabela se não existir
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS credit_packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            credits INT NOT NULL,
            bonus_credits INT NOT NULL DEFAULT 0,
            price_brl DECIMAL(10,2) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $packages = $pdo->query("SELECT * FROM credit_packages ORDER BY credits ASC")->fetchAll();

    // Estado da compra de créditos
    $cpStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'credits_purchase_enabled' LIMIT 1");
    $cpVal = $cpStmt ? $cpStmt->fetchColumn() : false;
    $creditsPurchaseEnabled = ($cpVal === false || ($cpVal !== 'false' && $cpVal !== '0'));

    // Estatísticas
    $hasCreditsCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'credits'")->fetch();
    $creditStats = [];
    if ($hasCreditsCol) {
        $creditStats = $pdo->query("
            SELECT
                COALESCE(SUM(credits), 0) as total_credits,
                COUNT(CASE WHEN credits > 0 THEN 1 END) as users_with_credits,
                COUNT(*) as total_users
            FROM users
        ")->fetch();
    }

    // Compras recentes
    $hasPurchases = $pdo->query("SHOW TABLES LIKE 'credit_purchases'")->fetch();
    $recentPurchases = [];
    if ($hasPurchases) {
        $recentPurchases = $pdo->query("
            SELECT cp.*, u.display_name, u.email, u.google_uid,
                   pkg.name as package_name
            FROM credit_purchases cp
            LEFT JOIN users u ON cp.user_id = u.id
            LEFT JOIN credit_packages pkg ON cp.package_id = pkg.id
            ORDER BY cp.created_at DESC
            LIMIT 50
        ")->fetchAll();
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-coins"></i> Gerenciamento de Creditos</h1>
        <p class="page-subtitle">Criar, editar e gerenciar pacotes de creditos para os jogadores</p>
    </div>

    <?php if (isset($message)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Toggle: Compra de Créditos -->
    <?php if (!$creditsPurchaseEnabled): ?>
    <div class="alert alert-danger" style="display:flex;align-items:center;gap:12px;">
        <i class="fas fa-cart-arrow-down" style="font-size:1.2rem;"></i>
        <span style="flex:1;"><strong>Compra de créditos está DESATIVADA.</strong> Jogadores não conseguirão comprar pacotes de créditos.</span>
        <button type="button" class="btn btn-success btn-sm" onclick="toggleCreditsPurchase()"><i class="fas fa-shopping-cart"></i> Reativar</button>
    </div>
    <?php endif; ?>

    <div class="panel" style="margin-bottom: 20px;">
        <div class="panel-body" style="padding: 14px 20px; display: flex; align-items: center; gap: 16px;">
            <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 2px;">Compra de Créditos</div>
                <div style="font-size: 0.82rem; color: var(--text-dim);">
                    <?php if ($creditsPurchaseEnabled): ?>
                        <span style="color: var(--success);"><i class="fas fa-circle" style="font-size:0.6rem;"></i> Ativada</span> — jogadores podem comprar pacotes normalmente.
                    <?php else: ?>
                        <span style="color: #ff3366;"><i class="fas fa-circle" style="font-size:0.6rem;"></i> Desativada</span> — compra de pacotes bloqueada para todos os jogadores.
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" onclick="toggleCreditsPurchase()"
                    class="btn <?php echo $creditsPurchaseEnabled ? 'btn-danger' : 'btn-success'; ?> btn-sm"
                    id="btnToggleCreditsPurchase">
                <i class="fas <?php echo $creditsPurchaseEnabled ? 'fa-cart-arrow-down' : 'fa-shopping-cart'; ?>"></i>
                <?php echo $creditsPurchaseEnabled ? 'Desativar Compras' : 'Ativar Compras'; ?>
            </button>
        </div>
    </div>

    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-coins"></i></div>
            <div class="value"><?php echo number_format($creditStats['total_credits'] ?? 0); ?></div>
            <div class="label">Creditos em Circulacao</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo $creditStats['users_with_credits'] ?? 0; ?></div>
            <div class="label">Jogadores com Creditos</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-box"></i></div>
            <div class="value"><?php echo count($packages); ?></div>
            <div class="label">Pacotes Cadastrados</div>
        </div>
        <div class="stat-card">
            <div class="icon" style="background: rgba(0,150,255,0.15); color: #0096ff;"><i class="fas fa-shopping-cart"></i></div>
            <div class="value"><?php echo count($recentPurchases); ?></div>
            <div class="label">Compras Recentes</div>
        </div>
    </div>

    <!-- Pacotes de Créditos -->
    <div class="panel">
        <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="panel-title"><i class="fas fa-box"></i> Pacotes de Creditos</h3>
            <button onclick="openPackageModal()" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> Novo Pacote
            </button>
        </div>
        <div class="panel-body">
            <?php if (empty($packages)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>Nenhum pacote cadastrado</h3>
                    <p>Crie seu primeiro pacote de creditos</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome</th>
                                <th>Creditos</th>
                                <th>Bonus</th>
                                <th>Total</th>
                                <th>Preco</th>
                                <th>R$/Credito</th>
                                <th>Status</th>
                                <th>Destaque</th>
                                <th>Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($packages as $pkg): ?>
                        <tr>
                            <td>#<?php echo $pkg['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($pkg['name']); ?></strong></td>
                            <td><?php echo $pkg['credits']; ?></td>
                            <td style="color: var(--success);"><?php echo $pkg['bonus_credits'] > 0 ? '+' . $pkg['bonus_credits'] : '-'; ?></td>
                            <td><strong><?php echo $pkg['credits'] + $pkg['bonus_credits']; ?></strong></td>
                            <td style="color: var(--success); font-weight: bold;">R$ <?php echo number_format($pkg['price_brl'], 2, ',', '.'); ?></td>
                            <td>
                                <?php
                                $total = $pkg['credits'] + $pkg['bonus_credits'];
                                echo $total > 0 ? 'R$ ' . number_format($pkg['price_brl'] / $total, 2, ',', '.') : '-';
                                ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $pkg['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $pkg['is_active'] ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($pkg['is_featured']): ?>
                                    <span class="badge badge-warning"><i class="fas fa-star"></i></span>
                                <?php else: ?>
                                    <span style="color: var(--text-dim);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button onclick='editPackage(<?php echo json_encode($pkg); ?>)' class="btn btn-primary btn-sm" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deletePackage(<?php echo $pkg['id']; ?>)" class="btn btn-danger btn-sm" title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
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

    <!-- Distribuição em Massa -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-gift"></i> Distribuir para Todos os Jogadores</h3>
        </div>
        <div class="panel-body">
            <p style="color: var(--text-dim); margin-bottom: 15px;">
                Adicione créditos ou saldo de bônus para <strong>todos os usuários</strong> de uma vez.
            </p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="width: 160px;">
                    <label class="form-label">Tipo</label>
                    <select id="bulkType" class="form-control" onchange="updateBulkLabel()">
                        <option value="credits">Créditos</option>
                        <option value="balance">Saldo (R$)</option>
                    </select>
                </div>
                <div class="form-group" style="width: 140px;">
                    <label class="form-label" id="bulkAmountLabel">Créditos</label>
                    <input type="number" id="bulkAmount" class="form-control" min="1" step="any" value="5">
                </div>
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label class="form-label">Motivo</label>
                    <input type="text" id="bulkReason" class="form-control" placeholder="Ex: Bonus de evento, compensacao por manutencao">
                </div>
                <button onclick="bulkDistribute()" class="btn btn-warning">
                    <i class="fas fa-paper-plane"></i> Distribuir
                </button>
            </div>
            <div id="bulkResult" style="display: none; margin-top: 15px; padding: 12px; border-radius: 8px; background: rgba(5,255,161,0.1); border: 1px solid rgba(5,255,161,0.3);">
            </div>
        </div>
    </div>

    <!-- Remoção em Massa -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-minus-circle" style="color: #ff3366;"></i> Remover de Todos os Jogadores</h3>
        </div>
        <div class="panel-body">
            <p style="color: var(--text-dim); margin-bottom: 15px;">
                Remova créditos ou saldo de <strong>todos os usuários</strong> de uma vez. Nenhuma conta ficará com valor negativo.
            </p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="width: 160px;">
                    <label class="form-label">Tipo</label>
                    <select id="bulkRemoveType" class="form-control" onchange="updateBulkRemoveLabel()">
                        <option value="credits">Créditos</option>
                        <option value="balance">Saldo (R$)</option>
                    </select>
                </div>
                <div class="form-group" style="width: 140px;">
                    <label class="form-label" id="bulkRemoveAmountLabel">Créditos</label>
                    <input type="number" id="bulkRemoveAmount" class="form-control" min="1" step="any" value="5">
                </div>
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label class="form-label">Motivo</label>
                    <input type="text" id="bulkRemoveReason" class="form-control" placeholder="Ex: Correção de evento, reset de bônus">
                </div>
                <button onclick="bulkRemove()" class="btn btn-danger">
                    <i class="fas fa-minus-circle"></i> Remover
                </button>
            </div>
            <div id="bulkRemoveResult" style="display: none; margin-top: 15px; padding: 12px; border-radius: 8px;"></div>
        </div>
    </div>

    <!-- Adicionar Créditos Manualmente -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-plus-circle"></i> Adicionar Creditos Manualmente</h3>
        </div>
        <div class="panel-body">
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                <div class="form-group" style="flex: 1; min-width: 200px;">
                    <label class="form-label">Google UID do Jogador</label>
                    <input type="text" id="manualCreditsUid" class="form-control" placeholder="Google UID">
                </div>
                <div class="form-group" style="width: 120px;">
                    <label class="form-label">Creditos</label>
                    <input type="number" id="manualCreditsAmount" class="form-control" min="1" value="5">
                </div>
                <div class="form-group" style="flex: 1; min-width: 150px;">
                    <label class="form-label">Motivo</label>
                    <input type="text" id="manualCreditsReason" class="form-control" placeholder="Ex: Bonus, compensacao">
                </div>
                <button onclick="addCreditsManually()" class="btn btn-success">
                    <i class="fas fa-plus"></i> Adicionar
                </button>
            </div>
        </div>
    </div>

    <!-- Compras Recentes -->
    <?php if (!empty($recentPurchases)): ?>
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-shopping-cart"></i> Compras Recentes</h3>
        </div>
        <div class="panel-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Jogador</th>
                            <th>Pacote</th>
                            <th>Creditos</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentPurchases as $p): ?>
                    <tr>
                        <td>#<?php echo $p['id']; ?></td>
                        <td>
                            <div><?php echo htmlspecialchars($p['display_name'] ?? 'Usuario'); ?></div>
                            <small style="color: var(--text-dim);"><?php echo htmlspecialchars($p['email'] ?? ''); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($p['package_name'] ?? 'Pacote #' . $p['package_id']); ?></td>
                        <td><strong><?php echo $p['credits_amount']; ?></strong></td>
                        <td style="color: var(--success);">R$ <?php echo number_format($p['price_brl'], 2, ',', '.'); ?></td>
                        <td>
                            <?php
                            $sClass = ['confirmed' => 'success', 'pending' => 'warning', 'failed' => 'danger'][$p['status']] ?? 'primary';
                            $sText = ['confirmed' => 'Confirmado', 'pending' => 'Pendente', 'failed' => 'Falhou'][$p['status']] ?? $p['status'];
                            ?>
                            <span class="badge badge-<?php echo $sClass; ?>"><?php echo $sText; ?></span>
                        </td>
                        <td><?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Criar/Editar Pacote -->
<div id="packageModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 id="packageModalTitle"><i class="fas fa-box"></i> Novo Pacote</h3>
            <button onclick="closeModal('packageModal')" class="modal-close">&times;</button>
        </div>
        <div style="display: flex; flex-direction: column; gap: 15px;">
            <input type="hidden" id="pkgId" value="0">
            <div class="form-group">
                <label class="form-label">Nome do Pacote</label>
                <input type="text" id="pkgName" class="form-control" placeholder="Ex: Comandante">
            </div>
            <div style="display: flex; gap: 10px;">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Creditos</label>
                    <input type="number" id="pkgCredits" class="form-control" min="1" placeholder="30">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Bonus</label>
                    <input type="number" id="pkgBonus" class="form-control" min="0" value="0" placeholder="5">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Preco (R$)</label>
                <input type="number" id="pkgPrice" class="form-control" step="0.01" min="0.01" placeholder="4.50">
            </div>
            <div style="display: flex; gap: 15px;">
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="checkbox" id="pkgActive" checked> Ativo
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="checkbox" id="pkgFeatured"> Destaque
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('packageModal')" class="btn btn-outline">Cancelar</button>
                <button type="button" onclick="savePackage()" class="btn btn-primary">Salvar</button>
            </div>
        </div>
    </div>
</div>

<style>
.modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center; }
.modal-overlay.active { display: flex; }
.modal-content { background: var(--bg-card); border: 1px solid var(--border); border-radius: 15px; padding: 25px; width: 100%; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-close { background: none; border: none; color: var(--text-dim); font-size: 1.5rem; cursor: pointer; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px; }
</style>

<script>
function openPackageModal() {
    document.getElementById('packageModalTitle').innerHTML = '<i class="fas fa-box"></i> Novo Pacote';
    document.getElementById('pkgId').value = 0;
    document.getElementById('pkgName').value = '';
    document.getElementById('pkgCredits').value = '';
    document.getElementById('pkgBonus').value = 0;
    document.getElementById('pkgPrice').value = '';
    document.getElementById('pkgActive').checked = true;
    document.getElementById('pkgFeatured').checked = false;
    document.getElementById('packageModal').classList.add('active');
}

function editPackage(pkg) {
    document.getElementById('packageModalTitle').innerHTML = '<i class="fas fa-edit"></i> Editar Pacote #' + pkg.id;
    document.getElementById('pkgId').value = pkg.id;
    document.getElementById('pkgName').value = pkg.name;
    document.getElementById('pkgCredits').value = pkg.credits;
    document.getElementById('pkgBonus').value = pkg.bonus_credits;
    document.getElementById('pkgPrice').value = pkg.price_brl;
    document.getElementById('pkgActive').checked = !!parseInt(pkg.is_active);
    document.getElementById('pkgFeatured').checked = !!parseInt(pkg.is_featured);
    document.getElementById('packageModal').classList.add('active');
}

async function savePackage() {
    const data = {
        action: 'save_credit_package',
        id: parseInt(document.getElementById('pkgId').value),
        name: document.getElementById('pkgName').value,
        credits: parseInt(document.getElementById('pkgCredits').value),
        bonus_credits: parseInt(document.getElementById('pkgBonus').value) || 0,
        price_brl: parseFloat(document.getElementById('pkgPrice').value),
        is_active: document.getElementById('pkgActive').checked ? 1 : 0,
        is_featured: document.getElementById('pkgFeatured').checked ? 1 : 0
    };

    try {
        const response = await adminAjax(data);
        if (response.success) {
            showToast(response.message, 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(response.error || response.message, 'error');
        }
    } catch (e) {
        showToast('Erro: ' + e.message, 'error');
    }
}

async function deletePackage(id) {
    if (!confirm('Excluir pacote #' + id + '?')) return;
    try {
        const response = await adminAjax({ action: 'delete_credit_package', id: id });
        if (response.success) {
            showToast('Pacote excluido!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(response.error || response.message, 'error');
        }
    } catch (e) {
        showToast('Erro: ' + e.message, 'error');
    }
}

async function addCreditsManually() {
    const uid = document.getElementById('manualCreditsUid').value.trim();
    const credits = parseInt(document.getElementById('manualCreditsAmount').value);
    const reason = document.getElementById('manualCreditsReason').value.trim() || 'Adicionado pelo admin';

    if (!uid) { showToast('Informe o Google UID', 'error'); return; }
    if (!credits || credits <= 0) { showToast('Quantidade invalida', 'error'); return; }

    if (!confirm('Adicionar ' + credits + ' credito(s) ao jogador?')) return;

    try {
        const response = await adminAjax({ action: 'add_credits', google_uid: uid, credits: credits, reason: reason });
        if (response.success) {
            showToast(response.message, 'success');
            document.getElementById('manualCreditsUid').value = '';
        } else {
            showToast(response.error || response.message, 'error');
        }
    } catch (e) {
        showToast('Erro: ' + e.message, 'error');
    }
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function updateBulkLabel() {
    const type = document.getElementById('bulkType').value;
    const label = document.getElementById('bulkAmountLabel');
    const input = document.getElementById('bulkAmount');
    if (type === 'credits') {
        label.textContent = 'Créditos';
        input.step = '1';
        input.min = '1';
        input.value = '5';
    } else {
        label.textContent = 'Valor (R$)';
        input.step = '0.01';
        input.min = '0.01';
        input.value = '1.00';
    }
}

async function bulkDistribute() {
    const type = document.getElementById('bulkType').value;
    const amount = parseFloat(document.getElementById('bulkAmount').value);
    const reason = document.getElementById('bulkReason').value.trim() || 'Distribuição em massa pelo admin';

    if (!amount || amount <= 0) { showToast('Quantidade inválida', 'error'); return; }

    const label = type === 'credits' ? amount + ' crédito(s)' : 'R$ ' + amount.toFixed(2);
    if (!confirm('Tem certeza que deseja distribuir ' + label + ' para TODOS os jogadores?\n\nEssa ação não pode ser desfeita.')) return;

    const resultDiv = document.getElementById('bulkResult');
    resultDiv.style.display = 'block';
    resultDiv.style.background = 'rgba(0,240,255,0.1)';
    resultDiv.style.borderColor = 'rgba(0,240,255,0.3)';
    resultDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando distribuição...';

    try {
        const response = await adminAjax({
            action: 'bulk_distribute',
            type: type,
            amount: amount,
            reason: reason
        });
        if (response.success) {
            resultDiv.style.background = 'rgba(5,255,161,0.1)';
            resultDiv.style.borderColor = 'rgba(5,255,161,0.3)';
            resultDiv.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success);"></i> ' + response.message;
            showToast(response.message, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            resultDiv.style.background = 'rgba(255,42,109,0.1)';
            resultDiv.style.borderColor = 'rgba(255,42,109,0.3)';
            resultDiv.innerHTML = '<i class="fas fa-times-circle" style="color: var(--danger);"></i> ' + (response.error || response.message);
            showToast(response.error || response.message, 'error');
        }
    } catch (e) {
        resultDiv.style.background = 'rgba(255,42,109,0.1)';
        resultDiv.style.borderColor = 'rgba(255,42,109,0.3)';
        resultDiv.innerHTML = '<i class="fas fa-times-circle" style="color: var(--danger);"></i> Erro: ' + e.message;
        showToast('Erro: ' + e.message, 'error');
    }
}

function updateBulkRemoveLabel() {
    const type = document.getElementById('bulkRemoveType').value;
    const label = document.getElementById('bulkRemoveAmountLabel');
    const input = document.getElementById('bulkRemoveAmount');
    if (type === 'credits') {
        label.textContent = 'Créditos';
        input.step = '1';
        input.min = '1';
        input.value = '5';
    } else {
        label.textContent = 'Valor (R$)';
        input.step = '0.01';
        input.min = '0.01';
        input.value = '1.00';
    }
}

async function bulkRemove() {
    const type = document.getElementById('bulkRemoveType').value;
    const amount = parseFloat(document.getElementById('bulkRemoveAmount').value);
    const reason = document.getElementById('bulkRemoveReason').value.trim() || 'Remoção em massa pelo admin';

    if (!amount || amount <= 0) { showToast('Quantidade inválida', 'error'); return; }

    const label = type === 'credits' ? amount + ' crédito(s)' : 'R$ ' + amount.toFixed(2);
    if (!confirm('ATENÇÃO: Tem certeza que deseja REMOVER ' + label + ' de TODOS os jogadores?\n\nNenhuma conta ficará com valor negativo.\nEssa ação não pode ser desfeita.')) return;

    const resultDiv = document.getElementById('bulkRemoveResult');
    resultDiv.style.display = 'block';
    resultDiv.style.background = 'rgba(0,240,255,0.1)';
    resultDiv.style.borderColor = 'rgba(0,240,255,0.3)';
    resultDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando remoção...';

    try {
        const response = await adminAjax({
            action: 'bulk_remove',
            type: type,
            amount: amount,
            reason: reason
        });
        if (response.success) {
            resultDiv.style.background = 'rgba(5,255,161,0.1)';
            resultDiv.style.borderColor = 'rgba(5,255,161,0.3)';
            resultDiv.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success);"></i> ' + response.message;
            showToast(response.message, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            resultDiv.style.background = 'rgba(255,42,109,0.1)';
            resultDiv.style.borderColor = 'rgba(255,42,109,0.3)';
            resultDiv.innerHTML = '<i class="fas fa-times-circle" style="color: var(--danger);"></i> ' + (response.error || response.message);
            showToast(response.error || response.message, 'error');
        }
    } catch (e) {
        resultDiv.style.background = 'rgba(255,42,109,0.1)';
        resultDiv.style.borderColor = 'rgba(255,42,109,0.3)';
        resultDiv.innerHTML = '<i class="fas fa-times-circle" style="color: var(--danger);"></i> Erro: ' + e.message;
        showToast('Erro: ' + e.message, 'error');
    }
}

function toggleCreditsPurchase() {
    const btn = document.getElementById('btnToggleCreditsPurchase');
    btn.disabled = true;
    fetch('../api/admin-ajax.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'toggle_credits_purchase'})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Erro: ' + (data.message || data.error || 'Falha desconhecida'));
            btn.disabled = false;
        }
    })
    .catch(() => { alert('Erro de conexão.'); btn.disabled = false; });
}
</script>
