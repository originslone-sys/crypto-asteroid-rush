-- ============================================
-- MIGRAÇÃO COMPLETA: Railway → Cloud SQL
-- Crypto Asteroid Rush / Unobix
-- Data: 2026-02-02
-- ============================================

-- 🚨 ATENÇÃO: Execute em ORDEM!
-- 1. Conectar ao Cloud SQL (novo banco)
-- 2. Executar este script
-- 3. Verificar migração

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET COLLATION_CONNECTION = 'utf8mb4_unicode_ci';

-- ============================================
-- 📊 TABELA: users (Jogadores)
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    google_uid VARCHAR(255) UNIQUE,           -- ID único do Google
    email VARCHAR(255) NOT NULL UNIQUE,       -- Email do Google
    display_name VARCHAR(255),                -- Nome do perfil
    photo_url VARCHAR(500),                   -- Foto do perfil
    wallet_address VARCHAR(42),               -- Carteira crypto (legado, pode ser NULL)
    balance_brl DECIMAL(10,2) DEFAULT 0.00,   -- Saldo em Reais
    balance_usdt DECIMAL(10,2) DEFAULT 0.00,  -- Saldo em USDT
    total_withdrawn_brl DECIMAL(10,2) DEFAULT 0.00, -- Total sacado BRL
    total_withdrawn DECIMAL(10,2) DEFAULT 0.00,     -- Total sacado (legado)
    is_banned BOOLEAN DEFAULT FALSE,          -- Se está banido
    ban_reason TEXT,                          -- Motivo do ban
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_google_uid (google_uid),
    INDEX idx_email (email),
    INDEX idx_wallet (wallet_address),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: game_sessions (Sessões de jogo)
-- ============================================
CREATE TABLE IF NOT EXISTS game_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,                              -- Referência ao usuário
    session_uuid VARCHAR(36) UNIQUE,          -- UUID da sessão
    google_uid VARCHAR(255),                  -- Google UID (se autenticado)
    wallet_address VARCHAR(42),               -- Wallet (se não autenticado)
    is_hard_mode BOOLEAN DEFAULT FALSE,       -- Modo difícil
    status ENUM('active', 'completed', 'flagged', 'abandoned') DEFAULT 'active',
    earnings_brl DECIMAL(10,2) DEFAULT 0.00,  -- Ganhos em BRL
    earnings_usdt DECIMAL(10,2) DEFAULT 0.00, -- Ganhos em USDT
    asteroids_destroyed INT DEFAULT 0,        -- Asteroides destruídos
    game_duration INT DEFAULT 0,              -- Duração em segundos
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME,
    ip_address VARCHAR(45),                   -- IPv4 ou IPv6
    user_agent TEXT,                          -- User agent do navegador
    
    INDEX idx_user_id (user_id),
    INDEX idx_google_uid (google_uid),
    INDEX idx_wallet (wallet_address),
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_session_uuid (session_uuid),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: game_events (Eventos do jogo)
-- ============================================
CREATE TABLE IF NOT EXISTS game_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_id INT,                           -- Sessão relacionada
    event_type VARCHAR(50),                   -- Tipo de evento
    event_data JSON,                          -- Dados do evento em JSON
    earnings_brl DECIMAL(10,2) DEFAULT 0.00,  -- Ganhos deste evento
    earnings_usdt DECIMAL(10,2) DEFAULT 0.00, -- Ganhos em USDT
    google_uid VARCHAR(255),                  -- Google UID (se autenticado)
    wallet_address VARCHAR(42),               -- Wallet (se não autenticado)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_session_id (session_id),
    INDEX idx_event_type (event_type),
    INDEX idx_created (created_at),
    INDEX idx_google_uid (google_uid),
    INDEX idx_wallet (wallet_address),
    FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: withdrawals (Saques)
