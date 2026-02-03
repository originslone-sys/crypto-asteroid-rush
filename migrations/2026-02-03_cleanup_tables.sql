-- Migration: Cleanup de tabelas e colunas obsoletas
-- Data: 2026-02-03
-- Objetivo: Remover tabela players antiga e colunas não utilizadas da tabela users

-- ============================================
-- 1. REMOVER TABELA players (ESTRUTURA ANTIGA)
-- ============================================
DROP TABLE IF EXISTS players;

-- ============================================
-- 2. REMOVER COLUNAS NÃO UTILIZADAS DA TABELA users
-- ============================================

-- Remover photo_url (não utilizado)
ALTER TABLE users DROP COLUMN IF EXISTS photo_url;

-- Remover wallet_address (autenticação apenas por Google UID)
ALTER TABLE users DROP COLUMN IF EXISTS wallet_address;

-- Remover balance_usdt (sistema usa apenas BRL)
ALTER TABLE users DROP COLUMN IF EXISTS balance_usdt;

-- ============================================
-- 3. VERIFICAR ESTRUTURA FINAL DA TABELA users
-- ============================================
-- Estrutura esperada após cleanup:
-- id                   int             PRIMARY KEY
-- google_uid           varchar(255)    UNIQUE
-- email                varchar(255)    NOT NULL UNIQUE  
-- display_name         varchar(255)
-- balance_brl          decimal(10,2)
-- total_withdrawn_brl  decimal(10,2)
-- total_withdrawn      decimal(10,2)
-- is_banned            tinyint(1)
-- ban_reason           text
-- created_at           datetime
-- last_login           datetime
-- updated_at           datetime

-- ============================================
-- 4. ATUALIZAR DADOS EXISTENTES (SE NECESSÁRIO)
-- ============================================
-- Garantir que todos os usuários tenham email válido
UPDATE users SET email = CONCAT('user_', id, '@unobix.com') WHERE email IS NULL OR email = '';

-- Garantir que google_uid seja único (remover duplicatas se existirem)
-- NOTA: Esta query é apenas exemplo, ajustar conforme necessidade
-- DELETE u1 FROM users u1
-- INNER JOIN users u2 
-- WHERE u1.id > u2.id AND u1.google_uid = u2.google_uid AND u1.google_uid IS NOT NULL;

-- ============================================
-- 5. LOG DE MIGRATION
-- ============================================
INSERT INTO system_config (config_key, config_value, is_public, created_at, updated_at)
VALUES (
    'migration_2026_02_03_cleanup',
    '{"action": "Removed players table and unused columns from users", "timestamp": "2026-02-03 19:09:00", "removed_columns": ["photo_url", "wallet_address", "balance_usdt"]}',
    0,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE 
    config_value = VALUES(config_value),
    updated_at = NOW();