<?php
// ============================================
// UNOBIX - Admin: Modo Campanha
// Arquivo: admin/pages/campaign.php
//
// MVP da página: aba Fases (CRUD leve) + toggles globais
// (manutenção, setor 2). Outras tabs do plano (XP, vidas,
// recompensas, monetização, etc) serão adicionadas em
// iterações seguintes ao mesmo arquivo.
// ============================================

$pageTitle = 'Modo Campanha';
$message = '';
$error = '';

// ============================================
// HELPERS
// ============================================
function campaignSetSetting($pdo, $key, $value, $isPublic = 0) {
    $stmt = $pdo->prepare("
        INSERT INTO campaign_settings (setting_key, setting_value, value_type, is_public, updated_at)
        VALUES (?, ?, 'string', ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $stmt->execute([$key, $value, $isPublic]);
}

function campaignGetSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM campaign_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

// ============================================
// PROCESSAR AÇÕES POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'toggle_stage':
                $stageId = trim($_POST['stage_id'] ?? '');
                $enable  = isset($_POST['enable']) && $_POST['enable'] === '1' ? 1 : 0;
                if ($stageId === '') throw new Exception('stage_id ausente');
                $pdo->prepare("UPDATE campaign_stages SET is_enabled = ? WHERE stage_id = ?")
                    ->execute([$enable, $stageId]);
                $message = "Fase {$stageId} " . ($enable ? 'habilitada' : 'desabilitada') . '.';
                break;

            case 'update_stage':
                $stageId  = trim($_POST['stage_id'] ?? '');
                if ($stageId === '') throw new Exception('stage_id ausente');

                $name        = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $duration    = max(0,   (int)($_POST['duration_seconds'] ?? 60));
                $cost        = max(0,   (int)($_POST['credit_cost'] ?? 1));
                $minLevel    = max(1,   (int)($_POST['min_level'] ?? 1));
                $xpReward    = max(0,   (int)($_POST['xp_reward'] ?? 0));
                $brlBase     = max(0.0, (float)($_POST['brl_base'] ?? 0));
                $isBoss      = isset($_POST['is_boss']) ? 1 : 0;

                // waves_json: aceita vazio ou JSON válido. Valida antes de salvar.
                $wavesJson = trim($_POST['waves_json'] ?? '');
                if ($wavesJson === '') {
                    $wavesJsonValue = null;
                } else {
                    $decoded = json_decode($wavesJson, true);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception('waves_json inválido: ' . json_last_error_msg());
                    }
                    // re-encode pra normalizar formatação
                    $wavesJsonValue = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }

                $pdo->prepare("
                    UPDATE campaign_stages
                    SET name = ?, description = ?, duration_seconds = ?, credit_cost = ?,
                        min_level = ?, xp_reward = ?, brl_base = ?, is_boss = ?, waves_json = ?
                    WHERE stage_id = ?
                ")->execute([
                    $name, $description, $duration, $cost,
                    $minLevel, $xpReward, $brlBase, $isBoss,
                    $wavesJsonValue, $stageId,
                ]);
                $message = "Fase {$stageId} salva.";
                break;

            case 'update_boss':
                $bossId   = (int)($_POST['boss_id'] ?? 0);
                $name     = trim($_POST['name'] ?? '');
                $sector   = max(1, (int)($_POST['sector'] ?? 1));
                $hpTotal  = max(1, (int)($_POST['hp_total'] ?? 100));
                $scale    = max(0.1, (float)($_POST['scale'] ?? 1.0));
                $speed    = max(0.0, (float)($_POST['speed'] ?? 1.0));
                $contact  = max(0, (int)($_POST['contact_damage'] ?? 0));
                $brlExtra = max(0.0, (float)($_POST['extra_brl'] ?? 0));
                $xpExtra  = max(0, (int)($_POST['extra_xp'] ?? 0));
                $skinChance = max(0.0, min(100.0, (float)($_POST['skin_drop_chance'] ?? 0)));
                $skinId   = empty($_POST['skin_drop_id']) ? null : (int)$_POST['skin_drop_id'];
                $berserk  = isset($_POST['berserk_enabled']) ? 1 : 0;
                $hpPersist= isset($_POST['hp_persists_on_continue']) ? 1 : 0;
                $enabled  = isset($_POST['is_enabled']) ? 1 : 0;
                $patterns = trim($_POST['attack_patterns_json'] ?? '');
                $thresholds = trim($_POST['phase_thresholds_json'] ?? '');
                $drops    = trim($_POST['guaranteed_drops_json'] ?? '');

                $patternsValue = $patterns === '' ? null : json_encode(json_decode($patterns, true), JSON_UNESCAPED_UNICODE);
                if ($patterns !== '' && $patternsValue === false) throw new Exception('attack_patterns_json inválido');
                $thresholdsValue = $thresholds === '' ? null : json_encode(json_decode($thresholds, true));
                if ($thresholds !== '' && $thresholdsValue === false) throw new Exception('phase_thresholds_json inválido');
                $dropsValue = $drops === '' ? null : json_encode(json_decode($drops, true));
                if ($drops !== '' && $dropsValue === false) throw new Exception('guaranteed_drops_json inválido');

                if ($bossId > 0) {
                    $pdo->prepare("
                        UPDATE campaign_bosses
                        SET name = ?, sector = ?, hp_total = ?, scale = ?, speed = ?,
                            attack_patterns_json = ?, phase_thresholds_json = ?,
                            extra_brl = ?, extra_xp = ?, guaranteed_drops_json = ?,
                            skin_drop_chance = ?, skin_drop_id = ?,
                            berserk_enabled = ?, hp_persists_on_continue = ?,
                            is_enabled = ?
                        WHERE id = ?
                    ")->execute([
                        $name, $sector, $hpTotal, $scale, $speed,
                        $patternsValue, $thresholdsValue,
                        $brlExtra, $xpExtra, $dropsValue,
                        $skinChance, $skinId,
                        $berserk, $hpPersist, $enabled, $bossId,
                    ]);
                } else {
                    $sprite = trim($_POST['sprite_key'] ?? '');
                    if ($sprite === '') throw new Exception('sprite_key obrigatório');
                    $pdo->prepare("
                        INSERT INTO campaign_bosses
                            (name, sector, sprite_key, hp_total, scale, speed,
                             attack_patterns_json, phase_thresholds_json,
                             extra_brl, extra_xp, guaranteed_drops_json,
                             skin_drop_chance, skin_drop_id,
                             berserk_enabled, hp_persists_on_continue, is_enabled, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ")->execute([
                        $name, $sector, $sprite, $hpTotal, $scale, $speed,
                        $patternsValue, $thresholdsValue,
                        $brlExtra, $xpExtra, $dropsValue,
                        $skinChance, $skinId,
                        $berserk, $hpPersist, $enabled,
                    ]);
                }
                $message = "Boss salvo.";
                break;

            case 'update_globals':
                $maintenance     = isset($_POST['maintenance']) ? 'true' : 'false';
                $maintMsg        = trim($_POST['maintenance_msg'] ?? '');
                $sector2Enabled  = isset($_POST['sector2_enabled']) ? 'true' : 'false';
                $showHeader      = isset($_POST['show_in_header']) ? 'true' : 'false';
                $showDashboard   = isset($_POST['show_in_dashboard']) ? 'true' : 'false';
                campaignSetSetting($pdo, 'campaign.launch.maintenance',         $maintenance,    1);
                campaignSetSetting($pdo, 'campaign.launch.maintenance_msg',     $maintMsg,       1);
                campaignSetSetting($pdo, 'campaign.launch.sector2_enabled',     $sector2Enabled, 1);
                campaignSetSetting($pdo, 'campaign.launch.show_in_header',      $showHeader,     1);
                campaignSetSetting($pdo, 'campaign.launch.show_in_dashboard',   $showDashboard,  1);
                $message = 'Configurações globais salvas.';
                break;

            default:
                $error = 'Ação desconhecida.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================
