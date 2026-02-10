# UNOBIX - Documentação Técnica Completa

**Versão do Sistema:** 7.0
**Última atualização:** 2026-02-09
**Fase:** Sistema de Anúncios integrado ao fluxo de jogo (Fase 4 completa)

---

## 📋 Índice

1. [Visão Geral](#1-visão-geral)
2. [Arquitetura do Sistema](#2-arquitetura-do-sistema)
3. [Estrutura do Banco de Dados](#3-estrutura-do-banco-de-dados)
4. [APIs Backend (PHP)](#4-apis-backend-php)
5. [Frontend (JavaScript)](#5-frontend-javascript)
6. [Fluxo de Jogo Completo](#6-fluxo-de-jogo-completo)
7. [Sistema de Anúncios](#7-sistema-de-anúncios)
8. [Sistema de Segurança](#8-sistema-de-segurança)
9. [Painel Administrativo](#9-painel-administrativo)
10. [Sistema de Staking](#10-sistema-de-staking)
11. [Sistema de Referrals](#11-sistema-de-referrals)
12. [Configurações](#12-configurações)
13. [Comunicação entre Páginas (sessionStorage)](#13-comunicação-entre-páginas-sessionstorage)
14. [Regras de Ouro](#14-regras-de-ouro)
15. [Troubleshooting](#15-troubleshooting)
16. [Catálogo Completo de Arquivos](#16-catálogo-completo-de-arquivos)
17. [Histórico de Mudanças](#17-histórico-de-mudanças)

---

## 1. Visão Geral

### O que é o UNOBIX?
UNOBIX é um jogo de nave espacial estilo "asteroid shooter" onde jogadores ganham recompensas em BRL (Real Brasileiro) ao destruir asteroides especiais. O jogo roda no navegador usando HTML5 Canvas.

### Stack Tecnológica
| Camada | Tecnologia |
|--------|------------|
| Frontend | HTML5 Canvas + JavaScript Vanilla |
| Backend | PHP 8.x |
| Banco de Dados | MySQL 8.x (Cloud SQL) |
| Autenticação | Firebase Auth (Google) |
| Hospedagem | Google Cloud Run |
| CDN/Assets | Google Cloud Storage |
| Moeda | BRL (Real Brasileiro) |
| Pagamentos | PIX |
| Anúncios | PropellerAds, Adsterra (via script_code) |

### Modelo de Negócio
- Jogadores jogam gratuitamente
- Destroem asteroides para acumular saldo em BRL
- Podem sacar via PIX quando atingirem R$ 1,00 mínimo
- Receita vem de anúncios exibidos no pré-jogo, pós-jogo e game over
- Sistema de staking permite rendimentos sobre saldo
- Programa de indicação gera comissões

---

## 2. Arquitetura do Sistema

### Diagrama de Fluxo
```
┌──────────────────────────────────────────────────────────────────────┐
│                          CLIENTE (Browser)                           │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  Páginas HTML:                                                       │
│    ├── index.html          → Landing page                            │
│    ├── game.html           → Jogo principal (Canvas + modais)        │
│    ├── pregame.html        → Loading pré-jogo com anúncios (v7.0)   │
│    ├── postgame.html       → Loading pós-jogo com anúncios (v7.0)   │
│    └── wallet.html         → Carteira, staking, saques              │
│                                                                      │
│  JavaScript Files (/js):                                             │
│    ├── auth-manager.js      → Autenticação Firebase/Google           │
│    ├── game-config.js       → CONFIG: rewards, spawn rates, limites  │
│    ├── game-main.js         → Init, botões, auto-start, redirects    │
│    ├── game-session-manager.js → Comunicação com backend (API)       │
│    ├── game-engine.js       → Lógica do jogo (Canvas, colisões)      │
│    ├── game-renderer.js     → Renderização Canvas (sprites, FX)      │
│    ├── game-ui.js           → Modais, notificações, resultados       │
│    ├── game-start.js        → Inicialização de missão                │
│    ├── captcha-manager.js   → Verificação anti-bot (hCaptcha)        │
│    └── ads-manager.js       → Gerenciamento de anúncios (v4.0)      │
│                                                                      │
│  CSS (/css):                                                         │
│    └── game.css             → Estilo do jogo e modais                │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
                              │
                              │ HTTPS / fetch()
                              ▼
┌──────────────────────────────────────────────────────────────────────┐
│                      BACKEND (Cloud Run)                             │
├──────────────────────────────────────────────────────────────────────┤
│  /api                                                                │
│    ├── config.php           → Constantes, funções, conexão DB        │
│    ├── game-start.php       → POST: Iniciar sessão de jogo           │
│    ├── game-end.php         → POST: Finalizar e creditar ganhos      │
│    ├── balance.php          → GET: Consultar saldo                   │
│    ├── wallet-info.php      → GET: Informações completas da wallet   │
│    ├── transactions.php     → GET: Histórico de transações           │
│    ├── withdraw.php         → POST: Solicitar saque PIX              │
│    ├── sync-user.php        → POST: Sincronizar usuário Firebase     │
│    ├── admin-ajax.php       → POST: API AJAX do admin panel          │
│    ├── admin-security.php   → POST: Segurança (ban, suspicious)      │
│    ├── admin-ads.php        → POST/GET: CRUD de ads + tracking       │
│    └── ads-config.php       → GET: Config pública de ads (redirect)  │
│                                                                      │
│  /admin                                                              │
│    ├── index.php            → Login e roteamento SPA                 │
│    ├── includes/            → header.php, footer.php, sidebar.php    │
│    ├── pages/ (11 páginas)  → dashboard, players, withdrawals, etc   │
│    ├── css/admin.css        → Estilo do painel (tema escuro)         │
│    └── js/admin.js          → JavaScript do painel                   │
└──────────────────────────────────────────────────────────────────────┘
                              │
                              │ Cloud SQL Socket
                              ▼
┌──────────────────────────────────────────────────────────────────────┐
│                    BANCO DE DADOS (MySQL 8.x)                        │
├──────────────────────────────────────────────────────────────────────┤
│  unobix_db (12 tabelas)                                              │
│    ├── users                → Usuários, saldos, referral_code        │
│    ├── game_sessions        → Sessões de jogo (stats, earnings)      │
│    ├── transactions         → Histórico financeiro completo          │
│    ├── withdrawals          → Solicitações de saque PIX              │
│    ├── staking              → Registros de stake ativo               │
│    ├── referrals            → Indicações e comissões                 │
│    ├── suspicious_activity  → Log de atividades suspeitas            │
│    ├── game_settings        → Configurações dinâmicas (admin)        │
│    ├── ad_slots             → Slots de anúncios configuráveis        │
│    ├── ad_impressions       → Impressões de anúncios (tracking)      │
│    ├── ad_clicks            → Cliques em anúncios (tracking)         │
│    └── rate_limits          → Controle de taxa de requisições         │
└──────────────────────────────────────────────────────────────────────┘
```

### Comunicação Cliente-Servidor
```
ANTES (v4.x - Inseguro):
  Cliente → 200+ requisições por partida → Servidor
  (1 requisição por asteroide destruído)

DEPOIS (v5.0+ - Seguro):
  Cliente → 2 requisições por partida → Servidor
  (1 no início: game-start.php | 1 no final: game-end.php)
```

---

## 3. Estrutura do Banco de Dados

### 3.1 Tabela: `users`
Armazena dados do jogador e saldo.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK AUTO_INCREMENT | ID único |
| google_uid | VARCHAR(128) UNIQUE | UID do Firebase Auth |
| email | VARCHAR(255) | Email do Google |
| display_name | VARCHAR(255) | Nome de exibição |
| photo_url | VARCHAR(500) | URL da foto do Google |
| balance_brl | DECIMAL(18,6) | Saldo disponível em BRL |
| total_earned_brl | DECIMAL(18,6) | Total ganho (histórico) |
| total_withdrawn_brl | DECIMAL(18,6) | Total sacado |
| total_played | INT | Número de partidas jogadas |
| is_banned | TINYINT(1) | Se está banido (0/1) |
| ban_reason | VARCHAR(255) | Motivo do ban |
| staked_balance_brl | DECIMAL(18,6) | Saldo em stake |
| referral_code | VARCHAR(20) UNIQUE | Código de indicação único |
| referred_by | VARCHAR(128) | google_uid de quem indicou |
| created_at | TIMESTAMP | Data de criação |
| last_login | TIMESTAMP | Último acesso |
| updated_at | TIMESTAMP | Última atualização |

**Índices:** `PRIMARY KEY (id)`, `UNIQUE (google_uid)`, `UNIQUE (referral_code)`, `INDEX (email)`

> ⚠️ **REGRA DE OURO:** Sempre usar tabela `users`, NUNCA `players`.

---

### 3.2 Tabela: `game_sessions`
Cada partida jogada.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK | ID da sessão |
| google_uid | VARCHAR(128) | UID do jogador |
| session_token | VARCHAR(64) | Token único da sessão |
| session_uuid | VARCHAR(32) | Seed para verificação |
| mission_number | INT | Número da missão |
| is_hard_mode | TINYINT(1) | Se é modo difícil |
| status | ENUM | 'active', 'completed', 'abandoned', 'flagged' |
| earnings_brl | DECIMAL(18,6) | Ganhos da sessão |
| asteroids_destroyed | INT | Total destruídos |
| common_asteroids | INT | Contador de comuns |
| rare_asteroids | INT | Contador de raros |
| epic_asteroids | INT | Contador de épicos |
| legendary_asteroids | INT | Contador de lendários |
| game_duration | INT | Duração em segundos |
| ip_address | VARCHAR(45) | IP do jogador |
| user_agent | VARCHAR(500) | User agent |
| started_at | TIMESTAMP | Início da partida |
| ended_at | TIMESTAMP | Fim da partida |
| created_at | TIMESTAMP | Criação do registro |

> ⚠️ **REGRA DE OURO:** Coluna é `game_duration`, NUNCA `session_duration`.

---

### 3.3 Tabela: `transactions`
Histórico financeiro.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK | ID da transação |
| google_uid | VARCHAR(128) | UID do jogador |
| type | VARCHAR(30) | Tipo da transação |
| amount_brl | DECIMAL(18,6) | Valor em BRL |
| description | VARCHAR(255) | Descrição |
| status | VARCHAR(20) | Status |
| created_at | TIMESTAMP | Data |
| updated_at | TIMESTAMP | Atualização |

**Tipos de transação:**
- `game_earning` — Recompensa de missão
- `withdraw` — Saque (débito)
- `withdraw_reject` — Saque rejeitado (estorno/crédito)
- `stake` — Aplicou em stake (débito)
- `unstake` — Resgatou stake (crédito)
- `stake_reward` — Rendimento de staking (crédito)
- `referral_bonus` — Comissão de indicação (crédito)
- `admin_adjust` — Ajuste manual pelo admin

> ⚠️ **REGRA DE OURO:** Todos os valores em 6 casas decimais (DECIMAL 18,6).

---

### 3.4 Tabela: `withdrawals`
Solicitações de saque.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK | ID do saque |
| user_id | INT FK | ID do usuário (users.id) |
| google_uid | VARCHAR(128) | UID (referência rápida) |
| amount_brl | DECIMAL(18,6) | Valor em BRL |
| wallet_address | VARCHAR(100) | Método (ex: "PIX") |
| status | VARCHAR(20) | Status do saque |
| transaction_hash | VARCHAR(100) | Hash/comprovante |
| admin_notes | TEXT | Notas do admin / dados PIX |
| created_at | TIMESTAMP | Solicitação |
| processed_at | TIMESTAMP | Processamento |
| completed_at | TIMESTAMP | Conclusão |

**Status de saque:** `pending` → `processing` → `completed` | `rejected` | `cancelled`

> ⚠️ JOIN com users via `user_id`, NUNCA `google_uid`.
> ⚠️ Status aprovado é `completed`, NUNCA `approved`.
> ⚠️ Coluna é `admin_notes`, NUNCA `reject_reason` ou `notes`.
> ⚠️ Coluna é `processed_at`, NUNCA `approved_at`.

---

### 3.5 Tabela: `staking`
Registros de stake (rendimentos sobre saldo).

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK | ID do stake |
| google_uid | VARCHAR(128) | UID do jogador |
| amount_brl | DECIMAL(18,6) | Valor em stake |
| earned_brl | DECIMAL(18,6) | Rendimento acumulado |
| apy_rate | DECIMAL(5,2) | Taxa APY (ex: 5.00) |
| status | VARCHAR(20) | 'active', 'completed', 'cancelled' |
| staked_at | TIMESTAMP | Data do stake |
| min_unstake_at | TIMESTAMP | Data mínima para resgate |
| last_compound_at | TIMESTAMP | Último cálculo de juros |
| unstaked_at | TIMESTAMP | Data do resgate |
| created_at | TIMESTAMP | Criação |
| updated_at | TIMESTAMP | Atualização |

> ⚠️ Tabela é `staking`, NUNCA `stakes`.
> ⚠️ Colunas são `amount_brl`/`earned_brl`/`apy_rate`, NUNCA `amount`/`total_earned`/`apy`.

---

### 3.6 Tabela: `referrals`
Sistema de indicação.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK | ID |
| referrer_google_uid | VARCHAR(128) | UID de quem indicou |
| referred_google_uid | VARCHAR(128) | UID do indicado |
| commission_brl | DECIMAL(18,6) | Valor da comissão |
| missions_completed | INT | Missões completadas pelo indicado |
| status | VARCHAR(20) | Status da indicação |
| claimed_at | TIMESTAMP | Data do resgate da comissão |
| created_at | TIMESTAMP | Criação |
| updated_at | TIMESTAMP | Atualização |

**Status:** `pending` → `qualified` → `claimed` (NUNCA `completed`)

---

### 3.7 Tabela: `suspicious_activity`
Log de atividades suspeitas (anti-cheat).

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK | ID |
| user_id | INT FK | ID do usuário (users.id) |
| ip_address | VARCHAR(45) | IP |
| activity_type | VARCHAR(50) | Tipo de atividade |
| details | JSON | Detalhes em JSON |
| severity | ENUM | 'low', 'medium', 'high', 'critical' |
| reviewed | TINYINT(1) | Se foi revisado |
| reviewed_by | VARCHAR(100) | Quem revisou |
| reviewed_at | TIMESTAMP | Data da revisão |
| created_at | TIMESTAMP | Data do registro |

> ⚠️ Tabela é `suspicious_activity`, NUNCA `security_logs`.
> ⚠️ JOIN com users via `user_id`, NUNCA `google_uid`.

---

### 3.8 Tabela: `game_settings`
Configurações dinâmicas (admin).

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK | ID |
| setting_key | VARCHAR(50) UNIQUE | Chave da config |
| setting_value | TEXT | Valor |
| data_type | VARCHAR(20) | Tipo de dado |
| description | VARCHAR(255) | Descrição |
| is_public | TINYINT(1) | Se é pública |
| created_at | TIMESTAMP | Criação |
| updated_at | TIMESTAMP | Atualização |

> ⚠️ Tabela é `game_settings`, NUNCA `system_config`.
> ⚠️ Colunas são `setting_key`/`setting_value`, NUNCA `config_key`/`config_value`.

**Configurações atuais:**
```
# Jogo
staking_apy = 5
staking_min_days = 7
withdraw_min_amount = 1.00
withdraw_processing_days_start = 20
withdraw_processing_days_end = 25
game_version = 1.0.0
maintenance_mode = false
registration_enabled = true
withdrawals_enabled = true

# Anúncios (prefixo ads_) — Ver seção 7 para lista completa
ads_enabled = true
ads_pregame_enabled = true
ads_endgame_enabled = true
ads_pregame_total_duration = 10
ads_pregame_rotation_interval = 5
ads_endgame_rotation_interval = 8
...etc
```

---

### 3.9 Tabela: `ad_slots`
Slots de anúncios configuráveis.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK AUTO_INCREMENT | ID |
| slot_name | VARCHAR(100) NOT NULL | Nome do slot |
| slot_type | ENUM | 'pregame', 'endgame', 'interstitial', 'banner' |
| position | VARCHAR(50) DEFAULT 'center' | Posição |
| script_code | TEXT NOT NULL | Código HTML/JS do provedor de ads |
| width | VARCHAR(20) | Largura (ex: "300", "100%") |
| height | VARCHAR(20) | Altura |
| display_order | INT DEFAULT 1 | Ordem de exibição |
| duration_seconds | INT DEFAULT 5 | Duração em segundos |
| custom_css | TEXT | CSS personalizado |
| custom_js | TEXT | JS personalizado |
| notes | VARCHAR(255) | Notas/observações |
| provider | VARCHAR(100) | Provedor (PropellerAds, Adsterra, etc) |
| is_active | TINYINT(1) DEFAULT 1 | Se está ativo |
| created_at | DATETIME | Criação |
| updated_at | DATETIME | Atualização |

**Índices:** `INDEX idx_slot_type (slot_type)`, `INDEX idx_is_active (is_active)`

---

### 3.10 Tabela: `ad_impressions`
Registro de impressões de anúncios.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | BIGINT PK AUTO_INCREMENT | ID |
| slot_id | INT FK → ad_slots(id) ON DELETE CASCADE | ID do slot |
| session_id | VARCHAR(100) | ID da sessão do jogo |
| google_uid | VARCHAR(128) | UID do jogador |
| page | VARCHAR(50) | Página (pregame, postgame, gameover) |
| ip_address | VARCHAR(45) | IP |
| user_agent | VARCHAR(500) | User agent |
| created_at | DATETIME | Data |

**Índices:** `INDEX idx_slot_date (slot_id, created_at)`

---

### 3.11 Tabela: `ad_clicks`
Registro de cliques em anúncios.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | BIGINT PK AUTO_INCREMENT | ID |
| slot_id | INT FK → ad_slots(id) ON DELETE CASCADE | ID do slot |
| session_id | VARCHAR(100) | ID da sessão |
| google_uid | VARCHAR(128) | UID do jogador |
| ip_address | VARCHAR(45) | IP |
| user_agent | VARCHAR(500) | User agent |
| created_at | DATETIME | Data |

**Índices:** `INDEX idx_slot_date (slot_id, created_at)`

---

### 3.12 Tabela: `rate_limits`
Controle de taxa de requisições.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT PK | ID |
| identifier | VARCHAR(100) | IP ou google_uid |
| action_type | VARCHAR(50) | Tipo de ação |
| count | INT | Contador |
| created_at | TIMESTAMP | Data |

---

## 4. APIs Backend (PHP)

### 4.1 config.php (v5.0)
Arquivo central de configuração.

**Função de conexão:**
```php
getDatabaseConnection()  // ⚠️ NUNCA usar getDBConnection()
```

**Constantes principais:**
```php
GAME_DURATION = 180          // 3 minutos
GAME_TOLERANCE = 30          // Tolerância de tempo
INITIAL_LIVES = 6            // Vidas iniciais
MAX_MISSIONS_PER_HOUR = 5    // Limite por hora

REWARD_COMMON = 0            // R$ 0,00
REWARD_RARE = 0.0002         // R$ 0,0002
REWARD_EPIC = 0.0004         // R$ 0,0004
REWARD_LEGENDARY = 0.001     // R$ 0,001

MAX_ASTEROIDS_PER_GAME = 400
MAX_LEGENDARY_PER_GAME = 5
MAX_EPIC_PER_GAME = 20
MAX_RARE_PER_GAME = 80
EARNINGS_BLOCK_BRL = 0.08
```

**Funções:**
```php
getDatabaseConnection()           // Retorna PDO
validateGoogleUid($uid)           // Valida UID Firebase
getRewardByType($type)            // Retorna valor por tipo
validateGameStats($stats, $dur, $isHard) // Anti-cheat
calculateServerEarnings($stats)   // Calcula ganhos
findPlayer($pdo, $identifier)     // Busca usuário
verifyCaptcha($token)             // Verifica CAPTCHA
```

---

### 4.2 game-start.php (v5.0)
Inicia nova sessão de jogo.

**Request:** `POST /api/game-start.php`
```json
{ "google_uid": "DqnexVtvrtdG3fe8fSGzHk8NA713" }
```

**Response (sucesso):**
```json
{
    "success": true,
    "session_id": 21,
    "session_token": "abc123...",
    "session_seed": "def456...",
    "google_uid": "DqnexVtvrtdG3fe8fSGzHk8NA713",
    "mission_number": 5,
    "is_hard_mode": false,
    "game_duration": 180,
    "initial_lives": 6,
    "missions_remaining": 4,
    "limits": { "max_asteroids": 400, "max_legendary": 5, "max_epic": 20, "max_rare": 80 }
}
```

---

### 4.3 game-end.php (v5.0)
Finaliza sessão e credita ganhos.

**Request:** `POST /api/game-end.php`
```json
{
    "session_id": 21, "session_token": "abc123...",
    "google_uid": "xxx", "score": 261, "earnings": 0.0116,
    "lives_remaining": 3, "victory": true,
    "stats": { "common": 224, "rare": 28, "epic": 5, "legendary": 4 },
    "captcha_token": "bWF0aF8yNV8xNzA3..."
}
```

---

### 4.4 admin-ajax.php (v6.0)
API principal do admin panel.

**Autenticação:** Requer `$_SESSION['admin']` (retorna 401 se não autenticado).

**Ações:** `dashboard_stats`, `list_players`, `search_player`, `ban_player`, `unban_player`, `list_withdrawals`, `approve_withdrawal`, `reject_withdrawal`, `list_flagged`, `list_suspicious`

---

### 4.5 admin-security.php (v6.0)
**Ações:** `ban`, `unban`, `list_banned`, `list_flagged`, `list_suspicious`, `search_player`

---

### 4.6 admin-ads.php (v7.0)
API completa de gerenciamento de anúncios.

**Ações Admin (requer auth):**
- `get_config` — Config completa de ads (tabela `game_settings`)
- `save_config` — Salvar configs (upsert `setting_key`/`setting_value`)
- `list_slots` / `add_slot` / `update_slot` / `delete_slot` / `toggle_slot`
- `reorder_slots` — Reordenar slots
- `get_stats` — Estatísticas (impressões, cliques, CTR por período)

**Ações Públicas (sem auth):**
- `get_public_config` — Config pública + slots ativos agrupados por tipo
- `log_impression` — Registrar impressão de anúncio
- `log_click` — Registrar clique em anúncio

**Response de get_public_config:**
```json
{
    "success": true,
    "config": {
        "ads_enabled": true,
        "pregame_enabled": true,
        "pregame_total_duration": 10,
        "pregame_rotation_interval": 5,
        "endgame_enabled": true,
        "endgame_rotation_interval": 8,
        "endgame_show_on_gameover": true
    },
    "slots": {
        "pregame": [
            { "id": 1, "slot_name": "PropellerAds Banner", "script_code": "<script>...</script>", "display_order": 1 }
        ],
        "endgame": [ ... ],
        "interstitial": [],
        "banner": []
    }
}
```

### 4.7 ads-config.php
Proxy público que redireciona `action=get_public_config` para `admin-ads.php`.
Caminho: `api/ads-config.php` → `api/admin-ads.php`

---

## 5. Frontend (JavaScript)

### 5.1 game-main.js (v7.0)
Ponto de entrada. Inicializa o jogo, gerencia botões e lida com redirects de volta do pregame/postgame.

```javascript
// Funções principais
startGameSession()       // Redireciona para pregame.html (não inicia direto)

// Auto-detection de retorno (no onAuthStateChanged)
?start=true   + loadingComplete    → startGameWithLoading()
?results=true + postgameComplete   → showEndGameResults() com flag _showResultsDirect
```

**Fluxo de retorno do pregame.html:**
```
game-main.js detecta params: ?start=true
Verifica sessionStorage: loadingComplete === 'true'
Se ambos → limpa flags, chama startGameWithLoading()
```

**Fluxo de retorno do postgame.html:**
```
game-main.js detecta params: ?results=true
Verifica sessionStorage: postgameComplete === 'true'
Se ambos → limpa flags, seta _showResultsDirect=true, chama showEndGameResults(dados)
```

---

### 5.2 game-ui.js (v7.0)
Interface do usuário, modais, notificações, e integração com ads no game over.

```javascript
// Funções exportadas (window.*)
initUIElements()         // Inicializa referências DOM
showLoading(show)        // Tela de carregamento inicial
showPreGameLoading(show) // Mantida para compatibilidade (não usada no fluxo atual)
showModal(id)            // Ativa modal por ID
showTransactionPopup()   // Popup de processamento
updateUI()               // Atualiza HUD (vidas, score, tempo)
updateLivesDisplay()     // Atualiza display de vidas
animateLifeLost()        // Animação de perda de vida
showNotification()       // Notificação toast
showMissionInfo()        // Info da missão no HUD
gameAlert(msg, type, title) // Alert customizado (Promise)
gameConfirm(msg, title)  // Confirm customizado (Promise)
showGameOver(lostEarnings) // Game over + ads direto na tela
showEndGameResults(stats, serverEarnings, serverBalance) // Resultados ou redirect para postgame
updateSelectedShipInfo()  // Info da nave selecionada
formatEarningsBRL(value)  // Formata: R$ 0,000200
formatBRL(value)          // Formata: R$ 1,23
```

**showEndGameResults — Lógica de decisão:**
```
1. Verifica sessionStorage._showResultsDirect
   → Se 'true': exibe resultados direto (voltando do postgame)
2. Verifica _shouldShowPostgameAds()
   → Se false (ads off ou sem slots): exibe resultados direto
3. Se ads habilitadas:
   → Salva dados em sessionStorage.postgameData
   → Redireciona para postgame.html
```

**showGameOver — Ads embutidos:**
```
1. Exibe tela de game over
2. Renderiza ads no #gameoverAdContainer (direto, sem redirect)
3. Usa AdsManager.getNextSlot('endgame')
```

---

### 5.3 game-session-manager.js (v6.1)
```javascript
SessionManager.startSession(googleUid)   // Inicia nova sessão no servidor
SessionManager.endSession(score, earnings, stats) // Finaliza e envia stats
SessionManager.resendAfterCaptcha(token) // Reenvia após CAPTCHA
SessionManager.hasActiveSession()        // true se sessão ativa
SessionManager.hasPendingCaptcha()       // Se aguardando CAPTCHA
SessionManager.getSession()              // Dados da sessão atual
SessionManager.clearSession()            // Limpa (emergência)
```

### 5.4 game-engine.js (v8.0)
```javascript
initCanvas()          // Inicializa canvas
createAsteroid(id)    // Cria asteroide aleatório
fireBullet()          // Dispara laser
gameOver()            // Processa game over → chama showGameOver()
endGame()             // Processa vitória → chama showEndGameResults()
getGameStats()        // Retorna {common, rare, epic, legendary}
```

### 5.5 game-config.js (v4.0)
```javascript
const CONFIG = {
    GAME_DURATION: 180,
    INITIAL_LIVES: 6,
    REWARDS: { COMMON: 0, RARE: 0.0002, EPIC: 0.0004, LEGENDARY: 0.001 },
    SPAWN_RATES: { COMMON: 0.85, RARE: 0.10, EPIC: 0.04, LEGENDARY: 0.01 }
};
```

### 5.6 ads-manager.js (v4.0)
Gerenciador de anúncios no frontend. Consome API `ads-config.php`.

```javascript
// Classe global: AdsManager (singleton)
AdsManager.init()                // Busca config de api/ads-config.php
AdsManager.isEnabled()           // Se ads estão habilitadas
AdsManager.getConfig()           // Objeto config completo
AdsManager.getSlots()            // { pregame: [], endgame: [], interstitial: [], banner: [] }
AdsManager.getNextSlot(type)     // Retorna próximo slot (round-robin por tipo)
AdsManager.getSlotHTML(slot)     // Gera HTML do slot usando slot.script_code
AdsManager.executeScripts(el)    // Re-executa <script> tags em container (necessário para 3rd party)
AdsManager.trackImpression(id)   // POST para api/admin-ads.php?action=log_impression
AdsManager.trackClick(id)        // POST para api/admin-ads.php?action=log_click

// Métodos de exibição (usados pelas páginas dedicadas):
AdsManager.showPreGameAd(containerId)   // Renderiza pregame
AdsManager.showEndGameAd(containerId)   // Renderiza endgame
AdsManager.showInterstitial()           // Modal intersticial
AdsManager.showBanner()                 // Banner fixo
```

**Rotação round-robin:**
```javascript
// getNextSlot mantém índice por tipo
// Cada chamada avança para o próximo slot da lista
getNextSlot('pregame') → slot[0], slot[1], slot[2], slot[0], ...
```

**API Endpoints usados:**
- `api/ads-config.php` → busca config + slots (GET, action=get_public_config)
- `api/admin-ads.php` → log_impression (POST)
- `api/admin-ads.php` → log_click (POST)

---

## 6. Fluxo de Jogo Completo

### 6.1 Diagrama de Fluxo Completo (v7.0)
```
┌─────────────────────────────────────────────────────┐
│  1. AUTENTICAÇÃO                                     │
│  ├── Usuário clica "Entrar com Google"               │
│  ├── Firebase Auth processa                          │
│  ├── Frontend recebe UID                             │
│  └── sync-user.php cria/atualiza na tabela users     │
│                                                      │
│  2. MENU (game.html)                                 │
│  ├── Selecionar nave                                 │
│  └── Clica "INICIAR MISSÃO"                          │
│       │                                              │
│       ▼                                              │
│  3. PRÉ-JOGO (pregame.html) ← PÁGINA DEDICADA       │
│  ├── Barra de progresso animada 0%→100%              │
│  ├── Anúncios pregame rodando (rotação automática)   │
│  ├── Dicas do jogo rotacionando                      │
│  ├── Duração: ads_pregame_total_duration (padrão 10s)│
│  ├── Sem ads: loading rápido de 3s                   │
│  └── 100% → redirect game.html?start=true            │
│       │                                              │
│       ▼                                              │
│  4. JOGO (game.html — Canvas, 180 segundos)          │
│  ├── game-main.js detecta ?start=true                │
│  ├── Verifica sessionStorage.loadingComplete          │
│  ├── startGameWithLoading() inicia partida           │
│  ├── Asteroides gerados aleatoriamente               │
│  ├── Contadores atualizados LOCALMENTE               │
│  └── NENHUMA requisição ao servidor durante o jogo   │
│       │                                              │
│       ├──── VITÓRIA ────┐     ┌──── GAME OVER ───┐  │
│       ▼                 │     │                   ▼  │
│  5a. PÓS-JOGO          │     │  5b. GAME OVER    │  │
│  (postgame.html)        │     │  (game.html)      │  │
│  ├── Tela dedicada      │     │  ├── Modal direto │  │
│  ├── Mini resumo        │     │  ├── Ads embutidos│  │
│  │  (score + earnings)  │     │  │  no container   │  │
│  ├── Anúncios endgame   │     │  ├── Botão retry  │  │
│  ├── Barra de progresso │     │  └── Botão sair   │  │
│  ├── Dicas pós-jogo     │     └───────────────────┘  │
│  ├── Duração: config    │                            │
│  └── 100% → redirect    │                            │
│     game.html?results=  │                            │
│     true                │                            │
│       │                 │                            │
│       ▼                 │                            │
│  6. RESULTADOS          │                            │
│  (game.html)            │                            │
│  ├── game-main.js       │                            │
│  │   detecta ?results   │                            │
│  ├── Lê postgameData    │                            │
│  │   do sessionStorage  │                            │
│  ├── Seta flag          │                            │
│  │   _showResultsDirect │                            │
│  ├── showEndGameResults │                            │
│  │   exibe direto       │                            │
│  ├── Score, earnings    │                            │
│  ├── Breakdown por tipo │                            │
│  ├── Novo saldo         │                            │
│  └── Botões: jogar de   │                            │
│     novo / carteira     │                            │
│                                                      │
│  7. CAPTCHA (se necessário)                          │
│  └── CaptchaManager → SessionManager                 │
│     .resendAfterCaptcha(token)                       │
│                                                      │
│  8. SAQUE (quando saldo >= R$ 1,00)                  │
│  └── withdraw.php → status "pending"                 │
│     → admin processa dias 20-25                      │
└─────────────────────────────────────────────────────┘
```

### 6.2 Fluxo Pré-Jogo Detalhado (pregame.html)
```
Clica "INICIAR MISSÃO" (game.html)
  → game-main.js: startGameSession()
  → window.location.href = 'pregame.html'

pregame.html carrega:
  → Inicia AdsManager.init()
  → Verifica se ads estão habilitadas
  │
  ├── Com ads:
  │   → Busca slots do tipo 'pregame'
  │   → renderSlot('pregame') exibe primeiro slot
  │   → Se slots.length > 1: inicia rotationTimer
  │   │   (a cada pregame_rotation_interval segundos)
  │   → Barra de progresso: duração = pregame_total_duration
  │
  └── Sem ads:
      → Barra de progresso: duração = 3s (rápido)

  → Dicas do jogo rotacionam a cada 3 segundos
  → Status: "Carregando recursos..." → "Pronto para lançamento!"
  → Progress 100%:
      → clearInterval(interval)
      → clearInterval(rotationTimer)
      → sessionStorage.setItem('loadingComplete', 'true')
      → window.location.href = 'game.html?start=true'
```

### 6.3 Fluxo Pós-Jogo Detalhado (postgame.html)
```
Missão completa (vitória):
  → game-engine.js: endGame()
  → game-ui.js: showEndGameResults(stats, earnings, balance)
  → Verifica: _showResultsDirect no sessionStorage?
  │
  ├── Se _showResultsDirect = 'true': exibe resultados direto (FIM)
  │
  └── Se não (primeira passagem):
      → Verifica _shouldShowPostgameAds():
      │   - AdsManager existe e habilitado?
      │   - Slots endgame existem?
      │
      ├── Se false: exibe resultados direto (FIM)
      │
      └── Se true:
          → sessionStorage.setItem('postgameData', JSON.stringify({
                stats, score, earnings, serverEarnings, serverBalance
            }))
          → window.location.href = 'postgame.html'

postgame.html carrega:
  → Lê postgameData do sessionStorage → mini resumo (score + earnings)
  → Inicia AdsManager → renderiza slots endgame com rotação
  → Barra de progresso: duração = endgame_rotation_interval (padrão 8s)
  → Status: "Calculando resultados..." → "Resultados prontos!"
  → Progress 100%:
      → sessionStorage.setItem('postgameComplete', 'true')
      → window.location.href = 'game.html?results=true'

game.html?results=true:
  → game-main.js detecta params
  → sessionStorage.postgameComplete === 'true'? sim
  → Remove: postgameComplete
  → Lê: postgameData → { stats, serverEarnings, serverBalance }
  → Remove: postgameData
  → Seta: _showResultsDirect = 'true'  ← PREVINE LOOP
  → showEndGameResults(stats, serverEarnings, serverBalance)
  → showEndGameResults verifica _showResultsDirect → exibe direto
```

### 6.4 Fluxo Game Over (sem redirect)
```
Perdeu todas as vidas:
  → game-engine.js: gameOver()
  → game-ui.js: showGameOver(lostEarnings)
  → Renderiza ads endgame no #gameoverAdContainer (embutido no modal)
  → Exibe modal gameOverModal (direto, sem redirect)
  → Botões: "TENTAR NOVAMENTE" | "SAIR"
```

---

## 7. Sistema de Anúncios

### 7.1 Arquitetura Completa
```
┌──────────────────────────────────────────┐
│           PAINEL ADMIN                     │
│  admin/pages/ads.php                       │
│  ├── Tab 1: Configurações gerais           │
│  ├── Tab 2: Lista de slots (CRUD)          │
│  └── Tab 3: Criar novo slot                │
│       ↕ AJAX                               │
│  api/admin-ads.php                         │
│  ├── get_config / save_config              │
│  ├── list_slots / add_slot / update_slot   │
│  ├── delete_slot / toggle_slot             │
│  ├── reorder_slots / get_stats             │
│  └── log_impression / log_click            │
└──────────────┬───────────────────────────┘
               │
    Dados no MySQL:                           
    ├── ad_slots (script_code do provedor)    
    ├── game_settings (config ads_*)          
    ├── ad_impressions (tracking)             
    └── ad_clicks (tracking)                  
               │
               ▼
┌──────────────────────────────────────────┐
│           FRONTEND (jogo)                  │
│                                            │
│  ads-manager.js (v4.0)                     │
│  ├── init() → fetch api/ads-config.php     │
│  │   → recebe config + slots por tipo      │
│  ├── getNextSlot(type) → round-robin       │
│  ├── getSlotHTML(slot) → usa script_code   │
│  ├── executeScripts(el) → re-executa JS    │
│  └── trackImpression/trackClick            │
│       ↕                                    │
│  pregame.html                              │
│  ├── Renderiza slots pregame               │
│  ├── Rotação automática se >1 slot         │
│  └── Duração = pregame_total_duration      │
│       ↕                                    │
│  postgame.html                             │
│  ├── Renderiza slots endgame               │
│  ├── Rotação automática se >1 slot         │
│  └── Duração = endgame_rotation_interval   │
│       ↕                                    │
│  game.html (game over)                     │
│  └── #gameoverAdContainer (embutido)       │
└──────────────────────────────────────────┘
```

### 7.2 Tipos de Slot
| Tipo | Onde aparece | Quando | Redirect? |
|------|-------------|--------|-----------|
| pregame | pregame.html | Antes de iniciar missão | Sim → game.html?start=true |
| endgame | postgame.html / game over | Após vitória ou derrota | Vitória: sim. Game over: não |
| interstitial | Modal overlay | A cada X jogos (config) | Não |
| banner | Fixo na página | Contínuo | Não |

### 7.3 Rotação de Slots
Quando há mais de 1 slot ativo para um tipo, a rotação automática é ativada:

```javascript
// pregame.html / postgame.html
const slots = AdsManager.getSlots()?.pregame || [];
if (slots.length > 1) {
    const rotInterval = AdsManager.getConfig()?.pregame_rotation_interval || 5;
    rotationTimer = setInterval(() => renderSlot('pregame'), rotInterval * 1000);
}
```

O `getNextSlot(type)` mantém um índice round-robin interno por tipo, avançando a cada chamada.

### 7.4 Configurações de Anúncios (game_settings)
Todas as configs usam prefixo `ads_` na tabela `game_settings`:

| Setting Key | Tipo | Padrão | Descrição |
|-------------|------|--------|-----------|
| ads_enabled | boolean | true | Master switch do sistema |
| ads_debug_mode | boolean | false | Logs no console |
| ads_tracking_enabled | boolean | true | Tracking de impressões/cliques |
| ads_pregame_enabled | boolean | true | Habilitar ads pré-jogo |
| ads_pregame_total_duration | number | 10 | Duração total da tela pregame (seg) |
| ads_pregame_skip_enabled | boolean | false | Permitir pular |
| ads_pregame_skip_after | number | 5 | Segundos até poder pular |
| ads_pregame_rotation_interval | number | 5 | Intervalo de rotação entre slots (seg) |
| ads_pregame_max_slots | number | 3 | Máximo de slots ativos |
| ads_endgame_enabled | boolean | true | Habilitar ads pós-jogo |
| ads_endgame_display_mode | string | 'grid' | Modo: grid, carousel, stacked |
| ads_endgame_max_slots | number | 4 | Máximo de slots |
| ads_endgame_auto_rotate | boolean | true | Rotação automática |
| ads_endgame_rotation_interval | number | 8 | Intervalo de rotação (seg) |
| ads_endgame_show_on_gameover | boolean | true | Mostrar no game over |
| ads_interstitial_enabled | boolean | false | Habilitar intersticial |
| ads_interstitial_frequency | number | 3 | A cada X jogos |
| ads_interstitial_duration | number | 5 | Duração (seg) |
| ads_banner_enabled | boolean | false | Habilitar banner |
| ads_banner_position | string | 'bottom' | Posição: top, bottom |
| ads_cache_duration | number | 300 | Cache de config (seg) |

### 7.5 Criando um Slot de Anúncio
1. Admin → Anúncios → Tab "Novo Slot"
2. Preencher: Nome, Tipo (pregame/endgame), Script Code
3. **Script Code:** Colar HTML/JS do provedor (PropellerAds, Adsterra, etc.)
4. O sistema renderiza o `script_code` diretamente e executa via `executeScripts()`

### 7.6 SQL de Criação das Tabelas de Ads
Arquivo: `create_ads_tables.sql`
```sql
CREATE TABLE IF NOT EXISTS ad_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slot_name VARCHAR(100) NOT NULL,
  slot_type ENUM('pregame','endgame','interstitial','banner'),
  position VARCHAR(50) DEFAULT 'center',
  script_code TEXT NOT NULL,
  width VARCHAR(20), height VARCHAR(20),
  display_order INT DEFAULT 1,
  duration_seconds INT DEFAULT 5,
  custom_css TEXT, custom_js TEXT,
  notes VARCHAR(255), provider VARCHAR(100),
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_slot_type (slot_type),
  INDEX idx_is_active (is_active)
);

CREATE TABLE IF NOT EXISTS ad_impressions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  slot_id INT NOT NULL,
  session_id VARCHAR(100), google_uid VARCHAR(128),
  page VARCHAR(50), ip_address VARCHAR(45),
  user_agent VARCHAR(500),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_slot_date (slot_id, created_at),
  FOREIGN KEY (slot_id) REFERENCES ad_slots(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ad_clicks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  slot_id INT NOT NULL,
  session_id VARCHAR(100), google_uid VARCHAR(128),
  ip_address VARCHAR(45), user_agent VARCHAR(500),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_slot_date (slot_id, created_at),
  FOREIGN KEY (slot_id) REFERENCES ad_slots(id) ON DELETE CASCADE
);
```

---

## 8. Sistema de Segurança

### 8.1 Validações Anti-Cheat (game-end.php)
```php
MAX_ASTEROIDS_PER_GAME = 400
MAX_LEGENDARY_PER_GAME = 5   / MAX_LEGENDARY_PERCENT = 2%
MAX_EPIC_PER_GAME = 20       / MAX_EPIC_PERCENT = 8%
MAX_RARE_PER_GAME = 80       / MAX_RARE_PERCENT = 25%
MAX_ASTEROIDS_PER_SECOND = 3
MIN_GAME_DURATION_SECONDS = 120
EARNINGS_ALERT_BRL = 0.03
EARNINGS_BLOCK_BRL = 0.08
```

### 8.2 Proteções
| Proteção | Descrição |
|----------|-----------|
| Session Token | Token único por sessão (64 chars) |
| Tempo de Jogo | Duração deve ser plausível (120-210s) |
| Proporções | % de raros/épicos estatisticamente possível |
| Rate Limit IP | Máx 5 missões/hora |
| CAPTCHA | Verificação matemática (hCaptcha) |
| Flagging | Sessões suspeitas marcadas para revisão |

---

## 9. Painel Administrativo

### 9.1 Estrutura de Arquivos
```
admin/
├── index.php              → Login + roteamento SPA de páginas
├── css/admin.css          → Estilo (tema escuro UNOBIX)
├── js/admin.js            → JavaScript (BRL, ban por google_uid)
├── includes/
│   ├── header.php         → Cabeçalho (branding UNOBIX)
│   ├── footer.php         → Rodapé
│   └── sidebar.php        → Menu lateral (tabela users)
└── pages/
    ├── dashboard.php      → Visão geral (stats de users, staking, referrals)
    ├── players.php        → Gerenciamento de jogadores (tabela users)
    ├── withdrawals.php    → Saques (JOIN users via user_id, status completed)
    ├── transactions.php   → Transações (JOIN users via google_uid)
    ├── sessions.php       → Sessões de jogo (coluna game_duration)
    ├── stakes.php         → Staking (tabela staking, BRL, google_uid)
    ├── security.php       → Segurança (suspicious_activity, users banidos)
    ├── referrals.php      → Indicações (status: pending→qualified→claimed)
    ├── logs.php           → Logs (suspicious_activity, transações, sessões)
    ├── settings.php       → Configurações (tabela game_settings)
    └── ads.php            → Anúncios (3 tabs: config, slots, novo slot)
```

### 9.2 Autenticação Admin
- Login em `admin/index.php` com credenciais verificadas contra variável de ambiente
- Sessão PHP: `$_SESSION['admin_logged_in']` e `$_SESSION['admin']`
- API endpoints verificam sessão; retornam 401 se não autenticado

### 9.3 Funções JavaScript do Admin (admin.js)
```javascript
approveWithdrawal(id, amount) // Aprovar saque (PIX/manual)
rejectWithdrawal(id)          // Rejeitar com motivo
banPlayer(googleUid, reason)  // Banir por Google UID
unbanPlayer(googleUid)        // Desbanir
adminAjax(data)               // AJAX centralizado
formatBRL(value)              // R$ com 6 decimais
formatBRLShort(value)         // R$ com 2 decimais
truncateUid(uid)              // Truncar Google UID para display
```

---

## 10. Sistema de Staking

### 10.1 Fluxo
```
1. Jogador aplica saldo → INSERT em staking, debita balance_brl
2. Rendimentos calculados periodicamente (APY definida em game_settings)
3. Após período mínimo (staking_min_days), pode resgatar
4. Resgate: earned_brl creditado em balance_brl
```

### 10.2 Colunas Importantes
- `amount_brl` (não `amount`)
- `earned_brl` (não `total_earned`)
- `apy_rate` (não `apy`) — formato: 5.00 = 5%

---

## 11. Sistema de Referrals

### 11.1 Fluxo Completo
```
1. Usuário A compartilha link: site.com/?ref=ABC123
2. Usuário B acessa link → código salvo na URL
3. B faz login com Google → sync-user.php captura referral code
4. INSERT em referrals (referrer_google_uid=A, referred_google_uid=B, status: pending)
5. B completa missões necessárias → status: qualified
6. A resgata comissão no painel → status: claimed, commission_brl creditado
```

### 11.2 Status
- `pending` → `qualified` → `claimed`
- **NUNCA** usar `completed` para referrals

---

## 12. Configurações

### 12.1 Variáveis de Ambiente (Cloud Run)
```
MYSQLDATABASE=unobix_db
MYSQLUSER=unobix_user
MYSQLPASSWORD=***
FIREBASE_PROJECT_ID=unobix-oauth-a69cd
ADMIN_PASSWORD=***
```

### 12.2 Conexão Cloud SQL
```php
// Socket path (Cloud Run)
/cloudsql/project-7be1cae5-5f08-45fb-aca:us-west1:unobix
```

---

## 13. Comunicação entre Páginas (sessionStorage)

O sistema usa `sessionStorage` para comunicar dados entre as 3 páginas do jogo (game.html, pregame.html, postgame.html). Cada chave tem um propósito específico e é removida após leitura.

### 13.1 Mapa Completo de Chaves
| Chave | Setada por | Lida por | Valor | Propósito |
|-------|-----------|---------|-------|-----------|
| `loadingComplete` | pregame.html | game-main.js | `'true'` | Sinaliza que pregame terminou |
| `postgameData` | game-ui.js | postgame.html + game-main.js | JSON string | Dados da partida para exibir |
| `postgameComplete` | postgame.html | game-main.js | `'true'` | Sinaliza que postgame terminou |
| `_showResultsDirect` | game-main.js | game-ui.js | `'true'` | Previne loop de redirect |

### 13.2 Formato do postgameData
```json
{
    "stats": { "common": 224, "rare": 28, "epic": 5, "legendary": 4 },
    "score": 261,
    "earnings": 0.0116,
    "serverEarnings": 0.0116,
    "serverBalance": 1.234567
}
```

### 13.3 Ciclo de Vida das Chaves
```
PREGAME:
  pregame.html SETA  → loadingComplete = 'true'
  game-main.js LÊ    → verifica valor
  game-main.js REMOVE → sessionStorage.removeItem('loadingComplete')

POSTGAME:
  game-ui.js   SETA  → postgameData = JSON {...}
  postgame.html LÊ   → exibe mini resumo
  postgame.html SETA  → postgameComplete = 'true'
  game-main.js LÊ    → verifica postgameComplete
  game-main.js REMOVE → postgameComplete + postgameData
  game-main.js SETA  → _showResultsDirect = 'true'
  game-ui.js   LÊ    → verifica flag
  game-ui.js   REMOVE → _showResultsDirect
```

### 13.4 Anti-Loop Protection
Sem o flag `_showResultsDirect`, o seguinte loop ocorreria:
```
game.html?results=true
  → showEndGameResults() → ads habilitadas? → redirect postgame.html
    → postgame.html 100% → redirect game.html?results=true
      → showEndGameResults() → redirect postgame.html → LOOP ∞
```

O flag `_showResultsDirect` é setado por `game-main.js` ANTES de chamar `showEndGameResults()`, garantindo que a função exiba os resultados diretamente sem redirecionar novamente.

---

## 14. Regras de Ouro

Estas regras devem ser seguidas em **TODA** correção ou desenvolvimento futuro. Violá-las causa inconsistência que quebra o sistema.

### 🔴 Tabelas
| ✅ CORRETO | ❌ NUNCA USAR |
|-----------|-------------|
| `users` | `players` |
| `staking` | `stakes` |
| `game_settings` | `system_config` |
| `suspicious_activity` | `security_logs` |
| `ad_slots` | `ads`, `advertisements` |

### 🔴 Colunas
| ✅ CORRETO | ❌ NUNCA USAR |
|-----------|-------------|
| `setting_key` / `setting_value` | `config_key` / `config_value` |
| `game_duration` | `session_duration` |
| `amount_brl` / `earned_brl` | `amount` / `total_earned` (staking) |
| `apy_rate` | `apy` |
| `admin_notes` | `reject_reason`, `notes` (withdrawals) |
| `processed_at` | `approved_at` |
| `transaction_hash` | `tx_hash` |
| `activity_type` / `details` / `severity` | `event_type` / `event_data` |
| `script_code` | `ad_code`, `html_code` (ad_slots) |

### 🔴 Conexão
| ✅ CORRETO | ❌ NUNCA USAR |
|-----------|-------------|
| `getDatabaseConnection()` | `getDBConnection()` |

### 🔴 Status
| Contexto | ✅ CORRETO | ❌ NUNCA USAR |
|----------|-----------|-------------|
| Withdrawals aprovado | `completed` | `approved` |
| Withdrawals tipo tx | `withdraw` | `withdrawal_approved` |
| Withdrawals rejeição tx | `withdraw_reject` | `withdrawal_rejected` |
| Referrals qualificado | `qualified` | `completed` |

### 🔴 Joins
| Contexto | ✅ CORRETO | ❌ NUNCA USAR |
|----------|-----------|-------------|
| withdrawals ↔ users | `w.user_id = u.id` | `w.google_uid = u.google_uid` |
| suspicious_activity ↔ users | `sa.user_id = u.id` | `sa.google_uid` |
| Demais tabelas ↔ users | `t.google_uid = u.google_uid` | `t.wallet_address` |

### 🔴 Moeda e Formatação
- **SEMPRE** BRL (R$), **NUNCA** USDT ou USD
- **SEMPRE** 6 casas decimais para precisão financeira
- **NUNCA** `UNITS_DIVISOR`, `formatUsdt()`, `$` como prefixo

### 🔴 Identificação
- **SEMPRE** `google_uid` como identificador principal
- **NUNCA** `wallet_address` para identificar usuários
- **NUNCA** MetaMask, BNB, BSC

### 🔴 Navegação entre Páginas
- **SEMPRE** usar `sessionStorage` para comunicação entre pregame/postgame/game
- **SEMPRE** limpar chaves do sessionStorage após leitura
- **SEMPRE** setar `_showResultsDirect` antes de chamar `showEndGameResults()` no retorno do postgame
- **NUNCA** chamar `window.location.href` para postgame.html sem antes salvar `postgameData`

---

## 15. Troubleshooting

### Saldo não credita
```sql
SELECT id, status, earnings_brl FROM game_sessions ORDER BY id DESC LIMIT 5;
SELECT * FROM transactions WHERE google_uid = 'xxx' ORDER BY id DESC LIMIT 5;
SELECT balance_brl, total_earned_brl FROM users WHERE google_uid = 'xxx';
```

### "Sessão não encontrada"
- Limpar localStorage e relogar
- Não abrir múltiplas abas

### Anúncios não aparecem
1. Verificar se tabelas `ad_slots`, `ad_impressions`, `ad_clicks` existem
2. Verificar se `ads_enabled = true` em `game_settings`
3. Verificar se há slots ativos: `SELECT * FROM ad_slots WHERE is_active = 1;`
4. Console do browser: procurar por `📺 AdsManager` logs
5. Verificar `api/ads-config.php` retorna JSON válido

### Tela postgame em loop infinito
- Verificar se `game-main.js` está setando `_showResultsDirect` antes de `showEndGameResults()`
- Verificar se `game-ui.js` está lendo e removendo o flag
- No console: `sessionStorage.getItem('_showResultsDirect')` — deve ser null após exibir resultados

### Rotação de slots não funciona
- Verificar se há mais de 1 slot ativo para o tipo
- Verificar `ads_pregame_rotation_interval` / `ads_endgame_rotation_interval` em game_settings
- Console: verificar se `rotationTimer` está sendo criado

### Erro de conexão com banco
```bash
gcloud run logs read --service=unobix
gcloud sql instances list
```

### Verificação rápida de integridade
```bash
# Zero referências a tabelas erradas
grep -r "players\b" api/ admin/ --include="*.php"  # deve ser vazio
grep -r "system_config" api/ admin/ --include="*.php"  # deve ser vazio
grep -r "getDBConnection" api/ admin/ --include="*.php"  # deve ser vazio

# Endpoints corretos no frontend
grep "ads-log.php" js/  # deve ser vazio
grep "customAds" js/    # deve ser vazio

# Integração ads
grep "AdsManager" js/game-ui.js  # deve encontrar referências
grep "AdsManager" pregame.html postgame.html  # deve encontrar referências
```

---

## 16. Catálogo Completo de Arquivos

### 16.1 Páginas HTML (raiz)
| Arquivo | Função | Versão |
|---------|--------|--------|
| index.html | Landing page | — |
| game.html | Jogo principal (Canvas, modais, game over) | v7.0 |
| pregame.html | **NOVO** Tela loading pré-jogo com anúncios | v7.0 |
| postgame.html | **NOVO** Tela loading pós-jogo com anúncios | v7.0 |
| wallet.html | Carteira, staking, saques | — |

### 16.2 JavaScript (/js)
| Arquivo | Função | Versão |
|---------|--------|--------|
| auth-manager.js | Autenticação Firebase/Google | — |
| game-config.js | CONFIG: rewards, spawn rates, limites | v4.0 |
| game-main.js | Init, botões, auto-start, redirects pregame/postgame | **v7.0** |
| game-session-manager.js | Comunicação com backend (API) | v6.1 |
| game-engine.js | Lógica do jogo (Canvas, colisões, game loop) | v8.0 |
| game-renderer.js | Renderização Canvas (sprites, efeitos) | — |
| game-ui.js | Modais, notificações, resultados, ads no game over | **v7.0** |
| game-start.js | Inicialização de missão (actualStartGame) | — |
| captcha-manager.js | Verificação anti-bot (hCaptcha) | — |
| ads-manager.js | Gerenciamento de anúncios (singleton AdsManager) | **v4.0** |

### 16.3 Backend API (/api)
| Arquivo | Função | Versão |
|---------|--------|--------|
| config.php | Constantes, funções, conexão DB | v5.0 |
| game-start.php | POST: Iniciar sessão de jogo | v5.0 |
| game-end.php | POST: Finalizar e creditar ganhos | v5.0 |
| balance.php | GET: Consultar saldo | — |
| wallet-info.php | GET: Informações completas da wallet | — |
| transactions.php | GET: Histórico de transações | — |
| withdraw.php | POST: Solicitar saque PIX | — |
| sync-user.php | POST: Sincronizar usuário Firebase | — |
| admin-ajax.php | POST: API AJAX do admin panel | v6.0 |
| admin-security.php | POST: Segurança (ban, suspicious) | v6.0 |
| admin-ads.php | POST/GET: CRUD de ads + tracking | **v7.0** |
| ads-config.php | GET: Proxy público para admin-ads.php | v6.0 |

### 16.4 Admin Panel (/admin)
| Arquivo | Função | Versão |
|---------|--------|--------|
| index.php | Login e roteamento SPA | v6.0 |
| css/admin.css | Estilo tema escuro UNOBIX | v6.0 |
| js/admin.js | JavaScript (BRL, ban, AJAX) | v6.0 |
| includes/header.php | Cabeçalho branding | v6.0 |
| includes/footer.php | Rodapé | v6.0 |
| includes/sidebar.php | Menu lateral | v6.0 |
| pages/dashboard.php | Visão geral | v6.0 |
| pages/players.php | Gerenciamento jogadores | v6.0 |
| pages/withdrawals.php | Saques | v6.0 |
| pages/transactions.php | Transações | v6.0 |
| pages/sessions.php | Sessões de jogo | v6.0 |
| pages/stakes.php | Staking | v6.0 |
| pages/security.php | Segurança | v6.0 |
| pages/referrals.php | Indicações | v6.0 |
| pages/logs.php | Logs | v6.0 |
| pages/settings.php | Configurações | v6.0 |
| pages/ads.php | Anúncios (3 tabs) | v6.0 |

### 16.5 SQL
| Arquivo | Função |
|---------|--------|
| create_ads_tables.sql | Criação das tabelas ad_slots, ad_impressions, ad_clicks |

### 16.6 CSS
| Arquivo | Função |
|---------|--------|
| css/game.css | Estilo do jogo, modais, HUD |
| admin/css/admin.css | Estilo do painel admin |

### 16.7 Arquivos Corrigidos por Fase

**Fase 1 — Core Admin (10 arquivos, v6.0)**
| Arquivo | Correções |
|---------|-----------|
| admin/css/admin.css | Branding UNOBIX, variáveis CSS |
| admin/js/admin.js | Removido MetaMask/USDT, adicionado BRL/PIX |
| admin/includes/header.php | Branding |
| admin/includes/footer.php | Branding |
| admin/includes/sidebar.php | `players`→`users`, referral `qualified` |
| admin/index.php | `getDatabaseConnection()`, branding |
| api/admin-ajax.php | Auth session, `users`, colunas doc |
| api/admin-security.php | `users`, `suspicious_activity` |
| api/ads-config.php | Path corrigido |
| api/admin-withdrawals.php | DESCONTINUADO (HTTP 410) |

**Fase 2 — Pages Admin (11 arquivos, v6.0)**
| Arquivo | Correções |
|---------|-----------|
| admin/pages/dashboard.php | `users`, `staking`, `qualified`, formatBRL 6 dec |
| admin/pages/players.php | `users` em todas as queries |
| admin/pages/withdrawals.php | JOIN `user_id`, status `completed`, `admin_notes` |
| admin/pages/transactions.php | JOIN `users` |
| admin/pages/sessions.php | `game_duration` |
| admin/pages/stakes.php | **REESCRITO**: `staking`, BRL, `google_uid` |
| admin/pages/security.php | **REESCRITO**: `users`, `suspicious_activity` |
| admin/pages/referrals.php | `users`, status `qualified` |
| admin/pages/logs.php | **REESCRITO**: `users`, `suspicious_activity` |
| admin/pages/settings.php | `game_settings`, `setting_key`/`setting_value` |
| admin/pages/ads.php | `game_settings`, `setting_key`/`setting_value` |

**Fase 3 — API Ads (1 arquivo, v6.0)**
| Arquivo | Correções |
|---------|-----------|
| api/admin-ads.php | `getDatabaseConnection()`, config_key→setting_key, senha removida |

**Fase 4 — Sistema de Anúncios Integrado (7 arquivos, v7.0)**
| Arquivo | O que foi feito |
|---------|----------------|
| create_ads_tables.sql | **NOVO**: Schema para ad_slots, ad_impressions, ad_clicks |
| api/admin-ads.php | Corrigido path `require_once`, alinhado com game_settings |
| js/ads-manager.js | **REESCRITO v4.0**: Consome API correta, script_code, rotação round-robin |
| pregame.html | **NOVO**: Página dedicada loading pré-jogo com ads + rotação |
| postgame.html | **NOVO**: Página dedicada loading pós-jogo com ads + rotação |
| js/game-main.js | Redirect para pregame.html, tratamento de ?start=true e ?results=true |
| js/game-ui.js | showEndGameResults com redirect postgame, showGameOver com ads, flag anti-loop |
| game.html | Adicionado #gameoverAdContainer no modal de game over |

---

## 17. Histórico de Mudanças

### v7.0 (2026-02-09) — Sistema de Anúncios Integrado ao Jogo
- ✅ Criado `pregame.html` — página dedicada de loading pré-jogo com anúncios
- ✅ Criado `postgame.html` — página dedicada de loading pós-jogo com anúncios
- ✅ Reescrito `ads-manager.js` v4.0 — alinhado com API, suporte a script_code, round-robin
- ✅ Criado `create_ads_tables.sql` — schema para ad_slots, ad_impressions, ad_clicks
- ✅ Corrigido `admin-ads.php` — path require_once, alinhado com game_settings
- ✅ Modificado `game-main.js` — redirect para pregame.html, tratamento de retornos
- ✅ Modificado `game-ui.js` — redirect para postgame.html, ads no game over, flag anti-loop
- ✅ Modificado `game.html` — adicionado #gameoverAdContainer
- ✅ Implementado sistema de comunicação via sessionStorage entre páginas
- ✅ Implementado rotação automática de slots (round-robin com setInterval)
- ✅ Implementado proteção anti-loop no retorno do postgame (_showResultsDirect)
- ✅ Fluxo: pregame com ads → jogo → postgame com ads → resultados
- ✅ Game over: ads direto na tela sem redirect
- ✅ Documentação expandida para ~1300+ linhas

### v6.0 (2026-02-06) — Admin Panel Completo
- ✅ 22 arquivos do admin panel corrigidos em 3 fases
- ✅ Todas as queries alinhadas com doc: `users`, `staking`, `game_settings`
- ✅ Removido TODO código legacy: MetaMask, USDT, wallet_address, UNITS_DIVISOR
- ✅ Removido senha hardcoded do admin-ads.php
- ✅ stakes.php reescrito do zero (era 100% USDT/crypto)
- ✅ security.php e logs.php reescritos (security_logs → suspicious_activity)
- ✅ Formatação BRL com 6 casas decimais em todos os arquivos
- ✅ Status de withdrawals: `completed` (não `approved`)
- ✅ Status de referrals: `qualified` (não `completed`)
- ✅ Documentação técnica expandida para ~900 linhas

### v5.0 (2026-02-05) — Arquitetura Segura
- ✅ Removido envio de eventos individuais
- ✅ Implementado validação anti-cheat no servidor
- ✅ Corrigido crédito de saldo
- ✅ Reduzido de 200+ para 2 requisições por partida
- ✅ Integrado CAPTCHA com reenvio automático

### v4.x — Versão Anterior
- Sistema de eventos individuais (inseguro)
- Vulnerável a flood de eventos

---

## 📞 Referências

**Banco de Dados:**
- Instância: `project-7be1cae5-5f08-45fb-aca:us-west1:unobix`
- Database: `unobix_db`
- User: `unobix_user`

**Arquivos Principais:**
- Backend: `/api/*.php`
- Frontend: `/js/*.js`
- Admin: `/admin/**`
- SQL: `/sql/*.sql`
- Páginas de jogo: `game.html`, `pregame.html`, `postgame.html`

**Deploy (ordem):**
1. Rodar `create_ads_tables.sql` no phpMyAdmin (se tabelas não existem)
2. Upload de arquivos PHP para `/api/`
3. Upload de arquivos JS para `/js/`
4. Upload de HTMLs para raiz
5. Admin → Anúncios → Criar slots e configurar

---

*Documentação atualizada em 2026-02-09*
*Versão do Sistema: 7.0*
*Fases completadas: 1 (Core Admin) + 2 (Pages) + 3 (API Ads) + 4 (Ads Integrado ao Jogo)*
*Total de linhas: ~1350*
