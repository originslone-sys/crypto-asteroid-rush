<?php
require_once __DIR__ . "/config.php";

$db = getDatabaseConnection();

if ($db) {
    echo "Conectado ao banco com sucesso!";
} else {
    echo "Erro ao conectar no banco.";
}
