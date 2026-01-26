<?php
require_once __DIR__ . "/api/config.php";

$db = getDatabaseConnection();
$stmt = $db->query("SHOW TABLES");

echo "<pre>";
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
echo "</pre>";
