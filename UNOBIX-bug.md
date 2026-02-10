# UNOBIX - Resumo Técnico para Continuação
## Auditoria de Segurança + Correções de Bugs
### Data: 10/02/2026 | Sessão: Chat 1 → Chat 2

---

## 1. CONTEXTO DO PROJETO

UNOBIX é um jogo web mobile-first (asteroid shooter) onde jogadores ganham BRL (centavos reais). Stack: PHP backend no Google Cloud Run + Cloud SQL, frontend vanilla JS, Firebase Auth (Google OAuth), reCAPTCHA v3. Deploy automático no Cloud Run.

### Arquitetura do Fluxo de Jogo
```
game.html (menu) 
  → botão "INICIAR MISSÃO" 
  → startGameSession() [game-main.js:191] 
  → redireciona para pregame.html (loading + ads, 3-10s)
  → pregame.html termina → seta sessionStorage.loadingComplete = true
  → redireciona para game.html?start=true
  → auth listener [game-main.js:431] detecta ?start=true + loadingComplete
  → chama startGameWithLoading() [game-start.js:11]
  → chama actualStartGame() [game-start.js:56]
  → chama SessionManager.startSession() [game-session-manager.js]
  → POST /api/game-start.php (cria sessão no servidor)
  → inicializa canvas, asteroids, timers, gameLoop
  → [3 minutos de jogo]
  → timer chega a 0 → endGame() [game-engine.js:357]
  → SessionManager.endSession() → POST /api/game-end.php
  → showEndGameResults() → pode redirecionar para postgame.html (ads)
  → volta para game.html?results=true → mostra modal de resultados
```

### Ordem de Carregamento dos Scripts (game.html)
```
1. firebase-app-compat.js + firebase-auth-compat.js (CDN)
2. firebase-config.js - inicializa Firebase
3. auth-manager.js - Google OAuth, dispara evento authStateChanged
4. game-config.js - CONFIG, gameState, missionStats (globais)
5. game-anticheat.js - stub, não funcional
6. game-ships.js - 8 designs de nave
7. game-audio.js - Web Audio API
8. game-ui.js - showModal(), showLoading(), gameAlert(), showNotification(), showEndGameResults()
9. notification-system.js - NotificationSystem (toasts, modais, banners)
10. game-session-manager.js - SessionManager (startSession, endSession)
11. captcha-manager.js - reCAPTCHA v3
12. ads-manager.js - gerenciamento de anúncios
13. game-engine.js - canvas, gameLoop, endGame(), gameOver(), startGameTimer()
14. ship-renderer.js - renderização SVG das naves
15. game-renderer.js - drawBackground, drawAsteroids, colisões, gameLoop()
16. game-start.js - startGameWithLoading(), actualStartGame()
17. game-main.js - DOMContentLoaded listener, event handlers, auth listener
```

**IMPORTANTE**: `game-ui.js` exporta `showNotification` como função global, mas `notification-system.js` SOBRESCREVE `window.showNotification` na linha 503 para usar NotificationSystem. Ambos coexistem.

---

## 2. PROBLEMAS IDENTIFICADOS E STATUS

### 2.1 BUGS CRÍTICOS (Travamentos)

#### BUG-001: Loop no auth listener após pregame.html [NÃO RESOLVIDO]
- **Sintoma**: Após pregame.html redirecionar para game.html?start=true, a tela de login "BEM-VINDO COMANDANTE" aparece brevemente, depois some, depois reaparece. Eventual travamento.
- **Causa raiz**: O `authStateChanged` event pode disparar MÚLTIPLAS VEZES (Firebase `onAuthStateChanged` dispara no load + em cada mudança de estado). Na primeira vez, `user` pode ser null (Firebase ainda carregando) → mostra `connectModal`. Na segunda vez, user existe → detecta `?start=true` → mas as flags podem já ter sido consumidas ou não.
- **Localização**: `game-main.js` linhas 431-487, `auth-manager.js` (dispara o evento)
- **Tentativas de correção**: 
  - Consumir flags (`replaceState` + `removeItem`) antes de processar
  - Guard `_autoStartProcessed` para evitar re-processamento
  - **RESULTADO**: Ainda com problemas. Possível causa: o timing entre Firebase resolver o auth e o listener processar as flags não está sincronizado.
