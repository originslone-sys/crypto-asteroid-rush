<?php
// ============================================
// UNOBIX - API de Tickets de Suporte
// Arquivo: api/support-tickets.php
// ============================================

require_once __DIR__ . '/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

try {
    $pdo = getDatabaseConnection();
    ensureSupportTables($pdo);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
    exit;
}

$input = getRequestInput();
$action = $input['action'] ?? '';

switch ($action) {
    case 'create':
        createTicket($pdo, $input);
        break;
    case 'list':
        listTickets($pdo, $input);
        break;
    case 'view':
        viewTicket($pdo, $input);
        break;
    case 'reply':
        replyTicket($pdo, $input);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}

// ============================================
// CRIAR TABELAS (migração segura)
// ============================================
function ensureSupportTables($pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $tableExists = $pdo->query("SHOW TABLES LIKE 'support_tickets'")->fetch();
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE support_tickets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                google_uid VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                category ENUM('account','payment','game','bug','other') DEFAULT 'other',
                status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
                priority ENUM('low','normal','high') DEFAULT 'normal',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user (google_uid),
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE support_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                sender_type ENUM('user','admin') NOT NULL,
                message TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ticket (ticket_id),
                FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

// ============================================
// CRIAR TICKET
// ============================================
function createTicket($pdo, $input) {
    $identifier = getUserIdentifier($input);
    $googleUid = $identifier['value'] ?? null;
    if (!$googleUid || !validateGoogleUid($googleUid)) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        return;
    }

    $subject = trim($input['subject'] ?? '');
    $message = trim($input['message'] ?? '');
    $category = $input['category'] ?? 'other';

    if (strlen($subject) < 3 || strlen($subject) > 255) {
        echo json_encode(['success' => false, 'error' => 'Assunto deve ter entre 3 e 255 caracteres']);
        return;
    }

    if (strlen($message) < 10 || strlen($message) > 5000) {
        echo json_encode(['success' => false, 'error' => 'Mensagem deve ter entre 10 e 5000 caracteres']);
        return;
    }

    $validCategories = ['account', 'payment', 'game', 'bug', 'other'];
    if (!in_array($category, $validCategories)) {
        $category = 'other';
    }

    $player = findPlayer($pdo, $googleUid);
    if (!$player) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        return;
    }

    // Limitar tickets abertos por usuário (máx 5)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE google_uid = ? AND status IN ('open','in_progress')");
    $stmt->execute([$googleUid]);
    if ($stmt->fetchColumn() >= 5) {
        echo json_encode(['success' => false, 'error' => 'Você já possui 5 tickets abertos. Aguarde a resolução antes de abrir novos.']);
        return;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO support_tickets (user_id, google_uid, subject, category, status, priority)
            VALUES (?, ?, ?, ?, 'open', 'normal')
        ");
        $stmt->execute([$player['id'], $googleUid, $subject, $category]);
        $ticketId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO support_messages (ticket_id, sender_type, message)
            VALUES (?, 'user', ?)
        ");
        $stmt->execute([$ticketId, $message]);

        $pdo->commit();

        secureLog("TICKET_CREATED | ID: $ticketId | User: {$player['id']} | Subject: $subject");

        echo json_encode([
            'success' => true,
            'ticket_id' => (int)$ticketId,
            'message' => 'Ticket criado com sucesso!'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao criar ticket']);
    }
}

// ============================================
// LISTAR TICKETS DO USUÁRIO
// ============================================
function listTickets($pdo, $input) {
    $identifier = getUserIdentifier($input);
    $googleUid = $identifier['value'] ?? null;
    if (!$googleUid || !validateGoogleUid($googleUid)) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT t.id, t.subject, t.category, t.status, t.priority, t.created_at, t.updated_at,
               (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.sender_type = 'admin') as admin_replies
        FROM support_tickets t
        WHERE t.google_uid = ?
        ORDER BY
            CASE t.status
                WHEN 'open' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'resolved' THEN 3
                WHEN 'closed' THEN 4
            END,
            t.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute([$googleUid]);
    $tickets = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'tickets' => $tickets
    ]);
}

// ============================================
// VER TICKET COM MENSAGENS
// ============================================
function viewTicket($pdo, $input) {
    $identifier = getUserIdentifier($input);
    $googleUid = $identifier['value'] ?? null;
    if (!$googleUid || !validateGoogleUid($googleUid)) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        return;
    }

    $ticketId = (int)($input['ticket_id'] ?? 0);
    if (!$ticketId) {
        echo json_encode(['success' => false, 'error' => 'Ticket inválido']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id, subject, category, status, priority, created_at, updated_at
        FROM support_tickets
        WHERE id = ? AND google_uid = ?
    ");
    $stmt->execute([$ticketId, $googleUid]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket não encontrado']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT sender_type, message, created_at
        FROM support_messages
        WHERE ticket_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$ticketId]);
    $messages = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'ticket' => $ticket,
        'messages' => $messages
    ]);
}

// ============================================
// RESPONDER TICKET (USUÁRIO)
// ============================================
function replyTicket($pdo, $input) {
    $identifier = getUserIdentifier($input);
    $googleUid = $identifier['value'] ?? null;
    if (!$googleUid || !validateGoogleUid($googleUid)) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
        return;
    }

    $ticketId = (int)($input['ticket_id'] ?? 0);
    $message = trim($input['message'] ?? '');

    if (!$ticketId) {
        echo json_encode(['success' => false, 'error' => 'Ticket inválido']);
        return;
    }

    if (strlen($message) < 2 || strlen($message) > 5000) {
        echo json_encode(['success' => false, 'error' => 'Mensagem deve ter entre 2 e 5000 caracteres']);
        return;
    }

    // Verificar se o ticket pertence ao usuário e está aberto
    $stmt = $pdo->prepare("
        SELECT id, status FROM support_tickets
        WHERE id = ? AND google_uid = ?
    ");
    $stmt->execute([$ticketId, $googleUid]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket não encontrado']);
        return;
    }

    if (in_array($ticket['status'], ['closed'])) {
        echo json_encode(['success' => false, 'error' => 'Este ticket está fechado']);
        return;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_type, message) VALUES (?, 'user', ?)");
        $stmt->execute([$ticketId, $message]);

        // Reabrir se estava resolvido
        if ($ticket['status'] === 'resolved') {
            $stmt = $pdo->prepare("UPDATE support_tickets SET status = 'open' WHERE id = ?");
            $stmt->execute([$ticketId]);
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Resposta enviada']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Erro ao enviar resposta']);
    }
}