-- ============================================
CREATE TABLE IF NOT EXISTS withdrawals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,                              -- Usuário que solicitou
    amount_brl DECIMAL(10,2) NOT NULL,        -- Valor em BRL
    amount_usdt DECIMAL(10,2),                -- Valor em USDT (opcional)
    wallet_address VARCHAR(42) NOT NULL,      -- Carteira destino
    status ENUM('pending', 'processing', 'completed', 'rejected', 'cancelled') DEFAULT 'pending',
    transaction_hash VARCHAR(66),             -- Hash da transação blockchain
    admin_notes TEXT,                         -- Notas do administrador
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME,                    -- Quando foi processado
    completed_at DATETIME,                    -- Quando foi finalizado
    
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_wallet (wallet_address),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: staking (Staking de tokens)
-- ============================================
CREATE TABLE IF NOT EXISTS staking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,                              -- Usuário
    amount DECIMAL(10,2) NOT NULL,            -- Valor staked
    apy DECIMAL(5,2) DEFAULT 5.00,            -- APY (5% padrão)
    start_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    end_date DATETIME,                        -- Data de término
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    earnings DECIMAL(10,2) DEFAULT 0.00,      -- Ganhos acumulados
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_start_date (start_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: admin_logs (Logs administrativos)
-- ============================================
CREATE TABLE IF NOT EXISTS admin_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_user VARCHAR(255) NOT NULL,         -- Admin que executou
    action_type VARCHAR(50) NOT NULL,         -- Tipo de ação
    target_type VARCHAR(50),                  -- Tipo de alvo (user, withdrawal, etc)
    target_id INT,                            -- ID do alvo
    details JSON,                             -- Detalhes da ação
    ip_address VARCHAR(45),                   -- IP do admin
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_admin_user (admin_user),
    INDEX idx_action_type (action_type),
    INDEX idx_created (created_at),
    INDEX idx_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: rate_limits (Limite de requisições)
-- ============================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,          -- IP do cliente
    endpoint VARCHAR(100) NOT NULL,           -- Endpoint da API
    request_count INT DEFAULT 1,              -- Contador de requisições
    first_request DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_request DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_ip_endpoint (ip_address, endpoint),
    INDEX idx_ip_address (ip_address),
    INDEX idx_endpoint (endpoint),
    INDEX idx_last_request (last_request)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: ip_blacklist (IPs banidos)
-- ============================================
CREATE TABLE IF NOT EXISTS ip_blacklist (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL UNIQUE,   -- IP banido
    reason TEXT,                              -- Motivo do ban
    banned_by VARCHAR(255),                   -- Quem baniu
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,                      -- Expiração (NULL = permanente)
    
    INDEX idx_ip_address (ip_address),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: user_sessions (Sessões de usuário)
-- ============================================
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,                              -- Usuário
    session_token VARCHAR(255) UNIQUE,        -- Token da sessão
    ip_address VARCHAR(45),                   -- IP da sessão
    user_agent TEXT,                          -- User agent
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,                      -- Expiração da sessão
    
    INDEX idx_user_id (user_id),
    INDEX idx_session_token (session_token),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: suspicious_activity (Atividade suspeita)
-- ============================================
CREATE TABLE IF NOT EXISTS suspicious_activity (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,                              -- Usuário (pode ser NULL)
    ip_address VARCHAR(45) NOT NULL,          -- IP da atividade
    activity_type VARCHAR(50) NOT NULL,       -- Tipo de atividade
    details JSON,                             -- Detalhes
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed BOOLEAN DEFAULT FALSE,           -- Se foi revisado
    reviewed_by VARCHAR(255),                 -- Quem revisou
    reviewed_at DATETIME,                     -- Quando foi revisado
    
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_activity_type (activity_type),
    INDEX idx_severity (severity),
    INDEX idx_created (created_at),
    INDEX idx_reviewed (reviewed),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: ip_sessions (Sessões por IP)
-- ============================================
CREATE TABLE IF NOT EXISTS ip_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,          -- IP
    session_count INT DEFAULT 1,              -- Contador de sessões
    first_session DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_session DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_ip (ip_address),
    INDEX idx_ip_address (ip_address),
    INDEX idx_last_session (last_session)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 📊 TABELA: game_settings (Configurações do jogo)
-- ============================================
CREATE TABLE IF NOT EXISTS game_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL, -- Chave da configuração
    setting_value TEXT,                       -- Valor (pode ser JSON)
    data_type ENUM('string', 'number', 'boolean', 'json', 'array') DEFAULT 'string',
    description TEXT,                         -- Descrição
    is_public BOOLEAN DEFAULT FALSE,          -- Se é público (API)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_setting_key (setting_key),
    INDEX idx_is_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 🔧 INSERIR CONFIGURAÇÕES PADRÃO
-- ============================================
INSERT INTO game_settings (setting_key, setting_value, data_type, description, is_public) VALUES
('staking_apy', '5', 'number', 'APY do staking em porcentagem', TRUE),
('staking_min_days', '7', 'number', 'Dias mínimos para staking', TRUE),
('withdraw_min_amount', '1.00', 'number', 'Valor mínimo para saque (BRL)', TRUE),
('withdraw_processing_days_start', '20', 'number', 'Dia inicial para processar saques', FALSE),
('withdraw_processing_days_end', '25', 'number', 'Dia final para processar saques', FALSE),
('game_version', '1.0.0', 'string', 'Versão do jogo', TRUE),
('maintenance_mode', 'false', 'boolean', 'Modo manutenção', TRUE),
('registration_enabled', 'true', 'boolean', 'Registro habilitado', TRUE),
('withdrawals_enabled', 'true', 'boolean', 'Saques habilitados', TRUE)
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    updated_at = CURRENT_TIMESTAMP;

-- ============================================
-- 👑 CRIAR USUÁRIO ADMIN PADRÃO
-- ============================================
-- Senha: Admin@Unobix2024! (configurada no .env)
-- Este usuário é para login no painel admin (/admin)

INSERT INTO users (
    email,
    display_name,
    balance_brl,
    created_at,
    last_login
) VALUES (
    'admin@unobix.com',
    'Administrador',
    0.00,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
) ON DUPLICATE KEY UPDATE 
    last_login = CURRENT_TIMESTAMP;

-- ============================================
-- ✅ FINALIZAR MIGRAÇÃO
-- ============================================
SET FOREIGN_KEY_CHECKS = 1;

SELECT '============================================' as `separator`;
SELECT '✅ MIGRAÇÃO COMPLETA!' as `message`;
SELECT '============================================' as `separator`;
SELECT '📊 TABELAS CRIADAS:' as info;
SHOW TABLES;
SELECT '' as spacer;
SELECT '👥 USUÁRIOS:' as info;
SELECT COUNT(*) as total_users FROM users;
SELECT '' as spacer;
SELECT '🎮 SESSÕES:' as info;  
SELECT COUNT(*) as total_sessions FROM game_sessions;
SELECT '' as spacer;
SELECT '⚙️  CONFIGURAÇÕES:' as info;
SELECT setting_key, setting_value FROM game_settings ORDER BY setting_key;