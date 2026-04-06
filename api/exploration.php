<?php
// ============================================
// UNOBIX - API de Exploração de Naves
// api/exploration.php
// Ações: list_ships, rent_ship, get_rentals, claim_credits
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zettpay-client.php';

setCorsHeaders();

$input = getRequestInput();
$action = $input['action'] ?? $_GET['action'] ?? '';
$googleUid = trim($input['google_uid'] ?? $input['googleUid'] ?? '');

if (empty($googleUid) || !validateGoogleUid($googleUid)) {
    echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => 'Erro de conexão']);
        exit;
    }

    // Verificar se exploração está habilitada
    $enabledStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'exploration_enabled' LIMIT 1");
    $enabled = $enabledStmt->fetch();
    if ($enabled && $enabled['setting_value'] !== 'true') {
        echo json_encode(['success' => false, 'error' => 'Exploração temporariamente desativada']);
        exit;
    }

    // Buscar usuário
    $userStmt = $pdo->prepare("SELECT id, google_uid, balance_brl, credits FROM users WHERE google_uid = ? LIMIT 1");
    $userStmt->execute([$googleUid]);
    $user = $userStmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    $userId = (int)$user['id'];

    // Expirar aluguéis vencidos automaticamente
    $pdo->prepare("UPDATE exploration_rentals SET status = 'expired' WHERE user_id = ? AND status = 'active' AND expires_at <= NOW()")
        ->execute([$userId]);

    // Função auxiliar: ativar rental pendente (SOMENTE por external_id, sem fallback)
    function activatePendingRental($pdo, $userId, $externalId) {
        // Buscar APENAS por external_id exato - nunca ativar outro rental
        $stmt = $pdo->prepare("SELECT * FROM exploration_rentals WHERE user_id = ? AND external_id = ? AND status = 'pending_payment' LIMIT 1");
        $stmt->execute([$userId, $externalId]);
        $rental = $stmt->fetch();

        if (!$rental) return false;

        // Buscar duração da nave
        $shipStmt = $pdo->prepare("SELECT rental_duration_hours FROM exploration_ships WHERE id = ? LIMIT 1");
        $shipStmt->execute([$rental['ship_id']]);
        $shipData = $shipStmt->fetch();
        $hours = $shipData ? (int)$shipData['rental_duration_hours'] : 72;

        $pdo->prepare("
            UPDATE exploration_rentals
            SET status = 'active', started_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR), last_accumulation_at = NOW()
            WHERE id = ? AND status = 'pending_payment'
        ")->execute([$hours, $rental['id']]);

        secureLog("EXPLORATION_ACTIVATED | user_id: {$userId} | rental_id: {$rental['id']} | ship_id: {$rental['ship_id']} | hours: {$hours}");
        return true;
    }

    switch ($action) {

        // ============================================
        // LISTAR NAVES DISPONÍVEIS
        // ============================================
        case 'list_ships':
            $ships = $pdo->query("
                SELECT id, ship_key, name, description, rental_price_brl, rental_duration_hours, credits_per_day
                FROM exploration_ships
                WHERE is_active = 1
                ORDER BY sort_order ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Aluguéis ativos do usuário
            $rentedShipIds = [];
            $pendingShipIds = [];
            try {
                $activeRentals = $pdo->prepare("
                    SELECT ship_id, status FROM exploration_rentals
                    WHERE user_id = ? AND status IN ('active', 'pending_payment')
                ");
                $activeRentals->execute([$userId]);
                $rentalRows = $activeRentals->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rentalRows as $row) {
                    $sid = (int)$row['ship_id'];
                    if ($row['status'] === 'active') {
                        $rentedShipIds[] = $sid;
                    } elseif ($row['status'] === 'pending_payment') {
                        $pendingShipIds[] = $sid;
                    }
                }
            } catch (Throwable $e) {
                // Fallback: tentar sem pending_payment (ENUM pode não estar atualizado)
                error_log("exploration list_ships rental query error: " . $e->getMessage());
                try {
                    $activeRentals = $pdo->prepare("SELECT ship_id FROM exploration_rentals WHERE user_id = ? AND status = 'active'");
                    $activeRentals->execute([$userId]);
                    $rentedShipIds = array_map('intval', $activeRentals->fetchAll(PDO::FETCH_COLUMN));
                } catch (Throwable $e2) {
                    error_log("exploration list_ships fallback error: " . $e2->getMessage());
                }
            }

            // Limite de aluguéis
            $maxRentals = 3;
            try {
                $maxStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'exploration_max_rentals_per_user' LIMIT 1");
                $maxRow = $maxStmt->fetch();
                if ($maxRow) $maxRentals = (int)$maxRow['setting_value'];
            } catch (Exception $e) {}

            foreach ($ships as &$ship) {
                $ship['id'] = (int)$ship['id'];
                $ship['rental_price_brl'] = (float)$ship['rental_price_brl'];
                $ship['rental_duration_hours'] = (int)$ship['rental_duration_hours'];
                $ship['credits_per_day'] = (int)$ship['credits_per_day'];
                $ship['is_rented'] = in_array($ship['id'], $rentedShipIds);
                $ship['is_pending_payment'] = in_array($ship['id'], $pendingShipIds);
            }
            unset($ship);

            echo json_encode([
                'success' => true,
                'ships' => $ships,
                'active_rentals' => count($rentedShipIds),
                'pending_rentals' => count($pendingShipIds),
                'max_rentals' => $maxRentals,
                'balance_brl' => (float)$user['balance_brl'],
                'credits' => (int)$user['credits']
            ]);
            break;

        // ============================================
        // ALUGAR NAVE (PAGAMENTO VIA PIX)
        // ============================================
        case 'rent_ship':
            $shipId = (int)($input['ship_id'] ?? 0);
            if (!$shipId) {
                echo json_encode(['success' => false, 'error' => 'ship_id obrigatório']);
                exit;
            }

            // Buscar nave
            $shipStmt = $pdo->prepare("SELECT * FROM exploration_ships WHERE id = ? AND is_active = 1 LIMIT 1");
            $shipStmt->execute([$shipId]);
            $ship = $shipStmt->fetch();

            if (!$ship) {
                echo json_encode(['success' => false, 'error' => 'Nave não encontrada ou desativada']);
                exit;
            }

            $price = (float)$ship['rental_price_brl'];

            // Verificar limite de aluguéis ativos
            $maxRentals = 3;
            try {
                $maxStmt = $pdo->query("SELECT setting_value FROM game_settings WHERE setting_key = 'exploration_max_rentals_per_user' LIMIT 1");
                $maxRow = $maxStmt->fetch();
                if ($maxRow) $maxRentals = (int)$maxRow['setting_value'];
            } catch (Exception $e) {}

            $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM exploration_rentals WHERE user_id = ? AND status = 'active'");
            $activeStmt->execute([$userId]);
            $activeCount = (int)$activeStmt->fetchColumn();

            if ($activeCount >= $maxRentals) {
                echo json_encode(['success' => false, 'error' => "Limite de $maxRentals aluguéis ativos atingido"]);
                exit;
            }

            // Verificar se já alugou esta nave (ativa ou pagamento pendente)
            $alreadyRented = $pdo->prepare("SELECT COUNT(*) FROM exploration_rentals WHERE user_id = ? AND ship_id = ? AND status IN ('active', 'pending_payment')");
            $alreadyRented->execute([$userId, $shipId]);
            if ((int)$alreadyRented->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Você já tem esta nave alugada ou com pagamento pendente']);
                exit;
            }

            // Garantir que tabela zettpay_transactions existe
            ensureZettpayTable($pdo);

            // Expirar pendentes antigos (>30 min) sem qr_code
            $pdo->prepare("
                UPDATE zettpay_transactions SET status = 'expired'
                WHERE user_id = ? AND type = 'deposit' AND status = 'pending'
                AND external_id LIKE 'EXP-%'
                AND (created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) OR qr_code IS NULL OR qr_code = '')
            ")->execute([$userId]);

            // Expirar aluguéis pendentes de pagamento antigos (>30 min)
            $pdo->prepare("
                UPDATE exploration_rentals SET status = 'expired'
                WHERE user_id = ? AND status = 'pending_payment'
                AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ")->execute([$userId]);

            // Gerar external_id com prefixo EXP-
            $externalId = 'EXP-' . $userId . '-SHIP' . $shipId . '-' . time() . '-' . bin2hex(random_bytes(4));
            $durationHours = (int)$ship['rental_duration_hours'];

            // Chamar ZettPay para gerar PIX
            $result = zettpayCreateDeposit(
                $price,
                $externalId,
                "Aluguel de nave: {$ship['name']} ({$durationHours}h)",
                [
                    'name' => $user['display_name'] ?? '',
                    'email' => $user['email'] ?? ''
                ],
                [
                    'user_id' => (string)$userId,
                    'ship_id' => (string)$shipId,
                    'type' => 'exploration_rent'
                ]
            );

            if (!$result['success']) {
                echo json_encode(['success' => false, 'error' => 'Erro ao gerar PIX: ' . $result['error']]);
                exit;
            }

            $apiData = $result['data'];
            $pixCode = $apiData['qr_code'] ?? null;

            if (empty($pixCode)) {
                secureLog("ZETTPAY_EXP_NO_QRCODE | external_id: {$externalId} | response: " . json_encode($apiData));
                echo json_encode(['success' => false, 'error' => 'PIX gerado sem QR Code']);
                exit;
            }

            // Salvar transação ZettPay
            $pdo->prepare("
                INSERT INTO zettpay_transactions (
                    user_id, external_id, zettpay_id, type, amount_brl, fee_brl,
                    status, qr_code, pix_copy_paste, expires_at, created_at
                ) VALUES (?, ?, ?, 'deposit', ?, ?, 'pending', ?, ?, ?, NOW())
            ")->execute([
                $userId,
                $externalId,
                $apiData['id'] ?? null,
                $price,
                (float)($apiData['fee_amount'] ?? 0),
                $pixCode,
                $pixCode,
                $apiData['expires_at'] ?? null
            ]);

            // Criar aluguel com status pending_payment (ativado pelo webhook)
            $pdo->prepare("
                INSERT INTO exploration_rentals (
                    user_id, google_uid, ship_id, ship_key,
                    rental_price_brl, credits_per_day,
                    status, external_id,
                    started_at, expires_at, last_accumulation_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending_payment', ?, NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR), NOW())
            ")->execute([
                $userId, $googleUid, $shipId, $ship['ship_key'],
                $price, (int)$ship['credits_per_day'],
                $externalId, $durationHours
            ]);

            // Registrar transação pendente no histórico
            try {
                $pdo->prepare("
                    INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
                    VALUES (?, 'exploration_rent', ?, ?, ?, 'pending', NOW())
                ")->execute([
                    $googleUid, $price, $price,
                    "Aluguel: {$ship['name']} ({$durationHours}h) [{$externalId}]"
                ]);
            } catch (Throwable $txErr) {
                $pdo->prepare("
                    INSERT INTO transactions (google_uid, type, amount, description, status, created_at)
                    VALUES (?, 'exploration_rent', ?, ?, 'pending', NOW())
                ")->execute([
                    $googleUid, $price,
                    "Aluguel: {$ship['name']} ({$durationHours}h) [{$externalId}]"
                ]);
            }

            secureLog("EXPLORATION_PIX | uid: {$googleUid} | ship: {$shipId} | price: R\${$price} | external_id: {$externalId}");

            echo json_encode([
                'success' => true,
                'payment_required' => true,
                'external_id' => $externalId,
                'amount_brl' => $price,
                'ship_name' => $ship['name'],
                'duration_hours' => $durationHours,
                'credits_per_day' => (int)$ship['credits_per_day'],
                'qr_code' => $pixCode,
                'pix_copy_paste' => $pixCode,
                'expires_at' => $apiData['expires_at'] ?? null
            ]);
            break;

        // ============================================
        // VERIFICAR STATUS DO PAGAMENTO (consulta ZettPay)
        // ============================================
        case 'check_payment':
            $externalId = trim($input['external_id'] ?? '');
            if (empty($externalId)) {
                echo json_encode(['success' => false, 'error' => 'external_id obrigatório']);
                exit;
            }

            // 1. Buscar transação ZettPay no banco
            $ztStmt = $pdo->prepare("SELECT * FROM zettpay_transactions WHERE external_id = ? AND user_id = ? AND type = 'deposit' LIMIT 1");
            $ztStmt->execute([$externalId, $userId]);
            $zt = $ztStmt->fetch();

            if (!$zt) {
                echo json_encode(['success' => false, 'error' => 'Transação não encontrada']);
                exit;
            }

            // 2. Se já confirmado no banco, verificar se rental já está ativo
            if ($zt['status'] === 'confirmed') {
                // Verificar se o rental já foi ativado para este external_id
                $rentalCheck = $pdo->prepare("SELECT status FROM exploration_rentals WHERE external_id = ? AND user_id = ? LIMIT 1");
                $rentalCheck->execute([$externalId, $userId]);
                $rentalRow = $rentalCheck->fetch();

                if ($rentalRow && $rentalRow['status'] === 'pending_payment') {
                    // Pagamento confirmado mas rental ainda pendente - ativar agora
                    activatePendingRental($pdo, $userId, $externalId);
                }
                // Se já active ou não encontrado, não fazer nada extra
                echo json_encode(['success' => true, 'status' => 'active', 'paid' => true]);
                exit;
            }

            // 3. Consultar ZettPay diretamente
            $apiResult = zettpayLookupDeposit($externalId);

            if (!$apiResult['success'] || empty($apiResult['data'])) {
                echo json_encode(['success' => true, 'status' => 'pending_payment', 'paid' => false]);
                exit;
            }

            $apiData = $apiResult['data'];
            if (isset($apiData['data']) && is_array($apiData['data'])) {
                $apiData = $apiData['data'];
            }

            $apiStatus = strtoupper($apiData['status'] ?? '');

            if (in_array($apiStatus, ['PAID', 'COMPLETED', 'APPROVED'])) {
                // Pagamento confirmado! Atualizar tudo
                $zettpayId = $apiData['provider_transaction_id'] ?? $apiData['id'] ?? '';

                $pdo->prepare("
                    UPDATE zettpay_transactions
                    SET status = 'confirmed', zettpay_id = ?, confirmed_at = NOW()
                    WHERE id = ? AND status = 'pending'
                ")->execute([$zettpayId, $zt['id']]);

                // Atualizar histórico
                $pdo->prepare("
                    UPDATE transactions SET status = 'completed'
                    WHERE google_uid = ? AND description LIKE ? AND status = 'pending' LIMIT 1
                ")->execute([$googleUid, '%' . $externalId . '%']);

                // Ativar rental
                activatePendingRental($pdo, $userId, $externalId);

                echo json_encode(['success' => true, 'status' => 'active', 'paid' => true]);
                exit;
            }

            if (in_array($apiStatus, ['EXPIRED', 'FAILED', 'CANCELLED', 'CANCELED'])) {
                $pdo->prepare("UPDATE zettpay_transactions SET status = 'expired' WHERE id = ? AND status = 'pending'")->execute([$zt['id']]);
                echo json_encode(['success' => true, 'status' => 'expired', 'paid' => false]);
                exit;
            }

            // Ainda pendente
            echo json_encode(['success' => true, 'status' => 'pending_payment', 'paid' => false]);
            break;

        // ============================================
        // MEUS ALUGUÉIS
        // ============================================
        case 'get_rentals':
            $rentals = $pdo->prepare("
                SELECT r.*, s.name as ship_name, s.description as ship_description
                FROM exploration_rentals r
                LEFT JOIN exploration_ships s ON s.id = r.ship_id
                WHERE r.user_id = ?
                ORDER BY FIELD(r.status, 'active', 'pending_payment', 'expired', 'cancelled'), r.created_at DESC
                LIMIT 20
            ");
            $rentals->execute([$userId]);
            $rows = $rentals->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $r) {
                $isActive = ($r['status'] === 'active');
                $isPending = ($r['status'] === 'pending_payment');
                $startedAt = strtotime($r['started_at']);
                $expiresAt = strtotime($r['expires_at']);
                $now = time();

                // Créditos só acumulam para rentals ativos (não pendentes, cancelados ou expirados)
                $totalAccumulated = 0;
                $unclaimed = 0;
                if ($isActive) {
                    $effectiveEnd = min($now, $expiresAt);
                    $elapsedSeconds = max(0, $effectiveEnd - $startedAt);
                    $totalAccumulated = (int)floor(($elapsedSeconds / 86400) * (int)$r['credits_per_day']);
                    $unclaimed = max(0, $totalAccumulated - (int)$r['credits_claimed']);
                }

                $result[] = [
                    'id' => (int)$r['id'],
                    'ship_key' => $r['ship_key'],
                    'ship_name' => $r['ship_name'] ?? $r['ship_key'],
                    'ship_description' => $r['ship_description'] ?? '',
                    'rental_price_brl' => (float)$r['rental_price_brl'],
                    'credits_per_day' => (int)$r['credits_per_day'],
                    'status' => $r['status'],
                    'total_accumulated' => $totalAccumulated,
                    'credits_claimed' => (int)$r['credits_claimed'],
                    'unclaimed' => $unclaimed,
                    'started_at' => $r['started_at'],
                    'expires_at' => $r['expires_at'],
                    'is_expired' => $isPending ? false : ($now >= $expiresAt)
                ];
            }

            echo json_encode([
                'success' => true,
                'rentals' => $result,
                'credits' => (int)$user['credits']
            ]);
            break;

        // ============================================
        // RESGATAR CRÉDITOS
        // ============================================
        case 'claim_credits':
            $rentalId = (int)($input['rental_id'] ?? 0);
            if (!$rentalId) {
                echo json_encode(['success' => false, 'error' => 'rental_id obrigatório']);
                exit;
            }

            $pdo->beginTransaction();

            try {
                // Lock no aluguel
                $rentalStmt = $pdo->prepare("
                    SELECT * FROM exploration_rentals
                    WHERE id = ? AND user_id = ?
                    FOR UPDATE
                ");
                $rentalStmt->execute([$rentalId, $userId]);
                $rental = $rentalStmt->fetch();

                if (!$rental) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Aluguel não encontrado']);
                    exit;
                }

                if ($rental['status'] !== 'active') {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Aluguel não está ativo']);
                    exit;
                }

                // Calcular créditos em tempo real
                $startedAt = strtotime($rental['started_at']);
                $expiresAt = strtotime($rental['expires_at']);
                $now = time();
                $effectiveEnd = min($now, $expiresAt);
                $elapsedSeconds = max(0, $effectiveEnd - $startedAt);
                $totalAccumulated = (int)floor(($elapsedSeconds / 86400) * (int)$rental['credits_per_day']);
                $alreadyClaimed = (int)$rental['credits_claimed'];
                $toClaim = max(0, $totalAccumulated - $alreadyClaimed);

                if ($toClaim <= 0) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Nenhum crédito disponível para resgate']);
                    exit;
                }

                // Creditar ao usuário
                $pdo->prepare("UPDATE users SET credits = credits + ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$toClaim, $userId]);

                // Atualizar aluguel
                $pdo->prepare("
                    UPDATE exploration_rentals
                    SET credits_accumulated = ?, credits_claimed = credits_claimed + ?, claimed_at = NOW(), last_accumulation_at = NOW()
                    WHERE id = ?
                ")->execute([$totalAccumulated, $toClaim, $rentalId]);

                // Registrar transação
                $shipName = $rental['ship_key'];
                $pdo->prepare("
                    INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
                    VALUES (?, 'exploration_credits', 0, 0, ?, 'completed', NOW())
                ")->execute([
                    $googleUid,
                    "Exploração {$shipName}: +{$toClaim} créditos"
                ]);

                $pdo->commit();

                // Buscar novo saldo de créditos
                $creditsStmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
                $creditsStmt->execute([$userId]);
                $newCredits = (int)$creditsStmt->fetchColumn();

                echo json_encode([
                    'success' => true,
                    'claimed' => $toClaim,
                    'total_claimed' => $alreadyClaimed + $toClaim,
                    'new_credits' => $newCredits,
                    'message' => "+{$toClaim} créditos resgatados!"
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        // ============================================
        // CANCELAR ALUGUEL PENDENTE
        // ============================================
        case 'cancel_rental':
            $rentalId = (int)($input['rental_id'] ?? 0);
            if (!$rentalId) {
                echo json_encode(['success' => false, 'error' => 'rental_id obrigatório']);
                exit;
            }

            $rentalStmt = $pdo->prepare("
                SELECT * FROM exploration_rentals
                WHERE id = ? AND user_id = ? AND status = 'pending_payment' LIMIT 1
            ");
            $rentalStmt->execute([$rentalId, $userId]);
            $rental = $rentalStmt->fetch();

            if (!$rental) {
                echo json_encode(['success' => false, 'error' => 'Aluguel pendente não encontrado']);
                exit;
            }

            // Cancelar rental
            $pdo->prepare("UPDATE exploration_rentals SET status = 'cancelled' WHERE id = ?")
                ->execute([$rentalId]);

            // Cancelar transação zettpay se existir
            if (!empty($rental['external_id'])) {
                $pdo->prepare("UPDATE zettpay_transactions SET status = 'expired', error_message = 'Cancelado pelo usuário' WHERE external_id = ? AND status = 'pending'")
                    ->execute([$rental['external_id']]);
                $pdo->prepare("UPDATE transactions SET status = 'failed' WHERE google_uid = ? AND description LIKE ? AND status = 'pending' LIMIT 1")
                    ->execute([$googleUid, '%' . $rental['external_id'] . '%']);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Aluguel cancelado'
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("exploration.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}
