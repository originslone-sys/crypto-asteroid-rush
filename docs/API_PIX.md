# API PIX — Depósitos e Saques

Documentação da integração PIX utilizada neste sistema, para referência ao implementar depósitos e saques via PIX em outro sistema.

## 1. Visão geral

- **Gateway/Provedor PIX:** ZettPay
- **Base API:** `https://api.zettpay.io/api`
- **Auth API (OAuth token):** `https://api.zettpay.io/api/oauth/token`
- **Autenticação com o gateway:** OAuth2 `client_credentials`
- **Autenticação dos endpoints internos (usuário):** identificador do usuário (`google_uid`) — **sem token de sessão**. Recomenda-se, no novo sistema, adotar autenticação forte (JWT/sessão) em vez de repetir esse modelo.
- **CORS:** liberado (`Access-Control-Allow-Origin: *`)

> ⚠️ **Nota de segurança:** no sistema atual, `client_id`/`client_secret` da ZettPay e o token de cron de reconciliação estão com fallback hardcoded no código-fonte (`api/config.php`). No novo sistema, mantenha essas credenciais **somente** em variáveis de ambiente/secret manager, nunca no código.

### 1.1 Autenticação com o gateway ZettPay

```
POST {ZETTPAY_AUTH_URL}
Content-Type: application/json

{
  "client_id": "string",
  "client_secret": "string"
}
```

Resposta:
```json
{
  "access_token": "string",
  "expires_in": 3600
}
```

Token deve ser cacheado (expirar ~5 min antes do `expires_in`) e usado em todas as chamadas seguintes:

```
Authorization: Bearer {access_token}
Idempotency-Key: {external_id}   # quando aplicável (criação de transação)
```

### 1.2 Endpoints do gateway ZettPay usados

| Método | Path | Uso |
|---|---|---|
| POST | `/transactions` | Criar cobrança PIX (cash-in / depósito) |
| GET | `/transactions/lookup?external_id=...` | Consultar status de depósito |
| POST | `/pix/pay` | Criar saque PIX (cash-out) |
| GET | `/pix/cashouts/lookup?external_id=...` | Consultar status de saque |
| GET | `/pix/wallet-info` | Consultar saldo da conta ZettPay |

---

## 2. Endpoints internos (aplicação → seus usuários)

### 2.1 Criar depósito PIX

`POST /api/deposit.php`

**Body (form/JSON):**
| Campo | Tipo | Obrigatório | Observações |
|---|---|---|---|
| `google_uid` | string | sim | identificador do usuário |
| `amount` / `amount_brl` | float | sim | valor em BRL. Min: `1.00`, Max: `500.00` |

Regras de negócio:
- Máximo de 3 depósitos pendentes por usuário a cada 30 minutos.

**Resposta (sucesso):**
```json
{
  "success": true,
  "external_id": "DEP-...",
  "amount_brl": 100.00,
  "qr_code": "string",
  "pix_copy_paste": "string",
  "expires_at": "2026-08-31T12:00:00Z",
  "message": "string"
}
```

**Resposta (erro):**
```json
{ "success": false, "error": "string" }
```

Efeitos internos: cria registro em `zettpay_transactions` (`type=deposit`, `status=pending`) e em `transactions` (`type=deposit`, `status=pending`).

---

### 2.2 Consultar status do depósito (polling)

`POST /api/deposit-status.php`

**Body:**
| Campo | Tipo | Obrigatório |
|---|---|---|
| `google_uid` | string | sim |
| `external_id` | string | sim |

**Resposta:**
```json
{
  "success": true,
  "external_id": "DEP-...",
  "status": "pending",
  "amount_brl": 100.00,
  "created_at": "...",
  "confirmed_at": null,
  "expires_at": "...",
  "is_confirmed": false,
  "is_expired": false,
  "is_failed": false
}
```

Se o status local ainda for `pending`, o backend consulta a API da ZettPay (rate-limited a 1x/10s) e confirma o depósito inline caso já esteja pago, antes de responder.

---

### 2.3 Solicitar saque PIX

`POST /api/withdraw.php`

**Body:**
| Campo | Tipo | Obrigatório | Observações |
|---|---|---|---|
| `google_uid` | string | sim | |
| `amount` / `amount_brl` | float | sim | Min: `50.00` |
| `payment_details` / `pix_key` | string | sim | somente **CPF** é aceito |
| `payment_method` | string | sim | deve ser `"pix"` |

