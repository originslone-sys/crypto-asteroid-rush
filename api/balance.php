<?php
// ============================================
// UNOBIX - API de Saldo (Unobix-only)
// Arquivo: api/balance.php
// v5.0 - Google UID only + BRL only + robust identifier resolver
// ============================================

// ============================================================
// JSON Guard (Railway): impedir HTML/Warnings de quebrar JSON
// ============================================================
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

if (!ob_get_level()) {
    ob_start();
}

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) { ob_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'balance_brl' => '0.00',
            'error' => 'Erro no servidor',
            'debug_error' => $err['message'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});
// ============================================================

require_once __DIR__ . "/config.php";

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

/**
 * Resolve google_uid from multiple possible formats.
 * Accepts:
 * - $input['google_uid'] / googleUid / uid
 * - identifier string (google uid)
 * - identifier array ['type'=>'google_uid','value'=>'...']
 * - identifier array ['google_uid'=>'...']
 */
function resolveGoogleUid(array $input): ?string {
    // 1) direct fields in input
    $candidates = [
        $input['google_uid'] ?? null,
        $input['googleUid'] ?? null,
        $input['uid'] ?? null,
    ];

    foreach ($candidates as $c) {
        if (is_string($c)) {
            $v = trim($c);
            if ($v !== '' && validateGoogleUid($v)) return $v;
        }
    }

    // 2) if config has getUserIdentifier(), try it but normalize output
    if (function_exists('getUserIdentifier')) {
        try {
            $id = getUserIdentifier($input);

            // string
            if (is_string($id)) {
                $v = trim($id);
                if ($v !== '' && validateGoogleUid($v)) return $v;
            }

            // array type/value
            if (is_array($id)) {
                if (isset($id['google_uid']) && is_string($id['google_uid'])) {
                    $v = trim($id['google_uid']);
                    if ($v !== '' && validateGoogleUid($v)) return $v;
                }
                if (isset($id['type'], $id['value']) && $id['type'] === 'google_uid' && is_string($id['value'])) {
                    $v = trim($id['value']);
                    if ($v !== '' && validateGoogleUid($v)) return $v;
                }
            }
        } catch (Throwable $e) {
            // ignore; we'll return null below
        }
    }

    return null;
}

$input = function_exists('getRequestInput') ? getRequestInput() : [];
if (!is_array($input)) $input = [];

// Debug opcional: /api/balance.php?debug=1
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

$googleUid = resolveGoogleUid($input);
if (!$googleUid) {
    $resp = [
        'success' => false,
        'balance_brl' => '0.00',
        'error' => 'Identificador inválido. Envie google_uid.'
    ];

    if ($debug) {
        $resp['debug'] = [
            'input_keys' => array_keys($input),
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        ];
    }

    if (ob_get_length()) { ob_clean(); }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    // Unobix-only: buscar por google_uid
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        if (ob_get_length()) { ob_clean(); }
        echo json_encode([
            'success' => true,
            'balance_brl' => '0.00',
            'staked_balance_brl' => '0.00',
            'pending_stake_reward' => '0.00',
            'total_earned_brl' => '0.00',
            'total_withdrawn_brl' => '0.00',
            'total_played' => 0,
            'is_new_player' => true
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Calcular stake rewards pendentes
    $stakeReward = 0.0;
    $staked = (float)($player['staked_balance_brl'] ?? 0);
    $lastUpdate = $player['last_stake_update'] ?? null;

    if ($staked > 0 && $lastUpdate) {
        $secondsPassed = time() - strtotime($lastUpdate);
        $dailyRate = (defined('STAKE_APY') ? STAKE_APY : 0.05) / 365;
        $daysElapsed = $secondsPassed / 86400;
        $stakeReward = $staked * (pow(1 + $dailyRate, $daysElapsed) - 1);
        if ($stakeReward < 0) $stakeReward = 0.0;
    }

    if (ob_get_length()) { ob_clean(); }
    echo json_encode([
        'success' => true,
        'google_uid' => $googleUid,
        'balance_brl' => number_format((float)($player['balance_brl'] ?? 0), 2, '.', ''),
        'staked_balance_brl' => number_format((float)($player['staked_balance_brl'] ?? 0), 2, '.', ''),
        'pending_stake_reward' => number_format((float)$stakeReward, 2, '.', ''),
        'total_earned_brl' => number_format((float)($player['total_earned_brl'] ?? 0), 2, '.', ''),
        'total_withdrawn_brl' => number_format((float)($player['total_withdrawn_brl'] ?? 0), 2, '.', ''),
        'total_played' => (int)($player['total_played'] ?? 0),
        'is_banned' => (bool)($player['is_banned'] ?? 0),
        'display_name' => $player['display_name'] ?? null,
        'email' => $player['email'] ?? null,
        'is_new_player' => false
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log("balance.php error: " . $e->getMessage());

    if (ob_get_length()) { ob_clean(); }
    echo json_encode([
        'success' => false,
        'balance_brl' => '0.00',
        'error' => 'Erro no servidor'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>
