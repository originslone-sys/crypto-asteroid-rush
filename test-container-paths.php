<?php
// Teste para verificar quais caminhos existem no container
// Será executado via curl no Cloud Run

$paths = [
    // Caminhos antigos (api/)
    'api/config.php',
    'api/config-cloudrun.php',
    'api/auth-firebase.php',
    'api/game-start.php',
    
    // Caminhos novos (raiz via symlinks)
    'config.php',
    'config-cloudrun.php',
    'auth-firebase.php',
    'game-start.php',
    
    // Caminhos absolutos
    '/app/api/config.php',
    '/app/config.php',
    
    // Current directory
    './config.php',
    './api/config.php'
];

header('Content-Type: application/json');

$results = [];
foreach ($paths as $path) {
    $exists = file_exists($path);
    $results[$path] = [
        'exists' => $exists,
        'is_link' => $exists ? is_link($path) : false,
        'realpath' => $exists ? realpath($path) : null
    ];
}

// Also check current directory
$results['current_dir'] = getcwd();
$results['dir_listing'] = [];
$results['dir_listing']['root'] = @scandir('.') ?: [];
$results['dir_listing']['api'] = @scandir('api') ?: [];

echo json_encode($results, JSON_PRETTY_PRINT);