// CARREGAR DADOS (para render)
// ============================================
$stages = [];
try {
    $result = $pdo->query("
        SELECT stage_id, sector, order_in_sector, name, description,
               duration_seconds, credit_cost, min_level, xp_reward,
               brl_base, is_boss, is_enabled, waves_json, updated_at
        FROM campaign_stages ORDER BY sector, order_in_sector
    ");
    while ($row = $result->fetch()) $stages[] = $row;
} catch (Exception $e) {
    $error = $e->getMessage();
}

$gMaint        = campaignGetSetting($pdo, 'campaign.launch.maintenance', 'false') === 'true';
$gMaintMsg     = campaignGetSetting($pdo, 'campaign.launch.maintenance_msg', '');
$gSector2      = campaignGetSetting($pdo, 'campaign.launch.sector2_enabled', 'false') === 'true';
$gShowHeader   = campaignGetSetting($pdo, 'campaign.launch.show_in_header', 'true') === 'true';
$gShowDash     = campaignGetSetting($pdo, 'campaign.launch.show_in_dashboard', 'true') === 'true';

// Edição inline: ?edit=stage_id
$editingStage = null;
if (!empty($_GET['edit'])) {
    foreach ($stages as $s) {
        if ($s['stage_id'] === $_GET['edit']) { $editingStage = $s; break; }
    }
}

function fmtBoolBadge($b) {
    return $b
        ? '<span style="background:#143a25;color:#5fdb91;padding:2px 8px;border-radius:999px;font-size:11px">ATIVA</span>'
        : '<span style="background:#3a1010;color:#ff6b6b;padding:2px 8px;border-radius:999px;font-size:11px">OFF</span>';
}
?>

<div style="margin-bottom:16px">
  <h1 style="margin:0 0 4px;font-size:22px"><i class="fas fa-rocket" style="color:#5cd5ff"></i> Modo Campanha</h1>
</div>

<?php
$activeTab = $_GET['tab'] ?? 'stages';
$tabs = [
    'stages'    => ['Fases',      'fa-list'],
    'bosses'    => ['Bosses',     'fa-skull'],
    'xp'        => ['XP & Níveis','fa-chart-line'],
    'settings'  => ['Configurações','fa-sliders-h'],
    'players'   => ['Jogadores',  'fa-user-shield'],
    'dashboard' => ['Métricas',   'fa-tachometer-alt'],
];
?>
<div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid #2a3375;padding-bottom:0;flex-wrap:wrap">
  <?php foreach ($tabs as $key => $meta): ?>
    <a href="?page=campaign&tab=<?= $key ?>"
       style="padding:8px 14px;text-decoration:none;font-size:13px;
              color:<?= $activeTab === $key ? '#5cd5ff' : '#8a93c8' ?>;
              border-bottom:2px solid <?= $activeTab === $key ? '#5cd5ff' : 'transparent' ?>;
              margin-bottom:-1px">
      <i class="fas <?= $meta[1] ?>"></i> <?= htmlspecialchars($meta[0]) ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($message): ?>
  <div style="background:#143a25;color:#5fdb91;padding:10px 14px;border-radius:6px;margin-bottom:12px;border:1px solid #1f5e3a">
    ✓ <?= htmlspecialchars($message) ?>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div style="background:#3a1010;color:#ff6b6b;padding:10px 14px;border-radius:6px;margin-bottom:12px;border:1px solid #6e2424">
    ⚠ <?= htmlspecialchars($error) ?>
  </div>
<?php endif; ?>

<?php if ($activeTab === 'stages'): ?>
<!-- ============================================
     CONFIGURAÇÕES GLOBAIS
     ============================================ -->
<div style="background:#0e1330;border:1px solid #2a3375;border-radius:10px;padding:18px;margin-bottom:20px">
  <h2 style="margin:0 0 12px;font-size:14px;text-transform:uppercase;letter-spacing:0.08em;color:#5cd5ff">Configurações Globais</h2>
  <form method="POST">
    <input type="hidden" name="action" value="update_globals">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input type="checkbox" name="maintenance" <?= $gMaint ? 'checked' : '' ?>>
        <span><strong>Manutenção</strong> — fecha o modo</span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input type="checkbox" name="sector2_enabled" <?= $gSector2 ? 'checked' : '' ?>>
        <span><strong>Setor 2 disponível</strong></span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input type="checkbox" name="show_in_header" <?= $gShowHeader ? 'checked' : '' ?>>
        <span>Mostrar link no header</span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input type="checkbox" name="show_in_dashboard" <?= $gShowDash ? 'checked' : '' ?>>
        <span>Mostrar card no dashboard</span>
      </label>
    </div>

    <div style="margin-top:14px">
      <label style="display:block;font-size:12px;color:#8a93c8;margin-bottom:4px">Mensagem de manutenção (mostrada ao jogador):</label>
      <input type="text" name="maintenance_msg" value="<?= htmlspecialchars($gMaintMsg) ?>"
             style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:6px;padding:8px 10px;color:#e7eaff">
    </div>

    <div style="margin-top:14px">
      <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#1c4cff,#5b1a8a);color:#fff;border:none;border-radius:6px;padding:8px 16px;cursor:pointer">
        Salvar configurações
      </button>
    </div>
  </form>
</div>

<!-- ============================================
     ABA: FASES
     ============================================ -->
<div style="background:#0e1330;border:1px solid #2a3375;border-radius:10px;padding:18px;margin-bottom:20px">
  <h2 style="margin:0 0 12px;font-size:14px;text-transform:uppercase;letter-spacing:0.08em;color:#5cd5ff">Fases (<?= count($stages) ?>)</h2>

  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="background:#161c4a;color:#8a93c8;text-align:left">
        <th style="padding:8px 10px">ID</th>
        <th style="padding:8px 10px">Nome</th>
        <th style="padding:8px 10px;text-align:center">Setor</th>
        <th style="padding:8px 10px;text-align:center">Lvl</th>
        <th style="padding:8px 10px;text-align:right">Custo</th>
        <th style="padding:8px 10px;text-align:right">XP</th>
        <th style="padding:8px 10px;text-align:right">BRL</th>
        <th style="padding:8px 10px;text-align:center">Boss</th>
        <th style="padding:8px 10px;text-align:center">Ondas</th>
        <th style="padding:8px 10px;text-align:center">Status</th>
        <th style="padding:8px 10px;text-align:center">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($stages as $s): ?>
      <?php $hasWaves = !empty($s['waves_json']); ?>
      <tr style="border-bottom:1px solid rgba(255,255,255,0.05)">
        <td style="padding:8px 10px;font-family:monospace;color:#5cd5ff"><?= htmlspecialchars($s['stage_id']) ?></td>
        <td style="padding:8px 10px"><?= htmlspecialchars($s['name']) ?></td>
        <td style="padding:8px 10px;text-align:center">S<?= (int)$s['sector'] ?>·<?= (int)$s['order_in_sector'] ?></td>
        <td style="padding:8px 10px;text-align:center"><?= (int)$s['min_level'] ?></td>
        <td style="padding:8px 10px;text-align:right;font-family:monospace"><?= (int)$s['credit_cost'] ?>💎</td>
        <td style="padding:8px 10px;text-align:right;font-family:monospace"><?= (int)$s['xp_reward'] ?></td>
        <td style="padding:8px 10px;text-align:right;font-family:monospace">R$ <?= number_format((float)$s['brl_base'], 2, ',', '.') ?></td>
        <td style="padding:8px 10px;text-align:center"><?= $s['is_boss'] ? '☠' : '—' ?></td>
        <td style="padding:8px 10px;text-align:center;color:<?= $hasWaves ? '#5fdb91' : '#ffd166' ?>"><?= $hasWaves ? '✓' : '—' ?></td>
        <td style="padding:8px 10px;text-align:center"><?= fmtBoolBadge((int)$s['is_enabled'] === 1) ?></td>
        <td style="padding:8px 10px;text-align:center;white-space:nowrap">
          <a href="?page=campaign&edit=<?= urlencode($s['stage_id']) ?>" style="color:#5cd5ff;text-decoration:none;margin-right:8px">editar</a>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="toggle_stage">
            <input type="hidden" name="stage_id" value="<?= htmlspecialchars($s['stage_id']) ?>">
            <input type="hidden" name="enable" value="<?= $s['is_enabled'] ? '0' : '1' ?>">
            <button type="submit" style="background:none;border:none;color:#ff7eea;cursor:pointer;font-size:13px;padding:0">
              <?= $s['is_enabled'] ? 'desabilitar' : 'habilitar' ?>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($editingStage): ?>
<!-- ============================================
     EDITOR DE FASE
     ============================================ -->
<div style="background:#0e1330;border:1px solid #5cd5ff;border-radius:10px;padding:18px;margin-bottom:20px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h2 style="margin:0;font-size:15px;color:#5cd5ff">
      Editando: <span style="font-family:monospace"><?= htmlspecialchars($editingStage['stage_id']) ?></span>
    </h2>
    <a href="?page=campaign" style="color:#8a93c8;text-decoration:none;font-size:13px">✕ fechar</a>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="update_stage">
    <input type="hidden" name="stage_id" value="<?= htmlspecialchars($editingStage['stage_id']) ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
      <div>
        <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">Nome</label>
        <input type="text" name="name" value="<?= htmlspecialchars($editingStage['name']) ?>" required
               style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff">
      </div>
      <div>
        <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">Duração (s)</label>
        <input type="number" name="duration_seconds" value="<?= (int)$editingStage['duration_seconds'] ?>" min="0"
               style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff">
      </div>
      <div>
        <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">Custo (créditos)</label>
        <input type="number" name="credit_cost" value="<?= (int)$editingStage['credit_cost'] ?>" min="0"
               style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff">
      </div>
      <div>
        <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">Nível mínimo</label>
        <input type="number" name="min_level" value="<?= (int)$editingStage['min_level'] ?>" min="1"
               style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff">
      </div>
      <div>
        <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">XP recompensa</label>
        <input type="number" name="xp_reward" value="<?= (int)$editingStage['xp_reward'] ?>" min="0"
               style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff">
      </div>
      <div>
        <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">BRL base</label>
        <input type="number" name="brl_base" value="<?= number_format((float)$editingStage['brl_base'], 2, '.', '') ?>" min="0" step="0.01"
               style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff">
      </div>
      <div style="display:flex;align-items:flex-end">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="is_boss" <?= $editingStage['is_boss'] ? 'checked' : '' ?>>
          <span>É boss</span>
        </label>
      </div>
    </div>

    <div style="margin-top:14px">
      <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">Descrição</label>
      <textarea name="description" rows="2"
                style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff;resize:vertical"><?= htmlspecialchars($editingStage['description'] ?? '') ?></textarea>
    </div>

    <div style="margin-top:14px">
      <label style="display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#8a93c8;margin-bottom:3px">
        <span>waves_json (JSON com <code>waves[]</code> + opcional <code>boss</code>)</span>
        <span style="color:#ffd166">vazio = fallback de 8 inimigos</span>
      </label>
      <textarea name="waves_json" rows="14" placeholder='{"waves":[{"duration_max":20,"clear_at":15,"spawns":[{"behavior":"tank","count":4,"interval":1000}]}]}'
                style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:9px 11px;color:#7eef9f;font-family:ui-monospace,Menlo,monospace;font-size:12px;line-height:1.45;resize:vertical"><?php
        if (!empty($editingStage['waves_json'])) {
            $decoded = json_decode($editingStage['waves_json'], true);
            echo htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
      ?></textarea>
      <div style="display:flex;justify-content:space-between;font-size:11px;color:#8a93c8;margin-top:4px">
        <span>Behaviors válidas: <code>tank, bullet, kamikaze, shooter, dodger</code></span>
        <span>Bosses: <code>asteroid_mother, junk_devourer</code></span>
      </div>
    </div>

    <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
      <button type="submit" style="background:linear-gradient(135deg,#1c4cff,#5b1a8a);color:#fff;border:none;border-radius:6px;padding:9px 18px;cursor:pointer;font-size:14px">
        Salvar fase
      </button>
      <a href="?page=campaign" style="color:#8a93c8;text-decoration:none">Cancelar</a>
      <span style="margin-left:auto;font-size:11px;color:#8a93c8">
        Última atualização: <?= htmlspecialchars($editingStage['updated_at'] ?? '—') ?>
      </span>
    </div>
  </form>
</div>
<?php endif; /* editingStage */ ?>

<?php endif; /* activeTab=stages */ ?>

<?php if ($activeTab === 'bosses'):
    $bosses = [];
    try {
        $bosses = $pdo->query("SELECT * FROM campaign_bosses ORDER BY sector, id")->fetchAll();
    } catch (Exception $e) {}
    $editingBoss = null;
    if (!empty($_GET['boss_id'])) {
        foreach ($bosses as $b) {
            if ((int)$b['id'] === (int)$_GET['boss_id']) { $editingBoss = $b; break; }
        }
    }
?>

<div style="background:#0e1330;border:1px solid #2a3375;border-radius:10px;padding:18px;margin-bottom:20px">
  <h2 style="margin:0 0 12px;font-size:14px;text-transform:uppercase;letter-spacing:0.08em;color:#5cd5ff">
    Bosses (<?= count($bosses) ?>)
  </h2>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="background:#161c4a;color:#8a93c8;text-align:left">
        <th style="padding:8px 10px">ID</th>
        <th style="padding:8px 10px">Nome</th>
        <th style="padding:8px 10px;text-align:center">Setor</th>
        <th style="padding:8px 10px;text-align:right">HP</th>
        <th style="padding:8px 10px;text-align:right">Dano contato</th>
        <th style="padding:8px 10px;text-align:right">BRL+</th>
        <th style="padding:8px 10px;text-align:right">XP+</th>
        <th style="padding:8px 10px">Sprite</th>
        <th style="padding:8px 10px;text-align:center">Status</th>
        <th style="padding:8px 10px"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($bosses as $b): ?>
      <tr style="border-bottom:1px solid rgba(255,255,255,0.05)">
        <td style="padding:8px 10px;font-family:monospace;color:#5cd5ff"><?= (int)$b['id'] ?></td>
        <td style="padding:8px 10px"><?= htmlspecialchars($b['name']) ?></td>
        <td style="padding:8px 10px;text-align:center"><?= (int)$b['sector'] ?></td>
        <td style="padding:8px 10px;text-align:right;font-family:monospace"><?= (int)$b['hp_total'] ?></td>
        <td style="padding:8px 10px;text-align:right;font-family:monospace"><?= (int)$b['contact_damage'] ?? 0 ?></td>
        <td style="padding:8px 10px;text-align:right;font-family:monospace">R$ <?= number_format((float)$b['extra_brl'], 2, ',', '.') ?></td>
        <td style="padding:8px 10px;text-align:right;font-family:monospace"><?= (int)$b['extra_xp'] ?></td>
        <td style="padding:8px 10px;font-family:monospace;font-size:11px"><?= htmlspecialchars($b['sprite_key']) ?></td>
        <td style="padding:8px 10px;text-align:center"><?= fmtBoolBadge((int)$b['is_enabled'] === 1) ?></td>
        <td style="padding:8px 10px;text-align:right">
          <a href="?page=campaign&tab=bosses&boss_id=<?= (int)$b['id'] ?>" style="color:#5cd5ff;text-decoration:none">editar</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($bosses)): ?>
      <tr><td colspan="10" style="padding:24px;text-align:center;color:#8a93c8">Nenhum boss cadastrado.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <p style="margin-top:10px;font-size:11px;color:#8a93c8">
    Bosses do MVP (<code>asteroid_mother</code> e <code>junk_devourer</code>) têm padrões hardcoded no engine. Esta tabela é para overrides
    de stats e drops (HP total, recompensas extras, chance de skin etc). Os <code>attack_patterns_json</code> e <code>phase_thresholds_json</code>
    ficam disponíveis para evolução futura — engine atual ignora.
  </p>
</div>

<?php if ($editingBoss): ?>
<div style="background:#0e1330;border:1px solid #5cd5ff;border-radius:10px;padding:18px;margin-bottom:20px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h2 style="margin:0;font-size:15px;color:#5cd5ff">Editando: <?= htmlspecialchars($editingBoss['name']) ?> <span style="font-family:monospace;color:#8a93c8">(<?= htmlspecialchars($editingBoss['sprite_key']) ?>)</span></h2>
    <a href="?page=campaign&tab=bosses" style="color:#8a93c8;text-decoration:none;font-size:13px">✕ fechar</a>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="update_boss">
    <input type="hidden" name="boss_id" value="<?= (int)$editingBoss['id'] ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
      <div><label style="display:block;font-size:11px;color:#8a93c8">Nome</label>
           <input type="text" name="name" value="<?= htmlspecialchars($editingBoss['name']) ?>" required
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">Setor</label>
           <input type="number" name="sector" value="<?= (int)$editingBoss['sector'] ?>" min="1" max="10"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">HP total</label>
           <input type="number" name="hp_total" value="<?= (int)$editingBoss['hp_total'] ?>" min="1"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">Escala</label>
           <input type="number" step="0.01" name="scale" value="<?= number_format((float)$editingBoss['scale'], 2, '.', '') ?>" min="0.1"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">Velocidade</label>
           <input type="number" step="0.01" name="speed" value="<?= number_format((float)$editingBoss['speed'], 2, '.', '') ?>" min="0"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">Dano contato</label>
           <input type="number" name="contact_damage" value="<?= (int)($editingBoss['contact_damage'] ?? 0) ?>" min="0"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">BRL extra</label>
           <input type="number" step="0.01" name="extra_brl" value="<?= number_format((float)$editingBoss['extra_brl'], 2, '.', '') ?>" min="0"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">XP extra</label>
           <input type="number" name="extra_xp" value="<?= (int)$editingBoss['extra_xp'] ?>" min="0"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">Skin drop chance (%)</label>
           <input type="number" step="0.1" name="skin_drop_chance" value="<?= number_format((float)$editingBoss['skin_drop_chance'], 1, '.', '') ?>" min="0" max="100"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
      <div><label style="display:block;font-size:11px;color:#8a93c8">Skin drop id</label>
           <input type="number" name="skin_drop_id" value="<?= $editingBoss['skin_drop_id'] !== null ? (int)$editingBoss['skin_drop_id'] : '' ?>"
                  style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#e7eaff"></div>
    </div>

    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:12px">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="berserk_enabled" <?= (int)$editingBoss['berserk_enabled'] ? 'checked' : '' ?>>
        <span>Modo berserk fase 3</span>
      </label>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="hp_persists_on_continue" <?= (int)$editingBoss['hp_persists_on_continue'] ? 'checked' : '' ?>>
        <span>HP persiste no continue</span>
      </label>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="is_enabled" <?= (int)$editingBoss['is_enabled'] ? 'checked' : '' ?>>
        <span>Boss ativo</span>
      </label>
    </div>

    <div style="margin-top:14px">
      <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">guaranteed_drops_json (lista de power-up keys, ex: <code>["repair","bomb"]</code>)</label>
      <textarea name="guaranteed_drops_json" rows="2"
                style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#7eef9f;font-family:ui-monospace,monospace;font-size:12px;resize:vertical"><?= htmlspecialchars($editingBoss['guaranteed_drops_json'] ?? '') ?></textarea>
    </div>
    <div style="margin-top:10px">
      <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">phase_thresholds_json (limiares de fase, ex: <code>[50,25]</code>)</label>
      <textarea name="phase_thresholds_json" rows="1"
                style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#7eef9f;font-family:ui-monospace,monospace;font-size:12px;resize:vertical"><?= htmlspecialchars($editingBoss['phase_thresholds_json'] ?? '') ?></textarea>
    </div>
    <div style="margin-top:10px">
      <label style="display:block;font-size:11px;color:#8a93c8;margin-bottom:3px">attack_patterns_json (futuro — engine atual usa hardcoded)</label>
      <textarea name="attack_patterns_json" rows="6"
                style="width:100%;background:#050720;border:1px solid #2a3375;border-radius:5px;padding:7px 9px;color:#7eef9f;font-family:ui-monospace,monospace;font-size:11px;resize:vertical"><?= htmlspecialchars($editingBoss['attack_patterns_json'] ?? '') ?></textarea>
    </div>

    <div style="margin-top:16px">
      <button type="submit" style="background:linear-gradient(135deg,#1c4cff,#5b1a8a);color:#fff;border:none;border-radius:6px;padding:9px 18px;cursor:pointer;font-size:14px">
        Salvar boss
      </button>
      <a href="?page=campaign&tab=bosses" style="color:#8a93c8;text-decoration:none;margin-left:12px">Cancelar</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php endif; /* activeTab=bosses */ ?>
