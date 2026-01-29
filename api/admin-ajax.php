<?php
// ===============================================================
// UNOBIX - ADMIN AJAX API
// v4.0 - Suporte a BRL, Google UID, novos métodos de pagamento
// ===============================================================

date_default_timezone_set('America/Sao_Paulo');

if (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
} elseif (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
}

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$input = getRequestInput();
$action = $input["action"] ?? "";
$response = ["success" => false, "message" => "Ação inválida"];

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    switch ($action) {

        // -------------------------------------------------------
        // LISTAR SAQUES
        // -------------------------------------------------------
        case "list_withdrawals":
            $status = $input['status'] ?? 'all';
            
            $sql = "
                SELECT 
                    w.id,
                    w.google_uid,
                    w.wallet_address,
                    COALESCE(w.amount_brl, w.amount_usdt) AS amount,
                    w.amount_brl,
                    w.amount_usdt,
                    w.payment_method,
                    w.payment_details,
                    w.status,
                    w.created_at,
                    w.approved_at,
                    p.email,
                    p.display_name
                FROM withdrawals w
                LEFT JOIN players p ON (
                    (w.google_uid IS NOT NULL AND p.google_uid = w.google_uid) OR
                    (w.google_uid IS NULL AND p.wallet_address = w.wallet_address)
                )
            ";
            
            $params = [];
            if ($status !== 'all') {
                $sql .= " WHERE w.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY w.created_at DESC LIMIT 200";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // -------------------------------------------------------
        // APROVAR SAQUE
        // -------------------------------------------------------
        case "approve_withdrawal":
            $id = intval($input["id"] ?? 0);
            $txHash = $input["tx_hash"] ?? null;
            
            if ($id <= 0) throw new Exception("ID inválido");

            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if ($withdrawal["status"] !== "pending") throw new Exception("Saque já processado");

            // Atualizar status
            $stmt = $pdo->prepare("
                UPDATE withdrawals 
                SET status = 'approved', approved_at = NOW(), tx_hash = ?
                WHERE id = ?
            ");
            $stmt->execute([$txHash, $id]);

            // Atualizar total_withdrawn do jogador
            $amount = $withdrawal['amount_brl'] ?? $withdrawal['amount_usdt'] ?? 0;
            $identifier = $withdrawal['google_uid'] ?? $withdrawal['wallet_address'];
            $isGoogle = !empty($withdrawal['google_uid']);
            
            if ($withdrawal['amount_brl']) {
                $sql = "UPDATE players SET total_withdrawn_brl = total_withdrawn_brl + ? WHERE " 
                     . ($isGoogle ? "google_uid = ?" : "wallet_address = ?");
            } else {
                $sql = "UPDATE players SET total_withdrawn = total_withdrawn + ? WHERE "
                     . ($isGoogle ? "google_uid = ?" : "wallet_address = ?");
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$amount, $identifier]);

            // Logar transação
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (wallet_address, google_uid, type, amount, amount_brl, description, status, created_at)
                VALUES (?, ?, 'withdrawal_approved', ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $withdrawal['wallet_address'],
                $withdrawal['google_uid'],
                $withdrawal['amount_usdt'] ?? 0,
                $withdrawal['amount_brl'],
                "Saque #{$id} aprovado via " . ($withdrawal['payment_method'] ?? 'N/A')
            ]);

            $pdo->commit();
            $response = ["success" => true, "message" => "✅ Saque #{$id} aprovado com sucesso!"];
            break;

        // -------------------------------------------------------
        // REJEITAR SAQUE
        // -------------------------------------------------------
        case "reject_withdrawal":
            $id = intval($input["id"] ?? 0);
            $reason = $input["reason"] ?? "Rejeitado pelo administrador";
            
            if ($id <= 0) throw new Exception("ID inválido");

            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if ($withdrawal["status"] !== "pending") throw new Exception("Saque já processado");

            // Devolver saldo ao jogador
            $identifier = $withdrawal['google_uid'] ?? $withdrawal['wallet_address'];
            $isGoogle = !empty($withdrawal['google_uid']);
            
            if ($withdrawal['amount_brl']) {
                $sql = "UPDATE players SET balance_brl = balance_brl + ? WHERE "
                     . ($isGoogle ? "google_uid = ?" : "wallet_address = ?");
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$withdrawal['amount_brl'], $identifier]);
            } else {
                $sql = "UPDATE players SET balance_usdt = balance_usdt + ? WHERE "
                     . ($isGoogle ? "google_uid = ?" : "wallet_address = ?");
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$withdrawal['amount_usdt'], $identifier]);
            }

            // Atualizar status
            $stmt = $pdo->prepare("
                UPDATE withdrawals 
                SET status = 'rejected', approved_at = NOW(), notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $id]);

            // Logar transação
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (wallet_address, google_uid, type, amount, amount_brl, description, status, created_at)
                VALUES (?, ?, 'withdrawal_rejected', ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $withdrawal['wallet_address'],
                $withdrawal['google_uid'],
                $withdrawal['amount_usdt'] ?? 0,
                $withdrawal['amount_brl'],
                "Saque #{$id} rejeitado - saldo devolvido. Motivo: {$reason}"
            ]);

            $pdo->commit();
            $response = ["success" => true, "message" => "❌ Saque #{$id} rejeitado e saldo devolvido."];
            break;

        // -------------------------------------------------------
        // ESTATÍSTICAS ADMIN
        // -------------------------------------------------------
        case "get_stats":
            $stats = [];
            
            // Total de jogadores
            $stats["total_players"] = $pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
            
            // Jogadores com Google Auth
            $stats["google_players"] = $pdo->query("SELECT COUNT(*) FROM players WHERE google_uid IS NOT NULL")->fetchColumn();
            
            // Total de saques
            $stats["total_withdrawals"] = $pdo->query("SELECT COUNT(*) FROM withdrawals")->fetchColumn();
            $stats["pending_withdrawals"] = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
            
            // Valores em BRL
            $stats["total_balance_brl"] = $pdo->query("SELECT COALESCE(SUM(balance_brl), 0) FROM players")->fetchColumn();
            $stats["total_staked_brl"] = $pdo->query("SELECT COALESCE(SUM(staked_balance_brl), 0) FROM players")->fetchColumn();
            $stats["total_withdrawn_brl"] = $pdo->query("SELECT COALESCE(SUM(amount_brl), 0) FROM withdrawals WHERE status = 'approved'")->fetchColumn();
            
            // Sessões de jogo
            $stats["total_sessions"] = $pdo->query("SELECT COUNT(*) FROM game_sessions")->fetchColumn();
            $stats["sessions_today"] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            
            // Sessões hard mode
            $stats["hard_mode_sessions"] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE is_hard_mode = 1")->fetchColumn();
            
            // CAPTCHA stats
            $stats["captcha_success"] = $pdo->query("SELECT COUNT(*) FROM captcha_log WHERE is_success = 1")->fetchColumn();
            $stats["captcha_failed"] = $pdo->query("SELECT COUNT(*) FROM captcha_log WHERE is_success = 0")->fetchColumn();
            
            $response = ["success" => true, "stats" => $stats];
            break;

        // -------------------------------------------------------
        // LISTAR TRANSAÇÕES
        // -------------------------------------------------------
        case "list_transactions":
            $stmt = $pdo->query("
                SELECT 
                    t.id,
                    t.wallet_address,
                    t.google_uid,
                    t.type,
                    COALESCE(t.amount_brl, t.amount) AS amount,
                    t.amount_brl,
                    t.description,
                    t.status,
                    t.created_at,
                    p.email,
                    p.display_name
                FROM transactions t
                LEFT JOIN players p ON (
                    (t.google_uid IS NOT NULL AND p.google_uid = t.google_uid) OR
                    (t.google_uid IS NULL AND p.wallet_address = t.wallet_address)
                )
                ORDER BY t.created_at DESC
                LIMIT 200
            ");
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // -------------------------------------------------------
        // LISTAR JOGADORES
        // -------------------------------------------------------
        case "list_players":
            $stmt = $pdo->query("
                SELECT 
                    id, google_uid, email, display_name, wallet_address,
                    balance_brl, balance_usdt, staked_balance_brl,
                    total_earned_brl, total_withdrawn_brl, total_played,
                    is_banned, ban_reason, created_at, updated_at
                FROM players
                ORDER BY created_at DESC
                LIMIT 200
            ");
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // -------------------------------------------------------
        // BUSCAR JOGADOR
        // -------------------------------------------------------
        case "search_player":
            $query = trim($input['query'] ?? '');
            
            if (strlen($query) < 3) {
                throw new Exception("Busca deve ter pelo menos 3 caracteres");
            }
            
            $stmt = $pdo->prepare("
                SELECT * FROM players 
                WHERE google_uid LIKE ? 
                OR email LIKE ? 
                OR display_name LIKE ?
                OR wallet_address LIKE ?
                LIMIT 20
            ");
            $searchTerm = "%{$query}%";
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // -------------------------------------------------------
        // BANIR JOGADOR
        // -------------------------------------------------------
        case "ban_player":
            $playerId = intval($input['player_id'] ?? 0);
            $googleUid = $input['google_uid'] ?? null;
            $reason = $input['reason'] ?? 'Banido pelo administrador';
            
            if ($playerId <= 0 && !$googleUid) {
                throw new Exception("player_id ou google_uid é obrigatório");
            }
            
            if ($googleUid) {
                $stmt = $pdo->prepare("UPDATE players SET is_banned = 1, ban_reason = ? WHERE google_uid = ?");
                $stmt->execute([$reason, $googleUid]);
            } else {
                $stmt = $pdo->prepare("UPDATE players SET is_banned = 1, ban_reason = ? WHERE id = ?");
                $stmt->execute([$reason, $playerId]);
            }
            
            $response = ["success" => true, "message" => "Jogador banido com sucesso"];
            break;

        // -------------------------------------------------------
        // DESBANIR JOGADOR
        // -------------------------------------------------------
        case "unban_player":
            $playerId = intval($input['player_id'] ?? 0);
            $googleUid = $input['google_uid'] ?? null;
            
            if ($playerId <= 0 && !$googleUid) {
                throw new Exception("player_id ou google_uid é obrigatório");
            }
            
            if ($googleUid) {
                $stmt = $pdo->prepare("UPDATE players SET is_banned = 0, ban_reason = NULL WHERE google_uid = ?");
                $stmt->execute([$googleUid]);
            } else {
                $stmt = $pdo->prepare("UPDATE players SET is_banned = 0, ban_reason = NULL WHERE id = ?");
                $stmt->execute([$playerId]);
            }
            
            $response = ["success" => true, "message" => "Jogador desbanido com sucesso"];
            break;

        // -------------------------------------------------------
        // EXECUTAR LIMPEZA
        // -------------------------------------------------------
        case "cleanup":
            $pdo->exec("CALL sp_cleanup_old_data()");
            $response = ["success" => true, "message" => "Limpeza executada com sucesso"];
            break;

        // -------------------------------------------------------
        // CONFIGURAÇÕES DO SISTEMA
        // -------------------------------------------------------
        case "get_config":
            $stmt = $pdo->query("SELECT config_key, config_value, description, is_public FROM system_config ORDER BY config_key");
            $configs = [];
            while ($row = $stmt->fetch()) {
                $configs[$row['config_key']] = [
                    'value' => json_decode($row['config_value'], true),
                    'description' => $row['description'],
                    'is_public' => (bool)$row['is_public']
                ];
            }
            $response = ["success" => true, "config" => $configs];
            break;

        default:
            $response = ["success" => false, "message" => "Ação não reconhecida: " . htmlspecialchars($action)];
            break;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[ADMIN-AJAX] " . $e->getMessage());
    $response = ["success" => false, "error" => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