Regras de negócio:
- Chave PIX validada como CPF (`validatePixKey`).
- CPF não pode já estar vinculado a outra conta.
- Limite de saques simultâneos pendentes (padrão: 2, configurável).
- Saldo suficiente obrigatório.
- CPF é salvo automaticamente em `saved_pix_keys` do usuário.

**Resposta (sucesso):**
```json
{
  "success": true,
  "message": "string",
  "withdrawal_id": 123,
  "amount_brl": 50.00,
  "payment_method": "pix",
  "new_balance": 450.00,
  "status": "pending",
  "estimated_processing": "string"
}
```

Efeitos internos: cria registro em `withdrawals` (`status=pending`) e em `transactions` (`type=withdraw`, `status=pending`).

---

### 2.4 Histórico de saques

`GET/POST /api/withdrawal-history.php`

**Parâmetros:** `google_uid`, `limit`, `offset`

**Resposta:**
```json
{
  "success": true,
  "withdrawals": [
    {
      "id": 1,
      "amount_brl": 50.00,
      "method": "pix",
      "pix_key": "***.***.***-**",
      "pix_key_type": "cpf",
      "status": "completed",
      "status_label": "string",
      "reject_reason": null,
      "created_at": "...",
      "processed_at": "..."
    }
  ],
  "limit": 20,
  "offset": 0,
  "has_more": false
}
```

---

### 2.5 Fila pública de saques

`GET /api/withdrawal-queue.php` (sem autenticação)

Retorna contadores agregados, tempo estimado e itens com dados mascarados (nome, valor, status, método, datas), com paginação e busca opcional por `queue_position`/`search_id`.

---

### 2.6 Chaves PIX salvas

`POST /api/saved-pix-keys.php`

**Body:** `google_uid`, `action` (`list` | `save` | `delete`), e para `save`: `pix_key`, `pix_key_type` (apenas CPF), `label`.

Regra: máximo de 1 chave salva por usuário.

---

### 2.7 Saldo da carteira

`GET/POST /api/wallet-info.php`

Retorna saldo do usuário, incluindo `pending_withdrawal_brl` (soma de saques PIX pendentes).

---

### 2.8 Endpoints administrativos / cron

**`GET /api/auto-withdraw.php`** — processa em lote saques pendentes, chamando o cash-out da ZettPay para cada um.
- Auth: `?token=` comparado a token estático de cron, ou sessão admin.
- Resposta:
```json
{
  "success": true,
  "enabled": true,
  "results": {
    "total_found": 0,
    "processed": 0,
    "failed_api": 0,
    "failed_invalid_key": 0,
    "skipped": 0,
    "errors": []
  }
}
```

**`GET/POST /api/reconcile-deposits.php`** — reconcilia depósitos pendentes entre 5min e 1h.
- Auth: `?token=` ou header `X-Cron-Token`.
- Resposta:
```json
{
  "success": true,
  "total_checked": 0,
  "confirmed": 0,
  "expired": 0,
  "still_pending": 0,
  "errors": 0,
  "details": []
}
```

**`GET /api/deposit-status-admin.php`** — versão para caixa/admin (external_id deve começar com `CAIXA-`).

---

## 3. Webhook (recebido do gateway PIX)

`POST /api/zettpay-webhook.php` (server-to-server)

**Autenticação:** o gateway não assina o payload nesta implementação. A mitigação é feita por:
1. Anti-replay: fingerprint (md5 do corpo bruto) gravado em `webhook_log`, requisições duplicadas são ignoradas.
2. Revalidação obrigatória: antes de creditar/estornar qualquer saldo, o backend consulta a API do gateway (`lookup`) para confirmar o status — **nunca confia apenas no conteúdo do webhook**.

> Recomenda-se ao novo sistema usar/exigir assinatura HMAC do provedor, se disponível, além da revalidação.

**Payload recebido:**
```json
{
  "event": "transaction.updated",
  "type": "cashin",
  "data": {
    "external_id": "string",
    "status": "PAID",
    "provider_transaction_id": "string",
    "amount": 100.00
  }
}
```

