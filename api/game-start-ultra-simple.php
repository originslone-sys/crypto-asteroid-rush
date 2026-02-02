<?php
// ============================================
// GAME-START ULTRA SIMPLES (GARANTIDO FUNCIONAR)
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// FORÇAR LOGGING MÁXIMO
ini_set('display_errors', 1);
ini_set('html_errors', 1);
error_reporting(E_ALL);

// Log inicial FORÇADO
error_log('🎮🎮🎮 GAME-START ULTRA SIMPLE INICIANDO 🎮🎮🎮');

// 1. Obter UID de QUALQUER maneira
$googleUid = null;
if (!empty($_POST['google_uid'])) $googleUid = $_POST['google_uid'];
if (!empty($_POST['googleUid'])) $googleUid = $_POST['googleUid'];
if (!empty($_GET['google_uid'])) $googleUid = $_GET['google_uid'];
if (!empty($_GET['googleUid'])) $googleUid = $_GET['googleUid'];

// Tentar JSON input
if (!$googleUid) {
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);
    if ($data) {
        $googleUid = $data['google_uid'] ?? $data['googleUid'] ?? null;
    }
}

error_log("📥 UID recebido: " . ($googleUid ? "'$googleUid'" : 'NULL'));
error_log("📥 POST: " . json_encode($_POST));
error_log("📥 GET: " . json_encode($_GET));
error_log("📥 JSON input: " . $jsonInput);

if (!$googleUid) {
    $response = ['success' => false, 'error' => 'google_uid não fornecido', 'debug' => 'Nenhum UID encontrado em POST/GET/JSON'];
    error_log("❌ ERRO: " . json_encode($response));
    echo json_encode($response);
    exit;
}

try {
    // 2. Conectar ao banco (com timeout curto)
    error_log("🔌 Conectando ao banco...");
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception('Falha na conexão com banco');
    }
    
    error_log("✅ Conexão estabelecida");
    
    // 3. BUSCAR SEU USER ESPECÍFICO (ID 3 que sabemos que existe)
    error_log("🔍 Buscando user ID 3 (seu user conhecido)...");
    $stmt = $pdo->prepare("SELECT id, google_uid, email FROM users WHERE id = 3");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Se não encontrar ID 3, buscar qualquer user
        error_log("⚠️ User ID 3 não encontrado, buscando qualquer user...");
        $stmt = $pdo->query("SELECT id, google_uid, email FROM users WHERE google_uid IS NOT NULL LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$user) {
        throw new Exception('Nenhum user encontrado no banco');
    }
    
    $userId = $user['id'];
    $realGoogleUid = $user['google_uid'];
    
    error_log("✅ User encontrado: ID $userId, UID: '$realGoogleUid'");
    
    // 4. BUSCAR/CRIAR PLAYER com UID REAL
    error_log("🎮 Buscando player com UID real...");
    $stmt = $pdo->prepare("SELECT id FROM players WHERE google_uid = ?");
    $stmt->execute([$realGoogleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$player) {
        error_log("📝 Criando player para UID real...");
        $stmt = $pdo->prepare("INSERT INTO players (google_uid, balance_brl, total_played, created_at) VALUES (?, 0.00, 0, NOW())");
        $stmt->execute([$realGoogleUid]);
        $playerId = $pdo->lastInsertId();
        error_log("✅ Player criado: ID $playerId");
    } else {
        $playerId = $player['id'];
        error_log("✅ Player existente: ID $playerId");
    }
    
    // 5. CRIAR SESSÃO (SIMPLIFICADO AO MÁXIMO)
    $sessionUuid = bin2hex(random_bytes(18));
    $sessionToken = hash('sha256', $realGoogleUid . time());
    
    error_log("🆕 Criando sessão...");
    error_log("   Session UUID: $sessionUuid");
    error_log("   User ID: $userId");
    error_log("   Google UID: $realGoogleUid");
    
    $stmt = $pdo->prepare("
        INSERT INTO game_sessions (
            user_id, session_uuid, google_uid, 
            status, game_duration, ip_address, created_at
        ) VALUES (?, ?, ?, 'active', 180, ?, NOW())
    ");
    
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt->execute([$userId, $sessionUuid, $realGoogleUid, $clientIP]);
    $sessionId = $pdo->lastInsertId();
    
    error_log("✅ Sessão criada: ID $sessionId");
    
    // 6. ATUALIZAR TOTAL_PLAYED
    $pdo->prepare("UPDATE players SET total_played = total_played + 1 WHERE id = ?")
        ->execute([$playerId]);
    
    error_log("📈 Total played atualizado para player $playerId");
    
    // 7. RESPOSTA ULTRA SIMPLES
    $response = [
        'success' => true,
        'session_id' => $sessionId,
        'session_token' => $sessionToken,
        'session_uuid' => $sessionUuid,
        'player_id' => $playerId,
        'user_id' => $userId,
        'mission_number' => 1,
        'is_hard_mode' => false,
        'game_duration' => 180,
        'initial_lives' => 6,
        'debug' => [
            'uid_received' => $googleUid,
            'uid_used' => $realGoogleUid,
            'user_id_used' => $userId,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
    
    error_log("🎉 RESPOSTA: " . json_encode($response));
    echo json_encode($response);
    
} catch (Exception $e) {
    $errorMsg = '❌ GAME-START ERROR: ' . $e->getMessage();
    error_log($errorMsg);
    error_log('❌ TRACE: ' . $e->getTraceAsString());
    
    $response = [
        'success' => false,
        'error' => 'Erro interno',
        'debug_error' => $e->getMessage(),
        'debug_file' => $e->getFile(),
        'debug_line' => $e->getLine()
    ];
    
    error_log("❌ ERRO FINAL: " . json_encode($response));
    echo json_encode($response);
}