<?php
// Debug game-end
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';

$pdo = getDatabaseConnection();

// Ver ENUM de status
$stmt = $pdo->query("SHOW COLUMNS FROM game_sessions WHERE Field = 'status'");
$statusCol = $stmt->fetch(PDO::FETCH_ASSOC);

// Ver última sessão
$stmt = $pdo->query("SELECT id, google_uid, status, earnings_brl, common_asteroids, rare_asteroids, epic_asteroids, legendary_asteroids FROM game_sessions ORDER BY id DESC LIMIT 3");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ver eventos da última sessão
$lastSessionId = $sessions[0]['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM game_events WHERE session_id = ? ORDER BY id DESC LIMIT 10");
$stmt->execute([$lastSessionId]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ver usuário
$stmt = $pdo->query("SELECT id, google_uid, balance_brl, total_earned_brl FROM users WHERE google_uid = 'DqnexVtvrtdG3fe8fSGzHk8NA713'");
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Ver transações
$stmt = $pdo->query("SELECT * FROM transactions ORDER BY id DESC LIMIT 5");
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'status_enum' => $statusCol['Type'],
    'last_sessions' => $sessions,
    'events_for_session_' . $lastSessionId => $events,
    'user' => $user,
    'recent_transactions' => $transactions
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
