<?php
// ============================================
// UNOBIX - API Premium Subscription
// api/premium-purchase.php v1.0
// Compra de assinatura Premium via PIX
// Remove anúncios e CAPTCHA por 30 dias
// ============================================

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/zettpay-client.php";

setCorsHeaders();

$input = getRequestInput();
$action = trim($input['action'] ?? 'get_status');
$googleUid = trim($input['google_uid'] ?? '');

if (!$googleUid || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Erro ao conectar ao banco");

    ensurePremiumTable($pdo);

    switch ($action) {

        // ============================================
        // STATUS DO PREMIUM
        // ============================================
        case 'get_status':
            $user = findPlayer($pdo, $googleUid);
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            $isPremium = !empty($user['is_premium']) && !empty($user['premium_expires_at']) && strtotime($user['premium_expires_at']) > time();
            $expiresAt = $isPremium ? $user['premium_expires_at'] : null;

            // Load price from settings
            $priceStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'premium_price_brl'");
            $price = $priceStmt ? (float)($priceStmt->fetchColumn() ?: 19.90) : 19.90;

            $durationStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'premium_duration_days'");
            $duration = $durationStmt ? (int)($durationStmt->fetchColumn() ?: 30) : 30;

            echo json_encode([
                'success' => true,
                'is_premium' => $isPremium,
                'expires_at' => $expiresAt,
                'price_brl' => $price,
                'duration_days' => $duration
            ]);
            break;

        // ============================================
        // COMPRAR PREMIUM
        // ============================================
        case 'purchase':
            $user = findPlayer($pdo, $googleUid);
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }
            if (!empty($user['is_banned'])) {
                echo json_encode(['success' => false, 'error' => 'Conta suspensa']);
                exit;
            }

            // Premium ativo: permitir renovação antecipada (dias serão somados ao vencimento atual)
            $isPremium = !empty($user['is_premium']) && !empty($user['premium_expires_at']) && strtotime($user['premium_expires_at']) > time();
            $isRenewal = $isPremium;

            // Load price from settings
            $priceStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'premium_price_brl'");
            $price = $priceStmt ? (float)($priceStmt->fetchColumn() ?: 19.90) : 19.90;

            $durationStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'premium_duration_days'");
            $duration = $durationStmt ? (int)($durationStmt->fetchColumn() ?: 30) : 30;

            // Ensure zettpay table
            ensureZettpayTable($pdo);

            // Expire old pending
            $pdo->prepare("
                UPDATE zettpay_transactions SET status = 'expired'
                WHERE user_id = ? AND type = 'deposit' AND status = 'pending'
                AND external_id LIKE 'PRM-%'
                AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ")->execute([$user['id']]);

            // Check for existing pending premium purchase
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM zettpay_transactions
                WHERE user_id = ? AND type = 'deposit' AND status = 'pending'
                AND external_id LIKE 'PRM-%'
                AND qr_code IS NOT NULL AND qr_code != ''
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ");
            $stmt->execute([$user['id']]);
            if ((int)$stmt->fetchColumn() >= 1) {
                echo json_encode(['success' => false, 'error' => 'Você já tem uma compra Premium pendente. Aguarde ou pague a existente.']);
                exit;
            }

            // Generate external_id
            $externalId = 'PRM-' . $user['id'] . '-' . time() . '-' . bin2hex(random_bytes(4));

            // Call ZettPay
            $result = zettpayCreateDeposit(
                $price,
                $externalId,
                "Assinatura Premium UNOBIX ({$duration} dias)",
                [
                    'name' => $user['display_name'] ?? '',
                    'email' => $user['email'] ?? ''
                ],
                [
                    'user_id' => (string)$user['id'],
                    'type' => 'premium_purchase',
                    'duration_days' => (string)$duration
                ]
            );

            if (!$result['success']) {
                echo json_encode(['success' => false, 'error' => 'Erro ao gerar PIX: ' . $result['error']]);
                exit;
            }

            $apiData = $result['data'];
            $pixCode = $apiData['qr_code'] ?? null;

            if (empty($pixCode)) {
                secureLog("PREMIUM_NO_QRCODE | external_id: {$externalId} | response: " . json_encode($apiData));
                echo json_encode(['success' => false, 'error' => 'PIX gerado sem QR Code.']);
                exit;
            }

            // Save to zettpay_transactions
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
                $price,
                (float)($apiData['fee_amount'] ?? 0),
                $pixCode,
                $pixCode,
                $apiData['expires_at'] ?? null
            ]);

            // Save to premium_subscriptions
            $stmt = $pdo->prepare("
                INSERT INTO premium_subscriptions (
                    user_id, external_id, price_brl, duration_days, status, created_at
                ) VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$user['id'], $externalId, $price, $duration]);

            // Register pending transaction
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
                    VALUES (?, 'premium_purchase', ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $googleUid,
                    $price,
                    $price,
                    "Assinatura Premium {$duration} dias [{$externalId}]"
                ]);
            } catch (Throwable $txErr) {
                $stmt = $pdo->prepare("
                    INSERT INTO transactions (google_uid, type, amount, description, status, created_at)
                    VALUES (?, 'premium_purchase', ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $googleUid,
                    $price,
                    "Assinatura Premium {$duration} dias [{$externalId}]"
                ]);
            }

            secureLog("PREMIUM_PURCHASE | uid: {$googleUid} | duration: {$duration}d | amount: R\${$price} | external_id: {$externalId}");

            echo json_encode([
                'success' => true,
                'external_id' => $externalId,
                'amount_brl' => $price,
                'duration_days' => $duration,
                'qr_code' => $pixCode,
                'pix_copy_paste' => $pixCode,
                'expires_at' => $apiData['expires_at'] ?? null
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }

} catch (Throwable $e) {
    error_log("premium-purchase.php error: " . $e->getMessage() . " | Line: " . $e->getLine());
    secureLog("PREMIUM_ERROR | " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro: ' . $e->getMessage()]);
}

// ============================================
// TABELAS - AUTO-CRIAÇÃO
// ============================================
function ensurePremiumTable($pdo) {
    static $checked = false;
    if ($checked) return;

    $flagFile = sys_get_temp_dir() . '/unobix_table_premium.flag';
    if (file_exists($flagFile) && (time() - filemtime($flagFile)) < 3600) {
        $checked = true;
        return;
    }

    try {
        // Premium subscriptions table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS premium_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                external_id VARCHAR(100) NOT NULL,
                price_brl DECIMAL(10,2) NOT NULL,
                duration_days INT NOT NULL DEFAULT 30,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                activated_at DATETIME DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_external_id (external_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Add premium columns to users table if they don't exist
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_premium'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_premium TINYINT(1) NOT NULL DEFAULT 0");
            secureLog("PREMIUM_TABLE | Coluna 'is_premium' adicionada à tabela users");
        }

        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'premium_expires_at'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE users ADD COLUMN premium_expires_at DATETIME DEFAULT NULL");
            secureLog("PREMIUM_TABLE | Coluna 'premium_expires_at' adicionada à tabela users");
        }

        // Add default premium settings if not exist
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM game_settings WHERE setting_key = 'premium_price_brl'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $defaults = [
                ['premium_price_brl', '19.90', 1],
                ['premium_duration_days', '30', 1],
                ['premium_enabled', 'true', 1],
            ];
            $ins = $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, ?, NOW())");
            foreach ($defaults as $d) {
                $ins->execute($d);
            }
            secureLog("PREMIUM_TABLE | Configurações padrão inseridas");
        }

        @touch($flagFile);
        $checked = true;
    } catch (Exception $e) {
        error_log("Premium table creation error: " . $e->getMessage());
    }
}
