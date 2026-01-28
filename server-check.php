<?php
/**
 * ===========================================
 * Diagnóstico do Servidor - Crypto Asteroid Rush
 * Acesse: /server-check.php
 * REMOVER APÓS TESTAR (expõe informações sensíveis)
 * ===========================================
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Coleta informações do servidor
$diagnostics = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    
    // Informações de memória
    'memory' => [
        'total_mb' => round(getSystemMemory() / 1024 / 1024, 2),
        'php_limit' => ini_get('memory_limit'),
        'php_used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
    ],
    
    // PHP-FPM
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => php_sapi_name(), // Deve ser 'fpm-fcgi'
        'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false,
    ],
    
    // OPcache detalhes
    'opcache' => getOpcacheInfo(),
    
    // Servidor web
    'server' => [
        'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'is_nginx' => strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false,
    ],
    
    // Extensões importantes
    'extensions' => [
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mysqli' => extension_loaded('mysqli'),
        'opcache' => extension_loaded('Zend OPcache'),
    ],
    
    // Teste de conexão com banco (se configurado)
    'database' => testDatabaseConnection(),
    
    // Variáveis de ambiente Railway
    'railway' => [
        'detected' => !empty(getenv('RAILWAY_ENVIRONMENT')) || !empty(getenv('MYSQLHOST')),
        'environment' => getenv('RAILWAY_ENVIRONMENT') ?: 'not set',
    ],
];

echo json_encode($diagnostics, JSON_PRETTY_PRINT);

// ===========================================
// FUNÇÕES AUXILIARES
// ===========================================

function getSystemMemory() {
    if (PHP_OS_FAMILY === 'Linux') {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo && preg_match('/MemTotal:\s+(\d+)/', $meminfo, $matches)) {
            return $matches[1] * 1024; // Converte KB para bytes
        }
    }
    return 0;
}

function getOpcacheInfo() {
    if (!function_exists('opcache_get_status')) {
        return ['enabled' => false, 'reason' => 'OPcache não instalado'];
    }
    
    $status = @opcache_get_status(false);
    if ($status === false) {
        return ['enabled' => false, 'reason' => 'OPcache desabilitado'];
    }
    
    return [
        'enabled' => true,
        'memory_usage_mb' => round($status['memory_usage']['used_memory'] / 1024 / 1024, 2),
        'memory_free_mb' => round($status['memory_usage']['free_memory'] / 1024 / 1024, 2),
        'cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
        'hit_rate' => round($status['opcache_statistics']['opcache_hit_rate'] ?? 0, 2) . '%',
    ];
}

function testDatabaseConnection() {
    // Tenta carregar config se existir
    $configFile = __DIR__ . '/api/config.php';
    
    if (!file_exists($configFile)) {
        return ['connected' => false, 'reason' => 'config.php não encontrado'];
    }
    
    try {
        // Verifica se constantes do DB estão definidas
        if (!defined('DB_HOST')) {
            require_once $configFile;
        }
        
        if (!defined('DB_HOST')) {
            return ['connected' => false, 'reason' => 'Constantes DB não definidas'];
        }
        
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_TIMEOUT => 5]
        );
        
        // Testa query simples
        $stmt = $pdo->query('SELECT 1');
        
        return [
            'connected' => true,
            'host' => DB_HOST,
            'database' => DB_NAME,
        ];
        
    } catch (Exception $e) {
        return [
            'connected' => false,
            'reason' => 'Erro: ' . $e->getMessage(),
        ];
    }
}
