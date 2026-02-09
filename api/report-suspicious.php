<?php
// ============================================
// UNOBIX - Relatório de Atividade Suspeita
// Arquivo: api/report-suspicious.php
// v7.0 - Corrigido: tabela users, schema alinhado
//        com doc 3.7 (user_id, details, severity)
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$googleUid = isset($input['google_uid']) ? trim($input['google_uid']) : '';
$sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
$logs = isset($input['logs']) ? $input['logs'] : [];
$userAgent = isset($input['user_agent']) ? substr($input['user_agent'], 0, 500) : '';
$screen = isset($input['screen']) ? $input['screen'] : [];
$devtoolsDetected = isset($input['devtools']) ? (bool)$input['devtools'] : false;

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        throw new Exception("Erro de conexão");
    }
    
    // ============================================
    // 1. BUSCAR user_id A PARTIR DO google_uid
    //    (JOIN correto conforme doc 3.7)
    // ============================================
    $userId = null;
    if (!empty($googleUid) && validateGoogleUid($googleUid)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $user = $stmt->fetch();
        if ($user) {
            $userId = (int)$user['id'];
        }
    }
    
    // ============================================
    // 2. MAPEAMENTO DE SEVERIDADE AUTOMÁTICA
    // ============================================
    $severityMap = [
        // Crítico
        'DEVTOOLS_OPEN'         => 'critical',
        'DEVTOOLS_DETECTED'     => 'critical',
        'CODE_INJECTION'        => 'critical',
        'MEMORY_TAMPERING'      => 'critical',
        
        // Alto
        'SPEED_HACK'            => 'high',
        'TIME_MANIPULATION'     => 'high',
        'EARNINGS_TAMPERING'    => 'high',
        'SESSION_HIJACK'        => 'high',
        'MULTIPLE_SESSIONS'     => 'high',
        'GAME_STATE_TAMPERING'  => 'high',
        
        // Médio
        'RAPID_CLICKS'          => 'medium',
        'UNUSUAL_TIMING'        => 'medium',
        'CONSOLE_ACCESS'        => 'medium',
        'TAB_SWITCHING'         => 'medium',
        'FOCUS_LOSS'            => 'medium',
        
        // Baixo
        'SCREEN_RESIZE'         => 'low',
        'ORIENTATION_CHANGE'    => 'low',
        'UNKNOWN'               => 'low'
    ];
    
    // ============================================
    // 3. REGISTRAR CADA ATIVIDADE SUSPEITA
    //    Schema alinhado com doc 3.7:
    //    user_id, ip_address, activity_type, details, severity
    // ============================================
    $ipAddress = getClientIP();
    $screenWidth = isset($screen['w']) ? (int)$screen['w'] : null;
    $screenHeight = isset($screen['h']) ? (int)$screen['h'] : null;
    
    $stmt = $pdo->prepare("
        INSERT INTO suspicious_activity 
        (user_id, ip_address, activity_type, details, severity, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $insertedCount = 0;
    $highestSeverity = 'low';
    $severityOrder = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
    
    foreach ($logs as $log) {
        $activityType = isset($log['type']) ? substr($log['type'], 0, 50) : 'UNKNOWN';
        
        // Determinar severidade
        $severity = $severityMap[strtoupper($activityType)] ?? 'low';
        if ($devtoolsDetected && $severityOrder[$severity] < $severityOrder['high']) {
            $severity = 'high';
        }
        
        // Montar details como JSON (doc 3.7: coluna details tipo JSON)
        $details = json_encode([
            'data' => $log['data'] ?? null,
            'session_id' => $sessionId > 0 ? $sessionId : null,
            'google_uid' => $googleUid ?: null,
            'user_agent' => $userAgent,
            'screen' => ($screenWidth && $screenHeight) ? "{$screenWidth}x{$screenHeight}" : null,
            'devtools' => $devtoolsDetected
        ], JSON_UNESCAPED_UNICODE);
        
        $stmt->execute([
            $userId,
            $ipAddress,
            $activityType,
            $details,
            $severity
        ]);
        
        $insertedCount++;
        
        if ($severityOrder[$severity] > $severityOrder[$highestSeverity]) {
            $highestSeverity = $severity;
        }
    }
    
    // ============================================
    // 4. AUTO-FLAG: VERIFICAR SE DEVE BANIR
    //    Tabela: users (CORRIGIDO - era players)
    // ============================================
    if ($userId) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN severity IN ('high', 'critical') THEN 1 ELSE 0 END) as critical_count
            FROM suspicious_activity
            WHERE user_id = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $totalCount = (int)$result['total'];
        $criticalCount = (int)$result['critical_count'];
        
        // Auto-ban: 5+ critical/high OU 20+ qualquer em 24h
        if ($criticalCount >= 5 || $totalCount >= 20) {
            $banReason = $criticalCount >= 5 
                ? "Auto-ban: {$criticalCount} atividades críticas em 24h"
                : "Auto-ban: {$totalCount} atividades suspeitas em 24h";
            
            $stmt = $pdo->prepare("
                UPDATE users 
                SET is_banned = 1, ban_reason = ?, updated_at = NOW()
                WHERE id = ? AND is_banned = 0
            ");
            $stmt->execute([$banReason, $userId]);
            
            if ($stmt->rowCount() > 0) {
                secureLog("AUTO_BAN | User ID: {$userId} | UID: {$googleUid} | Reason: {$banReason}");
                
                $pdo->prepare("
                    INSERT INTO suspicious_activity 
                    (user_id, ip_address, activity_type, details, severity, created_at)
                    VALUES (?, ?, 'AUTO_BAN', ?, 'critical', NOW())
                ")->execute([
                    $userId,
                    $ipAddress,
                    json_encode(['reason' => $banReason, 'total_24h' => $totalCount, 'critical_24h' => $criticalCount])
                ]);
            }
        }
    }
    
    // ============================================
    // 5. LOG
    // ============================================
    $logTypes = array_column($logs, 'type');
    $logTypesStr = implode(', ', array_unique($logTypes));
    
    secureLog("SUSPICIOUS_REPORT | UID: {$googleUid} | UserID: {$userId} | Session: {$sessionId} | Types: {$logTypesStr} | Severity: {$highestSeverity} | DevTools: " . ($devtoolsDetected ? 'YES' : 'NO'));
    
    echo json_encode([
        'success' => true,
        'logged' => $insertedCount
    ]);
    
} catch (Exception $e) {
    secureLog("REPORT_ERROR | " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