- **Solução sugerida para próximo chat**: Mudar a abordagem completamente. Em vez de depender do auth listener para auto-start, fazer o auto-start no `DOMContentLoaded` com um polling/await no auth:
  ```javascript
  // Em vez de confiar no auth listener:
  if (shouldStart && loadingComplete) {
      // Aguardar auth resolver (max 5s)
      const user = await waitForAuth(5000);
      if (user) startGameWithLoading();
      else showModal('connectModal');
  }
  ```

#### BUG-002: Travamento no fim do jogo (último segundo) [PARCIALMENTE RESOLVIDO]
- **Sintoma**: Aba do Chrome mobile congela quando timer chega a 0
- **Causa raiz**: `endGame()` faz `await SessionManager.endSession()` que faz fetch HTTP. O `cancelAnimationFrame` estava DEPOIS do await. Enquanto o fetch não retorna, o gameLoop continua rodando (consumindo CPU) + o UI thread está bloqueado.
- **Correção aplicada**: Mover `cancelAnimationFrame` + `clearInterval` para ANTES do await. Adicionar `Promise.race` com timeout de 15s.
- **Status**: Correção aplicada mas não testada com sucesso. Pode ter outros fatores (ex: reCAPTCHA v3 `getToken()` dentro do `endSession()` pode estar travando).

#### BUG-003: gameAlert() bloqueia a thread [PARCIALMENTE RESOLVIDO]
- **Sintoma**: Quando `actualStartGame()` falha (ex: SessionManager retorna erro), chama `await gameAlert()` que é uma Promise que só resolve quando o usuário clica "OK". Isso bloqueia a execução e pode conflitar com `showModal()`.
- **Causa raiz**: `gameAlert()` em `game-ui.js:292` retorna Promise. É chamada com `await` em `game-start.js:95`.
- **Correção aplicada**: Substituir `await gameAlert()` por `NotificationSystem.error()` (toast não-bloqueante).
- **Status**: Correção aplicada mas pode não ser suficiente se o problema real é outro.

#### BUG-004: Demora para iniciar partida [NÃO RESOLVIDO]
- **Sintoma**: Após seleção de nave → pregame.html (ads) → volta para game.html → demora vários segundos antes do jogo iniciar
- **Causa possível**: O `setTimeout(() => startGameWithLoading(), 500)` em `game-main.js:475` espera 500ms APÓS o auth resolver. Mas o auth pode levar 2-5 segundos para resolver no mobile (Firebase precisa verificar token).
- **Solução sugerida**: O `pregame.html` deveria chamar `game-start.php` ANTES de redirecionar, não depois. Assim quando game.html carrega, a sessão já existe.

### 2.2 PROBLEMAS DE SEGURANÇA

#### SEC-001: VPN não é detectada [NÃO RESOLVIDO]
- **Sintoma**: Jogou com VPN (IP: 185.165.240.48 — datacenter) e não foi bloqueado
- **Causa possível 1**: `proxy-check.php` está usando `getClientIP()` mas no Cloud Run o `REMOTE_ADDR` é `169.254.169.126` (IP interno). A função tenta `HTTP_X_FORWARDED_FOR` primeiro, mas pode estar pegando o IP errado.
- **Causa possível 2**: A constante `PROXY_CHECK_ENABLED` pode estar false no config.php
- **Causa possível 3**: O `PROXY_CHECK_LOG_ONLY` pode estar true (modo observação)
- **Causa possível 4**: A API proxycheck.io pode estar falhando (timeout, key inválida, etc.)
- **Diagnóstico adicionado**: Logs `GAME_START_REQUEST`, `PROXY_CHECK_START`, `PROXY_CHECK_RESULT` foram adicionados no game-start.php corrigido. **O usuário precisa testar com VPN e verificar estes logs.**
- **Localização**: `api/proxy-check.php`, `api/game-start.php` (CHECK 4)

#### SEC-002: Rate limiter silenciosamente falha [CORRIGIDO]
- **Sintoma**: Log `Column not found: 1054 Unknown column 'google_uid' in 'where clause'`
- **Causa**: Tabela `rate_limits` foi criada em versão anterior sem coluna `google_uid`. `CREATE TABLE IF NOT EXISTS` não atualiza.
- **Correção**: `rate-limiter.php` v3.0 faz `SHOW COLUMNS` + `ALTER TABLE ADD COLUMN` se faltar.
- **Status**: Correção entregue, precisa verificar se o deploy corrigiu e se os logs pararam.

