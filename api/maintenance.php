<?php
// ============================================
// UNOBIX - Manutenção do Banco
// Arquivo: api/maintenance.php
// v2.0 - Complementa sp_cleanup_old_data()
//
// Execute diariamente via CRON:
// Railway (UTC): 0 6 * * *  (03:00 Brasil)
//
// NOTA: Este script complementa a stored procedure
// sp_cleanup_old_data() que já existe no banco.
// Pode executar ambos ou apenas um.
// ============================================

require_once __DIR__ . "/config.php";

date_default_timezone_set('America/Sao_Paulo');

// Configurações de retenção (em dias)
define('RETENTION_EVENTS', 7);        // Eventos: manter 7 dias
define('RETENTION_SESSIONS', 30);     // Sessões válidas: manter 30 dias
define('RETENTION_FLAGGED', 90);      // Sessões suspeitas: manter 90 dias
define('RETENTION_LOGS', 30);         // Logs de segurança: manter 30 dias
define('RETENTION_AD_IMPRESSIONS', 90); // Ad impressions: 90 dias

// Contadores
$stats = [
    'sp_executed' => false,
    'deleted_events' => 0,
    'deleted_sessions' => 0,
    'deleted_flagged' => 0,
    'expired_sessions' => 0,
    'deleted_logs' => 0,
    'deleted_ad_impressions' => 0,
    'deleted_captcha_logs' => 0
];

function maintenanceLog($message) {
    $logEntry = date('Y-m-d H:i:s') . ' | ' . $message . "\n";
    @file_put_contents(__DIR__ . '/logs/maintenance.log', $logEntry, FILE_APPEND);
    error_log(trim($logEntry));
    echo $logEntry;
}

