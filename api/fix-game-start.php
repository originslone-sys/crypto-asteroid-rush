<?php
// ============================================
// SCRIPT PARA CORRIGIR GAME-START.PHP
// ============================================

require_once 'config-cloudrun.php';

header('Content-Type: text/plain');
header('Access-Control-Allow-Origin: *');

echo "🔧 CORRIGINDO GAME-START.PHP\n";
echo "============================\n\n";

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo "❌ Falha na conexão com banco\n";
        exit;
    }
    
    // 1. Verificar schema da tabela game_sessions
    echo "1. Verificando schema da tabela game_sessions...\n";
    $stmt = $pdo->query("DESCRIBE game_sessions");
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[$row['Field']] = $row;
    }
    
    echo "   Colunas encontradas (" . count($columns) . "):\n";
    foreach ($columns as $name => $info) {
        echo "   - $name ({$info['Type']})\n";
    }
    
    // 2. Verificar se tem google_uid ou user_id
    $hasGoogleUid = isset($columns['google_uid']);
    $hasUserId = isset($columns['user_id']);
    
    echo "\n2. Análise de relacionamento:\n";
    echo "   - Tem google_uid: " . ($hasGoogleUid ? '✅' : '❌') . "\n";
    echo "   - Tem user_id: " . ($hasUserId ? '✅' : '❌') . "\n";
    
    // 3. Sugerir correção
    echo "\n3. Sugestão de correção para INSERT:\n";
    
    if ($hasUserId && !$hasGoogleUid) {
        echo "   ✅ Schema novo: usar user_id\n";
        echo "   SQL corrigido:\n";
        echo "   INSERT INTO game_sessions (\n";
        echo "       user_id,           -- em vez de google_uid\n";
        echo "       session_uuid,      -- pode ser session_token\n";
        echo "       mission_id,        -- pode ser mission_number\n";
        echo "       status,\n";
        echo "       ...\n";
        echo "   ) VALUES (?, ?, ?, ?, ...)\n";
    } elseif ($hasGoogleUid && !$hasUserId) {
        echo "   ✅ Schema antigo: usar google_uid\n";
        echo "   Manter código atual\n";
    } elseif ($hasGoogleUid && $hasUserId) {
        echo "   ⚠️  Ambos: usar user_id (recomendado)\n";
    } else {
        echo "   ❌ Nenhum: problema no schema\n";
    }
    
    // 4. Verificar INSERT atual do game-start.php
    echo "\n4. Problemas no INSERT atual:\n";
    $currentIssues = [];
    
    // Contar placeholders vs valores
    $sqlFromFile = "INSERT INTO game_sessions (
            google_uid,
            wallet_address,
            session_token,
            mission_number,
            status,
            is_hard_mode,
            rare_asteroids_target,
            epic_asteroid_target,
            rare_ids,
            epic_id,
            ip_address,
            earnings_brl,
            started_at,
            created_at
        ) VALUES (?, NULL, ?, ?, 'active', ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())";
    
    // Contar placeholders (?)
    $placeholderCount = substr_count($sqlFromFile, '?');
    $valueCount = 9; // Do código: $stmt->execute([...]) tem 9 valores
    
    if ($placeholderCount !== $valueCount) {
        echo "   ❌ Placeholders ($placeholderCount) ≠ Valores ($valueCount)\n";
        $currentIssues[] = "Mismatch placeholders/valores";
    }
    
    // Verificar colunas que não existem
    preg_match_all('/\s+(\w+),/', $sqlFromFile, $matches);
    $columnsInSql = $matches[1];
    
    foreach ($columnsInSql as $col) {
        if (!isset($columns[$col])) {
            echo "   ❌ Coluna não existe: $col\n";
            $currentIssues[] = "Coluna $col não existe";
        }
    }
    
    // 5. Criar SQL corrigido
    echo "\n5. SQL corrigido sugerido:\n";
    
    if ($hasUserId) {
        $correctedSql = "INSERT INTO game_sessions (
            user_id,
            session_uuid,
            mission_id,
            status,
            is_hard_mode,
            rare_asteroids_target,
            epic_asteroid_target,
            rare_ids,
            epic_id,
            ip_address,
            earnings_brl,
            started_at
        ) VALUES (?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, 0, NOW())";
        
        echo $correctedSql . "\n";
        echo "\n   Valores: [\$userId, \$sessionToken, \$missionNumber, \$isHardMode, \$rareCount, \$hasEpic, json_encode(\$rareIds), \$epicId, \$clientIP]\n";
    }
    
    echo "\n🎯 CONCLUSÃO:\n";
    echo "O game-start.php precisa ser corrigido para:\n";
    echo "1. Usar tabela users em vez de players ✅ (já corrigido)\n";
    echo "2. Corrigir INSERT na game_sessions para schema real\n";
    echo "3. Usar user_id se a tabela tiver essa coluna\n";
    
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
}