#### SEC-003: Senha admin padrão [NÃO CORRIGIDO]
- **Sintoma**: `[UNOBIX] AVISO: Usando senha admin padrão! Configure ADMIN_PASS_HASH.`
- **Ação necessária**: Configurar variável de ambiente `ADMIN_PASS_HASH` no Cloud Run.

### 2.3 FINDINGS DA AUDITORIA ORIGINAL (do documento v2)

| ID | Severidade | Descrição | Status |
|----|-----------|-----------|--------|
| SEC-001 | CRÍTICO | Credenciais hardcoded em config.php | NÃO CORRIGIDO (user confirmou não acessível via web) |
| SEC-002 | ALTO | game_hash gerado mas nunca verificado | NÃO CORRIGIDO |
| SEC-003 | ALTO | Stats do cliente aceitas sem verificação cruzada | NÃO CORRIGIDO |
| SEC-004 | MÉDIO | Gaps no feedback (4 cenários) | PARCIALMENTE CORRIGIDO |
| SEC-005 | MÉDIO | Anti-cheat client-side é stub | NÃO CORRIGIDO |
| SEC-006 | MÉDIO | gameState/CONFIG expostos via window | NÃO CORRIGIDO |
| SEC-007 | BAIXO | Session token sem TTL | NÃO CORRIGIDO |

---

## 3. ARQUIVOS MODIFICADOS E ENTREGUES

### Arquivos que o usuário tem (versões corrigidas entregues):
| Arquivo | Versão | Alterações | Testado? |
|---------|--------|------------|----------|
| `api/game-start.php` | v6.0 | Barreira pré-jogo com 10 checks, block_reason, logs diagnóstico | ❌ VPN não funciona |
| `api/rate-limiter.php` | v3.0 | ALTER TABLE para google_uid faltante | ❌ Não confirmado |
| `js/game-session-manager.js` | v7.0 | _notifyBlock() com feedback por tipo | ❌ Não testado isolado |
| `js/game-start.js` | v4.2+fixes | _startGameLock, NotificationSystem em vez de gameAlert | ❌ Ainda trava |
| `js/game-main.js` | v4.1+fixes | Consumo imediato de flags, _autoStartProcessed guard | ❌ Ainda loop |
| `js/game-engine.js` | v8.0+fixes | cancelAnimationFrame antes do await, Promise.race timeout | ❌ Não confirmado |
| `js/auth-manager.js` | original+fix | NotificationSystem.banned() em vez de alert() no login | ❌ Não testado |

### Arquivos NÃO modificados (originais):
- `js/game-config.js` v4.0
- `js/game-ui.js` v3.0
- `js/notification-system.js` v8.0
- `js/game-ships.js` v4.0
- `js/game-audio.js`
- `js/game-renderer.js` v6.0
- `js/ship-renderer.js`
- `js/captcha-manager.js` v7.0
- `js/ads-manager.js` v4.0
- `js/firebase-config.js`
- `api/game-end.php`
- `api/config.php`
- `api/auth-google.php`
- `api/proxy-check.php`
- `pregame.html`
- `postgame.html`
- `game.html`

---

## 4. INFORMAÇÕES TÉCNICAS CHAVE

### Cloud Run
- Projeto: `project-7be1cae5-5f08-45fb-aca`
- Região: `us-west1`
- DB: Cloud SQL via socket `/cloudsql/project-7be1cae5-5f08-45fb-aca:us-west1:unobix`
- Fallback: localhost:3306
- DB name: `unobix_db`, user: `unobix_user`
- IP interno: `169.254.169.126` (REMOTE_ADDR no Cloud Run)
- IP real dos jogadores: via `HTTP_X_FORWARDED_FOR`

### Config.php Constantes Relevantes
```
GAME_DURATION: 180s
GAME_TOLERANCE: 30s
MAX_ASTEROIDS_PER_GAME: 400
MAX_LEGENDARY_PER_GAME: 5
MAX_EPIC_PER_GAME: 20
MAX_RARE_PER_GAME: 80
EARNINGS_BLOCK_BRL: 0.08
RECAPTCHA_SCORE_THRESHOLD: 0.5
PROXY_CHECK_ENABLED: ? (precisa verificar)
PROXY_CHECK_LOG_ONLY: ? (precisa verificar)
PROXY_CHECK_API_KEY: via getenv com fallback hardcoded
MAX_MISSIONS_PER_HOUR: ? (definido em config.php)
```