Valores possíveis:
- `event`: `transaction.updated` (depósito) | `cashout.updated` (saque)
- `type`: `cashin` | `cashout`
- `data.status`: `PAID`, `COMPLETED`, `APPROVED`, `EXPIRED`, `FAILED`, `REJECTED`

**Fluxo de processamento:**
- `transaction.updated` + `cashin`: se status é PAID/COMPLETED/APPROVED, revalida via lookup e credita o usuário (saldo, créditos, premium etc. conforme prefixo do `external_id`).
- `cashout.updated` + `cashout`: revalida via lookup; se aprovado, marca saque como `completed`; se `FAILED`/`REJECTED`, estorna saldo ao usuário e marca saque como `rejected`.

**Respostas:**
| HTTP | Corpo | Quando |
|---|---|---|
| 200 | `{"received": true}` | processado (mesmo com erro de negócio interno, para evitar retries do provedor) |
| 400 | `{"error": "Empty body"}` | corpo vazio |
| 400 | `{"error": "Invalid payload"}` | falta `event` ou `data` |
| 400 | `{"error": "Missing external_id"}` | falta `external_id` |
| 405 | `{"error": "Method not allowed"}` | método != POST |
| 500 | `{"error": "Database error"}` | falha de banco |
| 500 | `{"error": "Processing error"}` | falha no processamento |

---

## 4. Prefixos de `external_id`

Diferentes fluxos reutilizam o mesmo mecanismo de depósito PIX, diferenciados pelo prefixo do `external_id`:

| Prefixo | Fluxo |
|---|---|
| `DEP-` | Depósito padrão |
| `WDR-` | Saque |
| `CRD-` | Compra de créditos |
| `PRM-` | Compra premium |
| `EXP-` | Aluguel de exploração |

---

## 5. Modelos de dados

### `zettpay_transactions`
| Campo | Tipo |
|---|---|
| id | int |
| user_id | int |
| external_id | string (único) |
| zettpay_id | string |
| type | enum(`deposit`, `cashout`) |
| amount_brl | decimal(15,2) |
| fee_brl | decimal |
| status | string |
| pix_key | string |
| pix_key_type | string |
| qr_code | text |
| pix_copy_paste | text |
| expires_at | datetime |
| webhook_payload | json |
| withdrawal_id | int |
| error_message | string |
| created_at / confirmed_at / updated_at | datetime |

### `webhook_log` (anti-replay)
| Campo | Tipo |
|---|---|
| id | int |
| fingerprint | string(32) único |
| external_id | string |
| event | string |
| created_at | datetime |

### `saved_pix_keys`
| Campo | Tipo |
|---|---|
| id | int |
| user_id | int |
| pix_key | string |
| pix_key_type | string |
| label | string |
| created_at | datetime |

### `withdrawals`
| Campo | Tipo |
|---|---|
| id | int |
| user_id | int |
| amount_brl | decimal |
| status | enum(`pending`, `processing`, `under_review`, `completed`, `rejected`, `failed`) |
| admin_notes | json (detalhes PIX: method, pix_key_type, google_uid) |
| zettpay_external_id | string |
| zettpay_status | string |
| created_at / processed_at | datetime |

### `transactions` (histórico geral)
| Campo | Tipo |
|---|---|
| google_uid | string |
| type | enum(`deposit`, `withdraw`, `credit_purchase`, `premium_purchase`, `exploration_rent`, `withdraw_reject`) |
| amount | float |
| amount_brl | float |
| description | string |
| status | string |
| created_at | datetime |

---

## 6. Regras de valores e limites

| Regra | Valor |
|---|---|
| Depósito mínimo | R$ 5,00 |
| Depósito máximo | R$ 1000,00 |
| Saque mínimo | R$ 5,00 |


---

## 7. Recomendações para o novo sistema integrador

1. Não reutilizar autenticação apenas por `google_uid`/id de usuário sem token — usar sessão/JWT.
2. Validar assinatura do webhook do provedor, se disponível, além de revalidar via lookup na API do gateway antes de creditar/estornar saldo.
3. Implementar anti-replay (idempotência) nos webhooks, como feito via `webhook_log`.
4. Manter credenciais do gateway apenas em variáveis de ambiente/secret manager.
5. Sempre responder `200` a webhooks processados (mesmo com erro de negócio) para evitar retries excessivos do provedor, mas logar o erro internamente.
