# 📊 ESTRUTURA COMPLETA DO BANCO DE DADOS

**Banco:** `unobix_db`  
**Host:** 34.168.76.127  
**Data:** 2026-02-03 22:04:00

## 📋 TABELAS EXISTENTES

### 1. `users` - Usuários do sistema
**Colunas:**
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `google_uid` VARCHAR(128) UNIQUE
- `email` VARCHAR(255)
- `display_name` VARCHAR(100)
- `wallet_address` VARCHAR(42) NULL
- `balance_brl` DECIMAL(15,4) DEFAULT 0
- `total_played` INT DEFAULT 0
- `total_earned_brl` DECIMAL(15,4) DEFAULT 0
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
- `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
- `last_login` DATETIME NULL
- `is_admin` TINYINT(1) DEFAULT 0
- `referral_code` VARCHAR(20) NULL

**Índices:**
- `google_uid` (UNIQUE)
- `email` (NON-UNIQUE)
- `wallet_address` (NON-UNIQUE)
- `referral_code` (UNIQUE)

### 2. `game_sessions` - Sessões de jogo
**Colunas:**
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `user_id` INT NULL (FK para users.id)
- `session_uuid` VARCHAR(36)
- `google_uid` VARCHAR(128)
- `wallet_address` VARCHAR(42) NULL
- `is_hard_mode` TINYINT(1) DEFAULT 0
- `status` ENUM('active','completed','cancelled','expired')
- `earnings_brl` DECIMAL(15,4) DEFAULT 0
- `earnings_usdt` DECIMAL(15,4) DEFAULT 0 (legacy)
- `asteroids_destroyed` INT DEFAULT 0
- `game_duration` INT NULL
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
- `ended_at` DATETIME NULL
- `ip_address` VARCHAR(45) NULL
- `user_agent` TEXT NULL
- `mission_number` INT DEFAULT 1
- `rare_asteroids_target` INT NULL
- `epic_asteroid_target` INT NULL
- `rare_ids` TEXT NULL
- `epic_id` INT NULL
- `started_at` DATETIME NULL
- `session_token` VARCHAR(64)
- `legendary_asteroids` INT DEFAULT 0
- `epic_asteroids` INT DEFAULT 0
- `rare_asteroids` INT DEFAULT 0
- `common_asteroids` INT DEFAULT 0

**Índices:**
- `user_id` (NON-UNIQUE)
- `google_uid` (NON-UNIQUE)
- `session_uuid` (UNIQUE)
- `status` (NON-UNIQUE)
- `session_token` (NON-UNIQUE)

### 3. `game_events` - Eventos de destruição de asteroides
**Colunas:**
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `session_id` INT
- `google_uid` VARCHAR(128)
- `wallet_address` VARCHAR(42) NULL
- `asteroid_id` INT
- `reward_type` ENUM('none','common','rare','epic','legendary')
- `reward_amount` DECIMAL(15,4) DEFAULT 0
- `reward_amount_brl` DECIMAL(15,4) DEFAULT 0
- `client_timestamp` DATETIME NULL
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

**Índices:**
- `session_id` (NON-UNIQUE)
- `google_uid` (NON-UNIQUE)
- `created_at` (NON-UNIQUE)

### 4. `transactions` - Transações financeiras
**Colunas:**
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `google_uid` VARCHAR(128)
- `wallet_address` VARCHAR(42) NULL
- `type` ENUM('game_reward','withdraw','stake','unstake','referral','admin')
- `amount` DECIMAL(15,4) DEFAULT 0
- `amount_brl` DECIMAL(15,4) DEFAULT 0
- `description` VARCHAR(255)
- `status` ENUM('pending','completed','failed','cancelled') DEFAULT 'pending'
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
- `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

**Índices:**
- `google_uid` (NON-UNIQUE)
- `type` (NON-UNIQUE)
- `status` (NON-UNIQUE)

### 5. `captcha_log` - Logs de verificação CAPTCHA
**Colunas:**
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `session_id` INT
- `google_uid` VARCHAR(128)
- `wallet_address` VARCHAR(42) NULL
- `is_success` TINYINT(1) DEFAULT 0
- `ip_address` VARCHAR(45)
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP

**Índices:**
- `session_id` (NON-UNIQUE)
- `google_uid` (NON-UNIQUE)
- `is_success` (NON-UNIQUE)

### 6. `ip_sessions` - Controle de sessões por IP
**Colunas:**
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `session_id` INT
- `ip_address` VARCHAR(45)
- `user_agent` TEXT NULL
- `status` ENUM('active','completed','blocked')
- `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
- `ended_at` DATETIME NULL

**Índices:**
- `session_id` (NON-UNIQUE)
- `ip_address` (NON-UNIQUE)
- `status` (NON-UNIQUE)

### 7. VIEW `players` - Compatibilidade (legacy)
**Definição:** `SELECT * FROM users`

## 🔗 RELACIONAMENTOS

1. `users` → `game_sessions` (1:N)
   - `users.id` → `game_sessions.user_id`

2. `game_sessions` → `game_events` (1:N)
   - `game_sessions.id` → `game_events.session_id`

3. `game_sessions` → `transactions` (1:N)
   - `game_sessions.google_uid` → `transactions.google_uid`

4. `game_sessions` → `captcha_log` (1:N)
   - `game_sessions.id` → `captcha_log.session_id`

5. `game_sessions` → `ip_sessions` (1:1)
   - `game_sessions.id` → `ip_sessions.session_id`

## 📈 ESTATÍSTICAS GERAIS

- **Total de tabelas:** 7 (6 tabelas + 1 view)
- **Total de usuários:** Consultar `SELECT COUNT(*) FROM users`
- **Sessões ativas:** Consultar `SELECT COUNT(*) FROM game_sessions WHERE status = 'active'`
- **Transações:** Consultar `SELECT COUNT(*) FROM transactions`

## 🎯 COLUNAS CRÍTICAS PARA DASHBOARD

1. `users.total_played` - Missões jogadas
2. `users.total_earned_brl` - Total ganho em BRL
3. `users.balance_brl` - Saldo atual
4. `game_sessions.earnings_brl` - Ganho por sessão
5. `game_events.reward_amount_brl` - Ganho por evento

## ⚠️ COLUNAS LEGACY (MANTIDAS PARA COMPATIBILIDADE)

1. `game_sessions.earnings_usdt` - Duplicata de earnings_brl
2. `game_sessions.wallet_address` - Para usuários sem Google
3. `game_events.wallet_address` - Para compatibilidade
4. VIEW `players` - Para código legado

## 🔄 FLUXO DE DADOS

1. **Login:** `auth-google.php` → `users` (create/update)
2. **Start:** `game-start.php` → `game_sessions` (create) + `users.total_played++`
3. **Event:** `game-event.php` → `game_events` (insert) + `game_sessions` (update counters)
4. **End:** `game-end.php` → `game_sessions` (complete) + `users.balance_brl` (credit) + `transactions` (record)
5. **CAPTCHA:** `game-end.php` → `captcha_log` (verify)

---

*Documento atualizado automaticamente. Última verificação: 2026-02-03*