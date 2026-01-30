<?php
/**
 * UNOBIX - Admin Ads Management API
 * Gerenciamento completo de anúncios
 * 
 * Ações:
 * - get_config: Obter configuração atual
 * - save_config: Salvar configuração geral
 * - list_slots: Listar slots de anúncios
 * - add_slot: Adicionar slot de anúncio
 * - update_slot: Atualizar slot
 * - delete_slot: Remover slot
 * - reorder_slots: Reordenar slots
 * - get_stats: Estatísticas de visualizações
 * - toggle_slot: Ativar/desativar slot
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config.php';

// Verificar autenticação admin
session_start();
$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Para requisições GET públicas (get_public_config), não precisa de admin
$input = json_decode(file_get_contents('php://input'), true) ?? $_GET;
$action = $input['action'] ?? $_GET['action'] ?? '';

// Ações públicas (não precisam de admin)
$publicActions = ['get_public_config'];

if (!in_array($action, $publicActions) && !$isAdmin) {
    // Verificar senha de admin no request
    $adminPassword = $input['admin_password'] ?? '';
    if ($adminPassword !== getenv('ADMIN_PASSWORD') && $adminPassword !== 'UNOBIX_ADMIN_2026') {
        echo json_encode(['success' => false, 'error' => 'Acesso não autorizado']);
        exit;
    }
}

try {
    $pdo = getDBConnection();
    
    switch ($action) {
        case 'get_config':
            getAdsConfig($pdo);
            break;
            
        case 'get_public_config':
            getPublicAdsConfig($pdo);
            break;
            
        case 'save_config':
            saveAdsConfig($pdo, $input);
            break;
            
        case 'list_slots':
            listAdSlots($pdo, $input);
            break;
            
        case 'add_slot':
            addAdSlot($pdo, $input);
            break;
            
        case 'update_slot':
            updateAdSlot($pdo, $input);
            break;
            
        case 'delete_slot':
            deleteAdSlot($pdo, $input);
            break;
            
        case 'toggle_slot':
            toggleAdSlot($pdo, $input);
            break;
            
        case 'reorder_slots':
            reorderAdSlots($pdo, $input);
            break;
            
        case 'get_stats':
            getAdStats($pdo, $input);
            break;
            
        case 'log_impression':
            logAdImpression($pdo, $input);
            break;
            
        case 'log_click':
            logAdClick($pdo, $input);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }
    
} catch (Exception $e) {
    error_log("Admin Ads Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}

// ============================================
// FUNÇÕES
// ============================================

/**
 * Obter configuração completa de ads (admin)
 */
function getAdsConfig($pdo) {
    // Buscar configuração do banco
    $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'ads_%'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $config = [
        // Configurações gerais
        'ads_enabled' => true,
        'ads_debug_mode' => false,
        
        // Tela de carregamento (pré-jogo)
        'pregame_enabled' => true,
        'pregame_total_duration' => 10,        // Duração total em segundos
        'pregame_min_duration' => 5,           // Duração mínima (não pode pular antes)
        'pregame_skip_enabled' => false,       // Permitir pular após min_duration
        'pregame_skip_after' => 5,             // Segundos até poder pular
        'pregame_rotation_interval' => 5,      // Intervalo de rotação entre ads
        'pregame_max_slots' => 3,              // Máximo de slots ativos
        
        // Tela final (pós-jogo)
        'endgame_enabled' => true,
        'endgame_display_mode' => 'grid',      // grid, carousel, stacked
        'endgame_max_slots' => 4,              // Máximo de slots
        'endgame_auto_rotate' => true,         // Rotação automática
        'endgame_rotation_interval' => 8,      // Intervalo de rotação
        'endgame_show_on_gameover' => true,    // Mostrar também no game over
        
        // Intersticial (entre ações)
        'interstitial_enabled' => false,
        'interstitial_frequency' => 3,         // A cada X jogos
        'interstitial_duration' => 5,          // Duração em segundos
        'interstitial_skip_after' => 3,        // Pode pular após X segundos
        
        // Banner fixo
        'banner_enabled' => false,
        'banner_position' => 'bottom',         // top, bottom
        'banner_pages' => ['dashboard', 'wallet', 'staking'],
        
        // Configurações avançadas
        'cache_duration' => 300,               // Cache de config em segundos
        'fallback_enabled' => true,            // Mostrar placeholder se sem ads
        'tracking_enabled' => true,            // Rastrear impressões/cliques
    ];
    
    // Sobrescrever com valores do banco
    foreach ($rows as $row) {
        $key = str_replace('ads_', '', $row['config_key']);
        $value = $row['config_value'];
        
        // Converter tipos
        if ($value === 'true') $value = true;
        elseif ($value === 'false') $value = false;
        elseif (is_numeric($value)) $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
        elseif (substr($value, 0, 1) === '[' || substr($value, 0, 1) === '{') {
            $value = json_decode($value, true) ?? $value;
        }
        
        $config[$key] = $value;
    }
    
    echo json_encode([
        'success' => true,
        'config' => $config
    ]);
}

/**
 * Obter configuração pública (sem dados sensíveis)
 */
