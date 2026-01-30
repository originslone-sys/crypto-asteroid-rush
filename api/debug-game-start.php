<?php
// ============================================
// DIAGNÓSTICO - Descobrir erro no game-start.php
// Arquivo: api/debug-game-start.php
// REMOVER APÓS DIAGNOSTICAR!
// ============================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO game-start.php ===\n\n";

// 1. Verificar se config.php existe
echo "1. Verificando config.php...\n";
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    echo "   ✓ config.php existe\n";
    echo "   Tamanho: " . filesize($configPath) . " bytes\n";
} else {
    echo "   ✗ config.php NÃO EXISTE!\n";
    exit;
}

// 2. Tentar incluir config.php
echo "\n2. Incluindo config.php...\n";
try {
    require_once $configPath;
    echo "   ✓ config.php carregado com sucesso\n";
} catch (Throwable $e) {
    echo "   ✗ ERRO ao carregar config.php:\n";
    echo "   " . $e->getMessage() . "\n";
    echo "   Linha: " . $e->getLine() . "\n";
    exit;
}

// 3. Verificar funções necessárias
echo "\n3. Verificando funções...\n";
$functions = [
    'getDatabaseConnection',
    'getClientIP', 
    'setCorsHeaders',
    'validateGoogleUid',
    'validateGoogleUID',
    'getOrCreatePlayer',
    'isHardModeMission',
    'generateSessionToken',
    'secureLog',
    'findPlayer'
];

foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "   ✓ $func() existe\n";
    } else {
        echo "   ✗ $func() NÃO EXISTE!\n";
    }
}

// 4. Verificar constantes necessárias
echo "\n4. Verificando constantes...\n";
$constants = [
    'GAME_DURATION',
    'INITIAL_LIVES',
    'MAX_MISSIONS_PER_HOUR',
    'HARD_MODE_PERCENTAGE',
    'CAPTCHA_REQUIRED_ON_VICTORY'
];

foreach ($constants as $const) {
    if (defined($const)) {
        echo "   ✓ $const = " . constant($const) . "\n";
    } else {
        echo "   ✗ $const NÃO DEFINIDA!\n";
    }
}

// 5. Testar conexão com banco
echo "\n5. Testando conexão com banco...\n";
try {
    if (function_exists('getDatabaseConnection')) {
        $pdo = getDatabaseConnection();
        if ($pdo) {
            echo "   ✓ Conexão OK\n";
            
            // Verificar tabelas
            echo "\n6. Verificando tabelas...\n";
            $tables = ['players', 'game_sessions', 'ip_sessions', 'game_events'];
            foreach ($tables as $table) {
                $result = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
                if ($result) {
                    echo "   ✓ Tabela '$table' existe\n";
                } else {
                    echo "   ✗ Tabela '$table' NÃO EXISTE!\n";
                }
            }
            
            // Verificar colunas de game_sessions
            echo "\n7. Verificando colunas de game_sessions...\n";
            try {
                $cols = $pdo->query("DESCRIBE game_sessions")->fetchAll(PDO::FETCH_COLUMN);
                echo "   Colunas: " . implode(', ', $cols) . "\n";
            } catch (Exception $e) {
                echo "   Erro: " . $e->getMessage() . "\n";
            }
            
        } else {
            echo "   ✗ getDatabaseConnection() retornou null\n";
        }
    } else {
        echo "   ✗ Função getDatabaseConnection não existe\n";
    }
} catch (Throwable $e) {
    echo "   ✗ ERRO de conexão:\n";
    echo "   " . $e->getMessage() . "\n";
}

// 6. Tentar incluir game-start.php com output buffering
echo "\n8. Testando game-start.php...\n";
$gameStartPath = __DIR__ . '/game-start.php';
if (file_exists($gameStartPath)) {
    echo "   ✓ game-start.php existe\n";
    echo "   Tamanho: " . filesize($gameStartPath) . " bytes\n";
    
    // Checar sintaxe
    $output = [];
    $return = 0;
    exec("php -l " . escapeshellarg($gameStartPath) . " 2>&1", $output, $return);
    echo "   Verificação de sintaxe: " . implode("\n   ", $output) . "\n";
} else {
    echo "   ✗ game-start.php NÃO EXISTE!\n";
}

echo "\n=== FIM DO DIAGNÓSTICO ===\n";
