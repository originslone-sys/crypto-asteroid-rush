<?php
echo "🔍 Testando symlink includes...\n\n";

// Teste 1: Incluir via symlink
echo "Test 1: include 'config.php' (symlink)...\n";
if (file_exists('config.php')) {
    echo "✅ config.php exists (symlink)\n";
    
    // Verificar se é symlink
    if (is_link('config.php')) {
        echo "✅ Is a symlink\n";
        echo "   Target: " . readlink('config.php') . "\n";
    }
    
    // Tentar incluir
    try {
        require_once 'config.php';
        echo "✅ config.php included successfully\n";
        
        // Verificar se constantes foram definidas
        if (defined('DB_HOST')) {
            echo "✅ DB_HOST defined: " . DB_HOST . "\n";
        }
        if (defined('FIREBASE_API_KEY')) {
            echo "✅ FIREBASE_API_KEY defined: " . substr(FIREBASE_API_KEY, 0, 10) . "...\n";
        }
    } catch (Exception $e) {
        echo "❌ Error including config.php: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ config.php not found\n";
}

echo "\n";

// Teste 2: Incluir diretamente
echo "Test 2: include 'api/config.php' (original)...\n";
if (file_exists('api/config.php')) {
    echo "✅ api/config.php exists\n";
    
    try {
        require_once 'api/config.php';
        echo "✅ api/config.php included successfully\n";
    } catch (Exception $e) {
        echo "❌ Error including api/config.php: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Teste 3: auth-google.php incluindo config-cloudrun.php
echo "Test 3: Simulating auth-google.php include chain...\n";
echo "auth-google.php → config-cloudrun.php → config.php\n";

if (file_exists('auth-google.php')) {
    echo "✅ auth-google.php exists (symlink)\n";
    
    // Ler primeiras linhas para ver includes
    $lines = file('auth-google.php', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $found = false;
    foreach ($lines as $line) {
        if (strpos($line, 'config-cloudrun.php') !== false) {
            echo "   Line found: " . trim($line) . "\n";
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        echo "⚠️  config-cloudrun.php include not found in auth-google.php\n";
    }
}

echo "\n";

// Teste 4: Verificar se PHP pode seguir symlinks
echo "Test 4: PHP symlink resolution test...\n";
$realpath = realpath('config.php');
echo "   realpath('config.php'): " . ($realpath ?: 'false') . "\n";

if ($realpath && file_exists($realpath)) {
    echo "✅ PHP can resolve symlink to real file\n";
} else {
    echo "❌ PHP cannot resolve symlink\n";
}
