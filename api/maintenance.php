<?php
// ============================================
// CRYPTO ASTEROID RUSH - Manutenção do Banco
// Arquivo: api/maintenance.php
// 
// Execute diariamente via CRON:
// 0 3 * * * php /caminho/para/api/maintenance.php
// ============================================

// Configuração
if (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
} elseif (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
}

// Configurações de retenção (em dias)
define('RETENTION_EVENTS', 7);        // Eventos: manter 7 dias
define('RETENTION_SESSIONS', 30);     // Sessões válidas: manter 30 dias
define('RETENTION_FLAGGED', 90);      // Sessões suspeitas: manter 90 dias
define('RETENTION_LOGS', 30);         // Logs de segurança: manter 30 dias

// Log de manutenção
function maintenanceLog($message) {
    $logEntry = date('Y-m-d H:i:s') . ' | ' . $message . "\n";
    file_put_contents(__DIR__ . '/maintenance.log', $logEntry, FILE_APPEND);
    echo $logEntry;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    maintenanceLog("=== INÍCIO DA MANUTENÇÃO ===");
    
    // ============================================
    // 1. LIMPAR EVENTOS ANTIGOS
    // ============================================
    $stmt = $pdo->prepare("
        DELETE FROM game_events 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([RETENTION_EVENTS]);
    $deletedEvents = $stmt->rowCount();
    maintenanceLog("Eventos deletados: {$deletedEvents}");
    
    // ============================================
    // 2. LIMPAR SESSÕES VÁLIDAS ANTIGAS
    // ============================================
    $stmt = $pdo->prepare("
        DELETE FROM game_sessions 
        WHERE status IN ('completed', 'expired', 'cancelled')
        AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([RETENTION_SESSIONS]);
    $deletedSessions = $stmt->rowCount();
    maintenanceLog("Sessões válidas deletadas: {$deletedSessions}");
    
    // ============================================
    // 3. LIMPAR SESSÕES SUSPEITAS ANTIGAS
    // ============================================
    $stmt = $pdo->prepare("
        DELETE FROM game_sessions 
        WHERE status = 'flagged'
        AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([RETENTION_FLAGGED]);
    $deletedFlagged = $stmt->rowCount();
    maintenanceLog("Sessões suspeitas deletadas: {$deletedFlagged}");
    
    // ============================================
    // 4. LIMPAR SESSÕES ATIVAS ABANDONADAS (> 1 hora)
    // ============================================
    $stmt = $pdo->prepare("
        UPDATE game_sessions 
        SET status = 'expired'
        WHERE status = 'active'
        AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    $expiredSessions = $stmt->rowCount();
    maintenanceLog("Sessões abandonadas expiradas: {$expiredSessions}");
    
    // ============================================
    // 5. LIMPAR LOGS DE SEGURANÇA ANTIGOS
    // ============================================
    $tableExists = $pdo->query("SHOW TABLES LIKE 'security_logs'")->fetch();
    if ($tableExists) {
        $stmt = $pdo->prepare("
            DELETE FROM security_logs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([RETENTION_LOGS]);
        $deletedLogs = $stmt->rowCount();
        maintenanceLog("Logs de segurança deletados: {$deletedLogs}");
    }
    
    // ============================================
    // 6. OTIMIZAR TABELAS
    // ============================================
    $tables = ['game_sessions', 'game_events', 'players', 'transactions'];
    foreach ($tables as $table) {
        try {
            $pdo->exec("OPTIMIZE TABLE {$table}");
            maintenanceLog("Tabela otimizada: {$table}");
        } catch (Exception $e) {
            maintenanceLog("Erro ao otimizar {$table}: " . $e->getMessage());
        }
    }
    
    // ============================================
    // 7. ESTATÍSTICAS
    // ============================================
    $stats = [];
    
    $result = $pdo->query("SELECT COUNT(*) as total FROM game_sessions")->fetch();
    $stats['total_sessions'] = $result['total'];
    
    $result = $pdo->query("SELECT COUNT(*) as total FROM game_events")->fetch();
    $stats['total_events'] = $result['total'];
    
    $result = $pdo->query("SELECT COUNT(*) as total FROM players")->fetch();
    $stats['total_players'] = $result['total'];
    
    // Tamanho das tabelas
    $result = $pdo->query("
        SELECT 
            table_name,
            ROUND(data_length / 1024 / 1024, 2) as data_mb,
            ROUND(index_length / 1024 / 1024, 2) as index_mb
        FROM information_schema.tables 
        WHERE table_schema = '" . DB_NAME . "'
        AND table_name IN ('game_sessions', 'game_events', 'players', 'transactions')
    ")->fetchAll();
    
    maintenanceLog("--- ESTATÍSTICAS ---");
    maintenanceLog("Total de sessões: " . $stats['total_sessions']);
    maintenanceLog("Total de eventos: " . $stats['total_events']);
    maintenanceLog("Total de jogadores: " . $stats['total_players']);
    
    foreach ($result as $table) {
        $totalMb = $table['data_mb'] + $table['index_mb'];
        maintenanceLog("Tabela {$table['table_name']}: {$totalMb} MB");
    }
    
    maintenanceLog("=== FIM DA MANUTENÇÃO ===\n");
    
    // Retornar JSON se chamado via HTTP
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'deleted' => [
                'events' => $deletedEvents,
                'sessions' => $deletedSessions,
                'flagged' => $deletedFlagged,
                'expired' => $expiredSessions
            ],
            'stats' => $stats
        ]);
    }
    
} catch (Exception $e) {
    maintenanceLog("ERRO CRÍTICO: " . $e->getMessage());
    
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>
