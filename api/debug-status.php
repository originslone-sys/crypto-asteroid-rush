<?php
// Debug para verificar coluna status
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();

// Ver definição da coluna status
$stmt = $pdo->query("SHOW COLUMNS FROM game_sessions WHERE Field = 'status'");
$statusCol = $stmt->fetch(PDO::FETCH_ASSOC);

// Ver valores existentes
$stmt = $pdo->query("SELECT DISTINCT status FROM game_sessions LIMIT 10");
$existingValues = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'status_column_definition' => $statusCol,
    'existing_values' => $existingValues
], JSON_PRETTY_PRINT);
