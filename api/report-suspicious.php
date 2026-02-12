<?php
// ============================================
// UNOBIX - Relatório de Atividade Suspeita
// Arquivo: api/report-suspicious.php
// v2.0 - Google Auth + BRL
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

// Suporte híbrido: google_uid ou wallet
$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$wallet = isset($input['wallet']) ? trim(strtolower($input['wallet'])) : 'unknown';
$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$logs = isset($input['logs']) ? $input['logs'] : [];
$userAgent = isset($input['user_agent']) ? substr($input['user_agent'], 0, 500) : '';
$screen = isset($input['screen']) ? $input['screen'] : [];
$devtoolsDetected = isset($input['devtools']) ? (bool)$input['devtools'] : false;

// Determinar identificador
$identifier = '';
if (!empty($googleUid) && validateGoogleUid($googleUid)) {
    $identifier = $googleUid;
} elseif (!empty($wallet) && validateWallet($wallet)) {
    $identifier = $wallet;
} else {
    $identifier = 'unknown';
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Erro de conexão");
    }
    
    // ============================================
    // 1. CRIAR TABELA SE NÃO EXISTIR
    // ============================================
    $tableExists = $pdo->query("SHOW TABLES LIKE 'suspicious_activity'")->fetch();
    
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE suspicious_activity (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                google_uid VARCHAR(128) DEFAULT NULL,
                wallet_address VARCHAR(42) DEFAULT NULL,
                session_id INT DEFAULT NULL,
                activity_type VARCHAR(50) NOT NULL,
                activity_data TEXT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                screen_width INT DEFAULT NULL,
                screen_height INT DEFAULT NULL,
                devtools_detected TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_google_uid (google_uid),
                INDEX idx_wallet (wallet_address),
                INDEX idx_type (activity_type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Adicionar coluna google_uid se não existir
    $hasGoogleUid = $pdo->query("SHOW COLUMNS FROM suspicious_activity LIKE 'google_uid'")->fetch();
    if (!$hasGoogleUid) {
        $pdo->exec("ALTER TABLE suspicious_activity ADD COLUMN google_uid VARCHAR(128) DEFAULT NULL AFTER id");
        $pdo->exec("ALTER TABLE suspicious_activity ADD INDEX idx_google_uid (google_uid)");
    }
    
    // ============================================
    // 2. REGISTRAR CADA ATIVIDADE SUSPEITA
    // ============================================
    $ipAddress = getClientIP();
    $screenWidth = isset($screen['w']) ? (int)$screen['w'] : null;
    $screenHeight = isset($screen['h']) ? (int)$screen['h'] : null;
    
    $stmt = $pdo->prepare("
        INSERT INTO suspicious_activity 
        (google_uid, wallet_address, session_id, activity_type, activity_data, 
         ip_address, user_agent, screen_width, screen_height, devtools_detected)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $insertedCount = 0;
    
    foreach ($logs as $log) {
        $activityType = isset($log['type']) ? substr($log['type'], 0, 50) : 'UNKNOWN';
        $activityData = isset($log['data']) ? json_encode($log['data']) : null;
        
        $stmt->execute([
            $googleUid ?: null,
            $wallet !== 'unknown' ? $wallet : null,
            $sessionId > 0 ? $sessionId : null,
            $activityType,
            $activityData,
            $ipAddress,
            $userAgent,
            $screenWidth,
            $screenHeight,
            $devtoolsDetected ? 1 : 0
        ]);
        
        $insertedCount++;
    }
    
    // ============================================
    // 3. VERIFICAR SE JOGADOR DEVE SER FLAGGED
    // ============================================
    if ($identifier !== 'unknown') {
        // Contar atividades suspeitas nas últimas 24 horas
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM suspicious_activity
            WHERE (google_uid = ? OR wallet_address = ?)
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$googleUid, $wallet]);
        $result = $stmt->fetch();
        $suspiciousCount = (int)$result['count'];
        
        // Se muitas atividades suspeitas, marcar jogador
        if ($suspiciousCount >= 20) {
            // Verificar se tabela players tem coluna is_flagged
            $columnExists = $pdo->query("SHOW COLUMNS FROM players LIKE 'is_flagged'")->fetch();
            
            if (!$columnExists) {
                $pdo->exec("ALTER TABLE players ADD COLUMN is_flagged TINYINT(1) DEFAULT 0");
                $pdo->exec("ALTER TABLE players ADD COLUMN flagged_reason VARCHAR(255) DEFAULT NULL");
                $pdo->exec("ALTER TABLE players ADD COLUMN flagged_at DATETIME DEFAULT NULL");
            }
            
            $pdo->prepare("
                UPDATE players 
                SET is_flagged = 1, 
                    flagged_reason = 'Auto-flag: Múltiplas atividades suspeitas',
                    flagged_at = NOW()
                WHERE (google_uid = ? OR wallet_address = ?) AND is_flagged = 0
            ")->execute([$googleUid, $wallet]);
            
            secureLog("PLAYER_FLAGGED | UID: {$googleUid} | Wallet: {$wallet} | Suspicious count: {$suspiciousCount}");
        }
    }
    
    // ============================================
    // 4. LOG GERAL
    // ============================================
    $logTypes = array_column($logs, 'type');
    $logTypesStr = implode(', ', array_unique($logTypes));
    
    secureLog("SUSPICIOUS_REPORT | UID: {$googleUid} | Wallet: {$wallet} | Session: {$sessionId} | Types: {$logTypesStr} | DevTools: " . ($devtoolsDetected ? 'YES' : 'NO'));
    
    echo json_encode([
        'success' => true,
        'logged' => $insertedCount
    ]);
    
} catch (Exception $e) {
    secureLog("REPORT_ERROR | " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
