<?php
// ============================================
// CRYPTO ASTEROID RUSH - Gerenciamento de Jogadores
// Arquivo: admin/pages/players.php
// ============================================

$pageTitle = 'Jogadores';

// Processar ações
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'ban_wallet':
                $wallet = strtolower(trim($_POST['wallet']));
                $reason = $_POST['reason'] ?? 'Manual ban';
                $pdo->prepare("UPDATE players SET is_banned = 1, ban_reason = ? WHERE wallet_address = ?")
                    ->execute([$reason, $wallet]);
                $message = "Wallet banida com sucesso!";
                break;
                
            case 'unban_wallet':
                $wallet = strtolower(trim($_POST['wallet']));
                $pdo->prepare("UPDATE players SET is_banned = 0, ban_reason = NULL WHERE wallet_address = ?")
                    ->execute([$wallet]);
                $message = "Wallet desbanida com sucesso!";
                break;
                
            case 'adjust_balance':
                $wallet = strtolower(trim($_POST['wallet']));
                $amount = (float)$_POST['amount'];
                $type = $_POST['type'];
                $description = $_POST['description'] ?? 'Ajuste manual';
                
                if ($type === 'add') {
                    $pdo->prepare("UPDATE players SET balance_usdt = balance_usdt + ? WHERE wallet_address = ?")
                        ->execute([$amount, $wallet]);
                } else {
                    $pdo->prepare("UPDATE players SET balance_usdt = GREATEST(0, balance_usdt - ?) WHERE wallet_address = ?")
                        ->execute([$amount, $wallet]);
                }
                
                // Registrar transação
                $pdo->prepare("INSERT INTO transactions (wallet_address, type, amount, description, status) VALUES (?, 'admin_adjust', ?, ?, 'completed')")
                    ->execute([$wallet, $type === 'add' ? $amount : -$amount, $description]);
                
                $message = "Saldo ajustado com sucesso!";
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
        $sql .= " AND wallet_address LIKE ?";
        $params[] = "%$search%";
    }
    
    if ($filter === 'banned') {
        $sql .= " AND is_banned = 1";
    } elseif ($filter === 'active') {
        $sql .= " AND is_banned = 0 AND balance_usdt > 0";
    }
    
    // Ordenação
    if ($sort === 'played') {
        $sql .= " ORDER BY total_played DESC";
    } elseif ($sort === 'recent') {
        $sql .= " ORDER BY updated_at DESC";
    } else {
        $sql .= " ORDER BY balance_usdt DESC";
    }
    
    $sql .= " LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $players = $stmt->fetchAll();
    
    // Estatísticas
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_banned = 1 THEN 1 ELSE 0 END) as banned,
            SUM(CASE WHEN balance_usdt > 0 THEN 1 ELSE 0 END) as with_balance,
            SUM(balance_usdt) as total_balance,
            SUM(total_played) as total_games
        FROM players
    ")->fetch();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-users"></i> Gerenciamento de Jogadores</h1>
        <p class="page-subtitle">Visualizar, banir e gerenciar saldos dos jogadores</p>
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
            <div class="change">$<?php echo number_format($stats['total_balance'] ?? 0, 2); ?> total</div>
        </div>
        
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-gamepad"></i></div>
            <div class="value"><?php echo number_format($stats['total_games'] ?? 0); ?></div>
            <div class="label">Partidas Jogadas</div>
        </div>
        
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-ban"></i></div>
            <div class="value"><?php echo $stats['banned'] ?? 0; ?></div>
            <div class="label">Banidos</div>
        </div>
    </div>
    
    <!-- Filtros e Busca -->
    <div class="panel">
        <div class="panel-body">
            <form method="GET" class="filters">
                <input type="hidden" name="page" value="players">
                
                <div class="filter-group">
                    <label>Buscar:</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Endereço da carteira..." class="form-control" style="width: 300px;">
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
    
    <!-- Lista de Jogadores -->
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
                                <th>Carteira</th>
                                <th>Saldo</th>
                                <th>Partidas</th>
                                <th>Total Ganho</th>
                                <th>Status</th>
                                <th>Última Atividade</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($players as $p): ?>
                            <tr>
                                <td>
                                    <span class="wallet-addr" onclick="copyToClipboard('<?php echo $p['wallet_address']; ?>')">
                                        <?php echo substr($p['wallet_address'], 0, 6) . '...' . substr($p['wallet_address'], -4); ?>
                                    </span>
                                </td>
                                <td style="color: var(--success); font-weight: bold;">
                                    $<?php echo number_format($p['balance_usdt'], 6); ?>
                                </td>
                                <td><?php echo $p['total_played']; ?></td>
                                <td>$<?php echo number_format($p['total_earned'] ?? 0, 6); ?></td>
                                <td>
                                    <?php if ($p['is_banned']): ?>
                                        <span class="badge badge-danger">
                                            <i class="fas fa-ban"></i> Banido
                                        </span>
                                        <?php if ($p['ban_reason']): ?>
                                            <small style="display: block; color: var(--text-dim);"><?php echo htmlspecialchars($p['ban_reason']); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-success">Ativo</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $p['updated_at'] ? date('d/m/Y H:i', strtotime($p['updated_at'])) : '-'; ?></td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($p['is_banned']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="unban_wallet">
                                                <input type="hidden" name="wallet" value="<?php echo $p['wallet_address']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Desbanir este jogador?')">
                                                    <i class="fas fa-unlock"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button onclick="banWallet('<?php echo $p['wallet_address']; ?>')" class="btn btn-danger btn-sm">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button onclick="showAdjustModal('<?php echo $p['wallet_address']; ?>', <?php echo $p['balance_usdt']; ?>)" 
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

<!-- Modal Ajustar Saldo -->
<div id="adjustModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div class="panel" style="max-width: 500px; margin: 50px auto;">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-edit"></i> Ajustar Saldo</h3>
            <button onclick="closeAdjustModal()" class="btn btn-outline btn-sm">&times;</button>
        </div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="action" value="adjust_balance">
                <input type="hidden" name="wallet" id="adjustWallet">
                
                <div class="form-group">
                    <label class="form-label">Carteira</label>
                    <input type="text" id="adjustWalletDisplay" class="form-control" readonly>
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
                        <label class="form-label">Valor (USDT)</label>
                        <input type="number" name="amount" class="form-control" step="0.000001" min="0.000001" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Motivo</label>
                    <input type="text" name="description" class="form-control" placeholder="Descrição do ajuste" required>
                </div>
                
                <div class="btn-group" style="justify-content: flex-end;">
                    <button type="button" onclick="closeAdjustModal()" class="btn btn-outline">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Confirmar Ajuste</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAdjustModal(wallet, balance) {
    document.getElementById('adjustWallet').value = wallet;
    document.getElementById('adjustWalletDisplay').value = wallet;
    document.getElementById('adjustCurrentBalance').value = '$' + balance.toFixed(6);
    document.getElementById('adjustModal').style.display = 'flex';
}

function closeAdjustModal() {
    document.getElementById('adjustModal').style.display = 'none';
}
</script>