function tableExists(PDO $pdo, string $table): bool {
    $q = $pdo->quote($table);
    $stmt = $pdo->query("SHOW TABLES LIKE {$q}");
    return (bool)$stmt->fetchColumn();
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Falha ao conectar no banco");
    }

    try { $pdo->exec("SET time_zone = '-03:00'"); } catch (Exception $e) {}

    // ============================================
    // LOCK global
    // ============================================
    $lockName = 'cron_maintenance';
    $lockStmt = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 0) AS got_lock");
    $gotLock = (int)($lockStmt ? $lockStmt->fetchColumn() : 0);

    if ($gotLock !== 1) {
        maintenanceLog("Execução ignorada (lock já em uso).");
        exit(0);
    }

    maintenanceLog("=== INÍCIO DA MANUTENÇÃO UNOBIX ===");

    // ============================================
    // 0. TENTAR EXECUTAR STORED PROCEDURE PRIMEIRO
    // ============================================
    try {
        $stmt = $pdo->query("CALL sp_cleanup_old_data()");
        $spResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        
        $stats['sp_executed'] = true;
        maintenanceLog("sp_cleanup_old_data() executada com sucesso");
        
        if ($spResult) {
            maintenanceLog("  - Events: {$spResult['game_events_deleted']}");
            maintenanceLog("  - Rate limits: {$spResult['rate_limits_deleted']}");
            maintenanceLog("  - IP sessions: {$spResult['ip_sessions_deleted']}");
            maintenanceLog("  - Suspicious: {$spResult['suspicious_deleted']}");
        }
    } catch (Exception $e) {
        maintenanceLog("sp_cleanup_old_data() não disponível, usando limpeza manual");
    }

    // ============================================
    // 1. LIMPAR EVENTOS ANTIGOS (se SP não executou)
    // ============================================
    if (!$stats['sp_executed'] && tableExists($pdo, 'game_events')) {
        $stmt = $pdo->prepare("
            DELETE FROM game_events
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([RETENTION_EVENTS]);
        $stats['deleted_events'] = $stmt->rowCount();
        maintenanceLog("Eventos deletados: {$stats['deleted_events']}");
    }

    // ============================================
    // 2. LIMPAR SESSÕES VÁLIDAS ANTIGAS
    // ============================================
    if (tableExists($pdo, 'game_sessions')) {
        $stmt = $pdo->prepare("
            DELETE FROM game_sessions
            WHERE status IN ('completed', 'expired', 'cancelled')
              AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([RETENTION_SESSIONS]);
        $stats['deleted_sessions'] = $stmt->rowCount();
        maintenanceLog("Sessões válidas deletadas: {$stats['deleted_sessions']}");

        // ============================================
        // 3. LIMPAR SESSÕES SUSPEITAS ANTIGAS
        // ============================================
        $stmt = $pdo->prepare("
            DELETE FROM game_sessions
            WHERE status = 'flagged'
              AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([RETENTION_FLAGGED]);
        $stats['deleted_flagged'] = $stmt->rowCount();
        maintenanceLog("Sessões suspeitas deletadas: {$stats['deleted_flagged']}");

        // ============================================
        // 4. EXPIRAR SESSÕES ATIVAS ABANDONADAS (> 1 hora)
        // ============================================
        $stmt = $pdo->prepare("
            UPDATE game_sessions
            SET status = 'expired'
            WHERE status = 'active'
              AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();
        $stats['expired_sessions'] = $stmt->rowCount();
        maintenanceLog("Sessões abandonadas expiradas: {$stats['expired_sessions']}");
    }

    // ============================================
    // 5. LIMPAR LOGS DE SEGURANÇA ANTIGOS
    // ============================================
    if (tableExists($pdo, 'security_logs')) {
        $stmt = $pdo->prepare("
            DELETE FROM security_logs
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([RETENTION_LOGS]);
        $stats['deleted_logs'] = $stmt->rowCount();
        maintenanceLog("Logs de segurança deletados: {$stats['deleted_logs']}");
    }

    // ============================================
    // 6. LIMPAR AD IMPRESSIONS ANTIGAS
    // ============================================
    if (tableExists($pdo, 'ad_impressions')) {
        $stmt = $pdo->prepare("
            DELETE FROM ad_impressions
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([RETENTION_AD_IMPRESSIONS]);
        $stats['deleted_ad_impressions'] = $stmt->rowCount();
        maintenanceLog("Ad impressions deletadas: {$stats['deleted_ad_impressions']}");
    }

    // ============================================
    // 7. LIMPAR CAPTCHA LOGS ANTIGOS
    // ============================================
    if (tableExists($pdo, 'captcha_log')) {
        $stmt = $pdo->prepare("
            DELETE FROM captcha_log
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([RETENTION_LOGS]);
        $stats['deleted_captcha_logs'] = $stmt->rowCount();
        maintenanceLog("Captcha logs deletados: {$stats['deleted_captcha_logs']}");
    }

    // ============================================
    // 8. LIMPAR USER SESSIONS EXPIRADAS
    // ============================================
    if (tableExists($pdo, 'user_sessions')) {
        $stmt = $pdo->exec("
            DELETE FROM user_sessions
            WHERE (expires_at IS NOT NULL AND expires_at < NOW())
               OR (is_active = 0 AND last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY))
        ");
        maintenanceLog("User sessions expiradas limpas");
    }

    // ============================================
    // 9. OTIMIZAR TABELAS PRINCIPAIS
    // ============================================
    $tables = ['game_sessions', 'game_events', 'players', 'transactions', 'stakes', 'withdrawals'];
    foreach ($tables as $table) {
        if (!tableExists($pdo, $table)) continue;
        try {
            $pdo->exec("OPTIMIZE TABLE `{$table}`");
            maintenanceLog("Tabela otimizada: {$table}");
        } catch (Exception $e) {
            maintenanceLog("Erro ao otimizar {$table}: " . $e->getMessage());
        }
    }

    // ============================================
    // 10. ESTATÍSTICAS FINAIS
    // ============================================
    $dbStats = [];
    
    if (tableExists($pdo, 'game_sessions')) {
        $result = $pdo->query("SELECT COUNT(*) as total FROM game_sessions")->fetch();
        $dbStats['total_sessions'] = (int)($result['total'] ?? 0);
    }
    if (tableExists($pdo, 'players')) {
        $result = $pdo->query("SELECT COUNT(*) as total FROM players")->fetch();
        $dbStats['total_players'] = (int)($result['total'] ?? 0);
    }
    if (tableExists($pdo, 'stakes')) {
        $result = $pdo->query("SELECT COUNT(*) as total FROM stakes WHERE status = 'active'")->fetch();
        $dbStats['active_stakes'] = (int)($result['total'] ?? 0);
    }

    maintenanceLog("--- ESTATÍSTICAS ---");
    foreach ($dbStats as $key => $value) {
        maintenanceLog("{$key}: {$value}");
    }

    maintenanceLog("=== FIM DA MANUTENÇÃO ===\n");

    // Libera lock
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");

    // Retornar JSON se chamado via HTTP
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'deleted' => $stats,
            'db_stats' => $dbStats
        ]);
    }

} catch (Exception $e) {
    maintenanceLog("ERRO CRÍTICO: " . $e->getMessage());

    try {
        if (isset($pdo)) {
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote('cron_maintenance') . ")");
        }
    } catch (Exception $e2) {}

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
