<?php
// ===============================================================
// UNOBIX - ADMIN AJAX API
// v6.0 - Tabela users, google_uid, BRL, game_settings
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

// ===============================================================
// AUTENTICAÇÃO — verificar sessão admin
// ===============================================================
session_start();
// Restaurar sessão via cookie se perdida (Cloud Run stateless)
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    require_once __DIR__ . '/../admin/includes/admin-auth.php';
    adminRestoreSession();
}
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autorizado. Faça login no painel admin.']);
    exit;
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
        // Tabela: withdrawals (doc 3.4)
        // JOIN: users via user_id
        // -------------------------------------------------------
        case "list_withdrawals":
            $status = $input['status'] ?? 'all';
            
            $sql = "
                SELECT 
                    w.id,
                    w.user_id,
                    w.amount_brl,
                    w.amount_usdt,
                    w.wallet_address,
                    w.status,
                    w.transaction_hash,
                    w.admin_notes,
                    w.created_at,
                    w.processed_at,
                    u.google_uid,
                    u.email,
                    u.display_name
                FROM withdrawals w
                LEFT JOIN users u ON w.user_id = u.id
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
        // APROVAR SAQUE (manual - FaucetPay/USDT)
        // Tabela: withdrawals (doc 3.4) + users (doc 3.1)
        // -------------------------------------------------------
        case "approve_withdrawal":
            $id = intval($input["id"] ?? 0);
            $txHash = $input["transaction_hash"] ?? $input["tx_hash"] ?? null;

            if ($id <= 0) throw new Exception("ID inválido");

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if (!in_array($withdrawal["status"], ["pending", "under_review"])) throw new Exception("Saque já processado");

            // Atualizar status para completed (doc: status de saque)
            $stmt = $pdo->prepare("
                UPDATE withdrawals
                SET status = 'completed', processed_at = NOW(), transaction_hash = ?
                WHERE id = ?
            ");
            $stmt->execute([$txHash, $id]);

            // Atualizar total_withdrawn_brl do jogador
            $amount = $withdrawal['amount_brl'] ?? 0;
            $userId = $withdrawal['user_id'];

            $stmt = $pdo->prepare("
                UPDATE users SET total_withdrawn_brl = total_withdrawn_brl + ? WHERE id = ?
            ");
            $stmt->execute([$amount, $userId]);

            // Logar transação
            // Buscar google_uid do user para a transação
            $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            // Atualizar transação existente no histórico (pending → completed)
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'completed'
                WHERE google_uid = ? AND type = 'withdraw' AND description LIKE ? AND status = 'pending'
                LIMIT 1
            ");
            $updated = $stmt->execute([$user['google_uid'] ?? null, "%#{$id}%"]);

            // Se não encontrou transação existente, criar nova
            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO transactions
                    (google_uid, type, amount_brl, description, status, created_at)
                    VALUES (?, 'withdraw', ?, ?, 'completed', NOW())
                ");
                $stmt->execute([
                    $user['google_uid'] ?? null,
                    -abs($amount),
                    "Saque #{$id} aprovado"
                ]);
            }

            $pdo->commit();
            $response = ["success" => true, "message" => "✅ Saque #{$id} aprovado com sucesso!"];
            break;

        // -------------------------------------------------------
        // CONFIRMAR MANUALMENTE SAQUE TRAVADO EM PROCESSAMENTO
        // Admin confirma → muda de 'processing' para 'completed'
        // -------------------------------------------------------
        case "force_complete_withdrawal":
            $id = intval($input["id"] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido");

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if ($withdrawal["status"] !== "processing") throw new Exception("Saque não está em processamento (status: " . $withdrawal["status"] . ")");

            $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'completed', processed_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            // Atualizar total_withdrawn_brl
            $amount = $withdrawal['amount_brl'] ?? 0;
            $userId = $withdrawal['user_id'];
            $pdo->prepare("UPDATE users SET total_withdrawn_brl = total_withdrawn_brl + ? WHERE id = ?")->execute([$amount, $userId]);

            // Atualizar transação no histórico
            $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE google_uid = ? AND type = 'withdraw' AND description LIKE ? AND status = 'pending' LIMIT 1")
                ->execute([$user['google_uid'] ?? null, "%#{$id}%"]);

            $pdo->commit();
            $response = ["success" => true, "message" => "✅ Saque #{$id} confirmado manualmente como pago!"];
            break;

        // -------------------------------------------------------
        // APROVAR SAQUE VIA ZETTPAY (PIX automático)
        // Admin aprova → sistema processa via API ZettPay
        // -------------------------------------------------------
        case "approve_withdrawal_zettpay":
            require_once __DIR__ . "/zettpay-client.php";

            $id = intval($input["id"] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido");

            // Garantir tabelas ANTES da transação (DDL causa commit implícito no MySQL)
            ensureZettpayTable($pdo);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if (!in_array($withdrawal["status"], ["pending", "under_review"])) throw new Exception("Saque já processado");

            // Extrair dados PIX do admin_notes
            $notes = json_decode($withdrawal['admin_notes'] ?? '{}', true);
            $pixKey = $notes['details'] ?? '';
            $pixKeyType = $notes['pix_key_type'] ?? 'cpf';
            $paymentMethod = $notes['method'] ?? '';

            if (empty($pixKey)) throw new Exception("Chave PIX não encontrada nos dados do saque");

            // Mapear tipo de chave para formato ZettPay
            $keyTypeMap = [
                'cpf' => 'document',
                'cnpj' => 'document',
                'document' => 'document',
                'email' => 'email',
                'phone' => 'phone',
                'celular' => 'phone',
                'aleatoria' => 'evp',
                'evp' => 'evp'
            ];
            $zettpayKeyType = $keyTypeMap[strtolower($pixKeyType)] ?? 'document';

            $amount = (float)$withdrawal['amount_brl'];
            $userId = $withdrawal['user_id'];

            // Gerar external_id
            $externalId = zettpayWithdrawExternalId($id);

            // Chamar API ZettPay
            $result = zettpayCreateCashout(
                $amount,
                $pixKey,
                $zettpayKeyType,
                $externalId,
                ['user_id' => (string)$userId, 'withdrawal_id' => (string)$id]
            );

            if (!$result['success']) {
                $pdo->rollBack();
                $errorDetail = $result['error'] ?? 'Falha na API';
                $apiResponse = json_encode($result['data'] ?? null);
                secureLog("ADMIN_ZETTPAY_CASHOUT_ERROR | withdrawal_id: {$id} | error: {$errorDetail} | response: {$apiResponse}");
                throw new Exception("Erro ZettPay: {$errorDetail} | Resposta: {$apiResponse}");
            }

            $apiData = $result['data']['data'] ?? $result['data'] ?? [];

            // Atualizar withdrawal para processing
            $stmt = $pdo->prepare("
                UPDATE withdrawals
                SET status = 'processing',
                    zettpay_external_id = ?,
                    zettpay_status = 'processing'
                WHERE id = ?
            ");
            $stmt->execute([$externalId, $id]);

            // Registrar na tabela zettpay_transactions
            $stmt = $pdo->prepare("
                INSERT INTO zettpay_transactions (
                    user_id, external_id, zettpay_id, type, amount_brl, fee_brl,
                    status, pix_key, pix_key_type, withdrawal_id, created_at
                ) VALUES (?, ?, ?, 'cashout', ?, ?, 'processing', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $externalId,
                $apiData['provider_transaction_id'] ?? $apiData['id'] ?? null,
                $amount,
                0,
                $pixKey,
                $zettpayKeyType,
                $id
            ]);

            $pdo->commit();

            secureLog("ADMIN_ZETTPAY_CASHOUT | withdrawal_id: {$id} | external_id: {$externalId} | amount: R\${$amount} | key_type: {$zettpayKeyType}");

            $response = [
                "success" => true,
                "message" => "✅ Saque #{$id} enviado para processamento PIX via ZettPay. Aguarde confirmação automática.",
                "external_id" => $externalId
            ];
            break;

        // -------------------------------------------------------
        // VERIFICAR STATUS DO CASHOUT NA ZETTPAY
        // Consulta /pix/cashouts/lookup e atualiza localmente
        // -------------------------------------------------------
        case "check_cashout_status":
            require_once __DIR__ . "/zettpay-client.php";

            $id = intval($input["id"] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido");

            $stmt = $pdo->prepare("SELECT w.*, zt.external_id as zt_external_id, zt.id as zt_id, zt.status as zt_status
                FROM withdrawals w
                LEFT JOIN zettpay_transactions zt ON zt.withdrawal_id = w.id AND zt.type = 'cashout'
                WHERE w.id = ?");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if (empty($withdrawal['zt_external_id'])) throw new Exception("Saque não possui external_id ZettPay");

            // Consultar status na ZettPay
            $lookupResult = zettpayLookupCashout($withdrawal['zt_external_id']);

            if (!$lookupResult['success']) {
                throw new Exception("Erro ao consultar ZettPay: " . ($lookupResult['error'] ?? 'Falha na conexão'));
            }

            $apiData = $lookupResult['data']['data'] ?? $lookupResult['data'] ?? [];
            $apiStatus = strtoupper($apiData['status'] ?? '');
            $providerTxId = $apiData['provider_transaction_id'] ?? '';

            secureLog("ADMIN_CHECK_CASHOUT | withdrawal_id: {$id} | external_id: {$withdrawal['zt_external_id']} | api_status: {$apiStatus}");

            // Se aprovado na ZettPay, confirmar localmente
            if ($apiStatus === 'APPROVED' || $apiStatus === 'COMPLETED') {
                $pdo->beginTransaction();
                try {
                    // Atualizar zettpay_transactions
                    $stmt = $pdo->prepare("
                        UPDATE zettpay_transactions
                        SET status = 'confirmed', zettpay_id = ?, confirmed_at = NOW()
                        WHERE id = ? AND status != 'confirmed'
                    ");
                    $stmt->execute([$providerTxId, $withdrawal['zt_id']]);

                    // Atualizar withdrawal
                    $stmt = $pdo->prepare("
                        UPDATE withdrawals
                        SET status = 'completed', processed_at = NOW(), zettpay_status = 'confirmed'
                        WHERE id = ? AND status = 'processing'
                    ");
                    $stmt->execute([$id]);

                    // Atualizar transação no histórico do usuário (pending → completed)
                    $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
                    $stmt->execute([$withdrawal['user_id']]);
                    $user = $stmt->fetch();

                    if ($user) {
                        $stmt = $pdo->prepare("
                            UPDATE transactions
                            SET status = 'completed'
                            WHERE google_uid = ? AND type = 'withdraw' AND description LIKE ? AND status IN ('pending', 'processing')
                            LIMIT 1
                        ");
                        $stmt->execute([$user['google_uid'], "%#{$id}%"]);
                    }

                    $pdo->commit();
                    $response = [
                        "success" => true,
                        "message" => "✅ Saque #{$id} confirmado! Status ZettPay: {$apiStatus}",
                        "status" => "completed",
                        "api_status" => $apiStatus
                    ];
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            } elseif ($apiStatus === 'FAILED' || $apiStatus === 'REJECTED') {
                // Falhou na ZettPay - devolver saldo
                $pdo->beginTransaction();
                try {
                    $amount = (float)$withdrawal['amount_brl'];
                    $userId = $withdrawal['user_id'];

                    $stmt = $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$amount, $userId]);

                    $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'rejected', processed_at = NOW(), zettpay_status = 'failed' WHERE id = ?");
                    $stmt->execute([$id]);

                    $stmt = $pdo->prepare("UPDATE zettpay_transactions SET status = 'failed', error_message = ? WHERE id = ?");
                    $stmt->execute(["ZettPay status: {$apiStatus}", $withdrawal['zt_id']]);

                    // Atualizar transação no histórico do usuário
                    $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();

                    if ($user) {
                        $stmt = $pdo->prepare("
                            UPDATE transactions
                            SET status = 'failed'
                            WHERE google_uid = ? AND type = 'withdraw' AND description LIKE ? AND status IN ('pending', 'processing')
                            LIMIT 1
                        ");
                        $stmt->execute([$user['google_uid'], "%#{$id}%"]);
                    }

                    $pdo->commit();
                    $response = [
                        "success" => true,
                        "message" => "⚠️ Saque #{$id} falhou na ZettPay ({$apiStatus}). Saldo devolvido ao jogador.",
                        "status" => "rejected",
                        "api_status" => $apiStatus
                    ];
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            } else {
                // Ainda processando
                $response = [
                    "success" => true,
                    "message" => "⏳ Saque #{$id} ainda em processamento na ZettPay. Status: {$apiStatus}",
                    "status" => "processing",
                    "api_status" => $apiStatus
                ];
            }
            break;

        // -------------------------------------------------------
        // REJEITAR SAQUE
        // Tabela: withdrawals + users
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
            if (!in_array($withdrawal["status"], ["pending", "under_review"])) throw new Exception("Saque já processado");

            // Devolver saldo ao jogador
            $userId = $withdrawal['user_id'];
            $amount = $withdrawal['amount_brl'] ?? 0;
            
            $stmt = $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE id = ?");
            $stmt->execute([$amount, $userId]);

            // Atualizar status para rejected
            $stmt = $pdo->prepare("
                UPDATE withdrawals 
                SET status = 'rejected', processed_at = NOW(), admin_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $id]);

            // Logar transação de estorno
            $stmt = $pdo->prepare("SELECT google_uid FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (google_uid, type, amount_brl, description, status, created_at)
                VALUES (?, 'withdraw_reject', ?, ?, 'completed', NOW())
            ");
            $stmt->execute([
                $user['google_uid'] ?? null,
                $amount,
                "Saque #{$id} rejeitado - saldo devolvido. Motivo: {$reason}"
            ]);

            $pdo->commit();
            $response = ["success" => true, "message" => "❌ Saque #{$id} rejeitado e saldo devolvido."];
            break;

        // -------------------------------------------------------
        // ESTATÍSTICAS ADMIN
        // Tabelas: users, withdrawals, game_sessions, staking
        // -------------------------------------------------------
        case "get_stats":
            $stats = [];
            
            // Total de usuários
            $stats["total_players"] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
            // Usuários com Google Auth
            $stats["google_players"] = $pdo->query("SELECT COUNT(*) FROM users WHERE google_uid IS NOT NULL")->fetchColumn();
            
            // Total de saques
            $stats["total_withdrawals"] = $pdo->query("SELECT COUNT(*) FROM withdrawals")->fetchColumn();
            $stats["pending_withdrawals"] = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
            
            // Valores em BRL
            $stats["total_balance_brl"] = $pdo->query("SELECT COALESCE(SUM(balance_brl), 0) FROM users")->fetchColumn();
            $stats["total_staked_brl"] = $pdo->query("SELECT COALESCE(SUM(staked_balance_brl), 0) FROM users")->fetchColumn();
            $stats["total_withdrawn_brl"] = $pdo->query("SELECT COALESCE(SUM(amount_brl), 0) FROM withdrawals WHERE status = 'completed'")->fetchColumn();
            
            // Sessões de jogo
            $stats["total_sessions"] = $pdo->query("SELECT COUNT(*) FROM game_sessions")->fetchColumn();
            $stats["sessions_today"] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            
            // Sessões hard mode
            $stats["hard_mode_sessions"] = $pdo->query("SELECT COUNT(*) FROM game_sessions WHERE is_hard_mode = 1")->fetchColumn();
            
            $response = ["success" => true, "stats" => $stats];
            break;

        // -------------------------------------------------------
        // LISTAR TRANSAÇÕES
        // Tabela: transactions + users
        // -------------------------------------------------------
        case "list_transactions":
            $stmt = $pdo->query("
                SELECT 
                    t.id,
                    t.google_uid,
                    t.type,
                    t.amount_brl,
                    t.description,
                    t.status,
                    t.created_at,
                    u.email,
                    u.display_name
                FROM transactions t
                LEFT JOIN users u ON t.google_uid = u.google_uid
                ORDER BY t.created_at DESC
                LIMIT 200
            ");
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // -------------------------------------------------------
        // LISTAR JOGADORES
        // Tabela: users (doc 3.1)
        // -------------------------------------------------------
        case "list_players":
            $stmt = $pdo->query("
                SELECT 
                    id, google_uid, email, display_name,
                    balance_brl, staked_balance_brl,
                    total_earned_brl, total_withdrawn_brl, total_played,
                    is_banned, ban_reason, created_at, last_login, updated_at
                FROM users
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
                SELECT * FROM users 
                WHERE google_uid LIKE ? 
                OR email LIKE ? 
                OR display_name LIKE ?
                LIMIT 20
            ");
            $searchTerm = "%{$query}%";
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            
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
                $stmt = $pdo->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE google_uid = ?");
                $stmt->execute([$reason, $googleUid]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET is_banned = 1, ban_reason = ? WHERE id = ?");
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
                $stmt = $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE google_uid = ?");
                $stmt->execute([$googleUid]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = ?");
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
        // Tabela: game_settings (doc 3.9)
        // -------------------------------------------------------
        case "get_config":
            $stmt = $pdo->query("SELECT setting_key, setting_value, description, is_public FROM game_settings ORDER BY setting_key");
            $configs = [];
            while ($row = $stmt->fetch()) {
                $configs[$row['setting_key']] = [
                    'value' => json_decode($row['setting_value'], true) ?? $row['setting_value'],
                    'description' => $row['description'],
                    'is_public' => (bool)$row['is_public']
                ];
            }
            $response = ["success" => true, "config" => $configs];
            break;

        // -------------------------------------------------------
        // LISTAR PACOTES DE CRÉDITOS
        // -------------------------------------------------------
        case "list_credit_packages":
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS credit_packages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    credits INT NOT NULL,
                    bonus_credits INT NOT NULL DEFAULT 0,
                    price_brl DECIMAL(10,2) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    is_featured TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $stmt = $pdo->query("SELECT * FROM credit_packages ORDER BY credits ASC");
            $response = ["success" => true, "data" => $stmt->fetchAll()];
            break;

        // -------------------------------------------------------
        // CRIAR/EDITAR PACOTE DE CRÉDITOS
        // -------------------------------------------------------
        case "save_credit_package":
            $pkgId = intval($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $credits = intval($input['credits'] ?? 0);
            $bonusCredits = intval($input['bonus_credits'] ?? 0);
            $priceBrl = round((float)($input['price_brl'] ?? 0), 2);
            $isActive = intval($input['is_active'] ?? 1);
            $isFeatured = intval($input['is_featured'] ?? 0);

            if (empty($name)) throw new Exception("Nome é obrigatório");
            if ($credits <= 0) throw new Exception("Créditos deve ser maior que 0");
            if ($priceBrl <= 0) throw new Exception("Preço deve ser maior que 0");

            if ($pkgId > 0) {
                // Editar
                $stmt = $pdo->prepare("
                    UPDATE credit_packages
                    SET name = ?, credits = ?, bonus_credits = ?, price_brl = ?,
                        is_active = ?, is_featured = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $credits, $bonusCredits, $priceBrl, $isActive, $isFeatured, $pkgId]);
                $response = ["success" => true, "message" => "Pacote #{$pkgId} atualizado!"];
            } else {
                // Criar
                $stmt = $pdo->prepare("
                    INSERT INTO credit_packages (name, credits, bonus_credits, price_brl, is_active, is_featured)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $credits, $bonusCredits, $priceBrl, $isActive, $isFeatured]);
                $newId = $pdo->lastInsertId();
                $response = ["success" => true, "message" => "Pacote #{$newId} criado!", "id" => (int)$newId];
            }
            break;

        // -------------------------------------------------------
        // EXCLUIR PACOTE DE CRÉDITOS
        // -------------------------------------------------------
        case "delete_credit_package":
            $pkgId = intval($input['id'] ?? 0);
            if ($pkgId <= 0) throw new Exception("ID inválido");

            $stmt = $pdo->prepare("DELETE FROM credit_packages WHERE id = ?");
            $stmt->execute([$pkgId]);
            $response = ["success" => true, "message" => "Pacote excluído!"];
            break;

        // -------------------------------------------------------
        // ADICIONAR CRÉDITOS MANUALMENTE A UM JOGADOR
        // -------------------------------------------------------
        case "bulk_distribute":
            $type = trim($input['type'] ?? '');
            $amount = floatval($input['amount'] ?? 0);
            $reason = trim($input['reason'] ?? 'Distribuição em massa pelo admin');

            if (!in_array($type, ['credits', 'balance'])) throw new Exception("Tipo inválido. Use 'credits' ou 'balance'.");
            if ($amount <= 0) throw new Exception("Quantidade deve ser maior que 0.");

            $pdo->beginTransaction();

            if ($type === 'credits') {
                $creditsInt = intval($amount);
                if ($creditsInt <= 0) throw new Exception("Créditos devem ser um número inteiro maior que 0.");

                $stmt = $pdo->prepare("UPDATE users SET credits = credits + ?");
                $stmt->execute([$creditsInt]);
                $affected = $stmt->rowCount();

                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
                    SELECT google_uid, 'admin_adjust', 0, ?, 'completed', NOW() FROM users")
                    ->execute(["Distribuição em massa: +{$creditsInt} crédito(s) - {$reason}"]);

                $pdo->commit();
                $response = ["success" => true, "message" => "{$creditsInt} crédito(s) adicionado(s) para {$affected} jogadores."];
            } else {
                if ($amount > 100) throw new Exception("Limite de R\$ 100,00 por distribuição de saldo. Ajuste o valor.");

                $stmt = $pdo->prepare("UPDATE users SET balance_brl = balance_brl + ?");
                $stmt->execute([$amount]);
                $affected = $stmt->rowCount();

                $formatted = number_format($amount, 2, ',', '.');
                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
                    SELECT google_uid, 'admin_adjust', ?, ?, 'completed', NOW() FROM users")
                    ->execute([$amount, "Distribuição em massa: +R\$ {$formatted} bônus - {$reason}"]);

                $pdo->commit();
                $response = ["success" => true, "message" => "R\$ {$formatted} adicionado(s) para {$affected} jogadores."];
            }
            break;

        // -------------------------------------------------------
        // REMOVER CRÉDITOS/SALDO DE TODOS OS JOGADORES
        // -------------------------------------------------------
        case "bulk_remove":
            $type = trim($input['type'] ?? '');
            $amount = floatval($input['amount'] ?? 0);
            $reason = trim($input['reason'] ?? 'Remoção em massa pelo admin');

            if (!in_array($type, ['credits', 'balance'])) throw new Exception("Tipo inválido. Use 'credits' ou 'balance'.");
            if ($amount <= 0) throw new Exception("Quantidade deve ser maior que 0.");

            $pdo->beginTransaction();

            if ($type === 'credits') {
                $creditsInt = intval($amount);
                if ($creditsInt <= 0) throw new Exception("Créditos devem ser um número inteiro maior que 0.");

                // Só afeta quem tem créditos > 0, e não deixa ficar negativo
                $stmt = $pdo->prepare("UPDATE users SET credits = GREATEST(credits - ?, 0) WHERE credits > 0");
                $stmt->execute([$creditsInt]);
                $affected = $stmt->rowCount();

                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
                    SELECT google_uid, 'admin_adjust', 0, ?, 'completed', NOW() FROM users WHERE credits >= 0")
                    ->execute(["Remoção em massa: -{$creditsInt} crédito(s) - {$reason}"]);

                $pdo->commit();
                $response = ["success" => true, "message" => "{$creditsInt} crédito(s) removido(s) de {$affected} jogadores."];
            } else {
                if ($amount > 100) throw new Exception("Limite de R\$ 100,00 por remoção de saldo. Ajuste o valor.");

                // Só afeta quem tem saldo > 0, e não deixa ficar negativo
                $stmt = $pdo->prepare("UPDATE users SET balance_brl = GREATEST(balance_brl - ?, 0) WHERE balance_brl > 0");
                $stmt->execute([$amount]);
                $affected = $stmt->rowCount();

                $formatted = number_format($amount, 2, ',', '.');
                $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
                    SELECT google_uid, 'admin_adjust', ?, ?, 'completed', NOW() FROM users WHERE balance_brl >= 0")
                    ->execute([-$amount, "Remoção em massa: -R\$ {$formatted} - {$reason}"]);

                $pdo->commit();
                $response = ["success" => true, "message" => "R\$ {$formatted} removido(s) de {$affected} jogadores."];
            }
            break;

        // -------------------------------------------------------
        // RESETAR CONTAGEM DE SALDO/CRÉDITOS NO DASHBOARD
        // -------------------------------------------------------
        case "reset_balance_credits_offset":
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES ('stats_reference_date', ?, 0, NOW())
                ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                ->execute([$now, $now]);

            secureLog("RESET_STATS_REFERENCE_DATE | date: {$now}");
            $response = ["success" => true, "message" => "Contagem zerada a partir de {$now}"];
            break;

        case "add_credits":
            $targetUid = trim($input['google_uid'] ?? '');
            $creditsToAdd = intval($input['credits'] ?? 0);
            $reason = trim($input['reason'] ?? 'Adicionado pelo admin');

            if (empty($targetUid)) throw new Exception("google_uid é obrigatório");
            if ($creditsToAdd <= 0) throw new Exception("Quantidade de créditos deve ser maior que 0");

            $stmt = $pdo->prepare("UPDATE users SET credits = credits + ? WHERE google_uid = ?");
            $stmt->execute([$creditsToAdd, $targetUid]);

            if ($stmt->rowCount() === 0) throw new Exception("Jogador não encontrado");

            $pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at) VALUES (?, 'admin_adjust', 0, ?, 'completed', NOW())")
                ->execute([$targetUid, "Admin adicionou {$creditsToAdd} crédito(s): {$reason}"]);

            $response = ["success" => true, "message" => "✅ {$creditsToAdd} crédito(s) adicionado(s)!"];
            break;

        case "send_to_review":
            $id = intval($input["id"] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido");

            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ?");
            $stmt->execute([$id]);
            $withdrawal = $stmt->fetch();

            if (!$withdrawal) throw new Exception("Saque não encontrado");
            if (!in_array($withdrawal["status"], ["pending", "processing"])) throw new Exception("Só é possível enviar para análise saques pendentes ou em processamento");

            // Garantir que ENUM inclui under_review
            try {
                $colInfo = $pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'status'")->fetch();
                if ($colInfo && strpos($colInfo['Type'], 'under_review') === false) {
                    $pdo->exec("ALTER TABLE withdrawals MODIFY COLUMN status ENUM('pending','processing','under_review','completed','rejected','failed') NOT NULL DEFAULT 'pending'");
                }
            } catch (Exception $ignore) {}

            $pdo->prepare("UPDATE withdrawals SET status = 'under_review' WHERE id = ?")->execute([$id]);
            secureLog("WITHDRAWAL_UNDER_REVIEW | id: {$id} | user_id: {$withdrawal['user_id']} | amount: R\${$withdrawal['amount_brl']}");

            $response = ["success" => true, "message" => "Saque #{$id} enviado para análise"];
            break;

        case "toggle_withdrawals":
            $weStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'withdrawals_enabled' LIMIT 1");
            $current = $weStmt ? $weStmt->fetchColumn() : false;
            // Default: ativos. Toggle inverte.
            $isEnabled = ($current === false || ($current !== 'false' && $current !== '0'));
            $newVal = $isEnabled ? 'false' : 'true';
            $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at)
                           VALUES ('withdrawals_enabled', ?, 0, NOW())
                           ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                ->execute([$newVal, $newVal]);
            $response = [
                'success' => true,
                'enabled' => ($newVal === 'true'),
                'message' => ($newVal === 'true') ? 'Saques reativados.' : 'Saques desativados.'
            ];
            break;

        case "get_withdrawals_status":
            $weStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'withdrawals_enabled' LIMIT 1");
            $current = $weStmt ? $weStmt->fetchColumn() : false;
            $isEnabled = ($current === false || ($current !== 'false' && $current !== '0'));
            $response = ['success' => true, 'enabled' => $isEnabled];
            break;

        case "toggle_credits_purchase":
            $cpStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'credits_purchase_enabled' LIMIT 1");
            $current = $cpStmt ? $cpStmt->fetchColumn() : false;
            $isEnabled = ($current === false || ($current !== 'false' && $current !== '0'));
            $newVal = $isEnabled ? 'false' : 'true';
            $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at)
                           VALUES ('credits_purchase_enabled', ?, 0, NOW())
                           ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                ->execute([$newVal, $newVal]);
            $response = [
                'success' => true,
                'enabled' => ($newVal === 'true'),
                'message' => ($newVal === 'true') ? 'Compra de créditos reativada.' : 'Compra de créditos desativada.'
            ];
            break;

        case "toggle_registrations":
            $reStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'registrations_enabled' LIMIT 1");
            $current = $reStmt ? $reStmt->fetchColumn() : false;
            $isEnabled = ($current === false || ($current !== 'false' && $current !== '0'));
            $newVal = $isEnabled ? 'false' : 'true';
            $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at)
                           VALUES ('registrations_enabled', ?, 0, NOW())
                           ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()")
                ->execute([$newVal, $newVal]);
            $response = [
                'success' => true,
                'enabled' => ($newVal === 'true'),
                'message' => ($newVal === 'true') ? 'Novas contas reativadas.' : 'Novas contas desativadas.'
            ];
            break;

        case "get_registrations_status":
            $reStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'registrations_enabled' LIMIT 1");
            $current = $reStmt ? $reStmt->fetchColumn() : false;
            $isEnabled = ($current === false || ($current !== 'false' && $current !== '0'));
            $response = ['success' => true, 'enabled' => $isEnabled];
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
