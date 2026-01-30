<?php
// ============================================
// UNOBIX - Gerenciamento de Jogadores
// Arquivo: admin/pages/players.php
// ATUALIZADO: Google Auth, BRL, sem wallet
// ============================================

$pageTitle = 'Jogadores';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'ban_player':
                $googleUid = $_POST['google_uid'];
                $reason = $_POST['reason'] ?? 'Ban manual';
                $pdo->prepare("UPDATE players SET is_banned = 1, ban_reason = ? WHERE google_uid = ?")
                    ->execute([$reason, $googleUid]);
                $message = "Jogador banido com sucesso!";
                break;
                
            case 'unban_player':
                $googleUid = $_POST['google_uid'];
                $pdo->prepare("UPDATE players SET is_banned = 0, ban_reason = NULL WHERE google_uid = ?")
                    ->execute([$googleUid]);
                $message = "Jogador desbanido!";
                break;
                
            case 'adjust_balance':
                $googleUid = $_POST['google_uid'];
                $amount = (float)$_POST['amount'];
                $type = $_POST['type'];
                $description = $_POST['description'] ?? 'Ajuste manual';
                
                if ($type === 'add') {
                    $pdo->prepare("UPDATE players SET balance_brl = balance_brl + ? WHERE google_uid = ?")
                        ->execute([$amount, $googleUid]);
                } else {
                    $pdo->prepare("UPDATE players SET balance_brl = GREATEST(0, balance_brl - ?) WHERE google_uid = ?")
                        ->execute([$amount, $googleUid]);
                }
                
                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status) VALUES (?, 'admin_adjust', ?, ?, 'completed')")
                    ->execute([$googleUid, $type === 'add' ? $amount : -$amount, $description]);
                
                $message = "Saldo ajustado!";
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Filtros
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? 'all';
$sort = $_GET['sort'] ?? 'balance';

