<?php
// ============================================
// UNOBIX - Admin: Exploração de Naves
// Arquivo: admin/pages/exploration.php
// Gerenciar naves, aluguéis e configurações
// ============================================

$pageTitle = 'Exploração';
$message = '';
$messageType = '';

// ============================================
// GARANTIR TABELAS
// ============================================
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'exploration_ships'")->fetch();
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE exploration_ships (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ship_key VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(100) NOT NULL,
                description TEXT,
                rental_price_brl DECIMAL(10,2) NOT NULL DEFAULT 0,
                rental_duration_hours INT NOT NULL DEFAULT 24,
                credits_per_day INT NOT NULL DEFAULT 10,
                is_active TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    $rentalsTable = $pdo->query("SHOW TABLES LIKE 'exploration_rentals'")->fetch();
    if (!$rentalsTable) {
        $pdo->exec("
            CREATE TABLE exploration_rentals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                google_uid VARCHAR(255) NOT NULL,
                ship_id INT NOT NULL,
                ship_key VARCHAR(50) NOT NULL,
                rental_price_brl DECIMAL(10,2) NOT NULL DEFAULT 0,
                credits_per_day INT NOT NULL DEFAULT 10,
                credits_accumulated INT DEFAULT 0,
                credits_claimed INT DEFAULT 0,
                status ENUM('pending_payment','active','expired','cancelled') DEFAULT 'active',
                started_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                claimed_at DATETIME NULL,
                last_accumulation_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_status (status),
                INDEX idx_expires (expires_at)
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

        // --- Naves ---
        case 'add_ship':
            $shipKey = strtoupper(trim($_POST['ship_key'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = (float)($_POST['rental_price_brl'] ?? 0);
            $duration = (int)($_POST['rental_duration_hours'] ?? 24);
            $creditsPerDay = (int)($_POST['credits_per_day'] ?? 10);
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if (empty($shipKey) || empty($name) || $price <= 0 || $duration <= 0 || $creditsPerDay <= 0) {
                $message = "Preencha todos os campos obrigatórios (key, nome, preço, duração, créditos/dia)";
                $messageType = 'danger';
            } else {
                try {
                    $pdo->prepare("INSERT INTO exploration_ships (ship_key, name, description, rental_price_brl, rental_duration_hours, credits_per_day, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$shipKey, $name, $description, $price, $duration, $creditsPerDay, $sortOrder]);
                    $message = "Nave '$name' adicionada!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        case 'update_ship':
            $shipId = (int)($_POST['ship_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = (float)($_POST['rental_price_brl'] ?? 0);
            $duration = (int)($_POST['rental_duration_hours'] ?? 24);
            $creditsPerDay = (int)($_POST['credits_per_day'] ?? 10);
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($shipId && !empty($name) && $price > 0 && $duration > 0 && $creditsPerDay > 0) {
                try {
                    $pdo->prepare("UPDATE exploration_ships SET name = ?, description = ?, rental_price_brl = ?, rental_duration_hours = ?, credits_per_day = ?, sort_order = ?, is_active = ? WHERE id = ?")
                        ->execute([$name, $description, $price, $duration, $creditsPerDay, $sortOrder, $isActive, $shipId]);
                    $message = "Nave atualizada!";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        case 'delete_ship':
            $shipId = (int)($_POST['ship_id'] ?? 0);
            if ($shipId) {
                try {
                    // Verificar se tem aluguéis ativos
                    $activeCheck = $pdo->prepare("SELECT COUNT(*) FROM exploration_rentals WHERE ship_id = ? AND status = 'active'");
                    $activeCheck->execute([$shipId]);
                    if ((int)$activeCheck->fetchColumn() > 0) {
                        $message = "Não é possível excluir nave com aluguéis ativos!";
                        $messageType = 'danger';
                    } else {
                        $pdo->prepare("DELETE FROM exploration_ships WHERE id = ?")->execute([$shipId]);
                        $message = "Nave excluída!";
                        $messageType = 'success';
                    }
                } catch (Exception $e) {
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        // --- Aluguéis ---
        case 'cancel_rental':
            $rentalId = (int)($_POST['rental_id'] ?? 0);
            $refund = isset($_POST['refund']);
            if ($rentalId) {
                try {
                    $pdo->beginTransaction();

                    $rental = $pdo->prepare("SELECT * FROM exploration_rentals WHERE id = ? FOR UPDATE");
                    $rental->execute([$rentalId]);
                    $rental = $rental->fetch();

                    if (!$rental) {
                        $pdo->rollBack();
                        $message = "Aluguel não encontrado!";
                        $messageType = 'danger';
                    } else {
                        $pdo->prepare("UPDATE exploration_rentals SET status = 'cancelled' WHERE id = ?")->execute([$rentalId]);

                        if ($refund) {
                            $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE id = ?")
                                ->execute([(float)$rental['rental_price_brl'], (int)$rental['user_id']]);

                            $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at) VALUES (?, 'admin_refund', ?, ?, 'completed', NOW())")
                                ->execute([$rental['google_uid'], (float)$rental['rental_price_brl'], "Reembolso exploração #{$rentalId} (admin)"]);
                        }

                        $pdo->commit();
                        $message = "Aluguel #{$rentalId} cancelado" . ($refund ? " com reembolso de R$ " . number_format((float)$rental['rental_price_brl'], 2, ',', '.') : "") . "!";
                        $messageType = 'success';
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $message = "Erro: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
            break;

        // --- Ativar aluguel manualmente ---
        case 'activate_rental_manual':
            $email = trim($_POST['rental_email'] ?? '');
            $shipId = (int)($_POST['rental_ship_id'] ?? 0);
            $durationOverride = (int)($_POST['rental_duration_override'] ?? 0);

            if (empty($email)) {
                $message = "Informe o email do jogador";
                $messageType = 'danger';
                break;
            }
            if ($shipId <= 0) {
                $message = "Selecione uma nave";
                $messageType = 'danger';
                break;
            }

            try {
                $userStmt = $pdo->prepare("SELECT id, google_uid, email, display_name FROM users WHERE email = ? LIMIT 1");
                $userStmt->execute([$email]);
                $targetUser = $userStmt->fetch();

                if (!$targetUser) {
                    $message = "Jogador com email '{$email}' não encontrado";
                    $messageType = 'danger';
                    break;
                }

                $shipStmt = $pdo->prepare("SELECT * FROM exploration_ships WHERE id = ? LIMIT 1");
                $shipStmt->execute([$shipId]);
                $targetShip = $shipStmt->fetch();

                if (!$targetShip) {
                    $message = "Nave não encontrada";
                    $messageType = 'danger';
                    break;
                }

                $durationHours = $durationOverride > 0 ? $durationOverride : (int)$targetShip['rental_duration_hours'];

                $pdo->prepare("
                    INSERT INTO exploration_rentals
                        (user_id, google_uid, ship_id, ship_key, rental_price_brl, credits_per_day, credits_accumulated, credits_claimed, status, started_at, expires_at, created_at)
                    VALUES (?, ?, ?, ?, 0, ?, 0, 0, 'active', NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR), NOW())
                ")->execute([
                    (int)$targetUser['id'],
                    $targetUser['google_uid'],
                    (int)$targetShip['id'],
                    $targetShip['ship_key'],
                    (int)$targetShip['credits_per_day'],
                    $durationHours
                ]);

                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at) VALUES (?, 'admin_adjust', 0, ?, 'completed', NOW())")
                    ->execute([$targetUser['google_uid'], "Admin ativou aluguel manual: nave {$targetShip['name']} ({$durationHours}h) para {$targetUser['email']}"]);

                $message = "Aluguel ativado! Nave '{$targetShip['name']}' para {$targetUser['display_name']} ({$targetUser['email']}) por {$durationHours}h";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Erro: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;

        case 'update_settings':
            try {
                $explorationEnabled = isset($_POST['exploration_enabled']) ? 'true' : 'false';
                $maxRentals = max(1, (int)($_POST['max_rentals'] ?? 3));

                $settings = [
                    'exploration_enabled' => $explorationEnabled,
                    'exploration_max_rentals_per_user' => $maxRentals
                ];

                foreach ($settings as $key => $value) {
                    $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                        ->execute([$key, $value, $value]);
                }

                $message = "Configurações salvas!";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Erro: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
    }
}

// ============================================
// EXPIRAR ALUGUÉIS VENCIDOS
// ============================================
try {
    $pdo->exec("UPDATE exploration_rentals SET status = 'expired' WHERE status = 'active' AND expires_at <= NOW()");
} catch (Exception $e) {}

// ============================================
// CARREGAR DADOS
// ============================================
$tab = $_GET['tab'] ?? 'ships';

// Estatísticas
$stats = ['total_ships' => 0, 'active_ships' => 0, 'active_rentals' => 0, 'total_rentals' => 0, 'total_revenue' => 0, 'total_credits_claimed' => 0];
try {
    $stats['total_ships'] = (int)$pdo->query("SELECT COUNT(*) FROM exploration_ships")->fetchColumn();
    $stats['active_ships'] = (int)$pdo->query("SELECT COUNT(*) FROM exploration_ships WHERE is_active = 1")->fetchColumn();
    $stats['active_rentals'] = (int)$pdo->query("SELECT COUNT(*) FROM exploration_rentals WHERE status = 'active'")->fetchColumn();
    $stats['total_rentals'] = (int)$pdo->query("SELECT COUNT(*) FROM exploration_rentals")->fetchColumn();
    $stats['total_revenue'] = (float)$pdo->query("SELECT COALESCE(SUM(rental_price_brl), 0) FROM exploration_rentals WHERE status != 'cancelled'")->fetchColumn();
    $stats['total_credits_claimed'] = (int)$pdo->query("SELECT COALESCE(SUM(credits_claimed), 0) FROM exploration_rentals")->fetchColumn();
} catch (Exception $e) {}

// Configurações
$settings = ['exploration_enabled' => 'true', 'exploration_max_rentals_per_user' => '3'];
try {
    $settingsRows = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key LIKE 'exploration_%'")->fetchAll();
    foreach ($settingsRows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

// Naves
$ships = [];
try {
    $ships = $pdo->query("
        SELECT s.*,
            (SELECT COUNT(*) FROM exploration_rentals r WHERE r.ship_id = s.id AND r.status = 'active') as active_rental_count,
            (SELECT COUNT(*) FROM exploration_rentals r WHERE r.ship_id = s.id) as total_rental_count
        FROM exploration_ships s
        ORDER BY s.sort_order ASC, s.id ASC
    ")->fetchAll();
} catch (Exception $e) {}

// Aluguéis
$rentals = [];
$rentalFilter = $_GET['rental_status'] ?? 'all';
try {
    $rentalWhere = "1=1";
    $rentalParams = [];
    if ($rentalFilter !== 'all') {
        $rentalWhere = "r.status = ?";
        $rentalParams[] = $rentalFilter;
    }

    $stmt = $pdo->prepare("
        SELECT r.*,
            s.name as ship_name,
            COALESCE(u.display_name, u.google_uid) as user_name,
            u.email as user_email
        FROM exploration_rentals r
        LEFT JOIN exploration_ships s ON s.id = r.ship_id
        LEFT JOIN users u ON u.id = r.user_id
        WHERE $rentalWhere
        ORDER BY r.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($rentalParams);
    $rentals = $stmt->fetchAll();
} catch (Exception $e) {}

// Editar nave
$editShip = null;
$editShipId = (int)($_GET['edit_ship'] ?? 0);
if ($editShipId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM exploration_ships WHERE id = ?");
        $stmt->execute([$editShipId]);
        $editShip = $stmt->fetch();
    } catch (Exception $e) {}
}

$statusLabels = [
    'active' => ['Ativo', 'success'],
    'expired' => ['Expirado', 'warning'],
    'cancelled' => ['Cancelado', 'danger']
];
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-compass"></i> Exploração</h1>
        <p class="page-subtitle">Gerenciar naves, aluguéis e configurações do sistema de exploração</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:15px;margin-bottom:25px;">
        <div class="stat-card">
            <div class="value"><?php echo $stats['active_ships']; ?>/<?php echo $stats['total_ships']; ?></div>
            <div class="label">Naves Ativas</div>
        </div>
        <div class="stat-card" style="border-color:rgba(0,255,136,0.3);">
            <div class="value" style="color:#00ff88;"><?php echo $stats['active_rentals']; ?></div>
            <div class="label">Aluguéis Ativos</div>
        </div>
        <div class="stat-card">
            <div class="value"><?php echo $stats['total_rentals']; ?></div>
            <div class="label">Total Aluguéis</div>
        </div>
        <div class="stat-card" style="border-color:rgba(0,200,255,0.3);">
            <div class="value" style="color:#00c8ff;">R$ <?php echo number_format($stats['total_revenue'], 2, ',', '.'); ?></div>
            <div class="label">Receita Total</div>
        </div>
        <div class="stat-card" style="border-color:rgba(255,170,0,0.3);">
            <div class="value" style="color:#ffaa00;"><?php echo number_format($stats['total_credits_claimed']); ?></div>
            <div class="label">Créditos Distribuídos</div>
        </div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=exploration&tab=ships" class="btn <?php echo $tab === 'ships' ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration:none;font-size:0.85rem;">
            <i class="fas fa-rocket"></i> Naves
        </a>
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=exploration&tab=rentals" class="btn <?php echo $tab === 'rentals' ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration:none;font-size:0.85rem;">
            <i class="fas fa-satellite"></i> Aluguéis
        </a>
        <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=exploration&tab=settings" class="btn <?php echo $tab === 'settings' ? 'btn-primary' : 'btn-secondary'; ?>" style="text-decoration:none;font-size:0.85rem;">
            <i class="fas fa-cog"></i> Configurações
        </a>
    </div>

    <?php if ($tab === 'ships'): ?>
    <!-- ============================================ -->
    <!-- ABA: NAVES -->
    <!-- ============================================ -->

    <?php if ($editShip): ?>
    <!-- Formulário de edição -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1rem;">Editar Nave: <?php echo htmlspecialchars($editShip['name']); ?></h3>
            <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=exploration&tab=ships" class="btn btn-secondary" style="text-decoration:none;font-size:0.8rem;">
                <i class="fas fa-times"></i> Cancelar
            </a>
        </div>
        <div style="padding:20px;">
            <form method="POST">
                <input type="hidden" name="action" value="update_ship">
                <input type="hidden" name="ship_id" value="<?php echo $editShip['id']; ?>">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Key (ID interno)</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($editShip['ship_key']); ?>" disabled style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Nome *</label>
                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($editShip['name']); ?>" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Preço (R$) *</label>
                        <input type="number" name="rental_price_brl" class="form-control" value="<?php echo $editShip['rental_price_brl']; ?>" step="0.01" min="0.01" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Duração (horas) *</label>
                        <input type="number" name="rental_duration_hours" class="form-control" value="<?php echo $editShip['rental_duration_hours']; ?>" min="1" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Créditos/dia *</label>
                        <input type="number" name="credits_per_day" class="form-control" value="<?php echo $editShip['credits_per_day']; ?>" min="1" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Ordem</label>
                        <input type="number" name="sort_order" class="form-control" value="<?php echo $editShip['sort_order']; ?>" style="font-size:0.85rem;">
                    </div>
                    <div style="grid-column:1/-1;">
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Descrição</label>
                        <input type="text" name="description" class="form-control" value="<?php echo htmlspecialchars($editShip['description'] ?? ''); ?>" style="font-size:0.85rem;">
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_active" id="is_active" <?php echo $editShip['is_active'] ? 'checked' : ''; ?>>
                        <label for="is_active" style="font-size:0.85rem;cursor:pointer;">Ativa (visível para usuários)</label>
                    </div>
                </div>
                <div style="margin-top:15px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Formulário para adicionar nave -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header"><h3 style="margin:0;font-size:1rem;"><i class="fas fa-plus"></i> Adicionar Nova Nave</h3></div>
        <div style="padding:20px;">
            <form method="POST">
                <input type="hidden" name="action" value="add_ship">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                    <div>
                        <label style="font-size:0.75rem;color:var(--text-dim);display:block;margin-bottom:3px;">Ship Key *</label>
                        <input type="text" name="ship_key" class="form-control" placeholder="ex: FALCON" required style="font-size:0.85rem;text-transform:uppercase;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;color:var(--text-dim);display:block;margin-bottom:3px;">Nome *</label>
                        <input type="text" name="name" class="form-control" placeholder="ex: Falcon X" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;color:var(--text-dim);display:block;margin-bottom:3px;">Preço R$ *</label>
                        <input type="number" name="rental_price_brl" class="form-control" placeholder="5.00" step="0.01" min="0.01" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;color:var(--text-dim);display:block;margin-bottom:3px;">Duração (h) *</label>
                        <input type="number" name="rental_duration_hours" class="form-control" placeholder="24" min="1" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;color:var(--text-dim);display:block;margin-bottom:3px;">Créditos/dia *</label>
                        <input type="number" name="credits_per_day" class="form-control" placeholder="10" min="1" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;color:var(--text-dim);display:block;margin-bottom:3px;">Ordem</label>
                        <input type="number" name="sort_order" class="form-control" value="0" style="font-size:0.85rem;">
                    </div>
                    <div style="grid-column:1/-1;">
                        <label style="font-size:0.75rem;color:var(--text-dim);display:block;margin-bottom:3px;">Descrição</label>
                        <input type="text" name="description" class="form-control" placeholder="Descrição da nave..." style="font-size:0.85rem;">
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <button type="submit" class="btn btn-primary" style="font-size:0.85rem;"><i class="fas fa-plus"></i> Adicionar Nave</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabela de naves -->
    <div class="card">
        <div class="card-header"><h3 style="margin:0;font-size:1rem;">Naves Cadastradas (<?php echo count($ships); ?>)</h3></div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Key</th>
                        <th>Nome</th>
                        <th>Preço</th>
                        <th>Duração</th>
                        <th>Créd/dia</th>
                        <th>Aluguéis</th>
                        <th>Status</th>
                        <th>Ordem</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ships)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center;padding:40px;color:var(--text-dim);">
                                <i class="fas fa-rocket" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                                Nenhuma nave cadastrada
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ships as $ship): ?>
                            <tr style="<?php echo !$ship['is_active'] ? 'opacity:0.5;' : ''; ?>">
                                <td><strong>#<?php echo $ship['id']; ?></strong></td>
                                <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($ship['ship_key']); ?></code></td>
                                <td><?php echo htmlspecialchars($ship['name']); ?></td>
                                <td>R$ <?php echo number_format((float)$ship['rental_price_brl'], 2, ',', '.'); ?></td>
                                <td><?php echo (int)$ship['rental_duration_hours']; ?>h</td>
                                <td><?php echo (int)$ship['credits_per_day']; ?></td>
                                <td>
                                    <?php if ((int)$ship['active_rental_count'] > 0): ?>
                                        <span class="badge badge-success"><?php echo $ship['active_rental_count']; ?> ativo(s)</span>
                                    <?php endif; ?>
                                    <span style="font-size:0.75rem;color:var(--text-dim);"><?php echo $ship['total_rental_count']; ?> total</span>
                                </td>
                                <td>
                                    <?php if ($ship['is_active']): ?>
                                        <span class="badge badge-success">Ativa</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Inativa</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$ship['sort_order']; ?></td>
                                <td style="white-space:nowrap;">
                                    <a href="<?php echo $ADMIN_INDEX_URL; ?>?page=exploration&tab=ships&edit_ship=<?php echo $ship['id']; ?>" class="btn btn-primary" style="padding:5px 10px;font-size:0.8rem;text-decoration:none;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir nave <?php echo htmlspecialchars($ship['name']); ?>?');">
                                        <input type="hidden" name="action" value="delete_ship">
                                        <input type="hidden" name="ship_id" value="<?php echo $ship['id']; ?>">
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

    <?php elseif ($tab === 'rentals'): ?>
    <!-- ============================================ -->
    <!-- ABA: ALUGUÉIS -->
    <!-- ============================================ -->

    <!-- Ativar Aluguel Manualmente -->
    <div class="card" style="margin-bottom:20px;border-color:rgba(0,255,136,0.25);">
        <div class="card-header" style="border-bottom-color:rgba(0,255,136,0.15);">
            <h3 style="margin:0;font-size:1rem;"><i class="fas fa-plus-circle" style="color:#00ff88;"></i> Ativar Aluguel Manualmente</h3>
        </div>
        <div style="padding:20px;">
            <p style="font-size:0.82rem;color:var(--text-dim);margin-bottom:15px;">
                Ative um aluguel de nave para um jogador pesquisando pelo <strong>email da conta</strong>. O aluguel será ativado sem cobrança.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="activate_rental_manual">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Email do Jogador *</label>
                        <input type="email" name="rental_email" class="form-control" placeholder="exemplo@email.com" required style="font-size:0.85rem;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Nave *</label>
                        <select name="rental_ship_id" class="form-control" required style="font-size:0.85rem;">
                            <option value="">Selecione...</option>
                            <?php foreach ($ships as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?> (<?php echo (int)$s['credits_per_day']; ?> créd/dia — <?php echo (int)$s['rental_duration_hours']; ?>h)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Duração personalizada (h)</label>
                        <input type="number" name="rental_duration_override" class="form-control" placeholder="Padrão da nave" min="0" style="font-size:0.85rem;" title="Deixe 0 ou vazio para usar a duração padrão da nave">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-success" style="font-size:0.85rem;width:100%;" onclick="return confirm('Confirma ativar aluguel manual para este jogador?')">
                            <i class="fas fa-rocket"></i> Ativar Aluguel
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card" style="margin-bottom:20px;">
        <div style="padding:15px;display:flex;gap:15px;align-items:center;flex-wrap:wrap;">
            <div>
                <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:4px;">Status</label>
                <select onchange="window.location.href='<?php echo $ADMIN_INDEX_URL; ?>?page=exploration&tab=rentals&rental_status='+this.value" class="form-control" style="font-size:0.85rem;min-width:150px;">
                    <option value="all" <?php echo $rentalFilter === 'all' ? 'selected' : ''; ?>>Todos</option>
                    <option value="active" <?php echo $rentalFilter === 'active' ? 'selected' : ''; ?>>Ativos</option>
                    <option value="expired" <?php echo $rentalFilter === 'expired' ? 'selected' : ''; ?>>Expirados</option>
                    <option value="cancelled" <?php echo $rentalFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelados</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Tabela de aluguéis -->
    <div class="card">
        <div class="card-header"><h3 style="margin:0;font-size:1rem;">Aluguéis (<?php echo count($rentals); ?>)</h3></div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Usuário</th>
                        <th>Nave</th>
                        <th>Preço</th>
                        <th>Créd/dia</th>
                        <th>Resgatados</th>
                        <th>Início</th>
                        <th>Expira</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rentals)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center;padding:40px;color:var(--text-dim);">
                                <i class="fas fa-satellite" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                                Nenhum aluguel encontrado
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rentals as $r): ?>
                            <?php
                                $now = time();
                                $expiresAt = strtotime($r['expires_at']);
                                $startedAt = strtotime($r['started_at']);
                                $effectiveEnd = min($now, $expiresAt);
                                $elapsed = max(0, $effectiveEnd - $startedAt);
                                $totalAccum = (int)floor(($elapsed / 86400) * (int)$r['credits_per_day']);
                                $unclaimed = max(0, $totalAccum - (int)$r['credits_claimed']);
                            ?>
                            <tr style="<?php echo $r['status'] === 'cancelled' ? 'opacity:0.5;' : ''; ?>">
                                <td><strong>#<?php echo $r['id']; ?></strong></td>
                                <td>
                                    <div style="font-size:0.85rem;"><?php echo htmlspecialchars($r['user_name'] ?? 'N/A'); ?></div>
                                    <div style="font-size:0.7rem;color:var(--text-dim);"><?php echo htmlspecialchars($r['user_email'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <code style="font-size:0.75rem;"><?php echo htmlspecialchars($r['ship_key']); ?></code>
                                    <div style="font-size:0.75rem;"><?php echo htmlspecialchars($r['ship_name'] ?? ''); ?></div>
                                </td>
                                <td>R$ <?php echo number_format((float)$r['rental_price_brl'], 2, ',', '.'); ?></td>
                                <td><?php echo (int)$r['credits_per_day']; ?></td>
                                <td>
                                    <span style="color:#00ff88;font-weight:600;"><?php echo (int)$r['credits_claimed']; ?></span>
                                    <?php if ($unclaimed > 0): ?>
                                        <span style="font-size:0.7rem;color:#ffaa00;">(+<?php echo $unclaimed; ?> pendente)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.8rem;"><?php echo date('d/m/y H:i', $startedAt); ?></td>
                                <td style="font-size:0.8rem;">
                                    <?php echo date('d/m/y H:i', $expiresAt); ?>
                                    <?php if ($r['status'] === 'active' && $now < $expiresAt): ?>
                                        <div style="font-size:0.7rem;color:#00c8ff;">
                                            <?php
                                                $remaining = $expiresAt - $now;
                                                $days = floor($remaining / 86400);
                                                $hours = floor(($remaining % 86400) / 3600);
                                                echo $days > 0 ? "{$days}d {$hours}h restantes" : "{$hours}h restantes";
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $statusLabels[$r['status']][1] ?? 'secondary'; ?>">
                                        <?php echo $statusLabels[$r['status']][0] ?? $r['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($r['status'] === 'active'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancelar aluguel #<?php echo $r['id']; ?>?\n\nMarque a caixa para reembolsar R$ <?php echo number_format((float)$r['rental_price_brl'], 2, ',', '.'); ?>');">
                                            <input type="hidden" name="action" value="cancel_rental">
                                            <input type="hidden" name="rental_id" value="<?php echo $r['id']; ?>">
                                            <div style="display:flex;align-items:center;gap:4px;">
                                                <label style="font-size:0.7rem;white-space:nowrap;cursor:pointer;" title="Reembolsar valor pago">
                                                    <input type="checkbox" name="refund"> Reembolso
                                                </label>
                                                <button type="submit" class="btn btn-danger" style="padding:4px 8px;font-size:0.75rem;">
                                                    <i class="fas fa-ban"></i> Cancelar
                                                </button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:0.75rem;color:var(--text-dim);">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tab === 'settings'): ?>
    <!-- ============================================ -->
    <!-- ABA: CONFIGURAÇÕES -->
    <!-- ============================================ -->
    <div class="card">
        <div class="card-header"><h3 style="margin:0;font-size:1rem;"><i class="fas fa-cog"></i> Configurações de Exploração</h3></div>
        <div style="padding:20px;">
            <form method="POST">
                <input type="hidden" name="action" value="update_settings">

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:15px;">
                            <input type="checkbox" name="exploration_enabled" id="exploration_enabled"
                                <?php echo ($settings['exploration_enabled'] ?? 'true') === 'true' ? 'checked' : ''; ?>>
                            <label for="exploration_enabled" style="font-size:0.9rem;cursor:pointer;font-weight:600;">
                                Exploração Habilitada
                            </label>
                        </div>
                        <p style="font-size:0.8rem;color:var(--text-dim);margin:0;">
                            Desmarque para desativar temporariamente o sistema de exploração para todos os usuários.
                        </p>
                    </div>

                    <div>
                        <label style="font-size:0.85rem;color:var(--text-dim);display:block;margin-bottom:6px;">Máximo de aluguéis simultâneos por usuário</label>
                        <input type="number" name="max_rentals" class="form-control"
                            value="<?php echo (int)($settings['exploration_max_rentals_per_user'] ?? 3); ?>"
                            min="1" max="20" style="font-size:0.9rem;max-width:120px;">
                        <p style="font-size:0.75rem;color:var(--text-dim);margin-top:4px;">
                            Quantas naves cada usuário pode ter ativas ao mesmo tempo.
                        </p>
                    </div>
                </div>

                <div style="margin-top:20px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Configurações</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
