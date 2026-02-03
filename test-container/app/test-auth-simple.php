<?php
// Versão simplificada para teste
require_once "config-cloudrun.php";

function setCorsHeaders() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

setCorsHeaders();
header("Content-Type: application/json");

echo json_encode([
    "test" => "success",
    "config_loaded" => defined("DB_HOST"),
    "firebase_configured" => defined("FIREBASE_API_KEY"),
    "timestamp" => time()
]);
?>