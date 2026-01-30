-- ============================================
-- CORREÇÃO DA TABELA game_events
-- Permite NULL em wallet_address para Google users
-- ============================================

START TRANSACTION;

-- 1. Verificar estrutura atual
SELECT '=== ESTRUTURA ATUAL game_events ===' as info;
SHOW CREATE TABLE game_events;

-- 2. Permitir NULL em wallet_address
ALTER TABLE game_events MODIFY COLUMN wallet_address VARCHAR(42) NULL;

-- 3. Remover DEFAULT se existir
ALTER TABLE game_events ALTER COLUMN wallet_address DROP DEFAULT;

-- 4. Atualizar dados existentes (Google users)
UPDATE game_events ge
JOIN game_sessions gs ON ge.session_id = gs.id
SET ge.wallet_address = NULL
WHERE gs.google_uid IS NOT NULL 
  AND ge.wallet_address IS NOT NULL;

-- 5. Verificar correção
SELECT '=== VERIFICAÇÃO PÓS-CORREÇÃO ===' as info;

SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'game_events'
    AND COLUMN_NAME = 'wallet_address';

-- 6. Contar eventos por tipo
SELECT 
    'Eventos por tipo' as category,
    COUNT(CASE WHEN google_uid IS NOT NULL AND wallet_address IS NULL THEN 1 END) as google_only,
    COUNT(CASE WHEN wallet_address IS NOT NULL AND google_uid IS NULL THEN 1 END) as wallet_only,
    COUNT(CASE WHEN google_uid IS NOT NULL AND wallet_address IS NOT NULL THEN 1 END) as both,
    COUNT(CASE WHEN google_uid IS NULL AND wallet_address IS NULL THEN 1 END) as invalid
FROM game_events;

COMMIT;

SELECT '✅ TABELA game_events CORRIGIDA!' as final_message;