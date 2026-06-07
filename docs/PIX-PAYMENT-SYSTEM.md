# Sistema de Pagamento PIX - Documentação Completa

Documentação do sistema de pagamento PIX (cash-in e cash-out) utilizando o gateway **ZettPay**.
Pronta para configurar e integrar em outro projeto.

---

## Sumário

1. [Visão Geral](#1-visão-geral)
2. [Variáveis de Ambiente e Configuração](#2-variáveis-de-ambiente-e-configuração)
3. [Banco de Dados - Tabelas Necessárias](#3-banco-de-dados---tabelas-necessárias)
4. [ZettPay API Client](#4-zettpay-api-client)
5. [Cash-In (Depósitos PIX)](#5-cash-in-depósitos-pix)
6. [Cash-Out (Saques PIX)](#6-cash-out-saques-pix)
7. [Webhook - Confirmação de Pagamentos](#7-webhook---confirmação-de-pagamentos)
8. [Painel Admin - Gerenciamento de Saques](#8-painel-admin---gerenciamento-de-saques)
9. [Fila de Saques Pública](#9-fila-de-saques-pública)
10. [Segurança e Boas Práticas](#10-segurança-e-boas-práticas)
11. [Checklist de Integração](#11-checklist-de-integração)

---

## 1. Visão Geral

### Arquitetura

```
┌─────────────┐     ┌──────────────┐     ┌──────────────┐
│  Frontend   │────▶│  Backend PHP │────▶│  ZettPay API │
│  (wallet)   │     │  (API REST)  │     │  (Gateway)   │
└─────────────┘     └──────┬───────┘     └──────┬───────┘
                           │                     │
                           │    ┌────────────┐   │
                           └───▶│  MySQL DB  │◀──┘
                                └────────────┘
                                     ▲
                           ┌─────────┘
                    ┌──────┴───────┐
                    │   Webhook    │ (ZettPay notifica o backend)
                    └──────────────┘
```

### Fluxos Principais

- **Cash-In (Depósito):** Usuário solicita depósito → Backend gera cobrança PIX na ZettPay → Usuário paga via QR Code → ZettPay envia webhook → Backend credita saldo
- **Cash-Out (Saque):** Usuário solicita saque → Saldo é debitado → Admin aprova (manual ou automático) → Backend envia pagamento via ZettPay → ZettPay envia webhook → Saque marcado como concluído

### Arquivos do Sistema

| Arquivo | Função |
|---------|--------|
| `api/config.php` | Configuração (credenciais ZettPay, limites, constantes) |
| `api/zettpay-client.php` | Cliente HTTP da API ZettPay (auth, deposit, cashout, balance) |
| `api/deposit.php` | Endpoint para criar depósito PIX (gera QR Code) |
| `api/deposit-status.php` | Polling do frontend para verificar pagamento |
| `api/zettpay-webhook.php` | Recebe notificações da ZettPay (confirma pagamentos) |
| `api/withdraw.php` | Endpoint para solicitar saque |
| `api/auto-withdraw.php` | Processamento automático de saques via cron |
| `api/withdrawal-queue.php` | Fila de saques pública (read-only) |
| `api/admin-ajax.php` | Ações admin (aprovar/rejeitar saques) |
| `api/migrate.php` | Criação de tabelas do banco de dados |

---

## 2. Variáveis de Ambiente e Configuração

### Credenciais ZettPay (obrigatórias)

```php
// api/config.php

define('ZETTPAY_BASE_URL',      getenv('ZETTPAY_BASE_URL')      ?: 'https://api.zettpay.io/api');
define('ZETTPAY_AUTH_URL',       getenv('ZETTPAY_AUTH_URL')       ?: 'https://api.zettpay.io/api/oauth/token');
define('ZETTPAY_CLIENT_ID',     getenv('ZETTPAY_CLIENT_ID')      ?: 'SEU_CLIENT_ID_AQUI');
define('ZETTPAY_CLIENT_SECRET', getenv('ZETTPAY_CLIENT_SECRET')  ?: 'SEU_CLIENT_SECRET_AQUI');
```

**Como obter as credenciais:**
1. Cadastre-se em [zettpay.io](https://zettpay.io)
2. Acesse o painel de desenvolvedor
3. Crie uma aplicação e obtenha `client_id` e `client_secret`
4. Configure a URL de webhook no painel ZettPay (ver seção 7)

### Limites de Depósito

```php
define('MIN_DEPOSIT_BRL', 1.00);    // Mínimo para depósito: R$ 1,00
define('MAX_DEPOSIT_BRL', 500.00);  // Máximo para depósito: R$ 500,00
```

### Limites de Saque

```php
define('MIN_WITHDRAW_BRL', 50.00);       // Mínimo para saque: R$ 50,00
define('WITHDRAW_METHODS', ['pix']);      // Métodos aceitos
define('WITHDRAW_COOLDOWN_HOURS', 24);   // Cooldown entre saques
```

### Token de Segurança (cron/admin)

```php
define('RECONCILE_CRON_TOKEN', getenv('RECONCILE_CRON_TOKEN') ?: 'seu_token_seguro_aqui');
```

### Variáveis de Ambiente Recomendadas

```env
# ZettPay Gateway
ZETTPAY_BASE_URL=https://api.zettpay.io/api
ZETTPAY_AUTH_URL=https://api.zettpay.io/api/oauth/token
ZETTPAY_CLIENT_ID=clt_xxxxxxxxxxxxx
ZETTPAY_CLIENT_SECRET=sec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# Segurança
RECONCILE_CRON_TOKEN=rcn_seu_token_seguro

# Banco de Dados
MYSQLHOST=127.0.0.1
MYSQLPORT=3306
MYSQLDATABASE=seu_banco
MYSQLUSER=seu_usuario
MYSQLPASSWORD=sua_senha
```

---

## 3. Banco de Dados - Tabelas Necessárias

### 3.1 `zettpay_transactions` — Transações PIX (depósitos e saques)

```sql
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Campos importantes:**
- `external_id` — Identificador único gerado pelo seu sistema. Usado como chave de idempotência na ZettPay
- `zettpay_id` — ID da transação retornado pela ZettPay (preenchido pelo webhook)
- `type` — `deposit` (cash-in) ou `cashout` (cash-out)
- `status` — `pending` → `confirmed` | `expired` | `failed`
- `qr_code` / `pix_copy_paste` — Código PIX EMV para depósitos (copia e cola)
- `webhook_payload` — JSON bruto recebido pelo webhook (auditoria)
- `withdrawal_id` — FK para tabela `withdrawals` (apenas para cashout)

### 3.2 `withdrawals` — Solicitações de Saque

```sql
CREATE TABLE IF NOT EXISTS withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount_brl DECIMAL(15,6) NOT NULL,
    amount_usdt DECIMAL(15,6) DEFAULT 0,
    wallet_address VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','processing','under_review','completed','rejected','failed') NOT NULL DEFAULT 'pending',
    admin_notes TEXT DEFAULT NULL,
    zettpay_external_id VARCHAR(100) DEFAULT NULL,
    zettpay_status VARCHAR(30) DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_zettpay_ext_id (zettpay_external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Campos importantes:**
- `wallet_address` — Reutilizado para indicar o método de pagamento (ex: "PIX")
- `admin_notes` — JSON com dados PIX: `{"method":"pix","details":"CPF","pix_key_type":"cpf","google_uid":"..."}`
- `status` — Ciclo de vida: `pending` → `processing` → `completed` | `rejected` | `failed`
- `zettpay_external_id` — Vincula à transação ZettPay quando processado
- `zettpay_status` — Status do processamento: `null` → `sending` → `processing` → `confirmed` | `failed`

### 3.3 `webhook_log` — Anti-replay de Webhooks

```sql
CREATE TABLE IF NOT EXISTS webhook_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fingerprint VARCHAR(32) NOT NULL,
    external_id VARCHAR(100) NOT NULL,
    event VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_fingerprint (fingerprint),
    INDEX idx_external_id (external_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Finalidade:** Impede que o mesmo webhook seja processado duas vezes. O `fingerprint` é o MD5 do body bruto.

### 3.4 `saved_pix_keys` — Chaves PIX Salvas dos Usuários

```sql
CREATE TABLE IF NOT EXISTS saved_pix_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    pix_key VARCHAR(255) NOT NULL,
    pix_key_type VARCHAR(20) NOT NULL,
    label VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_pix_key_type (pix_key, pix_key_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Finalidade:** Armazena a chave PIX (CPF) do usuário para prevenir que o mesmo CPF seja usado em múltiplas contas.

### 3.5 `transactions` — Histórico Geral (auditoria)

Tabela genérica de transações para exibição no histórico do usuário:

```sql
-- Colunas relevantes (tabela já existente no sistema):
-- google_uid VARCHAR(128) — ID do usuário
-- type VARCHAR(30) — 'deposit', 'withdraw', 'credit_purchase', 'withdraw_reject'
-- amount DECIMAL(15,6)
-- amount_brl DECIMAL(15,2)
-- description TEXT
-- status VARCHAR(20) — 'pending', 'completed', 'failed'
-- created_at DATETIME
```

### 3.6 `users` — Colunas Necessárias na Tabela de Usuários

```sql
-- Colunas que devem existir na tabela users:
ALTER TABLE users ADD COLUMN balance_brl DECIMAL(15,6) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN total_earned_brl DECIMAL(15,6) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN total_withdrawn_brl DECIMAL(15,6) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN withdrawal_limit INT DEFAULT NULL;
```

### 3.7 `game_settings` — Configurações Dinâmicas

```sql
-- Configurações usadas pelo sistema de pagamento:
-- 'withdrawals_enabled' → 'true'/'false' — Liga/desliga saques globalmente
-- 'max_withdrawal_requests' → '2' — Máximo de saques simultâneos por usuário
-- 'auto_withdraw_enabled' → 'true'/'false' — Liga/desliga processamento automático
-- 'auto_withdraw_batch_size' → '5' — Quantos saques processar por vez
```

---

## 4. ZettPay API Client

Arquivo: `api/zettpay-client.php`

### 4.1 Autenticação (OAuth2)

A ZettPay usa OAuth2 Client Credentials. O token é obtido e cacheado em memória:

```php
function zettpayGetToken() {
    // POST https://api.zettpay.io/api/oauth/token
    // Body: { "client_id": "...", "client_secret": "..." }
    // Resposta: { "access_token": "...", "expires_in": 3600 }
    // Token cacheado com margem de 5 minutos antes da expiração
}
```

### 4.2 Endpoints da API

| Operação | Método | Endpoint | Função PHP |
|----------|--------|----------|------------|
| Autenticação | POST | `/oauth/token` | `zettpayGetToken()` |
| Criar depósito (cash-in) | POST | `/transactions` | `zettpayCreateDeposit()` |
| Consultar depósito | GET | `/transactions/lookup?external_id=X` | `zettpayLookupDeposit()` |
| Criar saque (cash-out) | POST | `/pix/pay` | `zettpayCreateCashout()` |
| Consultar saque | GET | `/pix/cashouts/lookup?external_id=X` | `zettpayLookupCashout()` |
| Consultar saldo gateway | GET | `/pix/wallet-info` | `zettpayGetBalance()` |

### 4.3 Função de Requisição Genérica

```php
function zettpayRequest($method, $endpoint, $body = null, $idempotencyKey = null) {
    // Headers:
    //   Authorization: Bearer {token}
    //   Content-Type: application/json
    //   Idempotency-Key: {idempotencyKey} (opcional, previne duplicatas)
    //
    // Retorna: ['success' => bool, 'data' => array, 'http_code' => int, 'error' => string]
}
```

### 4.4 Criar Depósito PIX

```php
function zettpayCreateDeposit($amount, $externalId, $description, $payer, $additionalFields) {
    // POST /transactions
    // Body:
    // {
    //   "amount": 10.00,
    //   "description": "Depósito UNOBIX - Usuário #123",
    //   "external_id": "DEP-123-1700000000-a1b2c3d4",
    //   "payer_name": "João Silva",
    //   "payer_email": "joao@email.com",
    //   "payer_document": "12345678900",
    //   "additional_fields": { "user_id": "123" }
    // }
    //
    // Resposta da ZettPay:
    // {
    //   "id": "zt_abc123",
    //   "qr_code": "00020126...EMV_PIX_CODE",  ← Código copia-e-cola PIX
    //   "amount": 10.00,
    //   "status": "pending",
    //   "expires_at": "2025-01-01T01:00:00Z",
    //   "fee_amount": 0.00
    // }
}
```

### 4.5 Criar Saque PIX

```php
function zettpayCreateCashout($amount, $pixKey, $pixKeyType, $externalId, $metadata) {
    // POST /pix/pay
    // Body:
    // {
    //   "amount": 50.00,
    //   "key_type": "document",    ← Tipos: document (CPF/CNPJ), email, phone, evp
    //   "key": "12345678900",      ← Chave PIX do destinatário
    //   "external_id": "WDR-456-1700000000-a1b2c3d4"
    // }
    //
    // Resposta:
    // {
    //   "id": "zt_xyz789",
    //   "provider_transaction_id": "...",
    //   "status": "PROCESSING"
    // }
}
```

### 4.6 Geração de External IDs

```php
// Depósito: DEP-{user_id}-{timestamp}-{random_hex}
function zettpayDepositExternalId($userId) {
    return 'DEP-' . $userId . '-' . time() . '-' . bin2hex(random_bytes(4));
}

// Saque: WDR-{withdrawal_id}-{timestamp}-{random_hex}
function zettpayWithdrawExternalId($withdrawalId) {
    return 'WDR-' . $withdrawalId . '-' . time() . '-' . bin2hex(random_bytes(4));
}

// Compra de créditos: CRD-{user_id}-PKG{package_id}-{timestamp}-{random_hex}
// Exemplo: CRD-123-PKG5-1700000000-a1b2c3d4
```

### 4.7 Mapeamento de Tipos de Chave PIX

```php
// Conversão do tipo local → tipo da API ZettPay:
$keyTypeMap = [
    'cpf'       => 'document',
    'cnpj'      => 'document',
    'document'  => 'document',
    'email'     => 'email',
    'phone'     => 'phone',
    'celular'   => 'phone',
    'aleatoria' => 'evp',
    'evp'       => 'evp'
];
```

---

## 5. Cash-In (Depósitos PIX)

### 5.1 Fluxo Completo

```
Usuário clica "Depositar"
         │
         ▼
Frontend envia POST /api/deposit.php
    { google_uid, amount }
         │
         ▼
Backend valida: login, limites, conta ativa, depósitos pendentes (<3)
         │
         ▼
Backend chama ZettPay: POST /transactions
         │
         ▼
ZettPay retorna QR Code PIX (código EMV)
         │
         ▼
Backend salva em zettpay_transactions (status: pending)
Backend registra em transactions (status: pending)
         │
         ▼
Frontend exibe QR Code para o usuário
         │
         ▼
Usuário paga via app do banco
         │
         ├──── ZettPay envia webhook (POST /api/zettpay-webhook.php)
         │     Backend verifica na API, credita saldo, marca como confirmed
         │
         └──── Frontend faz polling (GET /api/deposit-status.php)
               Backend consulta API ZettPay, credita se pago
```

### 5.2 Endpoint: Criar Depósito

**POST** `/api/deposit.php`

**Request:**
```json
{
    "google_uid": "abc123def456...",
    "amount": 10.00
}
```

**Response (sucesso):**
```json
{
    "success": true,
    "external_id": "DEP-123-1700000000-a1b2c3d4",
    "amount_brl": 10.00,
    "qr_code": "00020126580014br.gov.bcb.pix...",
    "pix_copy_paste": "00020126580014br.gov.bcb.pix...",
    "expires_at": "2025-01-01T01:00:00Z",
    "message": "PIX gerado com sucesso! Escaneie o QR Code ou copie o código para pagar."
}
```

**Validações:**
- `google_uid` válido (21-128 caracteres alfanuméricos)
- Valor entre `MIN_DEPOSIT_BRL` (R$ 1,00) e `MAX_DEPOSIT_BRL` (R$ 500,00)
- Usuário existe e não está banido
- Máximo 3 depósitos pendentes simultâneos (últimos 30 minutos)

### 5.3 Endpoint: Consultar Status (Polling)

**GET/POST** `/api/deposit-status.php`

**Request:**
```json
{
    "google_uid": "abc123def456...",
    "external_id": "DEP-123-1700000000-a1b2c3d4"
}
```

**Response:**
```json
{
    "success": true,
    "external_id": "DEP-123-1700000000-a1b2c3d4",
    "status": "confirmed",
    "amount_brl": 10.00,
    "created_at": "2025-01-01 00:00:00",
    "confirmed_at": "2025-01-01 00:05:00",
    "expires_at": "2025-01-01 01:00:00",
    "is_confirmed": true,
    "is_expired": false,
    "is_failed": false
}
```

**Rate limiting:** Máximo 1 consulta à API ZettPay por depósito a cada 10 segundos (cache em arquivo temporário).

**Comportamento:** Quando `status = pending`, o backend consulta a API ZettPay diretamente. Se a API retornar `paid`/`completed`/`approved`, o saldo é creditado imediatamente (sem esperar webhook).

### 5.4 Confirmação de Depósito (lógica interna)

A confirmação pode acontecer por **duas vias** (a primeira que chegar executa):

1. **Via Webhook** (`zettpay-webhook.php`) — ZettPay notifica
2. **Via Polling** (`deposit-status.php`) — Frontend consulta e backend verifica na API

Ambas usam `SELECT ... FOR UPDATE` para evitar duplicatas (idempotência).

Ao confirmar:
```sql
-- 1. Atualizar zettpay_transactions
UPDATE zettpay_transactions SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?;

-- 2. Creditar saldo do usuário
UPDATE users SET balance_brl = balance_brl + ?, total_earned_brl = total_earned_brl + ? WHERE id = ?;

-- 3. Atualizar histórico
UPDATE transactions SET status = 'completed' WHERE ... AND status = 'pending';
```

### 5.5 Tipos de Depósito (por prefixo do external_id)

O sistema usa o prefixo do `external_id` para determinar o tipo de operação:

| Prefixo | Tipo | Ação na Confirmação |
|---------|------|---------------------|
| `DEP-` | Depósito de saldo | Credita `balance_brl` |
| `CRD-` | Compra de créditos | Credita `credits` via tabela `credit_purchases` |
| `PRM-` | Compra de premium | Ativa assinatura premium |
| `EXP-` | Aluguel de nave | Ativa aluguel na tabela `exploration_rentals` |

---

## 6. Cash-Out (Saques PIX)

### 6.1 Fluxo Completo

```
Usuário solicita saque
         │
         ▼
POST /api/withdraw.php
    { google_uid, amount, pix_key, pix_key_type }
         │
         ▼
Backend valida: saldo, limites, CPF válido, CPF não duplicado, saques habilitados
         │
         ▼
Backend debita saldo do usuário IMEDIATAMENTE
Backend cria registro em withdrawals (status: pending)
Backend registra em transactions (status: pending)
         │
         ▼
Saque fica na fila de processamento
         │
         ├──── OPÇÃO A: Admin aprova manualmente no painel
         │     POST /api/admin-ajax.php { action: "approve_withdrawal_zettpay" }
         │     Backend chama ZettPay: POST /pix/pay
         │
         └──── OPÇÃO B: Auto-withdraw via cron
               GET /api/auto-withdraw.php?token=XXX
               Processa em lotes (batch_size configurável)
               Backend chama ZettPay: POST /pix/pay
         │
         ▼
ZettPay processa o pagamento PIX
         │
         ▼
ZettPay envia webhook (cashout.updated)
         │
         ▼
Backend marca saque como 'completed'
```

### 6.2 Endpoint: Solicitar Saque

**POST** `/api/withdraw.php`

**Request:**
```json
{
    "google_uid": "abc123def456...",
    "amount": 50.00,
    "pix_key": "12345678900",
    "pix_key_type": "cpf",
    "payment_method": "pix"
}
```

**Response (sucesso):**
```json
{
    "success": true,
    "message": "Solicitação enviada com sucesso",
    "withdrawal_id": 456,
    "amount_brl": 50.00,
    "payment_method": "pix",
    "new_balance": 150.00,
    "status": "pending",
    "estimated_processing": "Acompanhe o processamento em tempo real na fila de saques"
}
```

**Validações:**
- Saques habilitados globalmente (`game_settings.withdrawals_enabled`)
- Valor mínimo: `MIN_WITHDRAW_BRL` (R$ 50,00)
- Chave PIX (CPF) válida — apenas 11 dígitos numéricos
- CPF não vinculado a outra conta (`saved_pix_keys`)
- Saldo suficiente (`balance_brl >= amount`)
- Limite de saques simultâneos (default: 2, override por usuário via `users.withdrawal_limit`)

**Ações ao criar:**
1. Debita `balance_brl` imediatamente
2. Incrementa `total_withdrawn_brl`
3. Cria `withdrawals` com `status = 'pending'`
4. Registra em `transactions` com `status = 'pending'`
5. Auto-salva CPF em `saved_pix_keys` (prevenção anti-fraude)

### 6.3 Ciclo de Vida do Saque

```
pending ──┬──▶ processing ──▶ completed    (sucesso)
          │        │
          │        └──────────▶ rejected     (ZettPay rejeitou → saldo devolvido)
          │        └──────────▶ failed       (ZettPay falhou → saldo devolvido)
          │
          ├──▶ under_review ──▶ (volta para pending ou rejected)
          │
          └──▶ rejected       (admin rejeitou → saldo devolvido)
```

### 6.4 Processamento Automático (Auto-Withdraw)

Arquivo: `api/auto-withdraw.php`

**Chamada via cron:**
```
GET /api/auto-withdraw.php?token=SEU_RECONCILE_CRON_TOKEN
```

**Ou forçar execução:**
```
GET /api/auto-withdraw.php?token=XXX&force=1
```

**Configurações (via `game_settings`):**
- `auto_withdraw_enabled` — `true`/`false`
- `auto_withdraw_batch_size` — Quantos saques processar por execução (default: 5, max: 50)

**Mecanismo de Lock (anti-duplicata):**
1. Marca `zettpay_status = 'sending'` (lock atômico via `UPDATE ... WHERE zettpay_status IS NULL`)
2. Se `rowCount() === 0`, outro processo já está tratando → pula
3. Chama API ZettPay (`POST /pix/pay`)
4. Se sucesso: muda `status = 'processing'`, cria registro em `zettpay_transactions`
5. Se chave PIX inválida: rejeita, devolve saldo ao usuário
6. Se erro temporário: libera lock (`zettpay_status = 'retry'`), tenta na próxima execução
7. Locks antigos (> 5 min) são limpos automaticamente

**Cron recomendado (a cada 5 minutos):**
```bash
*/5 * * * * curl -s "https://seusite.com/api/auto-withdraw.php?token=SEU_TOKEN" > /dev/null 2>&1
```

### 6.5 Processamento Manual (Admin)

No painel admin, o administrador pode:

1. **Aprovar via ZettPay** — `action: "approve_withdrawal_zettpay"`
   - Extrai dados PIX do `admin_notes`
   - Chama `zettpayCreateCashout()` para enviar pagamento
   - Muda status para `processing`
   - Cria registro em `zettpay_transactions`

2. **Aprovar manualmente** — `action: "approve_withdrawal"`
   - Marca como `completed` sem usar ZettPay
   - Para pagamentos feitos por fora do gateway

3. **Rejeitar** — `action: "reject_withdrawal"`
   - Devolve saldo ao usuário (`balance_brl += amount`)
   - Registra transação de estorno (`type: 'withdraw_reject'`)

4. **Enviar para análise** — `action: "send_to_review"`
   - Muda status para `under_review`

5. **Verificar status na ZettPay** — `action: "check_cashout_status"`
   - Consulta API ZettPay via `zettpayLookupCashout()`
   - Atualiza status local baseado na resposta

6. **Forçar conclusão** — `action: "force_complete_withdrawal"`
   - Marca como `completed` sem verificar ZettPay

---

## 7. Webhook — Confirmação de Pagamentos

### 7.1 Configuração

No painel da ZettPay, configure a URL de webhook:

```
https://seusite.com/api/zettpay-webhook.php
```

**Método:** POST
**Content-Type:** application/json

### 7.2 Payload Recebido

**Cash-In (depósito confirmado):**
```json
{
    "event": "transaction.updated",
    "type": "cashin",
    "data": {
        "external_id": "DEP-123-1700000000-a1b2c3d4",
        "status": "PAID",
        "amount": 10.00,
        "provider_transaction_id": "zt_abc123",
        "id": "..."
    }
}
```

**Cash-Out (saque confirmado):**
```json
{
    "event": "cashout.updated",
    "type": "cashout",
    "data": {
        "external_id": "WDR-456-1700000000-a1b2c3d4",
        "status": "APPROVED",
        "amount": 50.00,
        "provider_transaction_id": "zt_xyz789",
        "id": "..."
    }
}
```

### 7.3 Processamento do Webhook

```
Webhook recebido (POST)
         │
         ▼
1. Parsear JSON do body
2. Anti-replay: MD5 do body → INSERT IGNORE em webhook_log
   (se rowCount === 0, já processado → retorna 200 e sai)
         │
         ▼
3. Identificar tipo de evento:
   - "transaction.updated" + "cashin" → processDeposit()
   - "cashout.updated" + "cashout" → processCashout()
         │
         ▼
4. VERIFICAÇÃO DUPLA (segurança):
   Consulta API ZettPay (GET /transactions/lookup) para confirmar
   que o pagamento realmente existe e está pago.
   ⚠️ NUNCA confiar apenas no webhook — sempre verificar na fonte.
         │
         ▼
5. Processar dentro de transação com SELECT FOR UPDATE:
   - Cash-in: creditar saldo ou créditos ao usuário
   - Cash-out: marcar saque como completed
         │
         ▼
6. Retornar HTTP 200 { "received": true }
```

### 7.4 Status Aceitos

| Status do Webhook | Ação |
|-------------------|------|
| `PAID`, `COMPLETED`, `APPROVED` | Confirmar (creditar/completar) |
| `EXPIRED`, `FAILED` | Marcar como falho |
| `REJECTED` (cash-out) | Devolver saldo ao usuário |

### 7.5 Anti-Replay

```php
// Fingerprint = MD5 do body bruto
$webhookFingerprint = md5($rawBody);

// INSERT IGNORE — se fingerprint já existe, rowCount = 0
$stmt = $pdo->prepare("INSERT IGNORE INTO webhook_log (fingerprint, external_id, event, created_at) VALUES (?, ?, ?, NOW())");
$stmt->execute([$webhookFingerprint, $externalId, $event]);

if ($stmt->rowCount() === 0) {
    // Webhook já processado — retornar 200 e sair
    echo json_encode(['received' => true]);
    exit;
}
```

### 7.6 Verificação Dupla (Anti-Fraude)

Antes de creditar qualquer valor, o webhook SEMPRE verifica na API ZettPay:

```php
// Consultar API ZettPay
$apiResult = zettpayLookupDeposit($externalId);
$verifiedStatus = strtoupper($apiData['status'] ?? '');

// Se a API NÃO confirma o pagamento, BLOQUEAR
if (!in_array($verifiedStatus, ['PAID', 'COMPLETED', 'APPROVED'])) {
    secureLog("WEBHOOK_VERIFY_MISMATCH | BLOQUEADO - webhook forjado?");
    return; // NÃO creditar
}
```

### 7.7 Tratamento de Falha em Cash-Out

Quando um saque falha na ZettPay (`FAILED` ou `REJECTED`):

```php
// 1. Devolver saldo ao usuário
$pdo->prepare("UPDATE users SET balance_brl = balance_brl + ? WHERE id = ?")->execute([$amount, $userId]);

// 2. Marcar withdrawal como rejected
$pdo->prepare("UPDATE withdrawals SET status = 'rejected' WHERE id = ?")->execute([$withdrawalId]);

// 3. Registrar transação de estorno
$pdo->prepare("INSERT INTO transactions (google_uid, type, amount_brl, description, status) VALUES (?, 'withdraw_reject', ?, ?, 'completed')")
    ->execute([$googleUid, $amount, "Saque PIX #{$id} falhou - saldo devolvido"]);
```

---

## 8. Painel Admin — Gerenciamento de Saques

### 8.1 Listar Saques

**Request:**
```json
{
    "action": "list_withdrawals",
    "status": "pending",
    "page": 1,
    "per_page": 50
}
```

### 8.2 Aprovar Saque via ZettPay

**Request:**
```json
{
    "action": "approve_withdrawal_zettpay",
    "id": 456
}
```

O backend extrai a chave PIX do `admin_notes`, chama a API ZettPay e move para `processing`.

### 8.3 Rejeitar Saque

**Request:**
```json
{
    "action": "reject_withdrawal",
    "id": 456,
    "reason": "Chave PIX inválida"
}
```

Devolve saldo automaticamente ao usuário.

### 8.4 Verificar Status na ZettPay

**Request:**
```json
{
    "action": "check_cashout_status",
    "id": 456
}
```

Consulta o status atual na API ZettPay e atualiza localmente.

### 8.5 Toggle Global de Saques

**Request:**
```json
{
    "action": "toggle_withdrawals"
}
```

Liga/desliga saques globalmente via `game_settings.withdrawals_enabled`.

---

## 9. Fila de Saques Pública

Arquivo: `api/withdrawal-queue.php`

Endpoint read-only que mostra a fila de saques para os usuários acompanharem:

**GET** `/api/withdrawal-queue.php?status=all&page=1`

**Response:**
```json
{
    "success": true,
    "withdrawals_enabled": true,
    "counts": {
        "pending": 5,
        "processing": 2,
        "under_review": 0,
        "completed": 150,
        "rejected": 3,
        "total": 160
    },
    "monitor": {
        "completed_24h": 12,
        "avg_per_hour": 0.5,
        "estimated_hours": 10.0,
        "pending_count": 5
    },
    "items": [
        {
            "id": 456,
            "name": "Jo****",
            "amount": "50,00",
            "status": "pending",
            "method": "PIX",
            "created_at": "2025-01-01 10:00:00",
            "processed_at": null
        }
    ],
    "pagination": {
        "page": 1,
        "total_pages": 2,
        "total_items": 160,
        "per_page": 100
    }
}
```

**Buscar por ID específico:**
```
GET /api/withdrawal-queue.php?search_id=456
```

Retorna `queue_position` com a posição na fila.

**Dados mascarados:** Nomes são ofuscados (ex: "João" → "Jo**") para privacidade.

---

## 10. Segurança e Boas Práticas

### 10.1 Idempotência

- Cada transação tem um `external_id` único
- O `external_id` é usado como `Idempotency-Key` na API ZettPay
- Webhooks duplicados são bloqueados via `webhook_log` (fingerprint MD5)
- Confirmações duplas são bloqueadas via `SELECT ... FOR UPDATE` + verificação de status

### 10.2 Verificação Dupla

- NUNCA confiar apenas no webhook
- Sempre consultar a API ZettPay (`zettpayLookupDeposit` / `zettpayLookupCashout`) antes de creditar
- Comparar status do webhook com status da API

### 10.3 Transações Atômicas

- Todas as operações financeiras usam `BEGIN TRANSACTION` + `COMMIT`
- Linhas críticas (usuário, transação) são bloqueadas com `SELECT ... FOR UPDATE`
- Em caso de erro, `ROLLBACK` automático

### 10.4 Anti-Fraude

- CPFs são validados (11 dígitos)
- CPFs são vinculados a uma única conta (`saved_pix_keys`)
- Usuários banidos não podem depositar nem sacar
- Limite de saques simultâneos por usuário (configurável)
- Saques podem ser desabilitados globalmente

### 10.5 Logging

Todas as operações financeiras são logadas via `secureLog()`:
```
[2025-01-01 10:00:00] 192.168.1.1 | ZETTPAY_DEPOSIT_CONFIRMED | external_id: DEP-123-... | amount: R$10.00
```

Prefixos de log:
- `ZETTPAY_DEPOSIT_*` — Operações de depósito
- `ZETTPAY_CASHOUT_*` — Operações de saque
- `ZETTPAY_WEBHOOK_*` — Processamento de webhooks
- `AUTO_WITHDRAW_*` — Processamento automático
- `WITHDRAW_*` — Solicitações de saque

### 10.6 Rate Limiting

- Polling de depósito: máximo 1 consulta à API por depósito a cada 10s
- Máximo 3 depósitos pendentes simultâneos por usuário
- Máximo N saques simultâneos por usuário (configurável via `game_settings` ou `users.withdrawal_limit`)

---

## 11. Checklist de Integração

### Passo 1: Configurar Banco de Dados
- [ ] Executar `api/migrate.php` para criar todas as tabelas
- [ ] Verificar se a tabela `users` tem as colunas: `balance_brl`, `total_earned_brl`, `total_withdrawn_brl`, `is_banned`, `withdrawal_limit`
- [ ] Verificar se a tabela `game_settings` tem os registros: `withdrawals_enabled`, `max_withdrawal_requests`, `auto_withdraw_enabled`, `auto_withdraw_batch_size`

### Passo 2: Configurar ZettPay
- [ ] Criar conta na ZettPay
- [ ] Obter `client_id` e `client_secret`
- [ ] Configurar variáveis de ambiente ou atualizar `api/config.php`
- [ ] Testar autenticação: verificar se `zettpayGetToken()` retorna token válido

### Passo 3: Configurar Webhook
- [ ] No painel ZettPay, configurar URL: `https://seusite.com/api/zettpay-webhook.php`
- [ ] Verificar que o endpoint é acessível publicamente (sem autenticação/firewall bloqueando)
- [ ] Testar com um depósito real de R$ 1,00

### Passo 4: Configurar Cash-In
- [ ] Copiar `api/deposit.php` e `api/deposit-status.php`
- [ ] Copiar `api/zettpay-client.php`
- [ ] Ajustar `MIN_DEPOSIT_BRL` e `MAX_DEPOSIT_BRL` conforme necessidade
- [ ] Implementar frontend com QR Code e polling (verificar a cada 5-10 segundos)

### Passo 5: Configurar Cash-Out
- [ ] Copiar `api/withdraw.php`
- [ ] Ajustar `MIN_WITHDRAW_BRL`
- [ ] Copiar `api/auto-withdraw.php` e configurar cron
- [ ] Ou implementar aprovação manual no painel admin
- [ ] Implementar frontend de solicitação de saque

### Passo 6: Segurança
- [ ] Definir `RECONCILE_CRON_TOKEN` com valor seguro (32+ caracteres aleatórios)
- [ ] NÃO expor credenciais ZettPay no frontend
- [ ] Configurar HTTPS obrigatório
- [ ] Verificar que webhooks são validados com consulta à API (verificação dupla)

### Passo 7: Monitoramento
- [ ] Verificar logs regularmente (`secureLog`)
- [ ] Monitorar webhooks não processados
- [ ] Configurar alertas para erros em `ZETTPAY_*`
- [ ] Opcional: copiar `api/withdrawal-queue.php` para fila pública

---

## Validação de Chaves PIX

```php
function validatePixKey($key, $type = 'cpf') {
    switch (strtolower($type)) {
        case 'cpf':
            return preg_match('/^\d{11}$/', preg_replace('/\D/', '', $key));
        case 'cnpj':
            return preg_match('/^\d{14}$/', preg_replace('/\D/', '', $key));
        case 'email':
            return filter_var($key, FILTER_VALIDATE_EMAIL) !== false;
        case 'phone':
            return preg_match('/^\d{10,13}$/', preg_replace('/\D/', '', $key));
        case 'evp':
            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key);
        default:
            return strlen($key) >= 5 && strlen($key) <= 100;
    }
}

function detectPixKeyType($key) {
    if (filter_var($key, FILTER_VALIDATE_EMAIL)) return 'email';
    $digits = preg_replace('/\D/', '', $key);
    if (preg_match('/^\d{11}$/', $digits)) return 'cpf';
    if (preg_match('/^\d{14}$/', $digits)) return 'cnpj';
    if (preg_match('/^\+?\d{10,13}$/', $digits)) return 'phone';
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) return 'evp';
    return 'cpf'; // fallback
}
```

---

## Resumo de Endpoints

| Endpoint | Método | Autenticação | Descrição |
|----------|--------|--------------|-----------|
| `/api/deposit.php` | POST | google_uid | Criar depósito PIX |
| `/api/deposit-status.php` | GET/POST | google_uid | Consultar status do depósito |
| `/api/withdraw.php` | POST | google_uid | Solicitar saque PIX |
| `/api/withdrawal-queue.php` | GET | Público | Ver fila de saques |
| `/api/zettpay-webhook.php` | POST | Anti-replay | Receber notificações ZettPay |
| `/api/auto-withdraw.php` | GET | Token cron | Processar saques automaticamente |
| `/api/admin-ajax.php` | POST | Sessão admin | Gerenciar saques (aprovar/rejeitar) |
| `/api/credits.php?action=buy_package` | POST | google_uid | Comprar créditos via PIX |