try {
    $sql = "SELECT * FROM players WHERE 1=1";
    $params = [];
    
    if ($search) {
        $sql .= " AND (display_name LIKE ? OR email LIKE ? OR google_uid LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($filter === 'banned') {
        $sql .= " AND is_banned = 1";
    } elseif ($filter === 'active') {
        $sql .= " AND is_banned = 0 AND balance_brl > 0";
    }
    
    if ($sort === 'played') {
        $sql .= " ORDER BY total_played DESC";
    } elseif ($sort === 'recent') {
        $sql .= " ORDER BY created_at DESC";
    } elseif ($sort === 'earned') {
        $sql .= " ORDER BY total_earned_brl DESC";
    } else {
        $sql .= " ORDER BY balance_brl DESC";
    }
    
    $sql .= " LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $players = $stmt->fetchAll();
    
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_banned = 1 THEN 1 ELSE 0 END) as banned,
            SUM(CASE WHEN balance_brl > 0 THEN 1 ELSE 0 END) as with_balance,
            SUM(balance_brl) as total_balance,
            SUM(total_earned_brl) as total_earned,
            SUM(total_played) as total_games
        FROM players
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
        <h1 class="page-title"><i class="fas fa-users"></i> Gerenciamento de Jogadores</h1>
        <p class="page-subtitle">Visualizar, banir e gerenciar saldos</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-users"></i></div>
            <div class="value"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="label">Total de Jogadores</div>
        </div>
        
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-wallet"></i></div>
            <div class="value"><?php echo $stats['with_balance'] ?? 0; ?></div>
            <div class="label">Com Saldo</div>
            <div class="change"><?php echo formatBRL($stats['total_balance']); ?> total</div>
        </div>
        
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-trophy"></i></div>
            <div class="value"><?php echo formatBRL($stats['total_earned']); ?></div>
            <div class="label">Total Ganho</div>
        </div>
        
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-ban"></i></div>
            <div class="value"><?php echo $stats['banned'] ?? 0; ?></div>
            <div class="label">Banidos</div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="players">
                
                <div class="filter-group">
                    <label>Buscar:</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Nome, email ou ID..." class="form-control" style="width: 300px;">
                </div>
                
                <div class="filter-group">
                    <label>Filtrar:</label>
                    <select name="filter" class="form-control">
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Todos</option>
                        <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Com saldo</option>
                        <option value="banned" <?php echo $filter === 'banned' ? 'selected' : ''; ?>>Banidos</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Ordenar:</label>
                    <select name="sort" class="form-control">
                        <option value="balance" <?php echo $sort === 'balance' ? 'selected' : ''; ?>>Maior saldo</option>
                        <option value="earned" <?php echo $sort === 'earned' ? 'selected' : ''; ?>>Mais ganhou</option>
                        <option value="played" <?php echo $sort === 'played' ? 'selected' : ''; ?>>Mais partidas</option>
                        <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Mais recente</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-search"></i> Buscar
                </button>
            </form>
        </div>
    </div>
    
    <!-- Lista -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> Jogadores</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($players)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <h3>Nenhum jogador encontrado</h3>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Jogador</th>
                                <th>Email</th>
                                <th>Saldo</th>
                                <th>Total Ganho</th>
                                <th>Partidas</th>
                                <th>Status</th>
                                <th>Cadastro</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($players as $p): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php if ($p['photo_url']): ?>
                                            <img src="<?php echo htmlspecialchars($p['photo_url']); ?>" 
                                                 style="width: 35px; height: 35px; border-radius: 50%;">
                                        <?php else: ?>
                                            <div style="width: 35px; height: 35px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-user" style="font-size: 0.8rem;"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars($p['display_name'] ?? 'Sem nome'); ?></strong>
                                            <div style="font-size: 0.75rem; color: var(--text-dim);">
                                                <?php echo substr($p['google_uid'], 0, 10); ?>...
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color: var(--text-dim);"><?php echo htmlspecialchars($p['email'] ?? '-'); ?></td>
                                <td style="color: var(--success); font-weight: bold;">
                                    <?php echo formatBRL($p['balance_brl']); ?>
                                </td>
                                <td style="color: var(--warning);"><?php echo formatBRL($p['total_earned_brl']); ?></td>
                                <td><?php echo $p['total_played']; ?></td>
                                <td>
                                    <?php if ($p['is_banned']): ?>
                                        <span class="badge badge-danger"><i class="fas fa-ban"></i> Banido</span>
                                        <?php if ($p['ban_reason']): ?>
                                            <div style="font-size: 0.7rem; color: var(--danger);"><?php echo htmlspecialchars($p['ban_reason']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-success">Ativo</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--text-dim);"><?php echo date('d/m/Y', strtotime($p['created_at'])); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($p['is_banned']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="unban_player">
                                                <input type="hidden" name="google_uid" value="<?php echo $p['google_uid']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Desbanir?')">
                                                    <i class="fas fa-unlock"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button onclick="banPlayer('<?php echo $p['google_uid']; ?>', '<?php echo htmlspecialchars($p['display_name']); ?>')" 
                                                    class="btn btn-danger btn-sm">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button onclick="adjustBalance('<?php echo $p['google_uid']; ?>', '<?php echo htmlspecialchars($p['display_name']); ?>', <?php echo $p['balance_brl']; ?>)" 
                                                class="btn btn-warning btn-sm">
                                            <i class="fas fa-edit"></i>
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
</div>

<!-- Modal Ban -->
<div id="banModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-ban"></i> Banir Jogador</h3>
            <button onclick="closeModal('banModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="ban_player">
            <input type="hidden" name="google_uid" id="banGoogleUid">
            
            <div class="form-group">
                <label class="form-label">Jogador</label>
                <input type="text" id="banPlayerName" class="form-control" readonly>
            </div>
            
            <div class="form-group">
                <label class="form-label">Motivo</label>
                <input type="text" name="reason" class="form-control" required placeholder="Ex: Uso de cheats">
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeModal('banModal')" class="btn btn-outline">Cancelar</button>
                <button type="submit" class="btn btn-danger">Banir</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ajustar Saldo -->
<div id="adjustModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Ajustar Saldo</h3>
            <button onclick="closeModal('adjustModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="adjust_balance">
            <input type="hidden" name="google_uid" id="adjustGoogleUid">
            
            <div class="form-group">
                <label class="form-label">Jogador</label>
                <input type="text" id="adjustPlayerName" class="form-control" readonly>
            </div>
            
            <div class="form-group">
                <label class="form-label">Saldo Atual</label>
                <input type="text" id="adjustCurrentBalance" class="form-control" readonly>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Tipo</label>
                    <select name="type" class="form-control" required>
                        <option value="add">Adicionar</option>
                        <option value="remove">Remover</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Valor (R$)</label>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Motivo</label>
                <input type="text" name="description" class="form-control" required placeholder="Ex: Bônus, correção">
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeModal('adjustModal')" class="btn btn-outline">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.8);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }
.modal-content {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 15px;
    padding: 25px;
    max-width: 500px;
    width: 100%;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.modal-close {
    background: none;
    border: none;
    color: var(--text-dim);
    font-size: 1.5rem;
    cursor: pointer;
}
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}
</style>

<script>
function banPlayer(googleUid, name) {
    document.getElementById('banGoogleUid').value = googleUid;
    document.getElementById('banPlayerName').value = name;
    document.getElementById('banModal').classList.add('active');
}

function adjustBalance(googleUid, name, balance) {
    document.getElementById('adjustGoogleUid').value = googleUid;
    document.getElementById('adjustPlayerName').value = name;
    document.getElementById('adjustCurrentBalance').value = 'R$ ' + balance.toFixed(2);
    document.getElementById('adjustModal').classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>
