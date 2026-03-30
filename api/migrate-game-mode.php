<?php
// ============================================
// UNOBIX - Migration: Adicionar coluna game_mode
// Executar uma vez para adicionar suporte a modos de jogo
// ============================================

require_once __DIR__ . "/config.php";

header('Content-Type: application/json');

try {
    $pdo = getDatabaseConnection();

    // Adicionar coluna game_mode se não existir
    $columns = $pdo->query("SHOW COLUMNS FROM game_sessions LIKE 'game_mode'")->fetch();

    if (!$columns) {
        $pdo->exec("
            ALTER TABLE game_sessions
            ADD COLUMN game_mode VARCHAR(20) DEFAULT 'normal'
            AFTER is_hard_mode
        ");

        // Atualizar sessões existentes: hard_mode=1 vira 'hard', senão 'normal'
        $pdo->exec("
            UPDATE game_sessions
            SET game_mode = CASE WHEN is_hard_mode = 1 THEN 'hard' ELSE 'normal' END
            WHERE game_mode = 'normal' OR game_mode IS NULL
        ");

        // Adicionar índice
        $pdo->exec("
            ALTER TABLE game_sessions
            ADD INDEX idx_game_mode (game_mode)
        ");

        echo json_encode([
            'success' => true,
            'message' => 'Coluna game_mode adicionada com sucesso!'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Coluna game_mode já existe. Nada a fazer.'
        ]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
