<?php
// ============================================
// UNOBIX - API de Exploração de Naves
// api/exploration.php
// Ações: list_ships, rent_ship, get_rentals, claim_credits
// ============================================

require_once __DIR__ . '/config.php';

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
            $activeRentals = $pdo->prepare("
                SELECT ship_id FROM exploration_rentals
                WHERE user_id = ? AND status = 'active'
            ");
            $activeRentals->execute([$userId]);
            $rentedShipIds = $activeRentals->fetchAll(PDO::FETCH_COLUMN);

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
            }
            unset($ship);

            echo json_encode([
                'success' => true,
                'ships' => $ships,
                'active_rentals' => count($rentedShipIds),
                'max_rentals' => $maxRentals,
                'balance_brl' => (float)$user['balance_brl'],
                'credits' => (int)$user['credits']
            ]);
            break;

        // ============================================
        // ALUGAR NAVE
        // ============================================
        case 'rent_ship':
            $shipId = (int)($input['ship_id'] ?? 0);
            if (!$shipId) {
                echo json_encode(['success' => false, 'error' => 'ship_id obrigatório']);
                exit;
            }

            $pdo->beginTransaction();

            try {
                // Lock no usuário
                $userLock = $pdo->prepare("SELECT id, balance_brl, credits FROM users WHERE id = ? FOR UPDATE");
                $userLock->execute([$userId]);
                $freshUser = $userLock->fetch();
                $balance = (float)$freshUser['balance_brl'];

                // Buscar nave
                $shipStmt = $pdo->prepare("SELECT * FROM exploration_ships WHERE id = ? AND is_active = 1 LIMIT 1");
                $shipStmt->execute([$shipId]);
                $ship = $shipStmt->fetch();

                if (!$ship) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Nave não encontrada ou desativada']);
                    exit;
                }

                $price = (float)$ship['rental_price_brl'];

                // Verificar saldo
                if ($balance < $price) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Saldo insuficiente. Necessário: R$ ' . number_format($price, 2, ',', '.')]);
                    exit;
                }

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
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => "Limite de $maxRentals aluguéis ativos atingido"]);
                    exit;
                }

                // Verificar se já alugou esta nave
                $alreadyRented = $pdo->prepare("SELECT COUNT(*) FROM exploration_rentals WHERE user_id = ? AND ship_id = ? AND status = 'active'");
                $alreadyRented->execute([$userId, $shipId]);
                if ((int)$alreadyRented->fetchColumn() > 0) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Você já tem esta nave alugada']);
                    exit;
                }

                // Deduzir saldo
                $pdo->prepare("UPDATE users SET balance_brl = balance_brl - ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$price, $userId]);

                // Criar aluguel
                $durationHours = (int)$ship['rental_duration_hours'];
                $pdo->prepare("
                    INSERT INTO exploration_rentals (user_id, google_uid, ship_id, ship_key, rental_price_brl, credits_per_day, started_at, expires_at, last_accumulation_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR), NOW())
                ")->execute([
                    $userId, $googleUid, $shipId, $ship['ship_key'],
                    $price, (int)$ship['credits_per_day'], $durationHours
                ]);

                $rentalId = (int)$pdo->lastInsertId();

                // Registrar transação
                $pdo->prepare("
                    INSERT INTO transactions (google_uid, type, amount, amount_brl, description, status, created_at)
                    VALUES (?, 'exploration_rent', ?, ?, ?, 'completed', NOW())
                ")->execute([
                    $googleUid, -$price, -$price,
                    "Aluguel: {$ship['name']} ({$durationHours}h)"
                ]);

                $pdo->commit();

                // Novo saldo
                $newBalance = $balance - $price;

                echo json_encode([
                    'success' => true,
                    'rental_id' => $rentalId,
                    'ship_name' => $ship['name'],
                    'duration_hours' => $durationHours,
                    'credits_per_day' => (int)$ship['credits_per_day'],
                    'price_paid' => $price,
                    'new_balance' => round($newBalance, 2),
                    'message' => "{$ship['name']} alugada com sucesso!"
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
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
                ORDER BY FIELD(r.status, 'active', 'expired', 'cancelled'), r.created_at DESC
                LIMIT 20
            ");
            $rentals->execute([$userId]);
            $rows = $rentals->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $r) {
                $startedAt = strtotime($r['started_at']);
                $expiresAt = strtotime($r['expires_at']);
                $now = time();

                // Calcular créditos acumulados em tempo real
                $effectiveEnd = min($now, $expiresAt);
                $elapsedSeconds = max(0, $effectiveEnd - $startedAt);
                $totalAccumulated = (int)floor(($elapsedSeconds / 86400) * (int)$r['credits_per_day']);
                $unclaimed = max(0, $totalAccumulated - (int)$r['credits_claimed']);

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
                    'is_expired' => $now >= $expiresAt
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
