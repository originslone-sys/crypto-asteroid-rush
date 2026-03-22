<?php
// ============================================
// UNOBIX - Gerenciamento de Jogadores
// Arquivo: admin/pages/players.php
// v7.0 - Compras, saques, status inativo, exclusão
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
                $pdo->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE google_uid = ?")
                    ->execute([$reason, $googleUid]);
                $message = "Jogador banido com sucesso!";
                break;

            case 'unban_player':
                $googleUid = $_POST['google_uid'];
                $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE google_uid = ?")
                    ->execute([$googleUid]);
                $message = "Jogador desbanido!";
                break;

            case 'adjust_balance':
                $googleUid = $_POST['google_uid'];
                $amount = (float)$_POST['amount'];
                $type = $_POST['type'];
                $description = $_POST['description'] ?? 'Ajuste manual';

                if ($type === 'add') {
                    $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE google_uid = ?")
                        ->execute([$amount, $googleUid]);
                } else {
                    $pdo->prepare("UPDATE users SET balance_brl = GREATEST(0, balance_brl - ?) WHERE google_uid = ?")
                        ->execute([$amount, $googleUid]);
                }

                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status) VALUES (?, 'admin_adjust', ?, ?, 'completed')")
                    ->execute([$googleUid, $type === 'add' ? $amount : -$amount, $description]);

                $message = "Saldo ajustado!";
                break;

            case 'delete_player':
                $googleUid = $_POST['google_uid'];
                $playerName = $_POST['player_name'] ?? 'Desconhecido';

                // Buscar user id
                $stmt = $pdo->prepare("SELECT id FROM users WHERE google_uid = ? LIMIT 1");
                $stmt->execute([$googleUid]);
                $user = $stmt->fetch();

                if (!$user) throw new Exception("Jogador não encontrado");

                $userId = $user['id'];

                $pdo->beginTransaction();
                try {
                    // Remover dados de todas as tabelas relacionadas
                    $pdo->prepare("DELETE FROM transactions WHERE google_uid = ?")->execute([$googleUid]);
                    $pdo->prepare("DELETE FROM game_sessions WHERE google_uid = ?")->execute([$googleUid]);
                    $pdo->prepare("DELETE FROM withdrawals WHERE user_id = ?")->execute([$userId]);

                    try { $pdo->prepare("DELETE FROM credit_purchases WHERE user_id = ?")->execute([$userId]); } catch (Exception $e) {}
                    try { $pdo->prepare("DELETE FROM zettpay_transactions WHERE user_id = ?")->execute([$userId]); } catch (Exception $e) {}
                    try { $pdo->prepare("DELETE FROM referrals WHERE referrer_id = ? OR referred_id = ?")->execute([$userId, $userId]); } catch (Exception $e) {}
                    try { $pdo->prepare("DELETE FROM suspicious_activity WHERE google_uid = ?")->execute([$googleUid]); } catch (Exception $e) {}
                    try { $pdo->prepare("DELETE FROM rate_limits WHERE google_uid = ?")->execute([$googleUid]); } catch (Exception $e) {}
                    try { $pdo->prepare("DELETE FROM ad_impressions WHERE google_uid = ?")->execute([$googleUid]); } catch (Exception $e) {}
                    try { $pdo->prepare("DELETE FROM ad_clicks WHERE google_uid = ?")->execute([$googleUid]); } catch (Exception $e) {}

                    // Remover o jogador
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

                    $pdo->commit();
                    secureLog("ADMIN_DELETE_PLAYER | google_uid: {$googleUid} | name: {$playerName} | user_id: {$userId}");
                    $message = "Jogador '{$playerName}' e todos os seus dados foram excluídos permanentemente.";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
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
    $sql = "
        SELECT u.*,
            COALESCE(u.total_withdrawn_brl, 0) as total_withdrawn,
            COALESCE(cp.total_purchases, 0) as total_purchases
        FROM users u
        LEFT JOIN (
            SELECT user_id, SUM(CASE WHEN status = 'confirmed' THEN price_brl ELSE 0 END) as total_purchases
            FROM credit_purchases
            GROUP BY user_id
        ) cp ON cp.user_id = u.id
        WHERE 1=1
    ";
    $params = [];

    if ($search) {
        $sql .= " AND (u.display_name LIKE ? OR u.email LIKE ? OR u.google_uid LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($filter === 'banned') {
        $sql .= " AND u.is_banned = 1";
    } elseif ($filter === 'active') {
        $sql .= " AND u.is_banned = 0 AND u.last_login > DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($filter === 'inactive') {
        $sql .= " AND (u.last_login IS NULL OR u.last_login <= DATE_SUB(NOW(), INTERVAL 30 DAY))";
    } elseif ($filter === 'with_balance') {
        $sql .= " AND u.is_banned = 0 AND u.balance_brl > 0";
    }

    if ($sort === 'played') {
        $sql .= " ORDER BY u.total_played DESC";
    } elseif ($sort === 'recent') {
        $sql .= " ORDER BY u.created_at DESC";
    } elseif ($sort === 'earned') {
        $sql .= " ORDER BY u.total_earned_brl DESC";
    } elseif ($sort === 'purchases') {
        $sql .= " ORDER BY total_purchases DESC";
    } elseif ($sort === 'withdrawn') {
        $sql .= " ORDER BY u.total_withdrawn_brl DESC";
    } else {
        $sql .= " ORDER BY u.balance_brl DESC";
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
            SUM(total_played) as total_games,
            SUM(CASE WHEN last_login IS NULL OR last_login <= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as inactive
        FROM users
    ")->fetch();

} catch (Exception $e) {
    $error = $e->getMessage();
}

// formatBRL() já definida em config.php
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-users"></i> Gerenciamento de Jogadores</h1>
        <p class="page-subtitle">Visualizar, banir, gerenciar saldos e excluir jogadores</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
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
            <div class="change"><?php echo $stats['inactive'] ?? 0; ?> inativos</div>
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
                        <option value="active" <?php echo $filter === 'active' ? 'selected' : ''; ?>>Ativos</option>
                        <option value="inactive" <?php echo $filter === 'inactive' ? 'selected' : ''; ?>>Inativos (+30d)</option>
                        <option value="with_balance" <?php echo $filter === 'with_balance' ? 'selected' : ''; ?>>Com saldo</option>
                        <option value="banned" <?php echo $filter === 'banned' ? 'selected' : ''; ?>>Banidos</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Ordenar:</label>
                    <select name="sort" class="form-control">
                        <option value="balance" <?php echo $sort === 'balance' ? 'selected' : ''; ?>>Maior saldo</option>
                        <option value="earned" <?php echo $sort === 'earned' ? 'selected' : ''; ?>>Mais ganhou</option>
                        <option value="purchases" <?php echo $sort === 'purchases' ? 'selected' : ''; ?>>Mais comprou</option>
                        <option value="withdrawn" <?php echo $sort === 'withdrawn' ? 'selected' : ''; ?>>Mais sacou</option>
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
                    <table class="table-compact">
                        <thead>
                            <tr>
                                <th>Jogador</th>
                                <th>Saldo</th>
                                <th>Compras</th>
                                <th>Ganhos</th>
                                <th>Saques</th>
                                <th>Jogos</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($players as $p):
                                $isInactive = empty($p['last_login']) || strtotime($p['last_login']) <= strtotime('-30 days');
                            ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php if (!empty($p['photo_url'] ?? '')): ?>
                                            <img src="<?php echo htmlspecialchars($p['photo_url']); ?>"
                                                 style="width: 30px; height: 30px; border-radius: 50%;">
                                        <?php else: ?>
                                            <div style="width: 30px; height: 30px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-user" style="font-size: 0.7rem;"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong style="font-size: 0.85rem;"><?php echo htmlspecialchars($p['display_name'] ?? 'Sem nome'); ?></strong>
                                            <div style="font-size: 0.7rem; color: var(--text-dim);"><?php echo htmlspecialchars($p['email'] ?? substr($p['google_uid'], 0, 10) . '...'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color: var(--success); font-weight: bold; white-space: nowrap;">
                                    <?php echo formatBRL($p['balance_brl']); ?>
                                </td>
                                <td style="color: var(--primary); white-space: nowrap;">
                                    <?php echo formatBRL($p['total_purchases'] ?? 0); ?>
                                </td>
                                <td style="color: var(--warning); white-space: nowrap;"><?php echo formatBRL($p['total_earned_brl']); ?></td>
                                <td style="color: var(--danger); white-space: nowrap;">
                                    <?php echo formatBRL($p['total_withdrawn'] ?? 0); ?>
                                </td>
                                <td><?php echo $p['total_played']; ?></td>
                                <td>
                                    <?php if ($p['is_banned']): ?>
                                        <span class="badge badge-danger" title="<?php echo htmlspecialchars($p['ban_reason'] ?? ''); ?>"><i class="fas fa-ban"></i> Banido</span>
                                    <?php elseif ($isInactive): ?>
                                        <span class="badge badge-warning" title="Último login: <?php echo $p['last_login'] ? date('d/m/Y', strtotime($p['last_login'])) : 'Nunca'; ?>"><i class="fas fa-moon"></i> Inativo</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Ativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <?php if ($p['is_banned']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="unban_player">
                                                <input type="hidden" name="google_uid" value="<?php echo $p['google_uid']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Desbanir?')" title="Desbanir">
                                                    <i class="fas fa-unlock"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button onclick="banPlayer('<?php echo $p['google_uid']; ?>', '<?php echo htmlspecialchars($p['display_name']); ?>')"
                                                    class="btn btn-danger btn-sm" title="Banir">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php endif; ?>

                                        <button onclick="adjustBalance('<?php echo $p['google_uid']; ?>', '<?php echo htmlspecialchars($p['display_name']); ?>', <?php echo $p['balance_brl']; ?>)"
                                                class="btn btn-warning btn-sm" title="Ajustar saldo">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <button onclick="deletePlayer('<?php echo $p['google_uid']; ?>', '<?php echo htmlspecialchars($p['display_name']); ?>')"
                                                class="btn btn-outline btn-sm" style="border-color: var(--danger); color: var(--danger);" title="Excluir jogador">
                                            <i class="fas fa-trash-alt"></i>
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

<!-- Modal Excluir Jogador -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-trash-alt" style="color: var(--danger);"></i> Excluir Jogador</h3>
            <button onclick="closeModal('deleteModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="delete_player">
            <input type="hidden" name="google_uid" id="deleteGoogleUid">
            <input type="hidden" name="player_name" id="deletePlayerNameHidden">

            <div style="padding: 15px; background: rgba(255,71,87,0.1); border: 1px solid var(--danger); border-radius: 10px; margin-bottom: 20px;">
                <p style="color: var(--danger); font-weight: 600; margin: 0 0 8px 0;">
                    <i class="fas fa-exclamation-triangle"></i> Esta ação é irreversível!
                </p>
                <p style="color: var(--text-dim); margin: 0; font-size: 0.9rem;">
                    Todos os dados do jogador serão permanentemente removidos: conta, transações, sessões de jogo, saques, compras, indicações e registros de atividade.
                </p>
            </div>

            <div class="form-group">
                <label class="form-label">Jogador</label>
                <input type="text" id="deletePlayerName" class="form-control" readonly>
            </div>

            <div class="form-group">
                <label class="form-label">Digite <strong>EXCLUIR</strong> para confirmar</label>
                <input type="text" id="deleteConfirmInput" class="form-control" placeholder="EXCLUIR" autocomplete="off">
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('deleteModal')" class="btn btn-outline">Cancelar</button>
                <button type="submit" id="deleteSubmitBtn" class="btn btn-danger" disabled>Excluir Permanentemente</button>
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
.table-compact .btn-sm {
    padding: 4px 8px;
    font-size: 0.75rem;
}
.table-compact .btn-group {
    gap: 4px;
}
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

function deletePlayer(googleUid, name) {
    document.getElementById('deleteGoogleUid').value = googleUid;
    document.getElementById('deletePlayerNameHidden').value = name;
    document.getElementById('deletePlayerName').value = name;
    document.getElementById('deleteConfirmInput').value = '';
    document.getElementById('deleteSubmitBtn').disabled = true;
    document.getElementById('deleteModal').classList.add('active');
}

document.getElementById('deleteConfirmInput').addEventListener('input', function() {
    document.getElementById('deleteSubmitBtn').disabled = this.value !== 'EXCLUIR';
});

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
</script>
