<?php
// ============================================
// UNOBIX - Campaign Config (público)
// api/campaign-config.php
//
// GET-only. Retorna a configuração estática do
// Modo Campanha (settings públicos, fases ativas
// e curva de XP). Sem autenticação — usado para
// montar o mapa de fases e calibrar o cliente.
//
// Resposta:
//   {
//     success: true,
//     data: {
//       version: "<sha>",
//       settings: { ...kv pares is_public=1... },
//       stages: [ { stage_id, sector, ..., is_boss }, ... ],
//       xp_table: [ { level, xp_required }, ... ]
//     }
//   }
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    // Settings públicos (is_public=1) — flags de UI, manutenção, ranking, etc
    $settingsStmt = $pdo->query("
        SELECT setting_key, setting_value, value_type
        FROM campaign_settings
        WHERE is_public = 1
        ORDER BY setting_key
    ");
    $settings = [];
    while ($row = $settingsStmt->fetch()) {
        $settings[$row['setting_key']] = castSettingValue($row['setting_value'], $row['value_type']);
    }

    // Fases ativas
    $stagesStmt = $pdo->query("
        SELECT stage_id, sector, order_in_sector, name, description,
               duration_seconds, credit_cost, min_level, xp_reward, brl_base,
               is_boss, boss_id
        FROM campaign_stages
        WHERE is_enabled = 1
        ORDER BY sector, order_in_sector
    ");
    $stages = [];
    while ($row = $stagesStmt->fetch()) {
        $stages[] = [
            'stage_id'         => $row['stage_id'],
            'sector'           => (int)$row['sector'],
            'order'            => (int)$row['order_in_sector'],
            'name'             => $row['name'],
            'description'      => $row['description'],
            'duration_seconds' => (int)$row['duration_seconds'],
            'credit_cost'      => (int)$row['credit_cost'],
            'min_level'        => (int)$row['min_level'],
            'xp_reward'        => (int)$row['xp_reward'],
            'brl_base'         => (float)$row['brl_base'],
            'is_boss'          => (bool)$row['is_boss'],
            'boss_id'          => $row['boss_id'] !== null ? (int)$row['boss_id'] : null,
        ];
    }

    // Curva de XP por nível
    $xpStmt = $pdo->query("SELECT level, xp_required FROM campaign_xp_table ORDER BY level");
    $xpTable = [];
    while ($row = $xpStmt->fetch()) {
        $xpTable[] = [
            'level'       => (int)$row['level'],
            'xp_required' => (int)$row['xp_required'],
        ];
    }

    // Versão simples baseada no maior updated_at — útil para cache no cliente
    $verStmt = $pdo->query("
        SELECT GREATEST(
            COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM campaign_settings), 0),
            COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM campaign_stages), 0)
        ) AS v
    ");
    $version = (string)($verStmt->fetch()['v'] ?? '0');

    header('Cache-Control: public, max-age=30');

    echo json_encode([
        'success' => true,
        'data' => [
            'version'  => $version,
            'settings' => $settings,
            'stages'   => $stages,
            'xp_table' => $xpTable,
        ],
    ]);

} catch (Exception $e) {
    error_log('campaign-config error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}

// Converte string -> tipo apropriado conforme value_type da tabela
function castSettingValue($value, $type) {
    switch ($type) {
        case 'int':     return (int)$value;
        case 'decimal': return (float)$value;
        case 'bool':    return $value === 'true' || $value === '1';
        case 'json':    $d = json_decode($value, true); return $d === null ? $value : $d;
        case 'string':
        default:        return (string)$value;
    }
}
