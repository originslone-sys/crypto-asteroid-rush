<?php
// ============================================
// UNOBIX - Configuração dinâmica de modos v2
// api/game-modes-config.php
// Retorna configs dos modos lidas do banco (admin)
// Fallback para defaults hardcoded se não houver no banco
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro de conexao']);
        exit;
    }

    // Ler todas as settings de modo do banco
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key LIKE 'mode_%'");
    $dbSettings = [];
    while ($row = $stmt->fetch()) {
        $dbSettings[$row['setting_key']] = $row['setting_value'];
    }

    // Defaults (iguais ao hardcoded no CONFIG JS e admin)
    $defaults = [
        'normal' => [
            'enabled' => true,
            'credits' => 1,
            'speed_multiplier' => 1.3,
            'max_asteroids' => 14,
            'spawn_interval' => 400,
        ],
        'hard' => [
            'enabled' => true,
            'credits' => 2,
            'speed_multiplier' => 1.7,
            'max_asteroids' => 18,
            'spawn_interval' => 200,
        ],
        'extreme' => [
            'enabled' => true,
            'credits' => 3,
            'speed_multiplier' => 2.4,
            'max_asteroids' => 22,
            'spawn_interval' => 100,
        ],
        'training' => [
            'enabled' => true,
        ],
    ];

    // Montar resposta mesclando DB com defaults
    $modes = [];
    foreach ($defaults as $mode => $def) {
        $prefix = "mode_{$mode}_";
        $config = [
            'enabled' => ($dbSettings[$prefix . 'enabled'] ?? ($def['enabled'] ? 'true' : 'false')) === 'true',
        ];

        if ($mode !== 'training') {
            $config['credits'] = (int)($dbSettings[$prefix . 'credits'] ?? $def['credits']);
            $config['speed_multiplier'] = (float)($dbSettings[$prefix . 'speed'] ?? $def['speed_multiplier']);
            $config['max_asteroids'] = (int)($dbSettings[$prefix . 'max_asteroids'] ?? $def['max_asteroids']);
            $config['spawn_interval'] = (int)($dbSettings[$prefix . 'spawn_interval'] ?? $def['spawn_interval']);
        }

        $modes[$mode] = $config;
    }

    echo json_encode([
        'success' => true,
        'modes' => $modes
    ]);

} catch (Throwable $e) {
    error_log("GAME-MODES-CONFIG ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
