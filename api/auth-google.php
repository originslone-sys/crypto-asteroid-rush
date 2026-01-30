<?php
// ============================================
// UNOBIX - Autenticação Google (Firebase ID Token)
// Arquivo: api/auth-google.php
// v5.0 - Unobix-only (sem legado wallet), token validation, robust JSON
// ============================================

require_once __DIR__ . "/config.php";

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readInput(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    $json = is_array($json) ? $json : [];
    return array_merge($_GET ?? [], $_POST ?? [], $json);
}

function requireEnv(string $key): string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        throw new Exception("Missing env: {$key}");
    }
    return $v;
}

/**
 * Valida ID Token do Firebase via Identity Toolkit (lookup)
 * Retorna array user (localId/email/displayName/photoUrl) ou lança Exception.
 */
function verifyFirebaseIdToken(string $idToken): array {
    $apiKey = requireEnv('FIREBASE_WEB_API_KEY');
    $url = "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=" . urlencode($apiKey);

    $payload = json_encode(['idToken' => $idToken]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 12
        ]
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        throw new Exception("Failed to verify token (network)");
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['users'][0])) {
        throw new Exception("Invalid token");
    }

    $u = $data['users'][0];
    return [
        'google_uid'   => $u['localId'] ?? null,
        'email'        => $u['email'] ?? null,
        'display_name' => $u['displayName'] ?? null,
        'photo_url'    => $u['photoUrl'] ?? null,
    ];
}

try {
    $input = readInput();
    $action = $input['action'] ?? 'login';

    $pdo = getDatabaseConnection();
    if (!$pdo) {
        jsonOut(['success' => false, 'error' => 'Falha na conexão com o banco'], 500);
    }

    if ($action === 'logout') {
        $sessionToken = $input['session_token'] ?? $input['sessionToken'] ?? null;
        if ($sessionToken) {
            $stmt = $pdo->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ?");
            $stmt->execute([$sessionToken]);
        }
        jsonOut(['success' => true, 'message' => 'Logout realizado com sucesso']);
    }

    if ($action === 'profile') {
        $googleUid = $input['google_uid'] ?? $input['googleUid'] ?? null;
        if (!$googleUid) jsonOut(['success' => false, 'error' => 'google_uid é obrigatório'], 400);

        $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
        $stmt->execute([$googleUid]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$player) jsonOut(['success' => false, 'error' => 'Usuário não encontrado'], 404);

        jsonOut([
            'success' => true,
            'player' => [
                'id' => (int)$player['id'],
                'google_uid' => $player['google_uid'],
                'email' => $player['email'] ?? '',
                'display_name' => $player['display_name'] ?? '',
                'photo_url' => $player['photo_url'] ?? '',
                'balance_brl' => number_format((float)($player['balance_brl'] ?? 0), 2, '.', ''),
                'total_earned_brl' => number_format((float)($player['total_earned_brl'] ?? 0), 2, '.', ''),
                'total_withdrawn_brl' => number_format((float)($player['total_withdrawn_brl'] ?? 0), 2, '.', ''),
                'staked_balance_brl' => number_format((float)($player['staked_balance_brl'] ?? 0), 2, '.', ''),
                'total_played' => (int)($player['total_played'] ?? 0),
                'created_at' => $player['created_at'] ?? null,
            ]
        ]);
    }

    // login/verify (Unobix-only)
    $idToken = $input['idToken'] ?? $input['id_token'] ?? null;
    if (!$idToken) {
        jsonOut(['success' => false, 'error' => 'idToken é obrigatório'], 400);
    }

    $firebaseUser = verifyFirebaseIdToken($idToken);
    $googleUid = $firebaseUser['google_uid'];

    if (!$googleUid || strlen($googleUid) < 10 || strlen($googleUid) > 128) {
        jsonOut(['success' => false, 'error' => 'google_uid inválido'], 400);
    }

    // Upsert player (sem wallet)
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($player) {
        // ban check
        if (!empty($player['is_banned']) && (int)$player['is_banned'] === 1) {
            $pdo->rollBack();
            jsonOut([
                'success' => false,
                'error' => 'Conta suspensa',
                'message' => $player['ban_reason'] ?? 'Entre em contato com o suporte.'
            ], 403);
        }

        // update fields if changed
        $updates = [];
        $params = [];

        foreach ([
            'email' => $firebaseUser['email'],
            'display_name' => $firebaseUser['display_name'],
            'photo_url' => $firebaseUser['photo_url'],
        ] as $col => $val) {
            if ($val !== null && $val !== '' && ($player[$col] ?? '') !== $val) {
                $updates[] = "{$col} = ?";
                $params[] = $val;
            }
        }

        if ($updates) {
            $params[] = $googleUid;
            $sql = "UPDATE players SET " . implode(', ', $updates) . " WHERE google_uid = ?";
            $pdo->prepare($sql)->execute($params);
        }

        $playerId = (int)$player['id'];
        $isNew = false;
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO players (google_uid, email, display_name, photo_url, balance_brl, total_played, created_at)
            VALUES (?, ?, ?, ?, 0.00, 0, NOW())
        ");
        $stmt->execute([
            $googleUid,
            $firebaseUser['email'],
            $firebaseUser['display_name'],
            $firebaseUser['photo_url'],
        ]);

        $playerId = (int)$pdo->lastInsertId();
        $isNew = true;
    }

    // create session token
    $sessionToken = hash('sha256', $googleUid . '|' . $playerId . '|' . microtime(true) . '|' . bin2hex(random_bytes(16)));

    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (google_uid, session_token, firebase_token, ip_address, user_agent, is_active, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
    ");
    $stmt->execute([
        $googleUid,
        $sessionToken,
        $idToken,
        getClientIP(),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);

    $pdo->commit();

    // fetch updated player (no lock)
    $stmt = $pdo->prepare("SELECT * FROM players WHERE google_uid = ? LIMIT 1");
    $stmt->execute([$googleUid]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    jsonOut([
        'success' => true,
        'message' => $isNew ? 'Conta criada com sucesso! Bem-vindo ao Unobix!' : 'Login realizado com sucesso',
        'is_new_user' => $isNew,
        'session_token' => $sessionToken,
        'player' => [
            'id' => (int)$player['id'],
            'google_uid' => $player['google_uid'],
            'email' => $player['email'] ?? '',
            'display_name' => $player['display_name'] ?? '',
            'photo_url' => $player['photo_url'] ?? '',
            'balance_brl' => number_format((float)($player['balance_brl'] ?? 0), 2, '.', ''),
            'total_earned_brl' => number_format((float)($player['total_earned_brl'] ?? 0), 2, '.', ''),
            'total_withdrawn_brl' => number_format((float)($player['total_withdrawn_brl'] ?? 0), 2, '.', ''),
            'staked_balance_brl' => number_format((float)($player['staked_balance_brl'] ?? 0), 2, '.', ''),
            'total_played' => (int)($player['total_played'] ?? 0),
        ]
    ]);

} catch (Throwable $e) {
    error_log("auth-google.php error: " . $e->getMessage());
    jsonOut(['success' => false, 'error' => 'Erro no servidor'], 500);
}
