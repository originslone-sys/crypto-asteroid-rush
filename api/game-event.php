<?php
require_once __DIR__ . "/config.php";

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================
// Input (JSON + POST)
// ===============================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$wallet       = $input['wallet']        ?? ($_POST['wallet'] ?? '');
$sessionId    = $input['session_id']    ?? ($_POST['session_id'] ?? '');
$sessionToken = $input['session_token'] ?? ($_POST['session_token'] ?? '');
$eventType    = $input['event_type']    ?? ($_POST['event_type'] ?? '');
$eventValue   = $input['event_value']   ?? ($_POST['event_value'] ?? 0);

$wallet = trim(strtolower($wallet));
$eventType = trim(strtolower($eventType));
$eventValue = (int)$eventValue;

// ===============================
// Validação básica
// ===============================
if (
    !preg_match('/^0x[a-f0-9]{40}$/', $wallet) ||
    !$sessionId ||
    !$sessionToken ||
    !$eventType
) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

// Eventos permitidos
$allowedEvents = [
    'asteroid_destroyed',
    'rare_asteroid_destroyed',
    'epic_asteroid_destroyed'
];

if (!in_array($eventType, $allowedEvents, true)) {
    echo json_encode(['success' => false, 'error' => 'Evento inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception('Erro ao conectar ao banco');

    $pdo->beginTransaction();

    // ===============================
    // Buscar sessão ativa
    // ===============================
    $stmt = $pdo->prepare("
        SELECT id,
               asteroids_destroyed,
               rare_asteroids,
               epic_asteroids,
               earnings_usdt
        FROM game_sessions
        WHERE id = ?
          AND wallet_address = ?
          AND session_token = ?
          AND status = 'active'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$sessionId, $wallet, $sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        throw new Exception('Sessão inválida ou finalizada');
    }

    // ===============================
    // Atualizar contadores
    // ===============================
    $asteroidsDestroyed = (int)$session['asteroids_destroyed'];
    $rareAsteroids     = (int)$session['rare_asteroids'];
    $epicAsteroids     = (int)$session['epic_asteroids'];
    $earnings          = (float)$session['earnings_usdt'];

    switch ($eventType) {
        case 'asteroid_destroyed':
            $asteroidsDestroyed += max(1, $eventValue);
            break;

        case 'rare_asteroid_destroyed':
            $rareAsteroids += max(1, $eventValue);
            $earnings += REWARD_RARE;
            break;

        case 'epic_asteroid_destroyed':
            $epicAsteroids += max(1, $eventValue);
            $earnings += REWARD_EPIC;
            break;
    }

    // ===============================
    // Persistir sessão
    // ===============================
    $stmt = $pdo->prepare("
        UPDATE game_sessions
        SET asteroids_destroyed = ?,
            rare_asteroids = ?,
            epic_asteroids = ?,
            earnings_usdt = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $asteroidsDestroyed,
        $rareAsteroids,
        $epicAsteroids,
        $earnings,
        $sessionId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'asteroids_destroyed' => $asteroidsDestroyed,
        'rare_asteroids' => $rareAsteroids,
        'epic_asteroids' => $epicAsteroids,
        'earnings_usdt' => $earnings
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("game-event error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao processar evento']);
}
