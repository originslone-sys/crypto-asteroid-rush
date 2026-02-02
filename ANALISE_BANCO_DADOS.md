# 🗃️ ANÁLISE COMPLETA DO BANCO DE DADOS
## Crypto Asteroid Rush / Unobix

**Data**: 2026-02-01  
**Fonte**: Análise de código PHP + arquivo SQL  
**Status**: Estrutura inferida do código

---

## 📊 VISÃO GERAL

### **✅ SISTEMA IDENTIFICADO:**
- **Banco**: MySQL (compatível com Railway)
- **Migração**: De `wallet_address` para `google_uid`
- **Moedas**: BRL (Real) + USDT (opcional)
- **Autenticação**: Google OAuth (Firebase)

### **⚠️ FALTANDO:**
- Schema completo do banco
- Scripts de criação de tabelas
- Migrações estruturadas
- Documentação de relações

---

## 📋 TABELAS IDENTIFICADAS

### **1. 👥 `players` / `users` (Jogadores)**
**Propósito**: Armazenar dados dos jogadores

**Campos identificados:**
```sql
id                      INT PRIMARY KEY
google_uid              VARCHAR(255)  -- ID único do Google (pode ser NULL)
email                   VARCHAR(255)  -- Email do Google
display_name            VARCHAR(255)  -- Nome do perfil
photo_url               VARCHAR(500)  -- Foto do perfil
wallet_address          VARCHAR(42)   -- Carteira crypto (legado, pode ser NULL)
balance_brl             DECIMAL(10,2) -- Saldo em Reais
balance_usdt            DECIMAL(10,2) -- Saldo em USDT (opcional)
total_withdrawn_brl     DECIMAL(10,2) -- Total sacado em BRL
total_withdrawn         DECIMAL(10,2) -- Total sacado (legado)
is_banned               BOOLEAN       -- Se está banido
ban_reason              TEXT          -- Motivo do ban
created_at              DATETIME      -- Data criação
last_login              DATETIME      -- Último login
```

**Observações:**
- Sistema híbrido: Google UID + wallet_address (legado)
- `google_uid` é o identificador principal atual
- `wallet_address` mantido para compatibilidade

### **2. 💰 `withdrawals` (Saques)**
**Propósito**: Solicitações de saque de dinheiro

**Campos identificados:**
```sql
id                      INT PRIMARY KEY
player_id               INT           -- FK para players.id
google_uid              VARCHAR(255)  -- Google UID do jogador
wallet_address          VARCHAR(42)   -- NULL (não usado mais)
amount_usdt             DECIMAL(10,2) -- Valor em USDT (compatibilidade)
amount_brl              DECIMAL(10,2) -- Valor em Reais
payment_method          ENUM('pix','paypal','usdt_bep20')
payment_details         JSON          -- Detalhes do pagamento
status                  ENUM('pending','approved','rejected','completed')
tx_hash                 VARCHAR(100)  -- Hash da transação (para crypto)
notes                   TEXT          -- Notas/observações
created_at              DATETIME      -- Data solicitação
approved_at             DATETIME      -- Data aprovação
```

**Observações:**
- Suporte a 3 métodos: PIX, PayPal, USDT BEP20
- `payment_details` armazena JSON específico por método
- Sistema de aprovação manual (admin)

### **3. 📊 `transactions` (Transações)**
**Propósito**: Histórico de todas transações financeiras

**Campos identificados:**
```sql
id                      INT PRIMARY KEY
google_uid              VARCHAR(255)  -- Google UID
wallet_address          VARCHAR(42)   -- NULL (legado)
type                    VARCHAR(50)   -- 'withdraw', 'stake', 'unstake', 'game_win', etc.
amount                  DECIMAL(10,2) -- Valor (USDT compat)
amount_brl              DECIMAL(10,2) -- Valor em Reais
description             TEXT          -- Descrição da transação
status                  VARCHAR(20)   -- 'pending', 'completed', 'failed'
created_at              DATETIME      -- Data transação
```

**Observações:**
- Audit trail completo de movimentações
- Suporte a múltiplos tipos de transação
- Rastreabilidade financeira

### **4. 🎮 `game_sessions` (Sessões de Jogo)**
**Propósito**: Registrar sessões de gameplay

**Campos identificados (inferidos):**
```sql
id                      INT PRIMARY KEY
google_uid              VARCHAR(255)  -- Google UID
session_token           VARCHAR(255)  -- Token único da sessão
started_at              DATETIME      -- Início da sessão
ended_at                DATETIME      -- Fim da sessão
duration_seconds        INT           -- Duração em segundos
score                   INT           -- Pontuação
earnings_brl            DECIMAL(10,2) -- Ganhos em BRL
captcha_verified        BOOLEAN       -- CAPTCHA verificado
ip_address              VARCHAR(45)   -- IP do jogador
user_agent              TEXT          -- User agent
```

