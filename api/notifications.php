<?php
// ============================================
// UNOBIX - API de Notificações Públicas
// api/notifications.php
// Retorna notificações ativas para exibir no dashboard
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

try {
    $pdo = getDatabaseConnection();

    // Buscar notificações ativas dentro do período válido
    $stmt = $pdo->query("
        SELECT id, title, message, type
        FROM notifications
        WHERE is_active = 1
          AND starts_at <= NOW()
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at DESC
        LIMIT 5
    ");

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as &$n) {
        $n['id'] = (int)$n['id'];
    }
    unset($n);

    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);

} catch (Throwable $e) {
    error_log("notifications.php error: " . $e->getMessage());
    echo json_encode(['success' => true, 'notifications' => []]);
}