### Reward Values (BRL)
- COMMON: R$0.00
- RARE: R$0.0002
- EPIC: R$0.0004
- LEGENDARY: R$0.001

### NotificationSystem API (notification-system.js v8.0)
```javascript
NotificationSystem.toast(title, msg, type, duration)  // tipo: success/error/warning/info
NotificationSystem.success(title, msg, duration=2500)
NotificationSystem.error(title, msg, duration=4000)
NotificationSystem.warning(title, msg, duration=3000)
NotificationSystem.info(title, msg, duration=2500)
NotificationSystem.modal(title, msg, options)          // options: {icon, btnText, btnClass, onClose, dismissable}
NotificationSystem.banned(reason)                       // modal vermelho + signOut
NotificationSystem.rateLimit(waitSeconds)               // modal com countdown
NotificationSystem.flagged()                            // toast warning
NotificationSystem.banner(msg, type, duration)          // barra superior
```

### showModal() (game-ui.js v3.0)
- Remove `.active` de TODOS `.modal-overlay` (exceto custom-alert e custom-confirm)
- Adiciona `.active` ao modal com o ID fornecido
- IDs: `connectModal`, `gameMenuModal`, `endGameModal`, `gameOverModal`, `preGameScreen`
- `showModal('')` → esconde todos

### gameAlert() (game-ui.js)
- Retorna Promise — bloqueia execução até click
- EVITAR dentro de fluxos async críticos

---

## 5. RECOMENDAÇÃO PARA PRÓXIMO CHAT

### Prioridade 1: Resolver travamentos (impacto no jogador)
1. **Pedir TODOS os logs** do momento do travamento (Cloud Run logs + console do navegador)
2. **Repensar o fluxo pregame → game**: O problema fundamental é que pregame.html redireciona para game.html e depende do auth listener para continuar. Isso é frágil. Alternativas:
   - Fazer pregame.html chamar game-start.php ANTES de redirecionar (sessão já criada quando game.html carrega)
   - Usar SPA approach (pregame como modal dentro de game.html, sem redirecionamento)
3. **Adicionar console.log extensivo** em cada ponto do fluxo para mapear onde exatamente trava

### Prioridade 2: VPN/Proxy detection
1. **Verificar logs de diagnóstico** adicionados ao game-start.php
2. **Verificar valores** de PROXY_CHECK_ENABLED e PROXY_CHECK_LOG_ONLY no config.php
3. Se necessário, fazer teste direto: `curl -X POST game-start.php` com IP fake para ver resposta

### Prioridade 3: Completar correções de segurança
1. Ativar verificação de game_hash (SEC-002)
2. Implementar seed-based stat validation (SEC-003)
3. Configurar ADMIN_PASS_HASH

### O que NÃO fazer
- NÃO reescrever arquivos inteiros — apenas alterações cirúrgicas
- NÃO assumir como funções funcionam sem ver o código
- NÃO fazer mais de 2-3 alterações sem o usuário testar
- SEMPRE pedir os logs COMPLETOS do momento do problema

---

## 6. LISTA DE ARQUIVOS DISPONÍVEIS

O usuário já forneceu todos estes arquivos (originais):

**Backend (PHP):**
- api/config.php
- api/game-start.php
- api/game-end.php
- api/auth-google.php
- api/rate-limiter.php
- api/proxy-check.php

**Frontend (JS):**
- js/firebase-config.js
- js/auth-manager.js
- js/game-config.js
- js/game-anticheat.js (stub)
- js/game-ships.js
- js/game-audio.js
- js/game-ui.js
- js/notification-system.js
- js/game-session-manager.js
- js/captcha-manager.js
- js/ads-manager.js
- js/game-engine.js
- js/ship-renderer.js
- js/game-renderer.js
- js/game-start.js
- js/game-main.js

**HTML:**
- game.html
- pregame.html
- postgame.html

**NÃO fornecidos (podem ser necessários):**
- css/game.css
- index.html (landing page)
- wallet.html
- js/game-anticheat.js (conteúdo completo)
- Painel admin (admin/index.php)
- api/ads-config.php
- api/admin-ads.php