### **5. 🎯 `game_events` (Eventos do Jogo)**
**Propósito**: Eventos específicos durante gameplay

**Campos identificados (do SQL):**
```sql
id                      INT PRIMARY KEY
session_id              INT           -- FK para game_sessions.id
google_uid              VARCHAR(255)  -- Google UID
wallet_address          VARCHAR(42)   -- NULL (permitido para Google users)
event_type              VARCHAR(50)   -- Tipo de evento
event_data              JSON          -- Dados do evento
created_at              DATETIME      -- Data do evento
```

**Observações:**
- Migração: `wallet_address` agora pode ser NULL
- Suporte a Google-only users
- Eventos detalhados para analytics

### **6. 📈 `stakes` (Investimentos Staking)**
**Propósito**: Registro de stakes (investimentos)

**Campos identificados:**
```sql
id                      INT PRIMARY KEY
player_id               INT           -- FK para players.id
google_uid              VARCHAR(255)  -- Google UID
amount_brl              DECIMAL(10,2) -- Valor investido
apy_percentage          DECIMAL(5,2)  -- APY (5% fixo vs 5-12% marketing)
start_date              DATETIME      -- Data início
end_date                DATETIME      -- Data término (opcional)
status                  ENUM('active','completed','cancelled')
earnings_brl            DECIMAL(10,2) -- Ganhos acumulados
created_at              DATETIME      -- Data criação
```

### **7. 👥 `referrals` (Sistema de Afiliados)**
**Propósito**: Programa de indicação

**Campos identificados:**
```sql
id                      INT PRIMARY KEY
referrer_uid            VARCHAR(255)  -- Google UID do indicador
referred_uid            VARCHAR(255)  -- Google UID do indicado
referral_code           VARCHAR(50)   -- Código de referral
commission_brl          DECIMAL(10,2) -- Comissão gerada
status                  VARCHAR(20)   -- 'pending', 'approved', 'paid'
created_at              DATETIME      -- Data registro
```

### **8. ⚙️ `system_config` (Configurações do Sistema)**
**Propósito**: Configurações globais do sistema

**Campos identificados:**
```sql
config_key              VARCHAR(100) PRIMARY KEY
config_value            JSON          -- Valor da configuração
description             TEXT          -- Descrição
is_public               BOOLEAN       -- Se é público
updated_at              DATETIME      -- Última atualização
```

### **9. 🔐 `user_sessions` (Sessões de Usuário)**
**Propósito**: Gerenciamento de sessões de login

**Campos identificados:**
```sql
id                      INT PRIMARY KEY
user_id                 INT           -- FK para players.id
session_id              VARCHAR(255)  -- Session ID
session_token           VARCHAR(255)  -- Token único
google_uid              VARCHAR(255)  -- Google UID
ip_address              VARCHAR(45)   -- IP
user_agent              TEXT          -- User agent
created_at              DATETIME      -- Data criação
expires_at              DATETIME      -- Data expiração
```

---

## 🔗 RELACIONAMENTOS INFERIDOS

```
players
  ├──┬ withdrawals (player_id → players.id)
  ├──┬ transactions (google_uid → players.google_uid)
  ├──┬ game_sessions (google_uid → players.google_uid)
  ├──┬ stakes (player_id → players.id)
  ├──┬ referrals (referrer_uid/referred_uid → players.google_uid)
  └──┬ user_sessions (user_id → players.id)

game_sessions
  └──┬ game_events (session_id → game_sessions.id)
```

---

## ⚠️ PROBLEMAS IDENTIFICADOS

### **1. 🚨 INCONSISTÊNCIA: APY Staking**
```sql
-- Código mostra APY fixo 5%
-- Marketing mostra 5-12% APY
-- Tabela stakes precisa suportar tiers dinâmicos
```

### **2. ⚠️ MIGRAÇÃO INCOMPLETA**
```sql
-- Sistema híbrido: google_uid + wallet_address
-- Algumas queries usam OR (google_uid OR wallet_address)
-- Deveria migrar completamente para google_uid
```

### **3. ❌ FALTANDO CONSTRAINTS**
```sql
-- Não identificadas FOREIGN KEY constraints
-- Não identificadas UNIQUE constraints (google_uid deveria ser único)
-- Não identificados INDEXES otimizados
```

### **4. ⚠️ NORMALIZAÇÃO INSUFICIENTE**
```sql
-- payment_details como JSON (ok para flexibilidade)
-- Mas dificulta queries e relatórios
-- Sem tabelas normalizadas para métodos de pagamento
```

### **5. ❌ SEM BACKUP/ROLLBACK DOCUMENTADO**
```sql
-- Não há scripts de backup
-- Não há migrações versionadas
-- Não há rollback plan documentado
```

