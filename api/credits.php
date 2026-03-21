<?php
// ============================================
// UNOBIX - API de Créditos
// api/credits.php v1.0
// Gerencia créditos do usuário e pacotes
// ============================================

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/zettpay-client.php";

setCorsHeaders();

$input = getRequestInput();
$action = trim($input['action'] ?? 'get_balance');
$googleUid = trim($input['google_uid'] ?? '');

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    ensureCreditsTable($pdo);

    switch ($action) {

        // ============================================
        // CONSULTAR SALDO DE CRÉDITOS
        // ============================================
        case 'get_balance':
            $user = findPlayer($pdo, $googleUid);
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            $credits = (int)($user['credits'] ?? 0);

            echo json_encode([
                'success' => true,
                'credits' => $credits,
                'credits_per_game' => CREDITS_PER_GAME
            ]);
            break;

        // ============================================
        // LISTAR PACOTES DISPONÍVEIS
        // ============================================
        case 'get_packages':
            $stmt = $pdo->prepare("
                SELECT id, name, credits, price_brl, bonus_credits, is_featured
                FROM credit_packages
                WHERE is_active = 1
                ORDER BY credits ASC
            ");
            $stmt->execute();
            $packages = $stmt->fetchAll();

            // Formatar
            $formatted = [];
            foreach ($packages as $pkg) {
                $totalCredits = (int)$pkg['credits'] + (int)($pkg['bonus_credits'] ?? 0);
                $pricePerCredit = $totalCredits > 0 ? round((float)$pkg['price_brl'] / $totalCredits, 2) : 0;

                $formatted[] = [
                    'id' => (int)$pkg['id'],
                    'name' => $pkg['name'],
                    'credits' => (int)$pkg['credits'],
                    'bonus_credits' => (int)($pkg['bonus_credits'] ?? 0),
                    'total_credits' => $totalCredits,
                    'price_brl' => (float)$pkg['price_brl'],
                    'price_per_credit' => $pricePerCredit,
                    'is_featured' => (bool)($pkg['is_featured'] ?? false)
                ];
            }

            echo json_encode([
                'success' => true,
                'packages' => $formatted,
                'credits_per_game' => CREDITS_PER_GAME
            ]);
            break;

        // ============================================
        // COMPRAR PACOTE (GERA PIX)
        // ============================================
        case 'buy_package':
            $packageId = (int)($input['package_id'] ?? 0);

            if ($packageId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Pacote inválido']);
                exit;
            }

            $user = findPlayer($pdo, $googleUid);
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }
            if (!empty($user['is_banned'])) {
                echo json_encode(['success' => false, 'error' => 'Conta suspensa']);
                exit;
            }

            // Buscar pacote
            $stmt = $pdo->prepare("SELECT * FROM credit_packages WHERE id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$packageId]);
            $package = $stmt->fetch();

            if (!$package) {
                echo json_encode(['success' => false, 'error' => 'Pacote não encontrado ou indisponível']);
                exit;
            }

            $amount = (float)$package['price_brl'];
            $totalCredits = (int)$package['credits'] + (int)($package['bonus_credits'] ?? 0);

            // Garantir que tabela zettpay_transactions existe ANTES de consultá-la
            ensureZettpayTable($pdo);

            // Verificar se já tem compra pendente recente
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM zettpay_transactions
                WHERE user_id = ? AND type = 'deposit' AND status = 'pending'
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ");
            $stmt->execute([$user['id']]);
            if ((int)$stmt->fetchColumn() >= 3) {
                echo json_encode(['success' => false, 'error' => 'Você já tem compras pendentes. Aguarde ou pague as existentes.']);
                exit;
            }

            // Gerar external_id com referência ao pacote
            $externalId = 'CRD-' . $user['id'] . '-PKG' . $packageId . '-' . time() . '-' . bin2hex(random_bytes(4));

            // Chamar ZettPay
            $result = zettpayCreateDeposit(
                $amount,
                $externalId,
                "Compra de {$totalCredits} créditos UNOBIX",
                [
                    'name' => $user['display_name'] ?? '',
                    'email' => $user['email'] ?? ''
                ],
                [
                    'user_id' => (string)$user['id'],
                    'package_id' => (string)$packageId,
                    'credits' => (string)$totalCredits,
                    'type' => 'credit_purchase'
                ]
            );

            if (!$result['success']) {
                echo json_encode(['success' => false, 'error' => 'Erro ao gerar PIX: ' . $result['error']]);
                exit;
            }

            $apiData = $result['data'];

            // Salvar no banco (zettpay_transactions)
            $stmt = $pdo->prepare("
                INSERT INTO zettpay_transactions (
                    user_id, external_id, zettpay_id, type, amount_brl, fee_brl,
                    status, qr_code, pix_copy_paste, expires_at, created_at
                ) VALUES (?, ?, ?, 'deposit', ?, ?, 'pending', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user['id'],
                $externalId,
                $apiData['id'] ?? null,
                $amount,
                (float)($apiData['fee_amount'] ?? 0),
                $apiData['qr_code'] ?? null,
                $apiData['pix_copy_paste'] ?? null,
                $apiData['expires_at'] ?? null
            ]);

            // Salvar referência ao pacote na tabela credit_purchases
            $stmt = $pdo->prepare("
                INSERT INTO credit_purchases (
                    user_id, package_id, credits_amount, price_brl,
                    external_id, status, created_at
                ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $user['id'],
                $packageId,
                $totalCredits,
                $amount,
                $externalId
            ]);

            // Registrar transação pendente
            $stmt = $pdo->prepare("
                INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
                VALUES (?, 'deposit', ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $googleUid,
                $amount,
                "Compra de {$totalCredits} créditos [{$externalId}]"
            ]);

            secureLog("CREDIT_PURCHASE | uid: {$googleUid} | package: {$packageId} | credits: {$totalCredits} | amount: R\${$amount} | external_id: {$externalId}");

            echo json_encode([
                'success' => true,
                'external_id' => $externalId,
                'amount_brl' => $amount,
                'credits' => $totalCredits,
                'package_name' => $package['name'],
                'qr_code' => $apiData['qr_code'] ?? null,
                'pix_copy_paste' => $apiData['pix_copy_paste'] ?? null,
                'expires_at' => $apiData['expires_at'] ?? null
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }

} catch (Throwable $e) {
    error_log("credits.php error: " . $e->getMessage() . " | Line: " . $e->getLine() . " | File: " . $e->getFile());
    $errorMsg = 'Erro no servidor';
    // Em ambiente de desenvolvimento, mostrar detalhe do erro
    if (strpos($e->getMessage(), 'Table') !== false || strpos($e->getMessage(), 'column') !== false) {
        $errorMsg = 'Erro de banco de dados. Tente novamente.';
    }
    echo json_encode(['success' => false, 'error' => $errorMsg]);
}

// ============================================
// TABELAS - AUTO-CRIAÇÃO
// ============================================
function ensureCreditsTable($pdo) {
    static $checked = false;
    if ($checked) return;

    try {
        // Tabela de pacotes de créditos
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
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Tabela de compras de créditos
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS credit_purchases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                package_id INT NOT NULL,
                credits_amount INT NOT NULL,
                price_brl DECIMAL(10,2) NOT NULL,
                external_id VARCHAR(100) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                confirmed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_external_id (external_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Adicionar coluna credits na tabela users se não existir
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'credits'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE users ADD COLUMN credits INT NOT NULL DEFAULT 0");
            secureLog("CREDITS_TABLE | Coluna 'credits' adicionada à tabela users");
        }

        // Inserir pacotes padrão se a tabela estiver vazia
        $count = $pdo->query("SELECT COUNT(*) FROM credit_packages")->fetchColumn();
        if ($count == 0) {
            $pdo->exec("
                INSERT INTO credit_packages (name, credits, bonus_credits, price_brl, is_featured) VALUES
                ('Iniciante', 5, 0, 1.00, 0),
                ('Explorador', 15, 2, 2.50, 0),
                ('Comandante', 30, 5, 4.50, 1),
                ('Elite', 60, 15, 8.00, 0),
                ('Lendário', 120, 40, 14.00, 0)
            ");
            secureLog("CREDITS_TABLE | Pacotes padrão inseridos");
        }

        $checked = true;
    } catch (Exception $e) {
        error_log("Credits table creation error: " . $e->getMessage());
    }
}
