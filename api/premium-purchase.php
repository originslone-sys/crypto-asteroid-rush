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

            // Calcular créditos diários pendentes
            $pendingDailyCredits = 0;
            if ($isPremium) {
                $lastClaimed = $user['premium_credits_claimed_at'] ?? null;
                if ($lastClaimed) {
                    $capTime = min(time(), strtotime($expiresAt));
                    $elapsed = $capTime - strtotime($lastClaimed);
                    if ($elapsed > 0) {
                        $pendingDailyCredits = (int)floor($elapsed / 86400) * 2;
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'is_premium' => $isPremium,
                'expires_at' => $expiresAt,
                'price_brl' => $price,
                'duration_days' => $duration,
                'pending_daily_credits' => $pendingDailyCredits,
                'current_credits' => (int)($user['credits'] ?? 0)
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

        // ============================================
        // RESGATAR CRÉDITOS DIÁRIOS PREMIUM
        // ============================================
        case 'claim_daily_credits':
            $user = findPlayer($pdo, $googleUid);
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
                exit;
            }

            $isPremium = !empty($user['is_premium']) && !empty($user['premium_expires_at']) && strtotime($user['premium_expires_at']) > time();
            if (!$isPremium) {
                echo json_encode(['success' => false, 'error' => 'Apenas usuários premium podem resgatar créditos diários.']);
                exit;
            }

            $expiresAt = $user['premium_expires_at'];
            $lastClaimed = $user['premium_credits_claimed_at'] ?? null;

            if (!$lastClaimed) {
                // Não deveria acontecer, mas fallback: inicializar agora com 0 pendentes
                $pdo->prepare("UPDATE users SET premium_credits_claimed_at = NOW() WHERE id = ?")->execute([$user['id']]);
                echo json_encode(['success' => false, 'error' => 'Nenhum crédito disponível ainda. Volte amanhã!']);
                exit;
            }

            $capTime = min(time(), strtotime($expiresAt));
            $elapsed = $capTime - strtotime($lastClaimed);
            $pendingDays = (int)floor($elapsed / 86400);
            $pendingCredits = $pendingDays * 2;

            if ($pendingCredits <= 0) {
                echo json_encode(['success' => false, 'error' => 'Nenhum crédito disponível ainda. Volte amanhã!']);
                exit;
            }

            // Creditar e atualizar timestamp (avançar pelo número de dias completos, não NOW(), para não perder frações de dia)
            $newClaimedAt = date('Y-m-d H:i:s', strtotime($lastClaimed) + ($pendingDays * 86400));
            $pdo->prepare("UPDATE users SET credits = credits + ?, premium_credits_claimed_at = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$pendingCredits, $newClaimedAt, $user['id']]);

            // Registrar na tabela de transações
            $pdo->prepare("
                INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
                VALUES (?, 'premium_daily_credits', ?, 0, ?, 'completed', NOW())
            ")->execute([
                $googleUid,
                $pendingCredits,
                "Créditos diários premium: {$pendingCredits} créditos ({$pendingDays} dias)"
            ]);

            $newBalance = (int)($user['credits'] ?? 0) + $pendingCredits;

            secureLog("PREMIUM_DAILY_CLAIM | UID: {$googleUid} | Credits: {$pendingCredits} ({$pendingDays}d) | NewBalance: {$newBalance}");

            echo json_encode([
                'success' => true,
                'claimed_credits' => $pendingCredits,
                'pending_days' => $pendingDays,
                'new_credits_balance' => $newBalance,
                'message' => "Você resgatou {$pendingCredits} créditos!"
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
    // Tabelas e configurações criadas via migrate.php no deploy
}
