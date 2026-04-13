<?php
// ============================================
// UNOBIX - Anúncios por Recompensa
// api/ad-rewards.php v1.0
// Assistir anúncios para ganhar créditos
// 500 visualizações = 1 crédito
// ============================================

require_once __DIR__ . "/config.php";

setCorsHeaders();

$input = getRequestInput();
$action = trim($input['action'] ?? $_GET['action'] ?? '');

// ============================================
// STATUS - Retorna progresso do usuário
// ============================================
if ($action === 'status') {
    $googleUid = trim($input['google_uid'] ?? $_GET['google_uid'] ?? '');

    if (!$googleUid || !validateGoogleUid($googleUid)) {
        echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
        exit;
    }

    try {
        $pdo = getDatabaseConnection();

        // Buscar dados do usuário
        $stmt = $pdo->prepare("SELECT id, credits, ad_views_progress, ad_views_total FROM users WHERE google_uid = ?");
        $stmt->execute([$googleUid]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
            exit;
        }

        $progress = (int)($user['ad_views_progress'] ?? 0);
        $total = (int)($user['ad_views_total'] ?? 0);
        $credits = (int)($user['credits'] ?? 0);
        $viewsNeeded = 500;

        // Views de hoje
        $stmtToday = $pdo->prepare("
            SELECT COUNT(*) FROM ad_reward_log
            WHERE user_id = ? AND created_at >= CURDATE()
        ");
        $stmtToday->execute([$user['id']]);
        $todayViews = (int)$stmtToday->fetchColumn();

        // Créditos ganhos com anúncios (total)
        $creditsFromAds = floor($total / $viewsNeeded);

        echo json_encode([
            'success'        => true,
            'credits'        => $credits,
            'progress'       => $progress,
            'views_needed'   => $viewsNeeded,
            'views_remaining'=> max(0, $viewsNeeded - $progress),
            'views_total'    => $total,
            'views_today'    => $todayViews,
            'credits_from_ads' => $creditsFromAds,
            'percentage'     => round(($progress / $viewsNeeded) * 100, 1)
        ]);

    } catch (Throwable $e) {
        error_log("ad-rewards status error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
    }
    exit;
}

// ============================================
// VIEW_COMPLETE - Registra visualização completa
// ============================================
if ($action === 'view_complete') {
    $googleUid = trim($input['google_uid'] ?? '');
    $slotId = (int)($input['slot_id'] ?? 0);

    if (!$googleUid || !validateGoogleUid($googleUid)) {
        echo json_encode(['success' => false, 'error' => 'google_uid inválido']);
        exit;
    }

    try {
        $pdo = getDatabaseConnection();

        // Buscar usuário com lock
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, credits, ad_views_progress, ad_views_total FROM users WHERE google_uid = ? FOR UPDATE");
        $stmt->execute([$googleUid]);
        $user = $stmt->fetch();

        if (!$user) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Usuário não encontrado']);
            exit;
        }

        // Anti-abuse: verificar tempo mínimo entre visualizações (8s de margem - timer é 10s)
        $stmtLast = $pdo->prepare("
            SELECT created_at FROM ad_reward_log
            WHERE user_id = ? ORDER BY created_at DESC LIMIT 1
        ");
        $stmtLast->execute([$user['id']]);
        $lastView = $stmtLast->fetchColumn();

        if ($lastView) {
            $elapsed = time() - strtotime($lastView);
            if ($elapsed < 8) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Aguarde entre visualizações', 'wait' => 8 - $elapsed]);
                exit;
            }
        }

        // Rate limit: máximo 200 views por hora (proteção contra automação)
        $stmtHour = $pdo->prepare("
            SELECT COUNT(*) FROM ad_reward_log
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmtHour->execute([$user['id']]);
        $hourlyViews = (int)$stmtHour->fetchColumn();

        if ($hourlyViews >= 200) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Limite de visualizações por hora atingido. Tente novamente em breve.']);
            exit;
        }

        // Incrementar contadores
        $progress = (int)($user['ad_views_progress'] ?? 0) + 1;
        $totalViews = (int)($user['ad_views_total'] ?? 0) + 1;
        $creditAwarded = false;
        $viewsNeeded = 500;

        if ($progress >= $viewsNeeded) {
            // Ganhou 1 crédito!
            $progress = $progress - $viewsNeeded; // Mantém excedente (normalmente 0)
            $pdo->prepare("UPDATE users SET credits = credits + 1, ad_views_progress = ?, ad_views_total = ? WHERE id = ?")
                ->execute([$progress, $totalViews, $user['id']]);
            $creditAwarded = true;

            // Registrar transação
            $pdo->prepare("
                INSERT INTO transactions (google_uid, type, amount_brl, description, status, created_at)
                VALUES (?, 'ad_reward', 0, '1 crédito ganho por assistir 500 anúncios', 'completed', NOW())
            ")->execute([$googleUid]);

            secureLog("AD_REWARD_CREDIT | UID: $googleUid | total_views: $totalViews");
        } else {
            $pdo->prepare("UPDATE users SET ad_views_progress = ?, ad_views_total = ? WHERE id = ?")
                ->execute([$progress, $totalViews, $user['id']]);
        }

        // Registrar no log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare("
            INSERT INTO ad_reward_log (user_id, google_uid, slot_id, ip_address, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$user['id'], $googleUid, $slotId ?: null, $ip]);

        $pdo->commit();

        // Buscar saldo atualizado
        $stmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $newCredits = (int)$stmt->fetchColumn();

        echo json_encode([
            'success'         => true,
            'credit_awarded'  => $creditAwarded,
            'progress'        => $progress,
            'views_needed'    => $viewsNeeded,
            'views_remaining' => max(0, $viewsNeeded - $progress),
            'views_total'     => $totalViews,
            'credits'         => $newCredits,
            'percentage'      => round(($progress / $viewsNeeded) * 100, 1)
        ]);

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("ad-rewards view_complete error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erro no servidor']);
    }
    exit;
}

// ============================================
// GET_SLOTS - Retorna slots de anúncio ativos
// ============================================
if ($action === 'get_slots') {
    try {
        $pdo = getDatabaseConnection();

        $stmt = $pdo->query("
            SELECT id, slot_name, slot_type, script_code, custom_js, custom_css,
                   width, height, provider, display_order
            FROM ad_slots
            WHERE is_active = 1
            ORDER BY display_order ASC
        ");
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'slots' => $slots]);

    } catch (Throwable $e) {
        // Se a tabela não existe, retorna slots vazios
        echo json_encode(['success' => true, 'slots' => []]);
    }
    exit;
}

// Ação inválida
echo json_encode(['success' => false, 'error' => 'Ação inválida. Use: status, view_complete, get_slots']);
