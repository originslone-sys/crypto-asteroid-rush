<?php
// ============================================
// TESTE DE CONEXÃO CLOUD SQL
// ============================================

echo "🔍 TESTE DE CONEXÃO CLOUD SQL\n";
echo "=============================\n\n";

// Testar diferentes métodos de conexão
$connectionMethods = [
    [
        'name' => 'Socket Unix (Cloud Run)',
        'dsn' => 'mysql:unix_socket=/cloudsql/project-7be1cae5-5f08-45fb-aca:us-west1:unobix;dbname=unobix_db;charset=utf8mb4',
        'user' => 'unobix_user',
        'pass' => 'YyZD3H)dndSo*A/N'
    ],
    [
        'name' => 'TCP/IP Público',
        'dsn' => 'mysql:host=34.168.76.127;port=3306;dbname=unobix_db;charset=utf8mb4',
        'user' => 'unobix_user', 
        'pass' => 'YyZD3H)dndSo*A/N'
    ],
    [
        'name' => 'TCP/IP Local (Cloud SQL Proxy)',
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=unobix_db;charset=utf8mb4',
        'user' => 'unobix_user',
        'pass' => 'YyZD3H)dndSo*A/N'
    ]
];

foreach ($connectionMethods as $method) {
    echo "🧪 Testando: {$method['name']}\n";
    echo "   DSN: {$method['dsn']}\n";
    
    try {
        $pdo = new PDO($method['dsn'], $method['user'], $method['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        echo "   ✅ CONEXÃO BEM-SUCEDIDA!\n";
        
        // Testar query simples
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "   📊 Total users: {$result['total']}\n";
        
        $pdo = null;
        
    } catch (Exception $e) {
        echo "   ❌ FALHA: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "🎯 RECOMENDAÇÕES:\n";
echo "1. No Windows: Use Cloud SQL Proxy ou Adminer Web\n";
echo "2. No Cloud Run: Socket Unix funciona automaticamente\n";
echo "3. Para debug: Use Adminer (senha: unobix_admin_2024)\n";
echo "\n";
echo "🔗 Adminer: https://crypto-asteroid-234282032979.us-west1.run.app/adminer.php\n";