# 🎮 UNOBIX - Documentação Técnica Completa

## Índice
1. [Visão Geral](#1-visão-geral)
2. [Arquitetura do Sistema](#2-arquitetura-do-sistema)
3. [Fluxo de Autenticação](#3-fluxo-de-autenticação)
4. [Fluxo do Jogo](#4-fluxo-do-jogo)
5. [Sistema de Recompensas](#5-sistema-de-recompensas)
6. [APIs Backend](#6-apis-backend)
7. [Frontend - Arquivos JavaScript](#7-frontend---arquivos-javascript)
8. [Banco de Dados](#8-banco-de-dados)
9. [Sistemas Auxiliares](#9-sistemas-auxiliares)
10. [Segurança e Anti-Fraude](#10-segurança-e-anti-fraude)
11. [Configurações e Constantes](#11-configurações-e-constantes)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Visão Geral

### O que é o Unobix?
Unobix (anteriormente "Crypto Asteroid Rush") é um jogo web arcade do tipo "shoot 'em up" onde jogadores controlam uma nave espacial, destroem asteroides e ganham recompensas em BRL (Real Brasileiro).

### Modelo de Negócio
- **Tipo**: Free-to-Play com recompensas reais
- **Monetização**: Anúncios (pré-jogo, banners, intersticiais)
- **Moeda**: BRL (Real Brasileiro)
- **House Edge**: 40% das missões são "hard mode" (secreto)

### Tecnologias Utilizadas
| Camada | Tecnologia |
|--------|------------|
| Frontend | HTML5, CSS3, JavaScript (Vanilla), Canvas 2D |
| Backend | PHP 8.2 |
| Banco de Dados | MySQL/MariaDB |
| Autenticação | Firebase Authentication (Google OAuth) |
| Hospedagem | Railway |
| CAPTCHA | Matemático (custom) ou hCaptcha |

### Evolução do Projeto
```
Crypto Asteroid Rush (v1.0) → Unobix (v4.0+)
├── MetaMask Wallet      → Google OAuth
├── USDT (BSC)           → BRL
├── Pay-to-Play          → Free-to-Play
└── Sem limite           → 5 missões/hora
```

---

## 2. Arquitetura do Sistema

### Diagrama de Arquitetura
```
┌─────────────────────────────────────────────────────────────────┐
│                         CLIENTE (Browser)                        │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌──────────────┐  ┌─────────────────────────┐ │
│  │   HTML      │  │     CSS      │  │      JavaScript         │ │
│  │  Pages      │  │   Styles     │  │   (Game + Auth + UI)    │ │
│  └─────────────┘  └──────────────┘  └─────────────────────────┘ │
│         │                │                      │                │
│         └────────────────┼──────────────────────┘                │
│                          │                                       │
│                    ┌─────▼─────┐                                 │
│                    │  Canvas   │ ← Renderização do jogo          │
│                    │   2D      │                                 │
│                    └───────────┘                                 │
└─────────────────────────────────────────────────────────────────┘
                               │
                               │ HTTP/HTTPS (JSON)
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                      SERVIDOR (Railway)                          │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────────┐│
│  │                      PHP APIs                                ││
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       ││
│  │  │ auth-google  │  │  game-start  │  │  game-end    │       ││
│  │  └──────────────┘  └──────────────┘  └──────────────┘       ││
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       ││
│  │  │ game-event   │  │   balance    │  │   withdraw   │       ││
│  │  └──────────────┘  └──────────────┘  └──────────────┘       ││
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       ││
│  │  │ referral-*   │  │   stake      │  │  ads-config  │       ││
│  │  └──────────────┘  └──────────────┘  └──────────────┘       ││
│  └─────────────────────────────────────────────────────────────┘│
│                               │                                  │
│                               ▼                                  │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │                   MySQL Database                             ││
│  │  players | game_sessions | game_events | transactions | ...  ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                               │
                               │ OAuth 2.0
                               ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Firebase (Google)                             │
│                  Authentication Service                          │
└─────────────────────────────────────────────────────────────────┘
```

### Estrutura de Diretórios
```
unobix/
├── api/                          # Backend PHP
│   ├── config.php                # Configurações globais
│   ├── auth-google.php           # Autenticação Google
│   ├── game-start.php            # Iniciar sessão de jogo
│   ├── game-event.php            # Registrar eventos (asteroides destruídos)
│   ├── game-end.php              # Finalizar sessão
│   ├── balance.php               # Consultar saldo
│   ├── withdraw.php              # Solicitar saque
│   ├── stake.php                 # Fazer staking
│   ├── unstake.php               # Desfazer staking
│   ├── referral-info.php         # Info de afiliados
│   ├── referral-claim.php        # Resgatar comissão
│   ├── ads-config.php            # Configurações de anúncios
│   ├── ads-log.php               # Log de eventos de ads
│   └── ...
│
├── js/                           # Frontend JavaScript
│   ├── firebase-config.js        # Configuração Firebase
│   ├── auth-manager.js           # Gerenciador de autenticação
│   ├── game-config.js            # Configurações do jogo
│   ├── game-main.js              # Ponto de entrada do jogo
│   ├── game-engine.js            # Motor do jogo (física, colisões)
│   ├── game-renderer.js          # Renderização (Canvas)
│   ├── game-ui.js                # Interface do usuário
│   ├── game-start.js             # Lógica de início de missão
│   ├── game-session-manager.js   # Gerenciador de sessão
│   ├── game-ships.js             # Seleção de naves
│   ├── game-audio.js             # Sistema de áudio
│   ├── game-anticheat.js         # Sistema anti-cheat
│   ├── captcha-manager.js        # Gerenciador de CAPTCHA
│   ├── ads-manager.js            # Gerenciador de anúncios
│   └── main.js                   # Scripts gerais do site
│
├── css/
│   ├── main.css                  # Estilos do site
│   └── game.css                  # Estilos do jogo
│
├── img/
│   └── logo.png
│
└── *.html                        # Páginas do site
    ├── index.html                # Landing page
    ├── game.html                 # Página do jogo
    ├── dashboard.html            # Painel do usuário
    ├── wallet.html               # Carteira
    ├── staking.html              # Staking
    └── affiliates.html           # Programa de afiliados
```

---

## 3. Fluxo de Autenticação

### Diagrama de Sequência - Login
```
┌────────┐     ┌──────────────┐     ┌──────────────┐     ┌────────────┐
│ Usuário│     │   Frontend   │     │   Firebase   │     │  Backend   │
└───┬────┘     └──────┬───────┘     └──────┬───────┘     └─────┬──────┘
    │                 │                    │                   │
    │ Clica "Login    │                    │                   │
    │ com Google"     │                    │                   │
    │────────────────>│                    │                   │
    │                 │                    │                   │
    │                 │ signInWithPopup()  │                   │
    │                 │───────────────────>│                   │
    │                 │                    │                   │
    │                 │    (Popup Google)  │                   │
    │<─ ─ ─ ─ ─ ─ ─ ─│<───────────────────│                   │
    │                 │                    │                   │
    │ Seleciona conta │                    │                   │
    │─ ─ ─ ─ ─ ─ ─ ─>│                    │                   │
    │                 │                    │                   │
    │                 │  User Object       │                   │
    │                 │  (uid, email...)   │                   │
    │                 │<───────────────────│                   │
    │                 │                    │                   │
    │                 │ POST /api/auth-google.php              │
    │                 │ {google_uid, email, display_name...}   │
    │                 │───────────────────────────────────────>│
    │                 │                    │                   │
    │                 │                    │     Cria/Atualiza │
    │                 │                    │     jogador no DB │
    │                 │                    │                   │
    │                 │    {success: true, session_token, player}
    │                 │<───────────────────────────────────────│
    │                 │                    │                   │
    │                 │ Salva em localStorage:                 │
    │                 │ - googleUid                            │
    │                 │ - sessionToken                         │
    │                 │ - userDisplayName                      │
    │                 │                    │                   │
    │  Redireciona    │                    │                   │
    │  para game.html │                    │                   │
    │<────────────────│                    │                   │
```

### Arquivos Envolvidos
| Arquivo | Função |
|---------|--------|
| `js/firebase-config.js` | Configuração do Firebase (API Key, Project ID) |
| `js/auth-manager.js` | Classe AuthManager - gerencia login/logout |
| `api/auth-google.php` | Valida e registra usuário no banco |

### Dados Armazenados (localStorage)
```javascript
localStorage.setItem('googleUid', user.uid);
localStorage.setItem('sessionToken', token);
localStorage.setItem('userDisplayName', user.displayName);
localStorage.setItem('userEmail', user.email);
localStorage.setItem('userPhotoURL', user.photoURL);
```

---

## 4. Fluxo do Jogo

### Diagrama Completo - Ciclo de uma Missão
```
┌─────────────────────────────────────────────────────────────────────────┐
│                           FLUXO DO JOGO                                  │
└─────────────────────────────────────────────────────────────────────────┘

┌──────────────────┐
│  1. MENU INICIAL │
│  (game.html)     │
└────────┬─────────┘
         │
         ▼
┌──────────────────────────────────────┐
│  2. SELEÇÃO DE NAVE                  │
│  - Player escolhe entre 5 designs    │
│  - Cada nave é apenas visual         │
│  - Salva em selectedShipDesign       │
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│  3. CLICA "INICIAR MISSÃO"           │
│  - Botão #startMissionBtn            │
│  - Chama startGameWithLoading()      │
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│  4. ANÚNCIO PRÉ-JOGO (5 segundos)    │
│  - AdsManager.showPreGameAd()        │
│  - Mostra contador regressivo        │
│  - Busca config de api/ads-config.php│
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│  5. CRIAR SESSÃO NO SERVIDOR         │
│  POST /api/game-start.php            │
│  Body: { google_uid }                │
│                                      │
│  Servidor:                           │
│  ├─ Valida google_uid                │
│  ├─ Verifica limite de IP (5/hora)   │
│  ├─ Verifica missão simultânea       │
│  ├─ Busca/cria jogador               │
│  ├─ Determina HARD MODE (40%)        │
│  ├─ Gera session_id e token          │
│  └─ Retorna configurações            │
│                                      │
│  Response:                           │
│  {                                   │
│    success: true,                    │
│    session_id: 12345,                │
│    session_token: "abc...",          │
│    mission_number: 5,                │
│    is_hard_mode: false,              │
│    rare_count: 2,                    │
│    has_epic: true,                   │
│    game_duration: 180,               │
│    initial_lives: 6                  │
│  }                                   │
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│  6. INICIALIZAR JOGO                 │
│  - Reseta gameState                  │
│  - Cria asteroides iniciais          │
│  - Posiciona nave                    │
│  - Inicia timers                     │
│  - Inicia game loop                  │
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────────────┐
│  7. GAME LOOP (180 segundos)                                     │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │                      A cada frame:                         │  │
│  │  1. Processar input (teclado/touch)                        │  │
│  │  2. Mover nave                                             │  │
│  │  3. Mover balas                                            │  │
│  │  4. Mover asteroides                                       │  │
│  │  5. Detectar colisões (bala ↔ asteroide)                   │  │
│  │  6. Detectar colisões (nave ↔ asteroide)                   │  │
│  │  7. Spawn de novos asteroides                              │  │
│  │  8. Atualizar partículas                                   │  │
│  │  9. Renderizar tudo no Canvas                              │  │
│  │ 10. Atualizar UI (tempo, vidas, ganhos)                    │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                  │
│  EVENTOS DURANTE O JOGO:                                         │
│                                                                  │
│  ┌─────────────────────────────────────┐                         │
│  │ Asteroide destruído                 │                         │
│  │ → Verifica tipo (common/rare/epic)  │                         │
│  │ → Calcula recompensa                │                         │
│  │ → Atualiza earnings local           │                         │
│  │ → Envia para servidor (queue)       │                         │
│  │   POST /api/game-event.php          │                         │
│  │   { session_id, asteroid_id,        │                         │
│  │     reward_type, reward_amount }    │                         │
│  └─────────────────────────────────────┘                         │
│                                                                  │
│  ┌─────────────────────────────────────┐                         │
│  │ Colisão nave ↔ asteroide            │                         │
│  │ → Perde 1 vida                      │                         │
│  │ → Ativa invencibilidade (60 frames) │                         │
│  │ → Se vidas = 0 → GAME OVER          │                         │
│  └─────────────────────────────────────┘                         │
│                                                                  │
│  ┌─────────────────────────────────────┐                         │
│  │ Tempo esgotado (180s)               │                         │
│  │ → VITÓRIA                           │                         │
│  └─────────────────────────────────────┘                         │
└────────┬─────────────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│  8. FIM DO JOGO                      │
│  - Para timers                       │
│  - Para game loop                    │
│  - Mostra modal de fim               │
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐      ┌─────────────────────┐
│  9. VERIFICAÇÃO CAPTCHA              │      │  Se GAME OVER:      │
│  (apenas se vitória)                 │      │  - Mostra estatísticas
│  - Exibe desafio matemático          │      │  - Ganhos = 0       │
│  - Ex: "12 + 7 = ?"                  │      │  - Botão "Tentar    │
│  - Usuário resolve                   │      │    Novamente"       │
│  - Se correto, habilita resgate      │      └─────────────────────┘
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│ 10. FINALIZAR SESSÃO NO SERVIDOR     │
│  POST /api/game-end.php              │
│  Body: {                             │
│    session_id,                       │
│    session_token,                    │
│    google_uid,                       │
│    score,                            │
│    earnings,                         │
│    captcha_token (se vitória)        │
│  }                                   │
│                                      │
│  Servidor:                           │
│  ├─ Valida sessão                    │
│  ├─ Verifica CAPTCHA                 │
│  ├─ Calcula ganhos do SERVIDOR       │
│  │   (soma game_events)              │
│  ├─ Compara com cliente (tolerância) │
│  ├─ Credita jogador                  │
│  ├─ Atualiza referral (se houver)    │
│  └─ Retorna saldo atualizado         │
│                                      │
│  Response:                           │
│  {                                   │
│    success: true,                    │
│    final_earnings: 0.023,            │
│    new_balance: 1.45,                │
│    credited: true                    │
│  }                                   │
└────────┬─────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────┐
│ 11. EXIBE RESULTADO                  │
│  - Mostra ganhos da missão           │
│  - Mostra novo saldo                 │
│  - Botões: "Jogar Novamente" / "Sair"│
└──────────────────────────────────────┘
```

### Estados do Jogo (gameState)
```javascript
gameState = {
    // Autenticação
    user: null,              // Objeto do usuário Firebase
    googleUid: "abc123...",  // UID do Google
    isConnected: true,       // Se está logado
    
    // Estado do Jogo
    gameActive: true,        // Se jogo está rodando
    timeLeft: 145,           // Tempo restante (segundos)
    score: 1250,             // Pontuação
    earnings: 0.015,         // Ganhos em BRL
    lives: 4,                // Vidas restantes
    invincibilityFrames: 0,  // Frames de invencibilidade
    
    // Objetos do Jogo
    ship: { x, y, width, height, speed, design },
    asteroids: [...],
    bullets: [...],
    particles: [...],
    destroyedAsteroids: [...],
    
    // Sessão
    sessionId: 12345,
    sessionToken: "xyz789..."
}
```

---

## 5. Sistema de Recompensas

### Tipos de Asteroides e Valores
| Tipo | Cor/Visual | Spawn Rate (Normal) | Spawn Rate (Hard) | Valor (BRL) |
|------|------------|---------------------|-------------------|-------------|
| COMMON | Cinza | 95% | 97% | R$ 0,00 |
| RARE | Azul brilhante | 3% | 2.2% | R$ 0,001 |
| EPIC | Roxo/magenta | 1.5% | 0.6% | R$ 0,005 |
| LEGENDARY | Dourado | 0.5% | 0.2% | R$ 0,02 |

### Cálculo de Ganhos
```
Ganho por Missão = Σ (asteroides destruídos × valor do tipo)

Exemplo (missão normal, 180s):
- 45 Common × R$ 0,00  = R$ 0,000
- 3 Rare × R$ 0,001    = R$ 0,003
- 1 Epic × R$ 0,005    = R$ 0,005
- 0 Legendary × R$ 0,02 = R$ 0,000
                        ─────────
Total:                   R$ 0,008
```

### Hard Mode (House Edge)
- **40% das missões** são secretamente "hard mode"
- O jogador **NÃO SABE** se está em hard mode
- Características do Hard Mode:
  - Asteroides 40% mais rápidos
  - Mais asteroides comuns (97% vs 95%)
  - Menos asteroides raros (2.2% vs 3%)
  - Menos asteroides épicos (0.6% vs 1.5%)
  - Menos asteroides lendários (0.2% vs 0.5%)

### Validação Server-Side
1. **Cliente** envia ganhos calculados localmente
2. **Servidor** calcula ganhos baseado nos `game_events`
3. **Comparação** com tolerância de 10%
4. **Sempre usa valor do servidor** (nunca confiar no cliente)

```php
// game-end.php
$serverEarnings = getServerCalculatedEarnings($sessionId);
$clientEarnings = $_POST['earnings'];

// Tolerância de 10%
$tolerance = $serverEarnings * 0.10;
if (abs($clientEarnings - $serverEarnings) > $tolerance) {
    logSuspiciousActivity($sessionId);
}

// SEMPRE creditar valor do servidor
$finalEarnings = $serverEarnings;
```

---

## 6. APIs Backend

### 6.1 auth-google.php
**Função**: Autenticação e registro de usuários

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/api/auth-google.php` | Login/Registro com Google |

**Request (action: login)**:
```json
{
    "action": "login",
    "google_uid": "abc123def456...",
    "email": "usuario@gmail.com",
    "display_name": "João Silva",
    "photo_url": "https://..."
}
```

**Response (sucesso)**:
```json
{
    "success": true,
    "message": "Login realizado com sucesso",
    "is_new_user": false,
    "session_token": "xyz789...",
    "player": {
        "id": 42,
        "google_uid": "abc123def456...",
        "email": "usuario@gmail.com",
        "display_name": "João Silva",
        "balance_brl": "15.50",
        "total_played": 47
    }
}
```

---

### 6.2 game-start.php
**Função**: Iniciar nova sessão de jogo

**Request**:
```json
{
    "google_uid": "abc123def456..."
}
```

**Response**:
```json
{
    "success": true,
    "session_id": 12345,
    "session_token": "xyz789...",
    "player_id": 42,
    "mission_number": 48,
    "is_hard_mode": false,
    "rare_count": 2,
    "has_epic": true,
    "rare_ids": [67, 134],
    "epic_id": 215,
    "game_duration": 180,
    "initial_lives": 6,
    "missions_remaining": 3
}
```

**Validações**:
- ✓ google_uid válido
- ✓ Usuário não banido
- ✓ Máximo 5 missões/hora por IP
- ✓ Sem missão simultânea ativa

---

### 6.3 game-event.php
**Função**: Registrar destruição de asteroide

**Request**:
```json
{
    "session_id": 12345,
    "session_token": "xyz789...",
    "google_uid": "abc123...",
    "asteroid_id": 67,
    "reward_type": "rare",
    "reward_amount": 0.001
}
```

**Response**:
```json
{
    "success": true,
    "event_id": 9876,
    "server_reward": 0.001,
    "session_total": 0.015
}
```

**Importante**: O `reward_amount` enviado pelo cliente é **ignorado**. O servidor calcula o valor baseado no `reward_type`.

---

### 6.4 game-end.php
**Função**: Finalizar sessão e creditar ganhos

**Request**:
```json
{
    "session_id": 12345,
    "session_token": "xyz789...",
    "google_uid": "abc123...",
    "score": 1250,
    "earnings": 0.023,
    "lives_remaining": 2,
    "captcha_token": "math_19_1706..."
}
```

**Response**:
```json
{
    "success": true,
    "session_id": 12345,
    "mission_number": 48,
    "is_hard_mode": false,
    "victory": true,
    "earnings_brl": 0.023,
    "final_earnings": 0.023,
    "new_balance": 15.73,
    "credited": true,
    "captcha_verified": true,
    "stats": {
        "asteroids_destroyed": 52,
        "legendary": 0,
        "epic": 1,
        "rare": 3,
        "common": 48
    }
}
```

---

### 6.5 Outras APIs

| API | Função |
|-----|--------|
| `balance.php` | Consultar saldo do jogador |
| `withdraw.php` | Solicitar saque (mín R$ 1,00) |
| `stake.php` | Fazer staking (5% APY) |
| `unstake.php` | Resgatar staking |
| `referral-info.php` | Informações do programa de afiliados |
| `referral-claim.php` | Resgatar comissão de indicação |
| `ads-config.php` | Configurações de anúncios |
| `ads-log.php` | Registrar eventos de anúncios |

---

## 7. Frontend - Arquivos JavaScript

### 7.1 Ordem de Carregamento
```html
<!-- game.html -->
<script src="https://www.gstatic.com/firebasejs/9.x/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.x/firebase-auth-compat.js"></script>
<script src="js/firebase-config.js"></script>   <!-- 1º: Config Firebase -->
<script src="js/auth-manager.js"></script>       <!-- 2º: Autenticação -->
<script src="js/game-config.js"></script>        <!-- 3º: Configurações -->
<script src="js/game-ui.js"></script>            <!-- 4º: UI -->
<script src="js/game-engine.js"></script>        <!-- 5º: Motor do jogo -->
<script src="js/game-renderer.js"></script>      <!-- 6º: Renderização -->
<script src="js/ship-renderer.js"></script>      <!-- 7º: Render de naves -->
<script src="js/game-ships.js"></script>         <!-- 8º: Seleção de naves -->
<script src="js/game-session-manager.js"></script><!-- 9º: Sessões -->
<script src="js/game-start.js"></script>         <!-- 10º: Início do jogo -->
<script src="js/game-audio.js"></script>         <!-- 11º: Áudio -->
<script src="js/game-anticheat.js"></script>     <!-- 12º: Anti-cheat -->
<script src="js/ads-manager.js"></script>        <!-- 13º: Anúncios -->
<script src="js/captcha-manager.js"></script>    <!-- 14º: CAPTCHA -->
<script src="js/game-main.js"></script>          <!-- 15º: Principal -->
```

### 7.2 Responsabilidades dos Arquivos

| Arquivo | Responsabilidade |
|---------|------------------|
| `firebase-config.js` | Configuração do Firebase (API keys) |
| `auth-manager.js` | Login/logout com Google, sincronização com backend |
| `game-config.js` | Constantes (CONFIG), gameState, missionStats |
| `game-ui.js` | Modais, notificações, atualização de UI |
| `game-engine.js` | Física, colisões, criação de asteroides |
| `game-renderer.js` | Desenho no Canvas (nave, asteroides, balas) |
| `ship-renderer.js` | Renderização das 5 naves diferentes |
| `game-ships.js` | Seleção de nave, preview |
| `game-session-manager.js` | Comunicação com APIs (start, event, end) |
| `game-start.js` | Lógica de início de missão |
| `game-audio.js` | Efeitos sonoros, música de fundo |
| `game-anticheat.js` | Validações básicas anti-cheat |
| `ads-manager.js` | Gerenciamento de anúncios |
| `captcha-manager.js` | CAPTCHA matemático |
| `game-main.js` | Inicialização, event listeners, game loop |

### 7.3 Funções Globais Importantes

```javascript
// game-config.js
window.CONFIG               // Configurações
window.gameState            // Estado do jogo
window.missionStats         // Estatísticas da missão
window.formatBRL(value)     // Formatar valor em BRL
window.getRandomAsteroidType() // Tipo aleatório de asteroide
window.determineHardMode()  // Determina se é hard mode

// game-main.js
window.startGameWithLoading() // Inicia jogo com loading
window.endGame(isVictory)     // Finaliza jogo
window.gameLoop()             // Loop principal

// game-session-manager.js
window.SessionManager.startSession(googleUid)
window.SessionManager.recordEvent(asteroidId, type, amount)
window.SessionManager.endSession(score, earnings, stats)

// auth-manager.js
window.authManager.signIn()    // Login
window.authManager.signOut()   // Logout
window.authManager.getUserId() // Pegar google_uid

// ads-manager.js
window.AdsManager.showPreGameAd()  // Mostrar anúncio pré-jogo
window.AdsManager.showInterstitial() // Mostrar intersticial

// captcha-manager.js
window.CaptchaManager.init()    // Inicializar CAPTCHA
window.CaptchaManager.verify()  // Verificar resposta
window.CaptchaManager.getToken() // Pegar token
```

---

## 8. Banco de Dados

### Diagrama ER Simplificado
```
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│     players     │       │  game_sessions  │       │   game_events   │
├─────────────────┤       ├─────────────────┤       ├─────────────────┤
│ id (PK)         │──┐    │ id (PK)         │──┐    │ id (PK)         │
│ google_uid (UK) │  │    │ google_uid (FK) │  │    │ session_id (FK) │
│ wallet_address  │  └───>│ wallet_address  │  └───>│ asteroid_id     │
│ email           │       │ session_token   │       │ reward_type     │
│ display_name    │       │ mission_number  │       │ reward_amount   │
│ balance_brl     │       │ is_hard_mode    │       │ created_at      │
│ total_earned    │       │ earnings_brl    │       └─────────────────┘
│ total_played    │       │ status          │
│ is_banned       │       │ started_at      │
│ created_at      │       │ ended_at        │
└─────────────────┘       └─────────────────┘

┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│  transactions   │       │    referrals    │       │   ip_sessions   │
├─────────────────┤       ├─────────────────┤       ├─────────────────┤
│ id (PK)         │       │ id (PK)         │       │ id (PK)         │
│ google_uid      │       │ referrer_uid    │       │ ip_address      │
│ type            │       │ referred_uid    │       │ session_id      │
│ amount_brl      │       │ status          │       │ google_uid      │
│ status          │       │ missions_done   │       │ status          │
│ created_at      │       │ commission      │       │ started_at      │
└─────────────────┘       └─────────────────┘       └─────────────────┘
```

### Tabela: players
```sql
CREATE TABLE players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_uid VARCHAR(128) UNIQUE,
    wallet_address VARCHAR(42),
    email VARCHAR(255),
    display_name VARCHAR(255),
    photo_url VARCHAR(500),
    balance_brl DECIMAL(15,4) DEFAULT 0,
    staked_balance_brl DECIMAL(15,4) DEFAULT 0,
    total_earned_brl DECIMAL(15,4) DEFAULT 0,
    total_withdrawn_brl DECIMAL(15,4) DEFAULT 0,
    total_played INT DEFAULT 0,
    is_banned TINYINT(1) DEFAULT 0,
    ban_reason VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_google (google_uid),
    INDEX idx_wallet (wallet_address)
);
```

### Tabela: game_sessions
```sql
CREATE TABLE game_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_uid VARCHAR(128),
    wallet_address VARCHAR(42),
    session_token VARCHAR(255),
    mission_number INT DEFAULT 1,
    status ENUM('active','completed','expired') DEFAULT 'active',
    is_hard_mode TINYINT(1) DEFAULT 0,
    earnings_brl DECIMAL(15,4) DEFAULT 0,
    client_score INT DEFAULT 0,
    client_earnings DECIMAL(15,4) DEFAULT 0,
    asteroids_destroyed INT DEFAULT 0,
    rare_asteroids_target INT DEFAULT 0,
    epic_asteroid_target INT DEFAULT 0,
    captcha_verified TINYINT(1) DEFAULT 0,
    ip_address VARCHAR(45),
    validation_errors TEXT,
    alert_level VARCHAR(20),
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_google (google_uid),
    INDEX idx_status (status),
    INDEX idx_ip (ip_address)
);
```

### Tabela: game_events
```sql
CREATE TABLE game_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    google_uid VARCHAR(128),
    asteroid_id INT,
    reward_type ENUM('common','rare','epic','legendary'),
    reward_amount_brl DECIMAL(10,4) DEFAULT 0,
    client_amount DECIMAL(10,4) DEFAULT 0,
    is_valid TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_google (google_uid),
    FOREIGN KEY (session_id) REFERENCES game_sessions(id)
);
```

---

## 9. Sistemas Auxiliares

### 9.1 Sistema de Afiliados
```
┌─────────────────────────────────────────────────────────────────┐
│                    PROGRAMA DE AFILIADOS                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  COMO FUNCIONA:                                                 │
│  1. Jogador gera link de referência: ?ref=ABC123                │
│  2. Novo usuário se registra usando o link                      │
│  3. Novo usuário joga 100 missões                               │
│  4. Referenciador ganha R$ 1,00 de comissão                     │
│                                                                 │
│  APIs:                                                          │
│  - GET /api/referral-info.php?google_uid=...                    │
│  - POST /api/referral-claim.php { google_uid, referral_id }     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 9.2 Sistema de Staking
```
┌─────────────────────────────────────────────────────────────────┐
│                       STAKING (5% APY)                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  COMO FUNCIONA:                                                 │
│  1. Jogador deposita BRL no staking                             │
│  2. Rendimento de 5% ao ano                                     │
│  3. Pode resgatar a qualquer momento                            │
│                                                                 │
│  APIs:                                                          │
│  - POST /api/stake.php { google_uid, amount }                   │
│  - POST /api/unstake.php { google_uid, amount }                 │
│  - GET /api/get-stakes.php?google_uid=...                       │
│                                                                 │
│  Cálculo de rendimento:                                         │
│  rendimento = valor × (0.05 / 365) × dias                       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 9.3 Sistema de Anúncios
```
┌─────────────────────────────────────────────────────────────────┐
│                    GERENCIAMENTO DE ADS                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  TIPOS DE ANÚNCIOS:                                             │
│  - Pre-game: Antes de iniciar a missão (5s)                     │
│  - Banner: Fixo na tela durante jogo                            │
│  - Interstitial: Entre missões                                  │
│  - Rewarded: Opcional, dá bônus                                 │
│                                                                 │
│  CONFIGURAÇÃO (via admin):                                      │
│  - enabled: true/false                                          │
│  - preGameAdDuration: 5 (segundos)                              │
│  - allowSkip: true/false                                        │
│  - skipButtonDelay: 3 (segundos)                                │
│                                                                 │
│  APIs:                                                          │
│  - GET /api/ads-config.php                                      │
│  - POST /api/ads-log.php { event_type, ad_id, ... }             │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 10. Segurança e Anti-Fraude

### 10.1 Validações no Servidor

| Validação | Arquivo | Descrição |
|-----------|---------|-----------|
| Limite por IP | game-start.php | Máx 5 missões/hora por IP |
| Sessão única | game-start.php | Apenas 1 missão ativa por vez |
| Token de sessão | game-event.php | Valida token em cada evento |
| Ganhos server-side | game-end.php | Nunca confiar no valor do cliente |
| CAPTCHA | game-end.php | Verificação humana na vitória |
| Rate limiting | rate-limiter.php | Limite de requisições |
| Ban system | players.is_banned | Bloquear jogadores fraudulentos |

### 10.2 Níveis de Alerta
```php
// game-end.php
define('EARNINGS_ALERT_BRL', 0.30);   // Alerta
define('EARNINGS_SUSPECT_BRL', 0.50); // Suspeito
define('EARNINGS_BLOCK_BRL', 1.00);   // Bloqueia

if ($earnings > EARNINGS_BLOCK_BRL) {
    $alertLevel = 'BLOCK';
    $finalEarnings = 0; // Zera ganhos
} elseif ($earnings > EARNINGS_SUSPECT_BRL) {
    $alertLevel = 'SUSPECT';
} elseif ($earnings > EARNINGS_ALERT_BRL) {
    $alertLevel = 'ALERT';
}
```

### 10.3 Logs de Segurança
```php
// Arquivo: logs/game_security.log
secureLog("GAME_START | IP: 1.2.3.4 | UID: abc123 | Session: 12345");
secureLog("EARNINGS_SUSPECT | session: 12345 | amount: 0.55");
secureLog("CAPTCHA_FAILED | session: 12345 | attempts: 3");
```

---

## 11. Configurações e Constantes

### config.php (Backend)
```php
// Banco de Dados
define('DB_HOST', getenv('MYSQLHOST'));
define('DB_NAME', getenv('MYSQLDATABASE'));
define('DB_USER', getenv('MYSQLUSER'));
define('DB_PASS', getenv('MYSQLPASSWORD'));

// Jogo
define('GAME_DURATION', 180);          // 3 minutos
define('INITIAL_LIVES', 6);            // 6 vidas
define('MAX_MISSIONS_PER_HOUR', 5);    // Limite por IP

// Recompensas (BRL)
define('REWARD_COMMON', 0);            // R$ 0,00
define('REWARD_RARE', 0.001);          // R$ 0,001
define('REWARD_EPIC', 0.005);          // R$ 0,005
define('REWARD_LEGENDARY', 0.02);      // R$ 0,02

// Hard Mode
define('HARD_MODE_PERCENTAGE', 40);    // 40% das missões

// Segurança
define('EARNINGS_ALERT_BRL', 0.30);
define('EARNINGS_SUSPECT_BRL', 0.50);
define('EARNINGS_BLOCK_BRL', 1.00);

// Saque
define('MIN_WITHDRAW_BRL', 1.00);      // Mínimo R$ 1,00
define('WITHDRAW_WEEKLY_LIMIT', 1);    // 1 saque/semana
```

### game-config.js (Frontend)
```javascript
const CONFIG = {
    GAME_DURATION: 180,
    INITIAL_LIVES: 6,
    INITIAL_ASTEROIDS: 4,
    MAX_ASTEROIDS_ON_SCREEN: 10,
    SPAWN_INTERVAL: 500,
    
    SHIP_SPEED: 18,
    BULLET_SPEED: 16,
    FIRE_RATE: 150,
    
    REWARDS: {
        COMMON: 0,
        RARE: 0.001,
        EPIC: 0.005,
        LEGENDARY: 0.02
    },
    
    SPAWN_RATES_NORMAL: {
        COMMON: 0.95,
        RARE: 0.03,
        EPIC: 0.015,
        LEGENDARY: 0.005
    },
    
    SPAWN_RATES_HARD: {
        COMMON: 0.97,
        RARE: 0.022,
        EPIC: 0.006,
        LEGENDARY: 0.002
    },
    
    HARD_MODE: {
        SPEED_MULTIPLIER: 1.4,
        SPAWN_RATE_MULTIPLIER: 0.7
    },
    
    HOUSE_EDGE_PERCENT: 40
};
```

---

## 12. Troubleshooting

### Erro 500 em APIs

**Causas comuns**:
1. Erro de sintaxe no PHP
2. Função não definida
3. Tabela não existe no banco
4. Variável de ambiente não configurada

**Diagnóstico**:
```php
// Criar api/test.php
<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

$pdo = getDatabaseConnection();
if ($pdo) {
    echo json_encode(['status' => 'ok']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed']);
}
```

### "Identificação inválida"

**Causa**: google_uid não está sendo enviado ou é inválido

**Verificar**:
1. localStorage tem 'googleUid'?
2. authManager.getUserId() retorna valor?
3. gameState.googleUid está preenchido?

### Jogo não inicia após anúncio

**Causa**: Erro no SessionManager.startSession()

**Verificar**:
1. Console do navegador para erros
2. Network tab para resposta da API
3. Log do Railway para erros PHP

### CAPTCHA não aparece

**Causa**: Modal não está inicializando o CAPTCHA

**Verificar**:
1. CaptchaManager.init() está sendo chamado?
2. Elemento #captchaWidget existe no HTML?
3. Console mostra "CaptchaManager pronto"?

---

## Histórico de Versões

| Versão | Data | Mudanças |
|--------|------|----------|
| v1.0 | 2025-01 | Lançamento inicial (Crypto Asteroid Rush) |
| v2.0 | 2025-06 | Migração para USDT |
| v3.0 | 2025-10 | Sistema de vidas, house edge |
| v4.0 | 2026-01 | Migração para Unobix (Google Auth, BRL) |
| v4.1 | 2026-01 | Correções de autenticação, CAPTCHA matemático |

---

## Contato e Suporte

- **Hospedagem**: Google Cloud Run
- **Domínio**: https://crypto-asteroid-234282032979.us-west1.run.app/
- **Banco**: MySQL (Cloud MySQL)
- **Autenticação**: Firebase (Google Cloud)

---

*Documentação atualizada em: 30/01/2026*
