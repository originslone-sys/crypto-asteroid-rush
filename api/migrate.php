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

    // ranking_cache (cache compartilhado entre instâncias)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ranking_cache (
            cache_key VARCHAR(50) NOT NULL PRIMARY KEY,
            cache_value MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'ranking_cache';
    output("  [OK] ranking_cache");

    // admin_login_log (histórico de login do admin)
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

    // saved_pix_keys (chaves PIX salvas do usuário)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saved_pix_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            pix_key VARCHAR(255) NOT NULL,
            pix_key_type VARCHAR(20) NOT NULL,
            label VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            CONSTRAINT fk_saved_pix_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'saved_pix_keys';
    output("  [OK] saved_pix_keys");

    // ============================================
    // COLUNAS EXTRAS EM TABELAS EXISTENTES
    // ============================================

    // users.credits
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'credits'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN credits INT NOT NULL DEFAULT 0");
        $results['migrations'][] = 'users.credits';
        output("  [OK] users.credits adicionada");
    }

    // users.is_premium
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_premium'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_premium TINYINT(1) NOT NULL DEFAULT 0");
        $results['migrations'][] = 'users.is_premium';
        output("  [OK] users.is_premium adicionada");
    }

    // users.premium_expires_at
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'premium_expires_at'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE users ADD COLUMN premium_expires_at DATETIME DEFAULT NULL");
        $results['migrations'][] = 'users.premium_expires_at';
        output("  [OK] users.premium_expires_at adicionada");
    }

    // transactions.amount_brl
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
            output("  [OK] transactions.type convertida para VARCHAR");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // withdrawals — colunas ZettPay
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

    // game_sessions — coluna total_spawned
    try {
        $col = $pdo->query("SHOW COLUMNS FROM game_sessions LIKE 'total_spawned'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE game_sessions ADD COLUMN total_spawned INT NOT NULL DEFAULT 0 AFTER legendary_asteroids");
            $results['migrations'][] = 'game_sessions.total_spawned';
            output("  [OK] game_sessions: coluna total_spawned adicionada");
        }
    } catch (Exception $e) { /* tabela pode não existir */ }

    // ============================================
    // ÍNDICES CRÍTICOS
    // ============================================
    $indexes = [
        ["users", "ALTER TABLE users ADD UNIQUE INDEX idx_google_uid (google_uid)"],
        ["game_sessions", "ALTER TABLE game_sessions ADD INDEX idx_status_started_uid (status, started_at, google_uid)"],
        ["game_sessions", "ALTER TABLE game_sessions ADD INDEX idx_google_uid_status (google_uid, status)"],
        ["game_sessions", "ALTER TABLE game_sessions ADD INDEX idx_ranking (status, started_at, google_uid, asteroids_destroyed)"],
        ["transactions", "ALTER TABLE transactions ADD INDEX idx_uid_created (google_uid, created_at DESC)"],
        ["transactions", "ALTER TABLE transactions ADD INDEX idx_uid_type_created (google_uid, type, created_at DESC)"],
        ["zettpay_transactions", "ALTER TABLE zettpay_transactions ADD INDEX idx_ext_user (external_id, user_id)"],
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

    // Pacotes de créditos padrão
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
        $results['migrations'][] = 'credit_packages:defaults';
        output("  [OK] Pacotes de créditos padrão inseridos");
    }

    // Configurações premium padrão
    try {
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
            $results['migrations'][] = 'game_settings:premium_defaults';
            output("  [OK] Configurações premium padrão inseridas");
        }
    } catch (Exception $e) { /* game_settings pode não existir */ }

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
    $results['migrations'][] = 'exploration_ships:create';
    output("  [OK] Tabela exploration_ships");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exploration_rentals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            google_uid VARCHAR(128) NOT NULL,
            ship_id INT NOT NULL,
            ship_key VARCHAR(20) NOT NULL,
            rental_price_brl DECIMAL(10,2) NOT NULL,
            credits_per_day INT NOT NULL,
            status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
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
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results['migrations'][] = 'exploration_rentals:create';
    output("  [OK] Tabela exploration_rentals");

    // Seed: naves padrão
    try {
        $shipCount = (int)$pdo->query("SELECT COUNT(*) FROM exploration_ships")->fetchColumn();
        if ($shipCount === 0) {
            $pdo->exec("
                INSERT INTO exploration_ships (ship_key, name, description, rental_price_brl, rental_duration_hours, credits_per_day, is_active, sort_order) VALUES
                ('PHOENIX', 'Phoenix Crimson', 'Explorador equilibrado com boa coleta', 2.00, 24, 5, 1, 1),
                ('GUARDIAN', 'Forest Guardian', 'Casco resistente para missões longas', 3.00, 48, 8, 1, 2),
                ('THUNDER', 'Thunder Strike', 'Coletor de alta velocidade', 5.00, 72, 12, 1, 3),
                ('INFERNO', 'Inferno Blaze', 'Motores potentes aceleram a coleta', 4.00, 48, 10, 1, 4),
                ('NEBULA', 'Nebula Phantom', 'Explorador ágil e eficiente', 6.00, 72, 15, 1, 5),
                ('VIPER', 'Toxic Viper', 'Serpente ágil coletora', 3.50, 48, 9, 1, 6),
                ('WOLF', 'Steel Wolf', 'Coletor pesado de assalto', 8.00, 96, 20, 1, 7)
            ");
            $results['migrations'][] = 'exploration_ships:seed';
            output("  [OK] Naves padrão inseridas (7)");
        }
    } catch (Exception $e) {}

    // Settings de exploração
    try {
        $expSettings = (int)$pdo->query("SELECT COUNT(*) FROM game_settings WHERE setting_key LIKE 'exploration_%'")->fetchColumn();
        if ($expSettings === 0) {
            $ins = $pdo->prepare("INSERT INTO game_settings (setting_key, setting_value, is_public, updated_at) VALUES (?, ?, ?, NOW())");
            $ins->execute(['exploration_enabled', 'true', 0]);
            $ins->execute(['exploration_max_rentals_per_user', '3', 0]);
            $results['migrations'][] = 'game_settings:exploration_defaults';
            output("  [OK] Configurações de exploração padrão inseridas");
        }
    } catch (Exception $e) {}

    // ============================================
    // TABELA: notifications (avisos globais do admin)
    // ============================================
    try {
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
        $results['migrations'][] = 'notifications:create';
        output("  [OK] Tabela notifications");
    } catch (Exception $e) {}

    // ============================================
    // LIMPEZA: Remover flag files antigos
    // ============================================
    $flagFiles = glob(sys_get_temp_dir() . '/unobix_*.flag');
    foreach ($flagFiles as $f) {
        @unlink($f);
    }
    $results['migrations'][] = 'cleanup:flag_files';
    output("  [OK] Flag files removidos");

    output("\nMigração concluída com sucesso! " . count($results['migrations']) . " operações.");

} catch (Exception $e) {
    $results['success'] = false;
    $results['errors'][] = $e->getMessage();
    output("ERRO: " . $e->getMessage());
}

if (!$isCli) {
    echo json_encode($results);
}
