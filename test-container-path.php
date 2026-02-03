<?php
// Testando diferentes caminhos que o diagnostic pode estar usando
$paths = [
    'api/config.php',
    '/app/api/config.php',
    './api/config.php',
    'config.php'
];

foreach ($paths as $path) {
    echo "Testing: $path - ";
    if (file_exists($path)) {
        echo "EXISTS\n";
    } else {
        echo "NOT FOUND\n";
    }
}
