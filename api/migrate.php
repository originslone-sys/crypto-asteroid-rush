<?php
// ============================================
// UNOBIX - Script de Migração de Banco de Dados
// api/migrate.php
// Roda uma vez no deploy para garantir que todas
// as tabelas e índices existam.
//
// Uso: php api/migrate.php
// Ou via HTTP: GET /api/migrate.php?token=SEU_TOKEN
// ============================================

require_once __DIR__ . "/config.php";

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    // Proteger endpoint: exigir token
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if ($token !== RECONCILE_CRON_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

function output($msg) {
    global $isCli;
    if ($isCli) {
        echo $msg . "\n";
    }
}

$results = ['success' => true, 'migrations' => [], 'errors' => []];

try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão com o banco");

    output("Conectado ao banco. Iniciando migrações...");

    // ============================================
    // TABELAS PRINCIPAIS
    // ============================================
    output("\n--- Tabelas principais ---");

    // zettpay_transactions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS zettpay_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            external_id VARCHAR(100) NOT NULL UNIQUE,
            zettpay_id VARCHAR(100) DEFAULT NULL,
            type ENUM('deposit', 'cashout') NOT NULL,
            amount_brl DECIMAL(15,2) NOT NULL,
            fee_brl DECIMAL(15,2) DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            pix_key VARCHAR(255) DEFAULT NULL,
            pix_key_type VARCHAR(20) DEFAULT NULL,
            qr_code TEXT DEFAULT NULL,
            pix_copy_paste TEXT DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            webhook_payload JSON DEFAULT NULL,
            withdrawal_id INT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_type_status (type, status),
            INDEX idx_withdrawal_id (withdrawal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'zettpay_transactions';
    output("  [OK] zettpay_transactions");

    // webhook_log (anti-replay)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webhook_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fingerprint VARCHAR(32) NOT NULL,
            external_id VARCHAR(100) NOT NULL,
            event VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_fingerprint (fingerprint),
            INDEX idx_external_id (external_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'webhook_log';
    output("  [OK] webhook_log");

    // rate_limits
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            google_uid VARCHAR(128) DEFAULT NULL,
            wallet_address VARCHAR(42) DEFAULT NULL,
            action_type VARCHAR(30) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip (ip_address),
            INDEX idx_google_uid (google_uid),
            INDEX idx_wallet (wallet_address),
            INDEX idx_action (action_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'rate_limits';
    output("  [OK] rate_limits");

    // ip_blacklist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ip_blacklist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            reason VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            INDEX idx_ip (ip_address),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'ip_blacklist';
    output("  [OK] ip_blacklist");

    // captcha_log
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS captcha_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT DEFAULT NULL,
            google_uid VARCHAR(128) DEFAULT NULL,
            wallet_address VARCHAR(64) DEFAULT NULL,
            captcha_type VARCHAR(20) DEFAULT 'recaptcha_v2',
            is_success TINYINT(1) DEFAULT 0,
            response_token VARCHAR(100) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id),
            INDEX idx_uid (google_uid),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'captcha_log';
    output("  [OK] captcha_log");

    // api_metrics
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS api_metrics (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            endpoint VARCHAR(50) NOT NULL,
            method VARCHAR(10) NOT NULL DEFAULT 'GET',
            response_time_ms DECIMAL(10,2) NOT NULL,
            status_code SMALLINT NOT NULL DEFAULT 200,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_endpoint (endpoint),
            INDEX idx_created (created_at),
            INDEX idx_endpoint_created (endpoint, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'api_metrics';
    output("  [OK] api_metrics");

    // ranking_cache
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ranking_cache (
            cache_key VARCHAR(50) NOT NULL PRIMARY KEY,
            cache_value MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'ranking_cache';
    output("  [OK] ranking_cache");

    // admin_login_log
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_login_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            region VARCHAR(100) DEFAULT NULL,
            country VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_ip (ip_address),
            INDEX idx_success (success)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'admin_login_log';
    output("  [OK] admin_login_log");

    // credit_packages
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
    $results['migrations'][] = 'credit_packages';
    output("  [OK] credit_packages");

    // credit_purchases
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
    $results['migrations'][] = 'credit_purchases';
    output("  [OK] credit_purchases");

    // premium_subscriptions
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
    $results['migrations'][] = 'premium_subscriptions';
    output("  [OK] premium_subscriptions");

    // saved_pix_keys
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saved_pix_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            pix_key VARCHAR(255) NOT NULL,
            pix_key_type VARCHAR(20) NOT NULL,
            label VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_pix_key_type (pix_key, pix_key_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'saved_pix_keys';
    output("  [OK] saved_pix_keys");

    // referral_codes
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS referral_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) NOT NULL,
            wallet_address VARCHAR(42) NOT NULL DEFAULT '',
            code VARCHAR(10) NOT NULL UNIQUE,
            uses_count INT NOT NULL DEFAULT 0,
            max_uses INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_google_uid (google_uid),
            INDEX idx_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'referral_codes';
    output("  [OK] referral_codes");

    // referrals
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS referrals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            referrer_google_uid VARCHAR(128) NOT NULL,
            referrer_wallet VARCHAR(42) NOT NULL DEFAULT '',
            referred_google_uid VARCHAR(128) NOT NULL,
            referred_wallet VARCHAR(42) NOT NULL DEFAULT '',
            referral_code VARCHAR(10) NOT NULL,
            missions_at_register INT NOT NULL DEFAULT 0,
            missions_completed INT NOT NULL DEFAULT 0,
            missions_required INT NOT NULL DEFAULT 20,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            commission_brl DECIMAL(10,6) NOT NULL DEFAULT 0,
            commission_paid_at DATETIME DEFAULT NULL,
            qualified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_referrer (referrer_google_uid),
            INDEX idx_referred (referred_google_uid),
            INDEX idx_status (status),
            INDEX idx_code (referral_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'referrals';
    output("  [OK] referrals");

    // suspicious_activity
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS suspicious_activity (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) DEFAULT NULL,
            wallet_address VARCHAR(42) DEFAULT NULL,
            session_id INT DEFAULT NULL,
            activity_type VARCHAR(50) NOT NULL,
            activity_data TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            screen_width INT DEFAULT NULL,
            screen_height INT DEFAULT NULL,
            devtools_detected TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_google_uid (google_uid),
            INDEX idx_wallet (wallet_address),
            INDEX idx_type (activity_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'suspicious_activity';
    output("  [OK] suspicious_activity");

    // support_tickets
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_tickets (
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
    $results['migrations'][] = 'support_tickets';
    output("  [OK] support_tickets");

    // support_messages
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            sender_type ENUM('user','admin') NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id),
            FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'support_messages';
    output("  [OK] support_messages");

    // notifications
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info','warning','success','promo') DEFAULT 'info',
            is_active TINYINT(1) DEFAULT 1,
            starts_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active),
            INDEX idx_dates (starts_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'notifications';
    output("  [OK] notifications");

    // ============================================
    // EXPLORAÇÃO DE NAVES
    // ============================================
    output("\n--- Exploração de Naves ---");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exploration_ships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ship_key VARCHAR(20) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            rental_price_brl DECIMAL(10,2) NOT NULL,
            rental_duration_hours INT NOT NULL DEFAULT 24,
            credits_per_day INT NOT NULL DEFAULT 10,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active),
            INDEX idx_ship_key (ship_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'exploration_ships';
    output("  [OK] exploration_ships");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exploration_rentals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            google_uid VARCHAR(128) NOT NULL,
            ship_id INT NOT NULL,
            ship_key VARCHAR(20) NOT NULL,
            rental_price_brl DECIMAL(10,2) NOT NULL,
            credits_per_day INT NOT NULL,
            status ENUM('pending_payment','active','expired','cancelled') NOT NULL DEFAULT 'active',
            external_id VARCHAR(100) DEFAULT NULL,
            credits_accumulated INT NOT NULL DEFAULT 0,
            credits_claimed INT NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            last_accumulation_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            claimed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_google_uid (google_uid),
            INDEX idx_status (status),
            INDEX idx_user_status (user_id, status),
            INDEX idx_expires (expires_at),
            INDEX idx_external_id (external_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'exploration_rentals';
    output("  [OK] exploration_rentals");

    // Adicionar coluna external_id em exploration_rentals
    try {
        $col = $pdo->query("SHOW COLUMNS FROM exploration_rentals LIKE 'external_id'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE exploration_rentals ADD COLUMN external_id VARCHAR(100) DEFAULT NULL AFTER status");
            try { $pdo->exec("ALTER TABLE exploration_rentals ADD INDEX idx_external_id (external_id)"); } catch (Exception $e2) {}
            $results['migrations'][] = 'exploration_rentals.external_id';
            output("  [OK] exploration_rentals.external_id adicionada");
        }
    } catch (Exception $e) {
        $results['errors'][] = 'exploration_rentals.external_id: ' . $e->getMessage();
    }

    // Atualizar ENUM de status para incluir pending_payment
    try {
        $colInfo = $pdo->query("SHOW COLUMNS FROM exploration_rentals LIKE 'status'")->fetch();
        if ($colInfo && strpos($colInfo['Type'], 'pending_payment') === false) {
            $pdo->exec("ALTER TABLE exploration_rentals MODIFY COLUMN status ENUM('pending_payment','active','expired','cancelled') NOT NULL DEFAULT 'active'");
            $results['migrations'][] = 'exploration_rentals.status_enum';
            output("  [OK] exploration_rentals.status atualizado com pending_payment");
        }
    } catch (Exception $e) {
        $results['errors'][] = 'exploration_rentals.status_enum: ' . $e->getMessage();
    }

    // ============================================
    // COLUNAS EXTRAS EM TABELAS EXISTENTES
    // ============================================
    output("\n--- Colunas extras ---");

    // --- users ---
    $userCols = [
        'credits'                    => "ALTER TABLE users ADD COLUMN credits INT NOT NULL DEFAULT 0",
        'is_premium'                 => "ALTER TABLE users ADD COLUMN is_premium TINYINT(1) NOT NULL DEFAULT 0",
        'premium_expires_at'         => "ALTER TABLE users ADD COLUMN premium_expires_at DATETIME DEFAULT NULL",
        'premium_credits_claimed_at' => "ALTER TABLE users ADD COLUMN premium_credits_claimed_at DATETIME DEFAULT NULL",
        'registration_ip'            => "ALTER TABLE users ADD COLUMN registration_ip VARCHAR(45) DEFAULT NULL",
        'last_login_ip'              => "ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) DEFAULT NULL",
        'last_login'                 => "ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL",
        'is_banned'                  => "ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0",
        'ban_reason'                 => "ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) DEFAULT NULL",
        'total_withdrawn_brl'        => "ALTER TABLE users ADD COLUMN total_withdrawn_brl DECIMAL(15,6) NOT NULL DEFAULT 0",
        'staked_balance_brl'         => "ALTER TABLE users ADD COLUMN staked_balance_brl DECIMAL(15,6) NOT NULL DEFAULT 0",
        'whatsapp'                   => "ALTER TABLE users ADD COLUMN whatsapp VARCHAR(20) DEFAULT NULL",
        'withdrawal_limit'           => "ALTER TABLE users ADD COLUMN withdrawal_limit INT DEFAULT NULL COMMENT 'Override global max_withdrawal_requests per user (NULL = use global)'",
    ];
    foreach ($userCols as $col => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM users LIKE '{$col}'")->fetch();
        if (!$exists) {
            $pdo->exec($sql);
            $results['migrations'][] = "users.{$col}";
            output("  [OK] users.{$col} adicionada");
        }
    }

    // --- transactions ---
    try {
        $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'amount_brl'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN amount_brl DECIMAL(15,2) DEFAULT 0 AFTER amount");
            $results['migrations'][] = 'transactions.amount_brl';
            output("  [OK] transactions.amount_brl adicionada");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // transactions.type → VARCHAR(30) (converter de ENUM se necessário)
    try {
        $colInfo = $pdo->query("SHOW COLUMNS FROM transactions WHERE Field = 'type'")->fetch();
        if ($colInfo && stripos($colInfo['Type'], 'enum') !== false) {
            $pdo->exec("ALTER TABLE transactions MODIFY COLUMN type VARCHAR(30) NOT NULL");
            $results['migrations'][] = 'transactions.type→varchar';
            output("  [OK] transactions.type convertida para VARCHAR(30)");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // --- withdrawals ---
    try {
        $col = $pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'zettpay_external_id'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE withdrawals ADD COLUMN zettpay_external_id VARCHAR(100) DEFAULT NULL");
            $pdo->exec("ALTER TABLE withdrawals ADD COLUMN zettpay_status VARCHAR(30) DEFAULT NULL");
            $pdo->exec("ALTER TABLE withdrawals ADD INDEX idx_zettpay_ext_id (zettpay_external_id)");
            $results['migrations'][] = 'withdrawals.zettpay_columns';
            output("  [OK] withdrawals: colunas ZettPay adicionadas");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // withdrawals: adicionar under_review ao ENUM de status
    try {
        $colInfo = $pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'status'")->fetch();
        if ($colInfo && strpos($colInfo['Type'], 'under_review') === false) {
            $pdo->exec("ALTER TABLE withdrawals MODIFY COLUMN status ENUM('pending','processing','under_review','completed','rejected','failed') NOT NULL DEFAULT 'pending'");
            $results['migrations'][] = 'withdrawals.status_enum_under_review';
            output("  [OK] withdrawals.status: under_review adicionado ao ENUM");
        }
    } catch (Exception $e) {
        $results['errors'][] = 'withdrawals.status_enum: ' . $e->getMessage();
    }

    // --- game_sessions ---
    try {
        $col = $pdo->query("SHOW COLUMNS FROM game_sessions LIKE 'total_spawned'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE game_sessions ADD COLUMN total_spawned INT NOT NULL DEFAULT 0 AFTER legendary_asteroids");
            $results['migrations'][] = 'game_sessions.total_spawned';
            output("  [OK] game_sessions.total_spawned adicionada");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // --- referrals: colunas extras ---
    try {
        $col = $pdo->query("SHOW COLUMNS FROM referrals LIKE 'commission_paid_at'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE referrals ADD COLUMN commission_paid_at DATETIME DEFAULT NULL");
            $results['migrations'][] = 'referrals.commission_paid_at';
            output("  [OK] referrals.commission_paid_at adicionada");
        }
        $col = $pdo->query("SHOW COLUMNS FROM referrals LIKE 'qualified_at'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE referrals ADD COLUMN qualified_at DATETIME DEFAULT NULL");
            $results['migrations'][] = 'referrals.qualified_at';
            output("  [OK] referrals.qualified_at adicionada");
        }
        // Garantir que status é VARCHAR (não ENUM) para aceitar todos os valores
        $colInfo = $pdo->query("SHOW COLUMNS FROM referrals WHERE Field = 'status'")->fetch();
        if ($colInfo && stripos($colInfo['Type'], 'enum') !== false) {
            $pdo->exec("ALTER TABLE referrals MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'");
            $results['migrations'][] = 'referrals.status→varchar';
            output("  [OK] referrals.status convertida para VARCHAR(20)");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // --- suspicious_activity: coluna google_uid ---
    try {
        $col = $pdo->query("SHOW COLUMNS FROM suspicious_activity LIKE 'google_uid'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE suspicious_activity ADD COLUMN google_uid VARCHAR(128) DEFAULT NULL");
            $results['migrations'][] = 'suspicious_activity.google_uid';
            output("  [OK] suspicious_activity.google_uid adicionada");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // --- users: colunas de recompensa por anúncios ---
    try {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'ad_views_progress'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE users ADD COLUMN ad_views_progress INT NOT NULL DEFAULT 0");
            $results['migrations'][] = 'users.ad_views_progress';
            output("  [OK] users.ad_views_progress adicionada");
        }
        $col2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'ad_views_total'")->fetch();
        if (!$col2) {
            $pdo->exec("ALTER TABLE users ADD COLUMN ad_views_total INT NOT NULL DEFAULT 0");
            $results['migrations'][] = 'users.ad_views_total';
            output("  [OK] users.ad_views_total adicionada");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // --- ad_reward_log: log de visualizações de anúncios para créditos ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ad_reward_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            google_uid VARCHAR(128) NOT NULL,
            slot_id INT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_google_uid (google_uid),
            INDEX idx_created_at (created_at),
            INDEX idx_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'ad_reward_log';
    output("  [OK] ad_reward_log");

    // ============================================
    // ÍNDICES CRÍTICOS
    // ============================================
    output("\n--- Índices ---");

    $indexes = [
        ["users",              "ALTER TABLE users ADD UNIQUE INDEX idx_google_uid (google_uid)"],
        ["game_sessions",      "ALTER TABLE game_sessions ADD INDEX idx_status_started_uid (status, started_at, google_uid)"],
        ["game_sessions",      "ALTER TABLE game_sessions ADD INDEX idx_google_uid_status (google_uid, status)"],
        ["game_sessions",      "ALTER TABLE game_sessions ADD INDEX idx_ranking (status, started_at, google_uid, asteroids_destroyed)"],
        ["transactions",       "ALTER TABLE transactions ADD INDEX idx_uid_created (google_uid, created_at DESC)"],
        ["transactions",       "ALTER TABLE transactions ADD INDEX idx_uid_type_created (google_uid, type, created_at DESC)"],
        ["zettpay_transactions","ALTER TABLE zettpay_transactions ADD INDEX idx_ext_user (external_id, user_id)"],
        ["saved_pix_keys",     "ALTER TABLE saved_pix_keys ADD INDEX idx_pix_key_lookup (pix_key, pix_key_type)"],
    ];

    foreach ($indexes as [$table, $sql]) {
        try {
            $pdo->exec($sql);
            $results['migrations'][] = "index:{$table}";
            output("  [OK] Índice em {$table}");
        } catch (Exception $e) {
            // Índice já existe — ok
        }
    }

    // ============================================
    // DADOS PADRÃO
    // ============================================
    output("\n--- Dados padrão ---");

    // Pacotes de créditos padrão
    $count = $pdo->query("SELECT COUNT(*) FROM credit_packages")->fetchColumn();
    if ($count == 0) {
        $pdo->exec("
            INSERT INTO credit_packages (name, credits, bonus_credits, price_brl, is_featured) VALUES
            ('Iniciante',  5,   0,  1.00, 0),
            ('Explorador', 15,  2,  2.50, 0),
            ('Comandante', 30,  5,  4.50, 1),
            ('Elite',      60,  15, 8.00, 0),
            ('Lendário',   120, 40, 14.00, 0)
        ");
        $results['migrations'][] = 'credit_packages:defaults';
        output("  [OK] Pacotes de créditos padrão inseridos");
    }

    // Naves padrão
    try {
        $shipCount = (int)$pdo->query("SELECT COUNT(*) FROM exploration_ships")->fetchColumn();
        if ($shipCount === 0) {
            $pdo->exec("
                INSERT INTO exploration_ships (ship_key, name, description, rental_price_brl, rental_duration_hours, credits_per_day, is_active, sort_order) VALUES
                ('PHOENIX',  'Phoenix Crimson',  'Explorador equilibrado com boa coleta',    2.00, 24, 5,  1, 1),
                ('GUARDIAN', 'Forest Guardian',  'Casco resistente para missões longas',     3.00, 48, 8,  1, 2),
                ('THUNDER',  'Thunder Strike',   'Coletor de alta velocidade',               5.00, 72, 12, 1, 3),
                ('INFERNO',  'Inferno Blaze',    'Motores potentes aceleram a coleta',       4.00, 48, 10, 1, 4),
                ('NEBULA',   'Nebula Phantom',   'Explorador ágil e eficiente',              6.00, 72, 15, 1, 5),
                ('VIPER',    'Toxic Viper',      'Serpente ágil coletora',                   3.50, 48, 9,  1, 6),
                ('WOLF',     'Steel Wolf',       'Coletor pesado de assalto',                8.00, 96, 20, 1, 7)
            ");
            $results['migrations'][] = 'exploration_ships:seed';
            output("  [OK] Naves padrão inseridas (7)");
        }
    } catch (Exception $e) {}

    // Configurações padrão do game_settings
    try {
        $defaults = [
            ['premium_price_brl',              '19.90', 1],
            ['premium_duration_days',          '30',    1],
            ['premium_enabled',                'true',  1],
            ['block_multiple_ip_accounts',     '1',     0],
            ['referral_missions_required',     '20',    0],
            ['referral_bonus_brl',             '5.00',  0],
            ['exploration_enabled',            'true',  0],
            ['exploration_max_rentals_per_user','3',    0],
            ['withdrawals_enabled',            'true',  0],
            ['registrations_enabled',          'true',  0],
            ['credits_purchase_enabled',       'true',  0],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, ?, NOW())");
        foreach ($defaults as $d) {
            $ins->execute($d);
        }
        $results['migrations'][] = 'game_settings:defaults';
        output("  [OK] Configurações padrão inseridas/ignoradas");
    } catch (Exception $e) { /* game_settings pode não existir */ }

    // Data de referência para contagem de saldo/créditos no dashboard
    try {
        $hasRef = $pdo->query("SELECT COUNT(*) FROM game_settings WHERE setting_key = 'stats_reference_date'")->fetchColumn();
        if (!$hasRef) {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES ('stats_reference_date', ?, 0, NOW())")
                ->execute([$now]);
            $results['migrations'][] = 'stats_reference_date:initial';
            output("  [OK] Data de referência para dashboard salva: {$now}");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // ============================================
    // PVP - TABELAS E COLUNAS
    // ============================================
    output("\n--- PvP: Tabelas e colunas ---");

    // Tabela principal de partidas PvP
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pvp_matches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            match_id VARCHAR(64) NOT NULL UNIQUE,
            player1_google_uid VARCHAR(128) NOT NULL,
            player2_google_uid VARCHAR(128) NOT NULL,
            winner_google_uid VARCHAR(128) DEFAULT NULL,
            status ENUM('waiting','countdown','active','completed','cancelled') NOT NULL DEFAULT 'waiting',
            entry_fee_credits INT NOT NULL DEFAULT 2,
            winner_prize_credits INT NOT NULL DEFAULT 3,
            player1_lives TINYINT NOT NULL DEFAULT 6,
            player2_lives TINYINT NOT NULL DEFAULT 6,
            player1_asteroids_destroyed INT NOT NULL DEFAULT 0,
            player2_asteroids_destroyed INT NOT NULL DEFAULT 0,
            player1_shots_fired INT NOT NULL DEFAULT 0,
            player2_shots_fired INT NOT NULL DEFAULT 0,
            player1_hits INT NOT NULL DEFAULT 0,
            player2_hits INT NOT NULL DEFAULT 0,
            win_condition VARCHAR(30) DEFAULT NULL COMMENT 'elimination, time_lives, time_asteroids, disconnect, draw',
            game_duration INT DEFAULT NULL COMMENT 'Duracao real da partida em segundos',
            started_at DATETIME DEFAULT NULL,
            ended_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_player1 (player1_google_uid),
            INDEX idx_player2 (player2_google_uid),
            INDEX idx_winner (winner_google_uid),
            INDEX idx_status (status),
            INDEX idx_created (created_at),
            INDEX idx_pvp_ranking (status, ended_at, winner_google_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'pvp_matches:create';
    output("  [OK] Tabela pvp_matches criada/verificada");

    // Tabela de ranking PvP semanal (cache)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pvp_ranking_cache (
            cache_key VARCHAR(50) NOT NULL PRIMARY KEY,
            cache_value MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'pvp_ranking_cache:create';
    output("  [OK] Tabela pvp_ranking_cache criada/verificada");

    // Adicionar game_type na tabela game_sessions (separar single-player de pvp)
    try {
        $col = $pdo->query("SHOW COLUMNS FROM game_sessions LIKE 'game_type'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE game_sessions ADD COLUMN game_type VARCHAR(20) NOT NULL DEFAULT 'singleplayer' AFTER google_uid");
            $pdo->exec("ALTER TABLE game_sessions ADD COLUMN pvp_match_id VARCHAR(64) DEFAULT NULL AFTER game_type");
            $pdo->exec("ALTER TABLE game_sessions ADD INDEX idx_game_type (game_type)");
            $pdo->exec("ALTER TABLE game_sessions ADD INDEX idx_pvp_match_id (pvp_match_id)");
            $results['migrations'][] = 'game_sessions.game_type+pvp_match_id';
            output("  [OK] game_sessions: colunas game_type e pvp_match_id adicionadas");
        }
    } catch (Exception $e) { /* já existe */ }

    // ============================================
    // CAMPAIGN - TABELAS E COLUNAS
    // ============================================
    output("\n--- Campaign: Tabelas e colunas ---");

    // users.total_xp (XP total acumulado na campanha)
    try {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'total_xp'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE users ADD COLUMN total_xp INT NOT NULL DEFAULT 0 AFTER credits");
            $pdo->exec("ALTER TABLE users ADD INDEX idx_total_xp (total_xp)");
            $results['migrations'][] = 'users.total_xp';
            output("  [OK] users: coluna total_xp adicionada");
        }
    } catch (Exception $e) { /* já existe */ }

    // campaign_progress (1 linha por jogador, estado global na campanha)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_progress (
            google_uid VARCHAR(128) NOT NULL PRIMARY KEY,
            current_level INT NOT NULL DEFAULT 1,
            total_xp INT NOT NULL DEFAULT 0,
            current_lives TINYINT NOT NULL DEFAULT 5,
            next_life_at DATETIME DEFAULT NULL,
            streak_count INT NOT NULL DEFAULT 0,
            daily_brl_earned DECIMAL(10,2) NOT NULL DEFAULT 0,
            daily_brl_reset_at DATETIME DEFAULT NULL,
            total_stars INT NOT NULL DEFAULT 0,
            equipped_skin_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_current_level (current_level),
            INDEX idx_total_xp (total_xp),
            INDEX idx_total_stars (total_stars)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_progress:create';
    output("  [OK] Tabela campaign_progress criada/verificada");

    // campaign_stage_progress (1 linha por jogador x fase)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_stage_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) NOT NULL,
            stage_id VARCHAR(20) NOT NULL,
            stars TINYINT NOT NULL DEFAULT 0,
            best_time INT DEFAULT NULL COMMENT 'Em segundos',
            attempts INT NOT NULL DEFAULT 0,
            wins INT NOT NULL DEFAULT 0,
            losses INT NOT NULL DEFAULT 0,
            total_brl_earned DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_combo INT NOT NULL DEFAULT 0,
            total_enemies_destroyed INT NOT NULL DEFAULT 0,
            last_played_at DATETIME DEFAULT NULL,
            first_completed_at DATETIME DEFAULT NULL,
            UNIQUE KEY idx_user_stage (google_uid, stage_id),
            INDEX idx_google_uid (google_uid),
            INDEX idx_stage_id (stage_id),
            INDEX idx_stars (stars)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_stage_progress:create';
    output("  [OK] Tabela campaign_stage_progress criada/verificada");

    // campaign_session (sessões em andamento ou recentes)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_session (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_token VARCHAR(64) NOT NULL UNIQUE,
            google_uid VARCHAR(128) NOT NULL,
            stage_id VARCHAR(20) NOT NULL,
            status ENUM('active','completed','abandoned','review') NOT NULL DEFAULT 'active',
            seed VARCHAR(32) NOT NULL,
            credits_spent INT NOT NULL DEFAULT 0,
            booster_applied VARCHAR(50) DEFAULT NULL,
            damage_taken INT NOT NULL DEFAULT 0,
            enemies_destroyed INT NOT NULL DEFAULT 0,
            max_combo INT NOT NULL DEFAULT 0,
            stars_awarded TINYINT DEFAULT NULL,
            xp_awarded INT DEFAULT NULL,
            brl_awarded DECIMAL(10,2) DEFAULT NULL,
            time_elapsed INT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME DEFAULT NULL,
            INDEX idx_google_uid (google_uid),
            INDEX idx_status (status),
            INDEX idx_stage_id (stage_id),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_session:create';
    output("  [OK] Tabela campaign_session criada/verificada");

    // campaign_stages (definição estática das fases — editável no admin)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_stages (
            stage_id VARCHAR(20) NOT NULL PRIMARY KEY COMMENT 'training, s1f1, s1f2, ..., s2f5',
            sector TINYINT NOT NULL,
            order_in_sector TINYINT NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            duration_seconds INT NOT NULL DEFAULT 60,
            credit_cost INT NOT NULL DEFAULT 1,
            min_level INT NOT NULL DEFAULT 1,
            xp_reward INT NOT NULL DEFAULT 80,
            brl_base DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_boss BOOLEAN NOT NULL DEFAULT FALSE,
            boss_id INT DEFAULT NULL,
            waves_json JSON DEFAULT NULL COMMENT 'Configuração das ondas de inimigos',
            is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sector (sector),
            INDEX idx_min_level (min_level),
            INDEX idx_is_enabled (is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_stages:create';
    output("  [OK] Tabela campaign_stages criada/verificada");

    // campaign_xp_table (curva de XP por nível, 1-30)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_xp_table (
            level INT NOT NULL PRIMARY KEY,
            xp_required INT NOT NULL COMMENT 'XP cumulativo total para atingir esse nível'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_xp_table:create';
    output("  [OK] Tabela campaign_xp_table criada/verificada");

    // campaign_settings (chave-valor para configs gerais)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            value_type ENUM('int','decimal','string','bool','json') NOT NULL DEFAULT 'string',
            description TEXT DEFAULT NULL,
            is_public BOOLEAN NOT NULL DEFAULT FALSE,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_settings:create';
    output("  [OK] Tabela campaign_settings criada/verificada");

    // campaign_tutorial_seen (flags por jogador para tooltips/cinemáticas vistas)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_tutorial_seen (
            id INT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) NOT NULL,
            tutorial_key VARCHAR(80) NOT NULL COMMENT 'welcome, tooltip_first_life_lost, cinematic_sector2, etc',
            seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_user_tutorial (google_uid, tutorial_key),
            INDEX idx_google_uid (google_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_tutorial_seen:create';
    output("  [OK] Tabela campaign_tutorial_seen criada/verificada");

    // campaign_bosses (definição dos bosses, CRUD pelo admin)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_bosses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            sector TINYINT NOT NULL,
            sprite_key VARCHAR(80) NOT NULL,
            hp_total INT NOT NULL,
            scale DECIMAL(4,2) NOT NULL DEFAULT 1.00,
            speed DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            attack_patterns_json JSON DEFAULT NULL COMMENT 'Padrões por fase de HP (100-50, 50-25, <25)',
            phase_thresholds_json JSON DEFAULT NULL COMMENT 'Limiares de transição (default [50, 25])',
            extra_brl DECIMAL(10,2) NOT NULL DEFAULT 0,
            extra_xp INT NOT NULL DEFAULT 0,
            guaranteed_drops_json JSON DEFAULT NULL COMMENT 'Lista de power-ups dropados',
            skin_drop_chance DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Chance de drop de skin (0-100%)',
            skin_drop_id INT DEFAULT NULL,
            berserk_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            hp_persists_on_continue BOOLEAN NOT NULL DEFAULT TRUE,
            is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sector (sector),
            INDEX idx_is_enabled (is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_bosses:create';
    output("  [OK] Tabela campaign_bosses criada/verificada");

    // campaign_skins (skins de nave, cosméticas)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_skins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            skin_key VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            sprite_path VARCHAR(255) NOT NULL,
            credit_cost INT NOT NULL DEFAULT 0,
            is_purchasable BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'false = drop apenas (ex: lendária)',
            is_default BOOLEAN NOT NULL DEFAULT FALSE,
            is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_enabled (is_enabled),
            INDEX idx_sort_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_skins:create';
    output("  [OK] Tabela campaign_skins criada/verificada");

    // campaign_player_skins (relação user x skin desbloqueada)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_player_skins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) NOT NULL,
            skin_id INT NOT NULL,
            obtained_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            obtained_via ENUM('purchase','drop','grant') NOT NULL DEFAULT 'purchase',
            UNIQUE KEY idx_user_skin (google_uid, skin_id),
            INDEX idx_google_uid (google_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_player_skins:create';
    output("  [OK] Tabela campaign_player_skins criada/verificada");

    // campaign_missions_daily (templates de missões diárias)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_missions_daily (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mission_key VARCHAR(80) NOT NULL UNIQUE,
            title VARCHAR(150) NOT NULL,
            description TEXT NOT NULL,
            condition_type ENUM('complete_stages','destroy_enemies','earn_xp','three_stars','defeat_miniboss') NOT NULL,
            condition_value INT NOT NULL,
            reward_type ENUM('brl','lives','brl_and_lives') NOT NULL,
            reward_brl DECIMAL(10,2) NOT NULL DEFAULT 0,
            reward_lives TINYINT NOT NULL DEFAULT 0,
            weight INT NOT NULL DEFAULT 1 COMMENT 'Peso para sorteio aleatório',
            is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_enabled (is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_missions_daily:create';
    output("  [OK] Tabela campaign_missions_daily criada/verificada");

    // campaign_missions_weekly (templates de missões semanais)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_missions_weekly (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mission_key VARCHAR(80) NOT NULL UNIQUE,
            title VARCHAR(150) NOT NULL,
            description TEXT NOT NULL,
            condition_type ENUM('complete_stages','three_stars','defeat_boss','reach_level','earn_xp') NOT NULL,
            condition_value INT NOT NULL,
            condition_extra VARCHAR(100) DEFAULT NULL COMMENT 'Ex: stage_id específico para defeat_boss',
            reward_type ENUM('brl','lives','brl_and_lives') NOT NULL,
            reward_brl DECIMAL(10,2) NOT NULL DEFAULT 0,
            reward_lives TINYINT NOT NULL DEFAULT 0,
            is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_enabled (is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_missions_weekly:create';
    output("  [OK] Tabela campaign_missions_weekly criada/verificada");

    // campaign_player_missions (progresso de missões por jogador)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_player_missions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) NOT NULL,
            mission_type ENUM('daily','weekly') NOT NULL,
            mission_id INT NOT NULL,
            progress INT NOT NULL DEFAULT 0,
            target INT NOT NULL,
            is_completed BOOLEAN NOT NULL DEFAULT FALSE,
            is_claimed BOOLEAN NOT NULL DEFAULT FALSE,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            claimed_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY idx_user_mission_period (google_uid, mission_type, mission_id, expires_at),
            INDEX idx_google_uid (google_uid),
            INDEX idx_expires_at (expires_at),
            INDEX idx_is_claimed (is_claimed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_player_missions:create';
    output("  [OK] Tabela campaign_player_missions criada/verificada");

    // campaign_streak (login diário consecutivo)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_streak (
            google_uid VARCHAR(128) NOT NULL PRIMARY KEY,
            current_day INT NOT NULL DEFAULT 1,
            last_claim_date DATE DEFAULT NULL,
            total_days_claimed INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_last_claim_date (last_claim_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_streak:create';
    output("  [OK] Tabela campaign_streak criada/verificada");

    // campaign_events (eventos especiais com calendário)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            description TEXT DEFAULT NULL,
            event_type ENUM('brl_multiplier','xp_multiplier','unlimited_lives','triple_star_bonus') NOT NULL,
            multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00,
            scope ENUM('all_stages','sector','single_stage','bosses_only') NOT NULL DEFAULT 'all_stages',
            scope_value VARCHAR(50) DEFAULT NULL COMMENT 'sector # ou stage_id quando aplicável',
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active_window (is_enabled, starts_at, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_events:create';
    output("  [OK] Tabela campaign_events criada/verificada");

    // campaign_achievements (conquistas permanentes)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_achievements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            achievement_key VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            description TEXT NOT NULL,
            icon VARCHAR(80) DEFAULT NULL,
            condition_type VARCHAR(50) NOT NULL COMMENT 'first_win, first_3stars, destroy_x_enemies, etc',
            condition_value INT DEFAULT NULL,
            condition_extra VARCHAR(100) DEFAULT NULL,
            reward_brl DECIMAL(10,2) NOT NULL DEFAULT 0,
            reward_lives TINYINT NOT NULL DEFAULT 0,
            is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_enabled (is_enabled),
            INDEX idx_sort_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_achievements:create';
    output("  [OK] Tabela campaign_achievements criada/verificada");

    // campaign_player_achievements (conquistas desbloqueadas por jogador)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campaign_player_achievements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            google_uid VARCHAR(128) NOT NULL,
            achievement_id INT NOT NULL,
            unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_claimed BOOLEAN NOT NULL DEFAULT FALSE,
            claimed_at DATETIME DEFAULT NULL,
            UNIQUE KEY idx_user_achievement (google_uid, achievement_id),
            INDEX idx_google_uid (google_uid),
            INDEX idx_is_claimed (is_claimed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'campaign_player_achievements:create';
    output("  [OK] Tabela campaign_player_achievements criada/verificada");

    // Configurações padrão da campanha (defaults inseridos uma vez)
    try {
        $defaults = [
            // Vidas
            ['campaign.lives.max',                  '5',     'int',     0],
            ['campaign.lives.recharge_minutes',     '30',    'int',     0],
            ['campaign.lives.consume_on',           'fail',  'string',  0],
            ['campaign.lives.cost_single',          '1',     'int',     0],
            ['campaign.lives.cost_pack5',           '4',     'int',     0],
            ['campaign.lives.cost_refill',          '3',     'int',     0],
            ['campaign.lives.unlimited_event',      'false', 'bool',    0],
            // Recompensas
            ['campaign.rewards.star1_multiplier',   '1.00',  'decimal', 0],
            ['campaign.rewards.star2_multiplier',   '1.25',  'decimal', 0],
            ['campaign.rewards.star3_multiplier',   '1.50',  'decimal', 0],
            ['campaign.rewards.star2_max_dmg_pct',  '50',    'int',     0],
            ['campaign.rewards.replay_policy',      'diff',  'string',  0],
            ['campaign.rewards.streak_pct',         '10',    'int',     0],
            ['campaign.rewards.streak_threshold',   '3',     'int',     0],
            ['campaign.rewards.daily_brl_cap',      '10.00', 'decimal', 0],
            ['campaign.rewards.daily_cap_enabled',  'true',  'bool',    0],
            // Custos
            ['campaign.cost.continue_after_death',  '2',     'int',     0],
            // Mecânicas
            ['campaign.mechanics.ship_max_hp',      '100',   'int',     0],
            ['campaign.mechanics.combo_max',        '5',     'int',     0],
            ['campaign.mechanics.combo_kills_per_step', '10','int',     0],
            ['campaign.mechanics.pause_enabled',    'true',  'bool',    0],
            ['campaign.mechanics.shoot_cooldown_ms','300',   'int',     0],
            // Monetização adicional
            ['campaign.monetization.skip_attempts', '3',     'int',     0],
            ['campaign.monetization.skip_cost',     '5',     'int',     0],
            ['campaign.monetization.skip_pays_brl', 'true',  'bool',    0],
            ['campaign.monetization.accelerate_s1', '20',    'int',     0],
            ['campaign.monetization.accelerate_s2', '30',    'int',     0],
            ['campaign.monetization.booster_cost',  '3',     'int',     0],
            ['campaign.monetization.kill_switch',   'false', 'bool',    0],
            // Anti-cheat
            ['campaign.anticheat.time_min_pct',     '80',    'int',     0],
            ['campaign.anticheat.time_max_pct',     '200',   'int',     0],
            ['campaign.anticheat.brl_tolerance_pct','50',    'int',     0],
            ['campaign.anticheat.xp_tolerance_pct', '50',    'int',     0],
            ['campaign.anticheat.jwt_expire_mult',  '1.5',   'decimal', 0],
            // Lançamento / visibilidade
            ['campaign.launch.sector2_enabled',     'false', 'bool',    1],
            ['campaign.launch.maintenance',         'false', 'bool',    1],
            ['campaign.launch.maintenance_msg',     'Modo Campanha em manutenção. Volte em breve!', 'string', 1],
            ['campaign.launch.show_in_header',      'true',  'bool',    1],
            ['campaign.launch.show_in_dashboard',   'true',  'bool',    1],
            // Ranking
            ['campaign.ranking.top_size',           '100',   'int',     1],
            ['campaign.ranking.show_stars',         'true',  'bool',    1],
            ['campaign.ranking.show_level',         'true',  'bool',    1],
            ['campaign.ranking.show_brl',           'true',  'bool',    1],
            ['campaign.ranking.show_weekly_xp',     'true',  'bool',    1],
            // Tutorial
            ['campaign.tutorial.welcome_enabled',   'true',  'bool',    0],
            ['campaign.tutorial.required',          'true',  'bool',    0],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO campaign_settings (setting_key, setting_value, value_type, description, is_public, updated_at) VALUES (?, ?, ?, NULL, ?, NOW())");
        foreach ($defaults as $d) {
            $ins->execute($d);
        }
        $results['migrations'][] = 'campaign_settings:defaults';
        output("  [OK] Configurações padrão da campanha inseridas/ignoradas");
    } catch (Exception $e) { /* tabela pode não existir */ }

    // Promove a public alguns settings que o cliente precisa ler
    // (custos visíveis no modal de compra de vida no mapa).
    try {
        $publicKeys = [
            'campaign.lives.cost_single',
            'campaign.lives.cost_pack5',
            'campaign.lives.cost_refill',
            'campaign.cost.continue_after_death',
        ];
        $stmt = $pdo->prepare("UPDATE campaign_settings SET is_public = 1 WHERE setting_key = ?");
        foreach ($publicKeys as $k) $stmt->execute([$k]);
        $results['migrations'][] = 'campaign_settings:public_costs';
        output("  [OK] Custos de vida/continue marcados como públicos");
    } catch (Exception $e) { /* tabela pode não existir */ }

    // Curva de XP por nível (1-30) — defaults
    try {
        $xpCurve = [
            1 => 0,       2 => 100,    3 => 220,    4 => 360,    5 => 500,
            6 => 660,     7 => 830,    8 => 1000,   9 => 1180,   10 => 1500,
            11 => 1700,   12 => 1900,  13 => 2150,  14 => 2500,  15 => 3000,
            16 => 3300,   17 => 3500,  18 => 3850,  19 => 4250,  20 => 4700,
            21 => 5050,   22 => 5400,  23 => 6000,  24 => 6700,  25 => 7500,
            26 => 8100,   27 => 8800,  28 => 9500,  29 => 10200, 30 => 11000,
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO campaign_xp_table (level, xp_required) VALUES (?, ?)");
        foreach ($xpCurve as $lvl => $xp) {
            $ins->execute([$lvl, $xp]);
        }
        $results['migrations'][] = 'campaign_xp_table:defaults';
        output("  [OK] Curva de XP (níveis 1-30) inserida/ignorada");
    } catch (Exception $e) { /* tabela pode não existir */ }

    // Fases padrão (treino + 10 fases) — defaults
    try {
        $stages = [
            // [stage_id, sector, order, name, duration, cost, min_level, xp, brl, is_boss]
            ['training', 0, 0, 'Treino',                   45, 1, 1,  50,  0.00, 0],
            ['s1f1',     1, 1, 'Cinturão — Iniciação',     60, 1, 1,  80,  0.05, 0],
            ['s1f2',     1, 2, 'Cinturão — Pressão',       60, 1, 3,  100, 0.08, 0],
            ['s1f3',     1, 3, 'Cinturão — Caos',          70, 1, 5,  120, 0.12, 0],
            ['s1f4',     1, 4, 'Cinturão — Berserker',     75, 1, 8,  140, 0.18, 0],
            ['s1f5',     1, 5, 'Asteroide-Mãe',            0,  1, 11, 200, 0.40, 1],
            ['s2f1',     2, 1, 'Detritos — Sucata',        70, 2, 14, 180, 0.25, 0],
            ['s2f2',     2, 2, 'Detritos — Sentinelas',    80, 2, 17, 200, 0.35, 0],
            ['s2f3',     2, 3, 'Detritos — Emboscada',     85, 2, 20, 220, 0.50, 0],
            ['s2f4',     2, 4, 'Detritos — Ruína',         90, 2, 23, 240, 0.70, 0],
            ['s2f5',     2, 5, 'Devorador de Sucata',      0,  2, 25, 350, 1.50, 1],
        ];
        $ins = $pdo->prepare("
            INSERT IGNORE INTO campaign_stages
            (stage_id, sector, order_in_sector, name, duration_seconds, credit_cost, min_level, xp_reward, brl_base, is_boss, is_enabled, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        foreach ($stages as $s) {
            $ins->execute($s);
        }
        $results['migrations'][] = 'campaign_stages:defaults';
        output("  [OK] Fases padrão (treino + 10) inseridas/ignoradas");
    } catch (Exception $e) { /* tabela pode não existir */ }

    // Waves padrão para fases não-boss do Setor 1.
    // Idempotente: só preenche quando waves_json IS NULL (não sobrescreve admin).
    try {
        $wavesByStage = [
            // Treino — tutorial guiado, leve
            'training' => json_encode([
                'waves' => [
                    ['duration_max' => 18, 'clear_at' => 12, 'spawns' => [
                        ['behavior' => 'tank',   'count' => 3, 'interval' => 1500],
                    ]],
                    ['duration_max' => 20, 'clear_at' => 14, 'spawns' => [
                        ['behavior' => 'bullet', 'count' => 3, 'interval' => 1000],
                    ]],
                ],
            ]),
            // F1 — só tank + bullet
            's1f1' => json_encode([
                'waves' => [
                    ['duration_max' => 20, 'clear_at' => 15, 'spawns' => [
                        ['behavior' => 'tank',   'count' => 4, 'interval' => 1000],
                    ]],
                    ['duration_max' => 20, 'clear_at' => 15, 'spawns' => [
                        ['behavior' => 'bullet', 'count' => 5, 'interval' => 700],
                    ]],
                    ['duration_max' => 20, 'clear_at' => 15, 'spawns' => [
                        ['behavior' => 'tank',   'count' => 2, 'interval' => 900],
                        ['behavior' => 'bullet', 'count' => 2, 'interval' => 900],
                    ]],
                ],
            ]),
            // F2 — apresenta kamikaze
            's1f2' => json_encode([
                'waves' => [
                    ['duration_max' => 20, 'clear_at' => 15, 'spawns' => [
                        ['behavior' => 'tank',     'count' => 5, 'interval' => 900],
                    ]],
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'bullet',   'count' => 4, 'interval' => 600],
                        ['behavior' => 'kamikaze', 'count' => 2, 'interval' => 1800],
                    ]],
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'kamikaze', 'count' => 3, 'interval' => 1400],
                        ['behavior' => 'tank',     'count' => 2, 'interval' => 1100],
                    ]],
                ],
            ]),
            // F3 — apresenta shooter
            's1f3' => json_encode([
                'waves' => [
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'kamikaze', 'count' => 4, 'interval' => 1400],
                    ]],
                    ['duration_max' => 24, 'clear_at' => 18, 'spawns' => [
                        ['behavior' => 'shooter',  'count' => 3, 'interval' => 1800],
                        ['behavior' => 'bullet',   'count' => 4, 'interval' => 700],
                    ]],
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'tank',     'count' => 4, 'interval' => 900],
                        ['behavior' => 'shooter',  'count' => 2, 'interval' => 2200],
                    ]],
                ],
            ]),
            // F4 — apresenta dodger; mistura mais densa
            's1f4' => json_encode([
                'waves' => [
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'dodger',   'count' => 5, 'interval' => 1100],
                    ]],
                    ['duration_max' => 24, 'clear_at' => 18, 'spawns' => [
                        ['behavior' => 'shooter',  'count' => 3, 'interval' => 1700],
                        ['behavior' => 'kamikaze', 'count' => 3, 'interval' => 1500],
                    ]],
                    ['duration_max' => 24, 'clear_at' => 18, 'spawns' => [
                        ['behavior' => 'tank',     'count' => 3, 'interval' => 950],
                        ['behavior' => 'bullet',   'count' => 4, 'interval' => 600],
                        ['behavior' => 'dodger',   'count' => 2, 'interval' => 1700],
                    ]],
                ],
            ]),
            // F5 — Boss Asteroide-Mãe (warm-up + boss fight)
            's1f5' => json_encode([
                'waves' => [
                    ['duration_max' => 18, 'clear_at' => 13, 'spawns' => [
                        ['behavior' => 'tank',     'count' => 3, 'interval' => 1000],
                        ['behavior' => 'bullet',   'count' => 3, 'interval' => 700],
                    ]],
                    ['duration_max' => 18, 'clear_at' => 13, 'spawns' => [
                        ['behavior' => 'kamikaze', 'count' => 2, 'interval' => 1400],
                        ['behavior' => 'shooter',  'count' => 2, 'interval' => 1700],
                    ]],
                ],
                'boss' => [
                    'boss_key'   => 'asteroid_mother',
                    'warning_ms' => 4000,
                ],
            ]),
            // ---------- SETOR 2 ----------
            // F6 — densidade subindo, tanks + bullets do setor 2 (sucata)
            's2f1' => json_encode([
                'waves' => [
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'tank',   'count' => 4, 'interval' => 850],
                        ['behavior' => 'bullet', 'count' => 4, 'interval' => 600],
                    ]],
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'kamikaze', 'count' => 3, 'interval' => 1300],
                        ['behavior' => 'tank',     'count' => 3, 'interval' => 950],
                    ]],
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'bullet',   'count' => 5, 'interval' => 600],
                        ['behavior' => 'shooter',  'count' => 2, 'interval' => 1900],
                    ]],
                ],
            ]),
            // F7 — sentinelas: muitos shooters
            's2f2' => json_encode([
                'waves' => [
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'shooter',  'count' => 4, 'interval' => 1500],
                    ]],
                    ['duration_max' => 24, 'clear_at' => 18, 'spawns' => [
                        ['behavior' => 'kamikaze', 'count' => 3, 'interval' => 1200],
                        ['behavior' => 'dodger',   'count' => 3, 'interval' => 1100],
                    ]],
                    ['duration_max' => 24, 'clear_at' => 18, 'spawns' => [
                        ['behavior' => 'shooter',  'count' => 3, 'interval' => 1500],
                        ['behavior' => 'tank',     'count' => 3, 'interval' => 900],
                    ]],
                ],
            ]),
            // F8 — emboscada com mistura agressiva
            's2f3' => json_encode([
                'waves' => [
                    ['duration_max' => 22, 'clear_at' => 16, 'spawns' => [
                        ['behavior' => 'kamikaze', 'count' => 5, 'interval' => 1200],
                    ]],
                    ['duration_max' => 24, 'clear_at' => 18, 'spawns' => [
                        ['behavior' => 'shooter',  'count' => 3, 'interval' => 1400],
                        ['behavior' => 'dodger',   'count' => 4, 'interval' => 1000],
                    ]],
                    ['duration_max' => 24, 'clear_at' => 18, 'spawns' => [
                        ['behavior' => 'tank',     'count' => 4, 'interval' => 800],
                        ['behavior' => 'bullet',   'count' => 5, 'interval' => 500],
                        ['behavior' => 'shooter',  'count' => 2, 'interval' => 1700],
                    ]],
                ],
            ]),
            // F9 — ruína: pré-boss, densidade alta
            's2f4' => json_encode([
                'waves' => [
                    ['duration_max' => 24, 'clear_at' => 18, 'spawns' => [
                        ['behavior' => 'dodger',   'count' => 5, 'interval' => 1000],
                        ['behavior' => 'bullet',   'count' => 4, 'interval' => 600],
                    ]],
                    ['duration_max' => 26, 'clear_at' => 20, 'spawns' => [
                        ['behavior' => 'shooter',  'count' => 4, 'interval' => 1400],
                        ['behavior' => 'kamikaze', 'count' => 4, 'interval' => 1100],
                    ]],
                    ['duration_max' => 26, 'clear_at' => 20, 'spawns' => [
                        ['behavior' => 'tank',     'count' => 4, 'interval' => 800],
                        ['behavior' => 'shooter',  'count' => 3, 'interval' => 1400],
                        ['behavior' => 'dodger',   'count' => 3, 'interval' => 1100],
                    ]],
                ],
            ]),
            // F10 — Boss Devorador de Sucata
            's2f5' => json_encode([
                'waves' => [
                    ['duration_max' => 18, 'clear_at' => 13, 'spawns' => [
                        ['behavior' => 'tank',     'count' => 3, 'interval' => 900],
                        ['behavior' => 'shooter',  'count' => 2, 'interval' => 1500],
                    ]],
                    ['duration_max' => 20, 'clear_at' => 15, 'spawns' => [
                        ['behavior' => 'dodger',   'count' => 3, 'interval' => 1100],
                        ['behavior' => 'kamikaze', 'count' => 3, 'interval' => 1200],
                    ]],
                ],
                'boss' => [
                    'boss_key'   => 'junk_devourer',
                    'warning_ms' => 4500,
                ],
            ]),
        ];
        $upd = $pdo->prepare("UPDATE campaign_stages SET waves_json = ? WHERE stage_id = ? AND waves_json IS NULL");
        $touched = 0;
        foreach ($wavesByStage as $stageId => $json) {
            $upd->execute([$json, $stageId]);
            if ($upd->rowCount() > 0) $touched++;
        }
        if ($touched > 0) {
            $results['migrations'][] = "campaign_stages:waves_json($touched)";
            output("  [OK] waves_json default aplicado a $touched fase(s)");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // ============================================
    // LIMPEZA
    // ============================================
    $flagFiles = glob(sys_get_temp_dir() . '/unobix_*.flag');
    foreach ($flagFiles ?? [] as $f) {
        @unlink($f);
    }
    $results['migrations'][] = 'cleanup:flag_files';

    output("\n✅ Migração concluída! " . count($results['migrations']) . " operações executadas.");

} catch (Exception $e) {
    $results['success'] = false;
    $results['errors'][] = $e->getMessage();
    output("ERRO FATAL: " . $e->getMessage());
}

if (!$isCli) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
