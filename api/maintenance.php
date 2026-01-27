<?php
// ============================================
// CRYPTO ASTEROID RUSH - Manutenção do Banco
// Arquivo: api/maintenance.php
//
// Execute diariamente via CRON:
// cPanel: 0 3 * * *
// Railway (UTC): 0 6 * * *  (03:00 Brasil)
// ============================================

require_once __DIR__ . "/config.php";

// Timezone (igual cPanel BR)
date_default_timezone_set('America/Sao_Paulo');

// Configurações de retenção (em dias)
define('RETENTION_EVENTS', 7);        // Eventos: manter 7 dias
define('RETENTION_SESSIONS', 30);     // Sessões válidas: manter 30 dias
define('RETENTION_FLAGGED', 90);      // Sessões suspeitas: manter 90 dias
define('RETENTION_LOGS', 30);         // Logs de segurança: manter 30 dias

// Contadores (evita undefined)
$deletedEvents = 0;
$deletedSessions = 0;
$deletedFlagged = 0;
$expiredSessions = 0;
$deletedLogs = 0;

// Log de manutenção (no Railway o FS pode ser efêmero, mas mantemos Opção A)
function maintenanceLog($message) {
    $logEntry = date('Y-m-d H:i:s') . ' | ' . $message . "\n";
    @file_put_contents(__DIR__ . '/maintenance.log', $logEntry, FILE_APPEND);
    error_log(trim($logEntry)); // logs confiáveis no Railway
    echo $logEntry;
}

function tableExists(PDO $pdo, string $table): bool {
    // SHOW TABLES LIKE não aceita placeholder com prepares nativos em alguns ambientes
    $q = $pdo->quote($table);
    $stmt = $pdo->query("SHOW TABLES LIKE {$q}");
    return (bool)$stmt->fetchColumn();
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Falha ao conectar no banco");
    }

    // Opcional: alinhar timezone do MySQL com BRT (se permitido)
    try { $pdo->exec("SET time_zone = '-03:00'"); } catch (Exception $e) {}

    // ============================================
    // LOCK global: impede execução concorrente
    // ============================================
    $lockName = 'cron_maintenance';
    $lockStmt = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 0) AS got_lock");
    $gotLock = (int)($lockStmt ? $lockStmt->fetchColumn() : 0);

    if ($gotLock !== 1) {
        maintenanceLog("Execução ignorada (lock já em uso).");
        exit(0);
    }

    maintenanceLog("=== INÍCIO DA MANUTENÇÃO ===");

    // ============================================
    // 1. LIMPAR EVENTOS ANTIGOS
    // ============================================
    if (tableExists($pdo, 'game_events')) {
        $stmt = $pdo->prepare("
            DELETE FROM game_events
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([RETENTION_EVENTS]);
        $deletedEvents = $stmt->rowCount();
        maintenanceLog("Eventos deletados: {$deletedEvents}");
    } else {
        maintenanceLog("Tabela game_events não existe (skip).");
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
    } else {
        maintenanceLog("Tabela game_sessions não existe (skip).");
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
        $deletedLogs = $stmt->rowCount();
        maintenanceLog("Logs de segurança deletados: {$deletedLogs}");
    } else {
        maintenanceLog("Tabela security_logs não existe (skip).");
    }

    // ============================================
    // 6. OTIMIZAR TABELAS
    // ============================================
    $tables = ['game_sessions', 'game_events', 'players', 'transactions'];
    foreach ($tables as $table) {
        if (!tableExists($pdo, $table)) {
            maintenanceLog("Tabela não existe (skip optimize): {$table}");
            continue;
        }
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
    $stats = [
        'total_sessions' => 0,
        'total_events' => 0,
        'total_players' => 0
    ];

    if (tableExists($pdo, 'game_sessions')) {
        $result = $pdo->query("SELECT COUNT(*) as total FROM game_sessions")->fetch(PDO::FETCH_ASSOC);
        $stats['total_sessions'] = (int)($result['total'] ?? 0);
    }
    if (tableExists($pdo, 'game_events')) {
        $result = $pdo->query("SELECT COUNT(*) as total FROM game_events")->fetch(PDO::FETCH_ASSOC);
        $stats['total_events'] = (int)($result['total'] ?? 0);
    }
    if (tableExists($pdo, 'players')) {
        $result = $pdo->query("SELECT COUNT(*) as total FROM players")->fetch(PDO::FETCH_ASSOC);
        $stats['total_players'] = (int)($result['total'] ?? 0);
    }

    maintenanceLog("--- ESTATÍSTICAS ---");
    maintenanceLog("Total de sessões: " . $stats['total_sessions']);
    maintenanceLog("Total de eventos: " . $stats['total_events']);
    maintenanceLog("Total de jogadores: " . $stats['total_players']);

    // Tamanho das tabelas (só se as tabelas existirem)
    try {
        $existing = [];
        foreach (['game_sessions', 'game_events', 'players', 'transactions'] as $t) {
            if (tableExists($pdo, $t)) $existing[] = $t;
        }

        if (!empty($existing)) {
            $in = "'" . implode("','", array_map('addslashes', $existing)) . "'";
            $rows = $pdo->query("
                SELECT
                    table_name,
                    ROUND(data_length / 1024 / 1024, 2) as data_mb,
                    ROUND(index_length / 1024 / 1024, 2) as index_mb
                FROM information_schema.tables
                WHERE table_schema = '" . addslashes(DB_NAME) . "'
                  AND table_name IN ({$in})
            ")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $table) {
                $totalMb = (float)$table['data_mb'] + (float)$table['index_mb'];
                maintenanceLog("Tabela {$table['table_name']}: {$totalMb} MB");
            }
        }
    } catch (Exception $e) {
        maintenanceLog("Erro ao coletar tamanhos das tabelas: " . $e->getMessage());
    }

    maintenanceLog("=== FIM DA MANUTENÇÃO ===\n");

    // Libera lock
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");

    // Retornar JSON se chamado via HTTP
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'deleted' => [
                'events' => $deletedEvents,
                'sessions' => $deletedSessions,
                'flagged' => $deletedFlagged,
                'expired' => $expiredSessions,
                'security_logs' => $deletedLogs
            ],
            'stats' => $stats
        ]);
    }

} catch (Exception $e) {
    maintenanceLog("ERRO CRÍTICO: " . $e->getMessage());

    // tenta liberar lock se possível
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
?>
