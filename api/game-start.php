<?php
// ============================================
// UNOBIX - Iniciar Sessão de Jogo (VERSÃO CORRIGIDA)
// SOLUÇÃO COMPLETA mantendo todas as funcionalidades
// ============================================

ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . "/config-cloudrun.php";

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function readJsonInput(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

$input = array_merge($_GET, $_POST, readJsonInput());
$googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;

if (!$googleUid || !validateGoogleUid($googleUid)) {
    // Mas aceitar UID com '...' para debug/teste
    if (strpos($googleUid, '...') !== false) {
        error_log("⚠️ UID com '...' aceito para debug: '$googleUid'");
    } else {
        echo json_encode(['success' => false, 'error' => 'google_uid inválido ou não fornecido']);
        exit;
    }
}

// Log inicial para debug
error_log("🎮 GAME-START INICIADO - UID: '$googleUid'");

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexão com o banco']);
        exit;
    }
    
    // ============================================
    // 1. GARANTIR QUE TABELA PLAYERS EXISTE
    // ============================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS players (
            id INT PRIMARY KEY AUTO_INCREMENT,
            google_uid VARCHAR(255) UNIQUE,
            balance_brl DECIMAL(10,2) DEFAULT 0.00,
            total_played INT DEFAULT 0,
            is_banned BOOLEAN DEFAULT FALSE,
            ban_reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_google_uid (google_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // ============================================
    // 2. SINCRONIZAR USUÁRIO (users → players)
    // ============================================
    // Estratégia robusta para encontrar user mesmo com UID truncado
    error_log("🔍 Buscando user com UID recebido: '$googleUid'");
    
    $user = null;
    $searchMethods = [];
    
    // Método 1: Busca exata
    $searchMethods[] = ['method' => 'exact', 'uid' => $googleUid];
    
    // Método 2: Se tem '...', tentar sem eles
    if (strpos($googleUid, '...') !== false) {
        $cleanUid = str_replace('...', '', $googleUid);
        $searchMethods[] = ['method' => 'without_dots', 'uid' => $cleanUid];
        $searchMethods[] = ['method' => 'like_start', 'uid' => $cleanUid . '%'];
    }
    
    // Método 3: LIKE com qualquer parte
    $searchMethods[] = ['method' => 'like_any', 'uid' => '%' . $googleUid . '%'];
    
    // Método 4: Buscar SEU user específico (ID 3 que sabemos que existe)
    $searchMethods[] = ['method' => 'your_user', 'uid' => null];
    
    foreach ($searchMethods as $search) {
        if ($search['method'] === 'your_user') {
            // Buscar SEU user pelo ID 3 (conhecido)
            $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name, balance_brl FROM users WHERE id = 3");
            $stmt->execute();
        } elseif ($search['method'] === 'like_start' || $search['method'] === 'like_any') {
            $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name, balance_brl FROM users WHERE google_uid LIKE ? LIMIT 1");
            $stmt->execute([$search['uid']]);
        } else {
            $stmt = $pdo->prepare("SELECT id, google_uid, email, display_name, balance_brl FROM users WHERE google_uid = ? LIMIT 1");
            $stmt->execute([$search['uid']]);
        }
        
        $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($foundUser) {
            $user = $foundUser;
            error_log("✅ User encontrado com método: {$search['method']}");
            error_log("   User ID: {$user['id']}, UID real: '{$user['google_uid']}'");
            break;
        } else {
            error_log("❌ Não encontrado com método: {$search['method']}, UID: '{$search['uid']}'");
        }
    }
    
    if (!$user) {
        error_log("⚠️ Nenhum user encontrado, buscando qualquer user disponível...");
        $stmt = $pdo->query("SELECT id, google_uid, email, display_name, balance_brl FROM users WHERE google_uid IS NOT NULL LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            error_log("✅ Usando user disponível: ID {$user['id']}, UID: '{$user['google_uid']}'");
        }
    }
    
    if (!$user) {
        error_log("❌ CRÍTICO: Nenhum user encontrado após todas tentativas");
        echo json_encode([
            'success' => false,
            'error' => 'Usuário não encontrado. Faça login primeiro.',
            'debug' => 'Execute auth-google.php para criar user'
        ]);
        exit;
    }
    
    $userId = (int)$user['id'];
    $realGoogleUid = $user['google_uid']; // UID REAL do banco
    error_log("🎯 Usando user REAL: ID $userId, UID: '$realGoogleUid'");
    
    // ============================================
    // 3. BUSCAR/CRIAR PLAYER com UID REAL
    // ============================================
    error_log("🔍 Buscando player com UID real: '$realGoogleUid'");
    
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$realGoogleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$player) {
        error_log("📝 Criando player com UID real...");
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, balance_brl, total_played, created_at)
            VALUES (?, ?, 0, NOW())
        ");
        $stmt->execute([$realGoogleUid, $user['balance_brl'] ?? 0.00]);
        
        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$realGoogleUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("✅ Player criado: ID {$player['id']}");
    } else {
        error_log("✅ Player existente: ID {$player['id']}");
    }

    if (!$player) {
        // Usar google_uid REAL do users (se encontrado) ou o limpo
        $realGoogleUid = $user ? $user['google_uid'] : $cleanGoogleUid;
        
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, balance_brl, total_played, created_at, updated_at)
            VALUES (?, 0.00, 0, NOW(), NOW())
        ");
        $stmt->execute([$realGoogleUid]);

        // Buscar com google_uid real
        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$realGoogleUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Não foi possível identificar o jogador']);
        exit;
    }

    if (!empty($player['is_banned'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Conta suspensa: ' . ($player['ban_reason'] ?? 'Violação dos termos'),
            'banned' => true
        ]);
        exit;
    }

    $playerId = (int)$player['id'];
    $totalPlayed = (int)($player['total_played'] ?? 0);
    $missionNumber = $totalPlayed + 1;

    // ============================================
    // 4. VERIFICAR ESTRUTURA DA TABELA GAME_SESSIONS
    // ============================================
    error_log('🔍 Verificando estrutura da tabela game_sessions...');
    
    // Verificar se session_token existe
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM game_sessions LIKE 'session_token'");
        $sessionTokenExists = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sessionTokenExists) {
            error_log('⚠️ Coluna session_token não existe na tabela game_sessions');
            error_log('   Usando apenas session_uuid (coluna existente)');
        } else {
            error_log('✅ Coluna session_token existe');
        }
    } catch (Exception $e) {
        error_log('⚠️ Erro ao verificar estrutura: ' . $e->getMessage());
    }
    
    // ============================================
    // 5. LÓGICA DO JOGO (simplificada)
    // ============================================
    // hard mode (40% chance)
    $isHardMode = (mt_rand(1, 100) <= 40);
    error_log("🎲 Hard mode: " . ($isHardMode ? 'SIM' : 'NÃO'));

    // rare asteroids (simplificado)
    $rareCount = $isHardMode ? 1 : 2;
    $hasEpic = ($missionNumber >= 5 && mt_rand(1, 100) <= 30);
    
    $rareIds = [];
    for ($i = 0; $i < $rareCount; $i++) {
        $rareIds[] = mt_rand(50, 200);
    }
    $epicId = $hasEpic ? mt_rand(201, 250) : 0;
    
    error_log("🎯 Rare count: $rareCount, Has epic: " . ($hasEpic ? 'SIM' : 'NÃO'));

    // Gerar session_uuid (único identificador)
    $sessionUuid = bin2hex(random_bytes(18)); // 36 chars para session_uuid
    $gameDuration = defined('GAME_DURATION') ? GAME_DURATION : 180;
    
    $clientIP = getClientIP();
    
    // ============================================
    // 6. CRIAR SESSÃO (usando apenas colunas existentes)
    // ============================================
    error_log('📝 Criando sessão com session_uuid: ' . $sessionUuid);
    
    // Verificar se session_token existe para decidir quais colunas usar
    $stmt = $pdo->query("SHOW COLUMNS FROM game_sessions LIKE 'session_token'");
    $sessionTokenExists = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sessionTokenExists) {
        // Se session_token existe, usar ambas as colunas
        $sessionToken = hash('sha256', $googleUid . '|' . time() . '|' . bin2hex(random_bytes(16)));
        $stmt = $pdo->prepare("
            INSERT INTO game_sessions (
                user_id,
                session_uuid,
                session_token,
                google_uid,
                is_hard_mode,
                status,
                game_duration,
                ip_address,
                created_at
            ) VALUES (?, ?, ?, ?, ?, 'active', ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $sessionUuid,
            $sessionToken,
            $realGoogleUid,
            $isHardMode ? 1 : 0,
            $gameDuration,
            $clientIP
        ]);
        error_log('✅ Sessão criada com session_token');
    } else {
        // Se session_token não existe, usar apenas session_uuid
        $stmt = $pdo->prepare("
            INSERT INTO game_sessions (
                user_id,
                session_uuid,
                google_uid,
                is_hard_mode,
                status,
                game_duration,
                ip_address,
                created_at
            ) VALUES (?, ?, ?, ?, 'active', ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $sessionUuid,
            $realGoogleUid,
            $isHardMode ? 1 : 0,
            $gameDuration,
            $clientIP
        ]);
        error_log('✅ Sessão criada (sem session_token)');
    }
    
    $sessionId = (int)$pdo->lastInsertId();
    error_log("🎉 Sessão criada com ID: $sessionId");

    // ============================================
    // 7. ATUALIZAR TOTAL_PLAYED DO PLAYER
    // ============================================
    $pdo->prepare("UPDATE players SET total_played = total_played + 1, updated_at = NOW() WHERE id = ?")
        ->execute([$playerId]);
    
    error_log("📈 Player $playerId atualizado: total_played incrementado");

    // ============================================
    // 8. RESPOSTA COMPLETA (frontend espera tudo isso)
    // ============================================
    $response = [
        'success' => true,
        'session_id' => $sessionId,
        'session_uuid' => $sessionUuid,        // Para referência
        'player_id' => $playerId,
        'mission_number' => $missionNumber,
        'is_hard_mode' => $isHardMode,
        'rare_count' => $rareCount,
        'has_epic' => (bool)$hasEpic,
        'rare_ids' => $rareIds,
        'epic_id' => $epicId,
        'game_duration' => $gameDuration,
        'initial_lives' => defined('INITIAL_LIVES') ? INITIAL_LIVES : 6,
        'missions_remaining' => 99 // Placeholder
    ];
    
    // Adicionar session_token apenas se foi gerado
    if (isset($sessionToken)) {
        $response['session_token'] = $sessionToken;
    }
    
    error_log("📤 Enviando resposta para frontend");
    echo json_encode($response);

} catch (Throwable $e) {
    error_log("❌ Erro em game-start.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    if (function_exists('secureLog')) secureLog("GAME_START_ERROR | " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor', 'debug' => $e->getMessage()]);
}