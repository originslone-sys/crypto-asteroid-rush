<?php
// ============================================
// UNOBIX - PvP: Info pública (sem autenticação)
// api/pvp-info.php
// Retorna configurações exibidas no lobby antes de entrar na fila
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Erro de conexão');

    $entryFee     = PVP_ENTRY_FEE_CREDITS;
    $prizeBrl     = 0.0;
    $pvpEnabled   = true;
    $gameDuration = PVP_GAME_DURATION;
    $lives        = PVP_LIVES;

    $stmt = $pdo->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key IN ('pvp_entry_fee_credits','pvp_winner_prize_brl','pvp_enabled','pvp_game_duration','pvp_lives')");
    while ($row = $stmt->fetch()) {
        switch ($row['setting_key']) {
            case 'pvp_entry_fee_credits': $entryFee     = (int)$row['setting_value']; break;
            case 'pvp_winner_prize_brl':  $prizeBrl     = (float)$row['setting_value']; break;
            case 'pvp_enabled':           $pvpEnabled   = ($row['setting_value'] !== 'false'); break;
            case 'pvp_game_duration':     $gameDuration = (int)$row['setting_value']; break;
            case 'pvp_lives':             $lives        = (int)$row['setting_value']; break;
        }
    }

    echo json_encode([
        'success'           => true,
        'pvp_enabled'       => $pvpEnabled,
        'entry_fee'         => $entryFee,
        'winner_prize_brl'  => $prizeBrl,
        'game_duration'     => $gameDuration,
        'lives'             => $lives,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno']);
}