---

## ✅ PONTOS FORTES

### **1. ESTRUTURA FLEXÍVEL**
```sql
-- Suporte a Google Auth + legacy wallet
-- JSON fields para dados flexíveis
-- Suporte a múltiplas moedas (BRL + USDT)
```

### **2. AUDIT TRAIL COMPLETO**
```sql
-- Tabela transactions para todo histórico
-- game_events para analytics detalhado
-- Timestamps em todas tabelas
```

### **3. SEGURANÇA BÁSICA**
```sql
-- Sessões com tokens únicos
-- Rate limiting implementado
-- CAPTCHA integration
```

---

## 🔧 RECOMENDAÇÕES URGENTES

### **1. CRIAR SCHEMA COMPLETO**
```sql
-- Gerar CREATE TABLE scripts completos
-- Documentar todas relações (FKs)
-- Criar INDEXES otimizados
```

### **2. COMPLETAR MIGRAÇÃO**
```sql
-- Remover dependência de wallet_address
-- Migrar todos dados para google_uid
-- Remover código legacy
```

### **3. IMPLEMENTAR CONSTRAINTS**
```sql
-- Adicionar FOREIGN KEY constraints
-- Adicionar UNIQUE constraints (google_uid)
-- Adicionar CHECK constraints (valores válidos)
```

### **4. MELHORAR NORMALIZAÇÃO**
```sql
-- Criar tabela payment_methods
-- Normalizar dados de transações
-- Separar dados estáticos de dinâmicos
```

### **5. IMPLEMENTAR BACKUP SYSTEM**
```sql
-- Scripts de backup automático
-- Migrações versionadas
-- Rollback procedures
```

---

## 🧪 TESTES RECOMENDADOS

### **Teste 1: Integridade Referencial**
```sql
-- Verificar todos relacionamentos
SELECT * FROM withdrawals w 
LEFT JOIN players p ON w.player_id = p.id 
WHERE p.id IS NULL;

-- Verificar google_uid único
SELECT google_uid, COUNT(*) 
FROM players 
WHERE google_uid IS NOT NULL 
GROUP BY google_uid 
HAVING COUNT(*) > 1;
```

### **Teste 2: Consistência Financeira**
```sql
-- Verificar soma de transações = saldo
SELECT 
    p.google_uid,
    p.balance_brl,
    SUM(CASE WHEN t.type = 'game_win' THEN t.amount_brl ELSE 0 END) as total_wins,
    SUM(CASE WHEN t.type = 'withdraw' THEN t.amount_brl ELSE 0 END) as total_withdrawals
FROM players p
LEFT JOIN transactions t ON p.google_uid = t.google_uid
GROUP BY p.google_uid, p.balance_brl;
```

### **Teste 3: Performance**
```sql
-- Verificar indexes
SHOW INDEXES FROM players;
SHOW INDEXES FROM transactions;
SHOW INDEXES FROM withdrawals;

-- Queries lentas
EXPLAIN SELECT * FROM transactions WHERE google_uid = ?;
```

---

## 📈 PLANO DE AÇÃO PARA O BANCO

### **FASE 1: DOCUMENTAÇÃO (1-2 dias)**
1. Gerar schema completo do banco atual
2. Documentar todas relações e constraints
3. Criar diagrama ER (Entity Relationship)

### **FASE 2: OTIMIZAÇÃO (2-3 dias)**
4. Adicionar FOREIGN KEY constraints
5. Criar INDEXES otimizados
6. Normalizar dados (payment_methods, etc.)

### **FASE 3: MIGRAÇÃO (3-4 dias)**
7. Completar migração para google_uid only
8. Remover código/dados legacy
9. Atualizar todas queries

### **FASE 4: BACKUP & MONITORING (1-2 dias)**
10. Implementar backup automático
11. Configurar monitoring
12. Criar relatórios de integridade

---

## 🎯 PRÓXIMOS PASSOS IMEDIATOS

### **1. 🚨 PRIORIDADE ALTA:**
- Recuperar schema real do banco de produção
- Verificar integridade referencial atual
- Identificar dados corrompidos/inconsistentes

### **2. ⚠️ PRIORIDADE MÉDIA:**
- Otimizar queries mais usadas
- Implementar backups
- Documentar procedures

### **3. 🔧 PRIORIDADE BAIXA:**
- Refatorar normalização
- Implementar partitioning (se necessário)
- Otimizar storage

---

**STATUS**: ⚠️ **ESTRUTURA INFERIDA, FALTA SCHEMA REAL**  
**RISCO**: 🔴 **ALTO** (sem documentação completa)  
**PRÓXIMO**: 🎯 **OBTER SCHEMA REAL DO BANCO DE PRODUÇÃO**

*Análise baseada em código PHP + arquivo SQL encontrado.* 🗃️