function getPublicAdsConfig($pdo) {
    $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key LIKE 'ads_%'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $config = [];
    foreach ($rows as $row) {
        $key = str_replace('ads_', '', $row['config_key']);
        $value = $row['config_value'];
        
        if ($value === 'true') $value = true;
        elseif ($value === 'false') $value = false;
        elseif (is_numeric($value)) $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
        
        $config[$key] = $value;
    }
    
    // Buscar slots ativos
    $stmt = $pdo->prepare("
        SELECT id, slot_name, slot_type, position, script_code, width, height, 
               display_order, custom_css
        FROM ad_slots 
        WHERE is_active = 1 
        ORDER BY slot_type, display_order
    ");
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por tipo
    $slotsByType = [
        'pregame' => [],
        'endgame' => [],
        'interstitial' => [],
        'banner' => []
    ];
    
    foreach ($slots as $slot) {
        $type = $slot['slot_type'];
        if (isset($slotsByType[$type])) {
            $slotsByType[$type][] = $slot;
        }
    }
    
    echo json_encode([
        'success' => true,
        'config' => $config,
        'slots' => $slotsByType
    ]);
}

/**
 * Salvar configuração de ads
 */
function saveAdsConfig($pdo, $input) {
    $config = $input['config'] ?? [];
    
    if (empty($config)) {
        echo json_encode(['success' => false, 'error' => 'Configuração vazia']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        foreach ($config as $key => $value) {
            $configKey = 'ads_' . $key;
            
            // Converter valor para string
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = json_encode($value);
            } else {
                $value = (string)$value;
            }
            
            // Upsert
            $stmt = $pdo->prepare("
                INSERT INTO system_config (config_key, config_value, is_public, updated_at)
                VALUES (?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()
            ");
            $stmt->execute([$configKey, $value]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Configurações salvas com sucesso'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Listar slots de anúncios
 */
function listAdSlots($pdo, $input) {
    $type = $input['type'] ?? null;
    
    $sql = "
        SELECT s.*, 
               COUNT(DISTINCT i.id) as total_impressions,
               COUNT(DISTINCT c.id) as total_clicks
        FROM ad_slots s
        LEFT JOIN ad_impressions i ON s.id = i.slot_id AND i.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        LEFT JOIN ad_clicks c ON s.id = c.slot_id AND c.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    
    $params = [];
    if ($type) {
        $sql .= " WHERE s.slot_type = ?";
        $params[] = $type;
    }
    
    $sql .= " GROUP BY s.id ORDER BY s.slot_type, s.display_order";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular CTR
    foreach ($slots as &$slot) {
        $impressions = (int)$slot['total_impressions'];
        $clicks = (int)$slot['total_clicks'];
        $slot['ctr'] = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
    }
    
    echo json_encode([
        'success' => true,
        'slots' => $slots
    ]);
}

/**
 * Adicionar slot de anúncio
 */
function addAdSlot($pdo, $input) {
    $required = ['slot_name', 'slot_type', 'script_code'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            echo json_encode(['success' => false, 'error' => "Campo obrigatório: $field"]);
            return;
        }
    }
    
    // Validar tipo
    $validTypes = ['pregame', 'endgame', 'interstitial', 'banner'];
    if (!in_array($input['slot_type'], $validTypes)) {
        echo json_encode(['success' => false, 'error' => 'Tipo de slot inválido']);
        return;
    }
    
    // Obter próxima ordem
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 as next_order FROM ad_slots WHERE slot_type = ?");
    $stmt->execute([$input['slot_type']]);
    $nextOrder = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        INSERT INTO ad_slots (
            slot_name, slot_type, position, script_code, 
            width, height, display_order, duration_seconds,
            custom_css, custom_js, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $input['slot_name'],
        $input['slot_type'],
        $input['position'] ?? 'center',
        $input['script_code'],
        $input['width'] ?? null,
        $input['height'] ?? null,
        $nextOrder,
        $input['duration_seconds'] ?? 5,
        $input['custom_css'] ?? null,
        $input['custom_js'] ?? null,
        $input['is_active'] ?? 1
    ]);
    
    $slotId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Slot criado com sucesso',
        'slot_id' => $slotId
    ]);
}

/**
 * Atualizar slot de anúncio
 */
function updateAdSlot($pdo, $input) {
    $slotId = $input['slot_id'] ?? 0;
    
    if (!$slotId) {
        echo json_encode(['success' => false, 'error' => 'ID do slot não informado']);
        return;
    }
    
    $fields = [];
    $params = [];
    
    $allowedFields = [
        'slot_name', 'slot_type', 'position', 'script_code',
        'width', 'height', 'display_order', 'duration_seconds',
        'custom_css', 'custom_js', 'is_active'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    
    if (empty($fields)) {
        echo json_encode(['success' => false, 'error' => 'Nenhum campo para atualizar']);
        return;
    }
    
    $fields[] = "updated_at = NOW()";
    $params[] = $slotId;
    
    $sql = "UPDATE ad_slots SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Slot atualizado com sucesso'
    ]);
}

/**
 * Deletar slot de anúncio
 */
function deleteAdSlot($pdo, $input) {
    $slotId = $input['slot_id'] ?? 0;
    
    if (!$slotId) {
        echo json_encode(['success' => false, 'error' => 'ID do slot não informado']);
        return;
    }
    
    $stmt = $pdo->prepare("DELETE FROM ad_slots WHERE id = ?");
    $stmt->execute([$slotId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Slot removido com sucesso'
    ]);
}

/**
 * Ativar/desativar slot
 */
function toggleAdSlot($pdo, $input) {
    $slotId = $input['slot_id'] ?? 0;
    
    if (!$slotId) {
        echo json_encode(['success' => false, 'error' => 'ID do slot não informado']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE ad_slots SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$slotId]);
    
    // Obter novo status
    $stmt = $pdo->prepare("SELECT is_active FROM ad_slots WHERE id = ?");
    $stmt->execute([$slotId]);
    $isActive = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'is_active' => (bool)$isActive,
        'message' => $isActive ? 'Slot ativado' : 'Slot desativado'
    ]);
}

