<?php
// ============================================
// TEST CONFIG VERSION
// Salvar como: api/test-config-version.php
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'config_file' => __FILE__,
    'config_exists' => file_exists(__DIR__ . '/config.php'),
    'config_size' => file_exists(__DIR__ . '/config.php') ? filesize(__DIR__ . '/config.php') : 0,
    'config_modified' => file_exists(__DIR__ . '/config.php') ? date('Y-m-d H:i:s', filemtime(__DIR__ . '/config.php')) : null,
    'functions_defined' => [
        'setCorsHeaders' => function_exists('setCorsHeaders'),
        'setCORSHeaders' => function_exists('setCORSHeaders'),
        'getDatabaseConnection' => function_exists('getDatabaseConnection'),
    ],
    'config_content_preview' => file_exists(__DIR__ . '/config.php') ? 
        substr(file_get_contents(__DIR__ . '/config.php'), -500) : null,
    'server_time' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION
], JSON_PRETTY_PRINT);
