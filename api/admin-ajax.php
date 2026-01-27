<?php
// ===============================================================
// CRYPTO ASTEROID RUSH - ADMIN AJAX API
// Compatível com Railway (PHP 8 + MySQL 8)
// ===============================================================

date_default_timezone_set('America/Sao_Paulo');

if (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
}
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===============================================================
// Entrada híbrida (JSON + POST + GET)
// ===============================================================
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = array_merge($_POST, $_GET);
}

$action = $input["action"] ?? "";
$response = ["success" => false, "message" => "Ação inválida"];

// ===============================================================
// Conexão PDO (Railway compatível)
// ===============================================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Ajuste de timezone MySQL
    $pdo->exec("SET time_zone = '-03:00'");

} catch (Exception $e) {
    error_log("[ADMIN-AJAX] Falha na conexão PDO: " . $e->getMessage());
    echo json_encode(["success" => false, "error" => "Falha de conexão"]);
    exit;
}

// ===============================================================
// Processamento de ações
// ===============================================================
try {
    switch ($action) {

        // -------------------------------------------------------
        // LISTAR SAQUES
        // -------------------------------------------------------
        case "list_withdrawals":
            $stmt = $pdo->query("
                SELECT 
                    id,
                    wallet_address,
                    COALESCE(amount_usdt, amount, 0) AS amount_usdt,
                    status,
                    COALESCE(created_at, date) AS created_at
                FROM withdrawals
                ORDER BY COALESCE(created_at, date) DESC
            ");
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // -------------------------------------------------------
        // APROVAR SAQUE
        // -------------------------------------------------------
        case "approve_withdrawal":
            $id = intval($input["id"] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido");

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if ($withdrawal["status"] !== "pending") throw new Exception("Saque já processado");

            $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'approved', approved_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            $pdo->commit();
            $response = ["success" => true, "message" => "✅ Saque #$id aprovado com sucesso."];
            break;

        // -------------------------------------------------------
        // REJEITAR SAQUE
        // -------------------------------------------------------
        case "reject_withdrawal":
            $id = intval($input["id"] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido");

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if ($withdrawal["status"] !== "pending") throw new Exception("Saque já processado");

            // Devolver saldo ao jogador
            $stmt = $pdo->prepare("UPDATE players SET balance_usdt = balance_usdt + ? WHERE wallet_address = ?");
            $stmt->execute([$withdrawal["amount_usdt"], $withdrawal["wallet_address"]]);

            // Atualizar status
            $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'rejected', approved_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            // Logar transação
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (wallet_address, type, amount, description, status, created_at)
                VALUES (?, 'withdrawal_rejected', ?, 'Saque rejeitado - saldo devolvido', 'completed', NOW())
            ");
            $stmt->execute([$withdrawal["wallet_address"], $withdrawal["amount_usdt"]]);

            $pdo->commit();
            $response = ["success" => true, "message" => "❌ Saque #$id rejeitado e saldo devolvido."];
            break;

        // -------------------------------------------------------
        // ESTATÍSTICAS ADMIN
        // -------------------------------------------------------
        case "get_stats":
            $stats = [];
            $stats["total_players"] = $pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
            $stats["total_withdrawals"] = $pdo->query("SELECT COUNT(*) FROM withdrawals")->fetchColumn();
            $stats["pending_withdrawals"] = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
            $stats["total_balance"] = $pdo->query("SELECT SUM(balance_usdt) FROM players")->fetchColumn();
            $response = ["success" => true, "stats" => $stats];
            break;

        // -------------------------------------------------------
        // LISTAR TRANSACOES
        // -------------------------------------------------------
        case "list_transactions":
            $stmt = $pdo->query("
                SELECT 
                    id,
                    wallet_address,
                    type,
                    COALESCE(amount_usdt, amount, 0) AS amount_usdt,
                    status,
                    COALESCE(created_at, date) AS created_at
                FROM transactions
                ORDER BY COALESCE(created_at, date) DESC
                LIMIT 200
            ");
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // -------------------------------------------------------
        // SEGURANÇA ADMIN - CHECAGEM
        // -------------------------------------------------------
        case "security_check":
            require_once __DIR__ . "/admin-security.php";
            break;

        default:
            $response = ["success" => false, "message" => "Ação não reconhecida"];
            break;
    }

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("[ADMIN-AJAX] " . $e->getMessage());
    $response = ["success" => false, "error" => $e->getMessage()];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
