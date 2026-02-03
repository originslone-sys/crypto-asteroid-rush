<?php
// Este arquivo será executado NO CONTAINER para verificar estrutura
// Vamos testar diferentes cenários

echo "🔍 Testing container file structure...\n\n";

// Cenário 1: Arquivos na raiz (/app/)
$rootFiles = ['config.php', 'auth-firebase.php', 'game-start.php'];
foreach ($rootFiles as $file) {
    echo "Root: /app/$file - ";
    echo file_exists($file) ? "✅ EXISTS\n" : "❌ MISSING\n";
}

// Cenário 2: Arquivos em api/ (/app/api/)
echo "\n";
$apiFiles = ['api/config.php', 'api/auth-firebase.php', 'api/game-start.php'];
foreach ($apiFiles as $file) {
    echo "API: $file - ";
    echo file_exists($file) ? "✅ EXISTS\n" : "❌ MISSING\n";
}

// Cenário 3: Listar diretório atual
echo "\n📁 Current directory listing:\n";
system("ls -la 2>/dev/null | head -20");

// Cenário 4: Verificar se api/ directory exists
echo "\n📁 Checking for api/ directory:\n";
if (is_dir('api')) {
    echo "✅ api/ directory exists\n";
    system("ls -la api/ 2>/dev/null | head -10");
} else {
    echo "❌ api/ directory NOT FOUND\n";
}
