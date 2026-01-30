<?php
// ============================================
// UNOBIX - Gerenciamento de Anúncios
// Arquivo: admin/pages/ads.php
// ============================================

$pageTitle = 'Anúncios';

$message = '';
$error = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'save_config':
                $configs = [
                    'ads_enabled' => isset($_POST['ads_enabled']) ? 'true' : 'false',
                    'ads_debug_mode' => isset($_POST['ads_debug_mode']) ? 'true' : 'false',
                    'ads_tracking_enabled' => isset($_POST['ads_tracking_enabled']) ? 'true' : 'false',
                    'ads_pregame_enabled' => isset($_POST['pregame_enabled']) ? 'true' : 'false',
                    'ads_pregame_total_duration' => (int)$_POST['pregame_total_duration'],
                    'ads_pregame_min_duration' => (int)$_POST['pregame_min_duration'],
                    'ads_pregame_rotation_interval' => (int)$_POST['pregame_rotation_interval'],
                    'ads_pregame_max_slots' => (int)$_POST['pregame_max_slots'],
                    'ads_pregame_skip_enabled' => isset($_POST['pregame_skip_enabled']) ? 'true' : 'false',
                    'ads_pregame_skip_after' => (int)$_POST['pregame_skip_after'],
                    'ads_endgame_enabled' => isset($_POST['endgame_enabled']) ? 'true' : 'false',
                    'ads_endgame_display_mode' => $_POST['endgame_display_mode'],
                    'ads_endgame_max_slots' => (int)$_POST['endgame_max_slots'],
                    'ads_endgame_rotation_interval' => (int)$_POST['endgame_rotation_interval'],
                    'ads_endgame_auto_rotate' => isset($_POST['endgame_auto_rotate']) ? 'true' : 'false',
                    'ads_endgame_show_on_gameover' => isset($_POST['endgame_show_on_gameover']) ? 'true' : 'false',
                ];
                
                $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value, is_public, updated_at) 
                                       VALUES (?, ?, 1, NOW()) 
                                       ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()");
                
                foreach ($configs as $key => $value) {
                    $stmt->execute([$key, $value]);
                }
                
                $message = "Configurações salvas com sucesso!";
                break;
                
            case 'add_slot':
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM ad_slots WHERE slot_type = ?");
                $stmt->execute([$_POST['slot_type']]);
                $nextOrder = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("INSERT INTO ad_slots (slot_name, slot_type, position, script_code, width, height, 
                                       duration_seconds, display_order, custom_css, notes, provider, is_active, created_at) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $_POST['slot_name'],
                    $_POST['slot_type'],
                    $_POST['position'] ?? 'center',
                    $_POST['script_code'],
                    $_POST['width'] ?: null,
                    $_POST['height'] ?: null,
                    (int)$_POST['duration_seconds'] ?: 5,
                    $nextOrder,
                    $_POST['custom_css'] ?: null,
                    $_POST['notes'] ?: null,
                    $_POST['provider'] ?: null,
                    isset($_POST['is_active']) ? 1 : 0
                ]);
                
                $message = "Slot criado com sucesso!";
                break;
                
            case 'update_slot':
                $stmt = $pdo->prepare("UPDATE ad_slots SET 
                                       slot_name = ?, slot_type = ?, position = ?, script_code = ?,
                                       width = ?, height = ?, duration_seconds = ?, custom_css = ?,
                                       notes = ?, provider = ?, is_active = ?, updated_at = NOW()
                                       WHERE id = ?");
                $stmt->execute([
                    $_POST['slot_name'],
                    $_POST['slot_type'],
                    $_POST['position'] ?? 'center',
                    $_POST['script_code'],
                    $_POST['width'] ?: null,
                    $_POST['height'] ?: null,
                    (int)$_POST['duration_seconds'] ?: 5,
                    $_POST['custom_css'] ?: null,
                    $_POST['notes'] ?: null,
                    $_POST['provider'] ?: null,
                    isset($_POST['is_active']) ? 1 : 0,
                    (int)$_POST['slot_id']
                ]);
                
                $message = "Slot atualizado com sucesso!";
                break;
                
            case 'delete_slot':
                $stmt = $pdo->prepare("DELETE FROM ad_slots WHERE id = ?");
                $stmt->execute([(int)$_POST['slot_id']]);
                $message = "Slot removido!";
                break;
                
            case 'toggle_slot':
                $stmt = $pdo->prepare("UPDATE ad_slots SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?");
                $stmt->execute([(int)$_POST['slot_id']]);
                $message = "Status alterado!";
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Carregar configurações
$config = [];
try {
    $stmt = $pdo->query("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'ads_%'");
    while ($row = $stmt->fetch()) {
        $key = str_replace('ads_', '', $row['config_key']);
        $value = $row['config_value'];
        if ($value === 'true') $value = true;
        elseif ($value === 'false') $value = false;
        elseif (is_numeric($value)) $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
        $config[$key] = $value;
    }
} catch (Exception $e) {}

// Valores padrão
$config = array_merge([
    'enabled' => true,
    'debug_mode' => false,
    'tracking_enabled' => true,
    'pregame_enabled' => true,
    'pregame_total_duration' => 10,
    'pregame_min_duration' => 5,
    'pregame_rotation_interval' => 5,
    'pregame_max_slots' => 3,
    'pregame_skip_enabled' => false,
    'pregame_skip_after' => 5,
    'endgame_enabled' => true,
    'endgame_display_mode' => 'grid',
    'endgame_max_slots' => 4,
    'endgame_rotation_interval' => 8,
    'endgame_auto_rotate' => true,
    'endgame_show_on_gameover' => true,
], $config);

// Carregar slots
$slots = [];
try {
    $slots = $pdo->query("
        SELECT s.*, 
               COUNT(DISTINCT i.id) as impressions_30d,
               COUNT(DISTINCT c.id) as clicks_30d
        FROM ad_slots s
        LEFT JOIN ad_impressions i ON s.id = i.slot_id AND i.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        LEFT JOIN ad_clicks c ON s.id = c.slot_id AND c.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY s.id
        ORDER BY s.slot_type, s.display_order
    ")->fetchAll();
} catch (Exception $e) {}

// Estatísticas
$stats = ['impressions' => 0, 'clicks' => 0, 'ctr' => 0];
try {
    $stats = $pdo->query("
        SELECT 
            COUNT(DISTINCT i.id) as impressions,
            COUNT(DISTINCT c.id) as clicks
        FROM ad_impressions i
        LEFT JOIN ad_clicks c ON DATE(i.created_at) = DATE(c.created_at)
        WHERE i.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")->fetch();
    $stats['ctr'] = $stats['impressions'] > 0 ? round(($stats['clicks'] / $stats['impressions']) * 100, 2) : 0;
} catch (Exception $e) {}

// Slot para edição
$editSlot = null;
if (isset($_GET['edit'])) {
    foreach ($slots as $s) {
        if ($s['id'] == $_GET['edit']) {
            $editSlot = $s;
            break;
        }
    }
}
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-ad"></i> Gerenciamento de Anúncios</h1>
        <p class="page-subtitle">Configure os anúncios do jogo para gerar receita</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="icon primary"><i class="fas fa-eye"></i></div>
            <div class="value"><?php echo number_format($stats['impressions']); ?></div>
            <div class="label">Impressões (30d)</div>
        </div>
        <div class="stat-card">
            <div class="icon success"><i class="fas fa-mouse-pointer"></i></div>
            <div class="value"><?php echo number_format($stats['clicks']); ?></div>
            <div class="label">Cliques (30d)</div>
        </div>
        <div class="stat-card">
            <div class="icon warning"><i class="fas fa-percentage"></i></div>
            <div class="value"><?php echo $stats['ctr']; ?>%</div>
            <div class="label">CTR</div>
        </div>
        <div class="stat-card">
            <div class="icon danger"><i class="fas fa-th-large"></i></div>
            <div class="value"><?php echo count($slots); ?></div>
            <div class="label">Slots Ativos</div>
        </div>
    </div>
    
    <!-- Tabs -->
    <div class="panel" style="margin-top: 30px;">
        <div class="panel-body">
            <div class="tabs">
                <a href="#config" class="tab active" onclick="showTab('config')">
                    <i class="fas fa-cog"></i> Configurações
                </a>
                <a href="#slots" class="tab" onclick="showTab('slots')">
                    <i class="fas fa-th-large"></i> Slots (<?php echo count($slots); ?>)
                </a>
                <a href="#new-slot" class="tab" onclick="showTab('new-slot')">
                    <i class="fas fa-plus"></i> Novo Slot
                </a>
            </div>
        </div>
    </div>
    
    <!-- Tab: Configurações -->
    <div id="tab-config" class="tab-content">
        <form method="POST">
            <input type="hidden" name="action" value="save_config">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <!-- Configurações Gerais -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title"><i class="fas fa-sliders-h"></i> Configurações Gerais</h3>
                    </div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="ads_enabled" <?php echo $config['enabled'] ? 'checked' : ''; ?>>
                                <span>Sistema de Anúncios Habilitado</span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="ads_tracking_enabled" <?php echo $config['tracking_enabled'] ? 'checked' : ''; ?>>
                                <span>Rastrear Impressões e Cliques</span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="ads_debug_mode" <?php echo $config['debug_mode'] ? 'checked' : ''; ?>>
                                <span>Modo Debug (Console)</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Pré-Jogo -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title"><i class="fas fa-hourglass-start"></i> Tela de Carregamento</h3>
                    </div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="pregame_enabled" <?php echo $config['pregame_enabled'] ? 'checked' : ''; ?>>
                                <span>Anúncios Pré-Jogo</span>
                            </label>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Duração Total (s)</label>
                                <input type="number" name="pregame_total_duration" class="form-control" 
                                       value="<?php echo $config['pregame_total_duration']; ?>" min="3" max="60">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Duração Mínima (s)</label>
                                <input type="number" name="pregame_min_duration" class="form-control" 
                                       value="<?php echo $config['pregame_min_duration']; ?>" min="1" max="30">
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Rotação Entre Ads (s)</label>
                                <input type="number" name="pregame_rotation_interval" class="form-control" 
                                       value="<?php echo $config['pregame_rotation_interval']; ?>" min="2" max="30">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Máx. Slots</label>
                                <input type="number" name="pregame_max_slots" class="form-control" 
                                       value="<?php echo $config['pregame_max_slots']; ?>" min="1" max="10">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="pregame_skip_enabled" <?php echo $config['pregame_skip_enabled'] ? 'checked' : ''; ?>>
                                <span>Permitir Pular</span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Pular Após (s)</label>
                            <input type="number" name="pregame_skip_after" class="form-control" 
                                   value="<?php echo $config['pregame_skip_after']; ?>" min="1" max="30">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Pós-Jogo -->
            <div class="panel" style="margin-top: 30px;">
                <div class="panel-header">
                    <h3 class="panel-title"><i class="fas fa-flag-checkered"></i> Tela Final (Pós-Jogo)</h3>
                </div>
                <div class="panel-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="endgame_enabled" <?php echo $config['endgame_enabled'] ? 'checked' : ''; ?>>
                                <span>Anúncios Pós-Jogo</span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="endgame_auto_rotate" <?php echo $config['endgame_auto_rotate'] ? 'checked' : ''; ?>>
                                <span>Rotação Automática</span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="endgame_show_on_gameover" <?php echo $config['endgame_show_on_gameover'] ? 'checked' : ''; ?>>
                                <span>Mostrar no Game Over</span>
                            </label>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 15px;">
                        <div class="form-group">
                            <label class="form-label">Modo de Exibição</label>
                            <select name="endgame_display_mode" class="form-control">
                                <option value="grid" <?php echo $config['endgame_display_mode'] === 'grid' ? 'selected' : ''; ?>>Grid (Grade)</option>
                                <option value="carousel" <?php echo $config['endgame_display_mode'] === 'carousel' ? 'selected' : ''; ?>>Carousel (Rotativo)</option>
                                <option value="stacked" <?php echo $config['endgame_display_mode'] === 'stacked' ? 'selected' : ''; ?>>Stacked (Empilhado)</option>
                                <option value="single" <?php echo $config['endgame_display_mode'] === 'single' ? 'selected' : ''; ?>>Single (Um por vez)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Máx. Slots</label>
                            <input type="number" name="endgame_max_slots" class="form-control" 
                                   value="<?php echo $config['endgame_max_slots']; ?>" min="1" max="10">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Intervalo Rotação (s)</label>
                            <input type="number" name="endgame_rotation_interval" class="form-control" 
                                   value="<?php echo $config['endgame_rotation_interval']; ?>" min="3" max="60">
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 20px; text-align: right;">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Salvar Configurações
                </button>
            </div>
        </form>
    </div>
    
    <!-- Tab: Slots -->
    <div id="tab-slots" class="tab-content" style="display: none;">
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-th-large"></i> Slots de Anúncios</h3>
            </div>
            <div class="panel-body">
                <?php if (empty($slots)): ?>
                    <div class="empty-state">
                        <i class="fas fa-ad"></i>
                        <h3>Nenhum slot cadastrado</h3>
                        <p>Crie seu primeiro slot de anúncio</p>
                        <button onclick="showTab('new-slot')" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Criar Slot
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Tipo</th>
                                    <th>Dimensões</th>
                                    <th>Duração</th>
                                    <th>Impressões</th>
                                    <th>Cliques</th>
                                    <th>CTR</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($slots as $s): ?>
                            <?php $ctr = $s['impressions_30d'] > 0 ? round(($s['clicks_30d'] / $s['impressions_30d']) * 100, 2) : 0; ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($s['slot_name']); ?></strong>
                                    <?php if ($s['provider']): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-dim);"><?php echo htmlspecialchars($s['provider']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $typeColors = ['pregame' => 'primary', 'endgame' => 'success', 'interstitial' => 'warning', 'banner' => 'danger'];
                                    ?>
                                    <span class="badge badge-<?php echo $typeColors[$s['slot_type']] ?? 'primary'; ?>">
                                        <?php echo $s['slot_type']; ?>
                                    </span>
                                </td>
                                <td><?php echo ($s['width'] ?: '-') . ' x ' . ($s['height'] ?: '-'); ?></td>
                                <td><?php echo $s['duration_seconds']; ?>s</td>
                                <td><?php echo number_format($s['impressions_30d']); ?></td>
                                <td><?php echo number_format($s['clicks_30d']); ?></td>
                                <td><?php echo $ctr; ?>%</td>
                                <td>
                                    <?php if ($s['is_active']): ?>
                                        <span class="badge badge-success"><i class="fas fa-check"></i> Ativo</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><i class="fas fa-times"></i> Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="?page=ads&edit=<?php echo $s['id']; ?>" class="btn btn-outline btn-sm">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_slot">
                                            <input type="hidden" name="slot_id" value="<?php echo $s['id']; ?>">
                                            <button type="submit" class="btn btn-<?php echo $s['is_active'] ? 'warning' : 'success'; ?> btn-sm">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Excluir este slot?');">
                                            <input type="hidden" name="action" value="delete_slot">
                                            <input type="hidden" name="slot_id" value="<?php echo $s['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
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
    
    <!-- Tab: Novo/Editar Slot -->
    <div id="tab-new-slot" class="tab-content" style="display: none;">
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="fas fa-<?php echo $editSlot ? 'edit' : 'plus'; ?>"></i> 
                    <?php echo $editSlot ? 'Editar Slot' : 'Novo Slot de Anúncio'; ?>
                </h3>
            </div>
            <div class="panel-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?php echo $editSlot ? 'update_slot' : 'add_slot'; ?>">
                    <?php if ($editSlot): ?>
                        <input type="hidden" name="slot_id" value="<?php echo $editSlot['id']; ?>">
                    <?php endif; ?>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label class="form-label">Nome do Slot *</label>
                            <input type="text" name="slot_name" class="form-control" required
                                   value="<?php echo htmlspecialchars($editSlot['slot_name'] ?? ''); ?>"
                                   placeholder="Ex: Banner Principal Pré-Jogo">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Tipo *</label>
                            <select name="slot_type" class="form-control" required>
                                <option value="pregame" <?php echo ($editSlot['slot_type'] ?? '') === 'pregame' ? 'selected' : ''; ?>>Pré-Jogo (Carregamento)</option>
                                <option value="endgame" <?php echo ($editSlot['slot_type'] ?? '') === 'endgame' ? 'selected' : ''; ?>>Pós-Jogo (Resultado)</option>
                                <option value="interstitial" <?php echo ($editSlot['slot_type'] ?? '') === 'interstitial' ? 'selected' : ''; ?>>Intersticial</option>
                                <option value="banner" <?php echo ($editSlot['slot_type'] ?? '') === 'banner' ? 'selected' : ''; ?>>Banner Fixo</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label class="form-label">Largura</label>
                            <input type="text" name="width" class="form-control"
                                   value="<?php echo htmlspecialchars($editSlot['width'] ?? ''); ?>"
                                   placeholder="300 ou 100%">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Altura</label>
                            <input type="text" name="height" class="form-control"
                                   value="<?php echo htmlspecialchars($editSlot['height'] ?? ''); ?>"
                                   placeholder="250 ou auto">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Duração (segundos)</label>
                            <input type="number" name="duration_seconds" class="form-control"
                                   value="<?php echo $editSlot['duration_seconds'] ?? 5; ?>" min="0" max="120">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Código do Anúncio (HTML/JavaScript) *</label>
                        <textarea name="script_code" class="form-control" rows="8" required
                                  placeholder="Cole aqui o código fornecido pelo seu provedor de anúncios..."><?php echo htmlspecialchars($editSlot['script_code'] ?? ''); ?></textarea>
                        <small style="color: var(--text-dim);">Aceita HTML, JavaScript e tags de terceiros</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">CSS Personalizado (opcional)</label>
                        <textarea name="custom_css" class="form-control" rows="3"
                                  placeholder=".ad-container { ... }"><?php echo htmlspecialchars($editSlot['custom_css'] ?? ''); ?></textarea>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label class="form-label">Provedor</label>
                            <input type="text" name="provider" class="form-control"
                                   value="<?php echo htmlspecialchars($editSlot['provider'] ?? ''); ?>"
                                   placeholder="Ex: PropellerAds, Adsterra">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Notas/Observações</label>
                            <input type="text" name="notes" class="form-control"
                                   value="<?php echo htmlspecialchars($editSlot['notes'] ?? ''); ?>"
                                   placeholder="Ex: Campanha Janeiro 2026">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="is_active" <?php echo ($editSlot['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <span>Slot Ativo</span>
                        </label>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $editSlot ? 'Atualizar Slot' : 'Criar Slot'; ?>
                        </button>
                        <?php if ($editSlot): ?>
                            <a href="?page=ads" class="btn btn-outline">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function showTab(tab) {
    // Esconder todas as tabs
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    
    // Mostrar tab selecionada
    document.getElementById('tab-' + tab).style.display = 'block';
    event.target.classList.add('active');
}

// Se tem slot para editar, mostrar tab de edição
<?php if ($editSlot): ?>
document.addEventListener('DOMContentLoaded', function() {
    showTab('new-slot');
});
<?php endif; ?>
</script>

<style>
.toggle-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}

.toggle-label input[type="checkbox"] {
    width: 20px;
    height: 20px;
    accent-color: var(--primary);
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.tab-content {
    margin-top: 20px;
}
</style>