/**
 * Reordenar slots
 */
function reorderAdSlots($pdo, $input) {
    $order = $input['order'] ?? [];
    
    if (empty($order)) {
        echo json_encode(['success' => false, 'error' => 'Ordem não informada']);
        return;
    }
    
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("UPDATE ad_slots SET display_order = ? WHERE id = ?");
        
        foreach ($order as $position => $slotId) {
            $stmt->execute([$position + 1, $slotId]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Ordem atualizada'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Estatísticas de anúncios
 */
function getAdStats($pdo, $input) {
    $period = $input['period'] ?? 30; // dias
    $slotId = $input['slot_id'] ?? null;
    
    // Stats gerais
    $sql = "
        SELECT 
            COUNT(DISTINCT i.id) as total_impressions,
            COUNT(DISTINCT c.id) as total_clicks,
            COUNT(DISTINCT i.session_id) as unique_sessions,
            COUNT(DISTINCT DATE(i.created_at)) as active_days
        FROM ad_impressions i
        LEFT JOIN ad_clicks c ON i.slot_id = c.slot_id AND i.session_id = c.session_id
        WHERE i.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
    ";
    
    $params = [$period];
    
    if ($slotId) {
        $sql .= " AND i.slot_id = ?";
        $params[] = $slotId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $general = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Stats por dia
    $sql = "
        SELECT 
            DATE(i.created_at) as date,
            COUNT(DISTINCT i.id) as impressions,
            COUNT(DISTINCT c.id) as clicks
        FROM ad_impressions i
        LEFT JOIN ad_clicks c ON i.slot_id = c.slot_id 
            AND DATE(i.created_at) = DATE(c.created_at)
        WHERE i.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
    ";
    
    $params = [$period];
    if ($slotId) {
        $sql .= " AND i.slot_id = ?";
        $params[] = $slotId;
    }
    
    $sql .= " GROUP BY DATE(i.created_at) ORDER BY date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Stats por slot
    $sql = "
        SELECT 
            s.id, s.slot_name, s.slot_type,
            COUNT(DISTINCT i.id) as impressions,
            COUNT(DISTINCT c.id) as clicks
        FROM ad_slots s
        LEFT JOIN ad_impressions i ON s.id = i.slot_id 
            AND i.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
        LEFT JOIN ad_clicks c ON s.id = c.slot_id 
            AND c.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY s.id
        ORDER BY impressions DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$period, $period]);
    $bySlot = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular CTRs
    $general['ctr'] = $general['total_impressions'] > 0 
        ? round(($general['total_clicks'] / $general['total_impressions']) * 100, 2) 
        : 0;
    
    foreach ($bySlot as &$slot) {
        $slot['ctr'] = $slot['impressions'] > 0 
            ? round(($slot['clicks'] / $slot['impressions']) * 100, 2) 
            : 0;
    }
    
    echo json_encode([
        'success' => true,
        'period' => $period,
        'general' => $general,
        'daily' => $daily,
        'by_slot' => $bySlot
    ]);
}

/**
 * Registrar impressão de anúncio
 */
function logAdImpression($pdo, $input) {
    $slotId = $input['slot_id'] ?? 0;
    $sessionId = $input['session_id'] ?? null;
    $googleUid = $input['google_uid'] ?? null;
    $page = $input['page'] ?? null;
    
    if (!$slotId) {
        echo json_encode(['success' => false, 'error' => 'Slot não informado']);
        return;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO ad_impressions (slot_id, session_id, google_uid, page, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $slotId,
        $sessionId,
        $googleUid,
        $page,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    echo json_encode(['success' => true]);
}

/**
 * Registrar clique em anúncio
 */
function logAdClick($pdo, $input) {
    $slotId = $input['slot_id'] ?? 0;
    $sessionId = $input['session_id'] ?? null;
    $googleUid = $input['google_uid'] ?? null;
    
    if (!$slotId) {
        echo json_encode(['success' => false, 'error' => 'Slot não informado']);
        return;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO ad_clicks (slot_id, session_id, google_uid, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $slotId,
        $sessionId,
        $googleUid,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    echo json_encode(['success' => true]);
}
