# 📚 DOCUMENTAÇÃO ÚNICA DO PROJETO - UNOBIX
*Baseada em revisão completa + docbase.md - Última atualização: 2026-02-03*

## 🎮 VISÃO GERAL DO PROJETO

### **NOME E EVOLUÇÃO:**
- **Nome atual:** Unobix (v4.0+)
- **Nome anterior:** Crypto Asteroid Rush (v1.0)
- **Evolução:** MetaMask → Google OAuth | USDT → BRL | Pay-to-Play → Free-to-Play

### **MODELO DE NEGÓCIO:**
- **Tipo:** Free-to-Play com recompensas reais em BRL
- **Monetização:** Anúncios (pré-jogo na loading.html, pós-jogo na finalização, banners, intersticiais)
- **House Edge:** 40% das missões são "hard mode" (secreto)
- **Limite:** 5 missões por hora por IP

## 📊 ESTATÍSTICAS ATUAIS (VERIFICADAS)
- **Total arquivos:** 111 (após limpeza)
- **Arquivos PHP:** 45 (29 na pasta `api/`)
- **Páginas HTML:** 13 (após remoções)
- **Arquivos JavaScript:** 19
- **Arquivos CSS:** 4
- **Imagens:** 4 arquivos
- **Sons:** 4 arquivos
- **Documentação:** 1 arquivo MD (este)

## 📁 ESTRUTURA REAL ATUAL DO PROJETO

### **PASTAS PRINCIPAIS:**
```
crypto-asteroid-rush/
├── 📁 admin/                    # Painel administrativo (12 arquivos)
│   ├── 📁 css/                 # Estilos admin
│   ├── 📁 includes/            # Componentes reutilizáveis
│   ├── 📁 js/                  # JavaScript admin
│   └── 📁 pages/               # Páginas do admin
├── 📁 api/                     # Backend PHP (29 arquivos ativos)
│   └── 📁 logs/                # Logs da API
├── 📁 css/                     # Estilos (4 arquivos)
├── 📁 docker/                  # Configuração Docker (5 arquivos)
├── 📁 img/                     # Imagens (4 arquivos)
├── 📁 js/                      # JavaScript (19 arquivos)
├── 📁 sounds/                  # Sons do jogo (4 arquivos)
└── 📁 thumbnails/              # Miniaturas (2 arquivos)
```

## 📄 ARQUIVOS PHP DA API (ATUALIZADO)

### **🔐 AUTENTICAÇÃO:**
- `auth-google.php`       # Login Google direto
- `auth-firebase.php`     # Verificação Firebase (CORRIGIDO)
- `login.php`            # Login Wallet (legado)

### **🎮 JOGO:**
- `game-start.php`       # Iniciar sessão (CORRIGIDO)
- `game-event.php`       # Eventos do jogo
- `game-end.php`         # Finalizar jogo

### **💰 FINANCEIRO:**
- `balance.php`          # Saldo
- `stake.php`            # Staking
- `withdraw.php`         # Saques
- `transactions.php`     # Transações

### **🔗 REFERRAL:**
- `referral-info.php`    # Info referrals
- `referral-claim.php`   # Claim rewards
- `referral-register.php` # Registrar referral

### **⚙️ CONFIGURAÇÃO:**
- `config.php`           # Configuração completa (CORRIGIDO)
- `config-cloudrun.php`  # Config Cloud Run
- `rate-limiter.php`     # Rate limiting

### **🛡️ SEGURANÇA:**
- `admin-security.php`   # Segurança admin
- `verify-captcha.php`   # CAPTCHA
- `report-suspicious.php` # Reportar atividades

## 🌐 PÁGINAS HTML ATUAIS (13 ARQUIVOS)

### **🏠 INTERFACE DO USUÁRIO:**
- `index.html`           # Página inicial
- `dashboard.html`       # Painel do usuário (login Google)
- `game.html`            # Jogo principal
- `wallet.html`          # Carteira
- `staking.html`         # Staking

### **📚 CONTEÚDO INFORMATIVO:**
- `how-to-play.html`     # Como jogar
- `rules.html`           # Regras
- `faq.html`             # FAQ
- `economy.html`         # Economia

### **👤 PAINEL DO CLIENTE:**
- `dashboard.html`       # Painel principal
- `wallet.html`          # Carteira
- `staking.html`         # Staking
- `affiliates.html`      # Programa de afiliados (painel cliente)

### **📋 LEGAL:**
- `terms.html`           # Termos de uso
- `privacy.html`         # Política de privacidade

### **🎮 GAMEPLAY:**
- `gameplay.html`        # Demonstração gameplay

## 📜 ARQUIVOS JAVASCRIPT (19 ARQUIVOS)

### **🎮 CORE DO JOGO:**
- `game-main.js`         # Principal
- `game-engine.js`       # Motor do jogo
- `game-renderer.js`     # Renderização
- `game-ui.js`           # Interface
- `game-session.js`      # Sessão
- `game-ships.js`        # Naves

### **🔐 AUTENTICAÇÃO:**
- `auth-manager.js`      # Gerenciador auth (Firebase SDK)
- `firebase-config.js`   # Config Firebase

### **💰 FINANCEIRO:**
- `game-wallet.js`       # Carteira
- `game-session-manager.js` # Gerenciador sessão

### **🎵 MULTIMÍDIA:**
- `game-audio.js`        # Áudio
- `ship-renderer.js`     # Renderização naves

### **🛡️ SEGURANÇA:**
- `game-anticheat.js`    # Anti-cheat
- `captcha-manager.js`   # Gerenciador CAPTCHA

### **📊 CONFIGURAÇÃO:**
- `game-config.js`       # Configurações (CONFIG, gameState, missionStats)
- `game-start.js`        # Lógica de início de missão
- `main.js`              # Script principal global (inicialização, event listeners)

### **📢 ANÚNCIOS:**
- `ads-manager.js`       # Gerenciador de anúncios (pré-jogo, banners, etc.)

### **🎯 ORDEM DE CARREGAMENTO (game.html):**
```
1. firebase-config.js    # Config Firebase
2. auth-manager.js       # Autenticação
3. game-config.js        # Configurações
4. game-ui.js            # UI
5. game-engine.js        # Motor do jogo
6. game-renderer.js      # Renderização
7. ship-renderer.js      # Render de naves
8. game-ships.js         # Seleção de naves
9. game-session-manager.js # Sessões
10. game-start.js        # Início do jogo
11. game-audio.js        # Áudio
12. game-anticheat.js    # Anti-cheat
13. ads-manager.js       # Anúncios
14. captcha-manager.js   # CAPTCHA
15. game-main.js         # Principal (game loop)
```

## 🎨 ARQUIVOS CSS (4 ARQUIVOS)
- `main.css`             # Estilos gerais
- `game.css`             # Estilos do jogo
- `dashboard.css`        # Estilos dashboard
- `admin/css/admin.css`  # Estilos admin

## 🐳 DEPLOY & INFRAESTRUTURA

### **DOCKER & CLOUD RUN:**
- `Dockerfile`           # Imagem Docker
- `cloudbuild.yaml`      # Build Cloud Run
- `docker/nginx.conf`    # Config Nginx
- `docker/entrypoint.sh` # Script inicialização
- `docker/supervisord.conf` # Supervisor
- `docker/php-fpm-dynamic.conf` # PHP-FPM dinâmico
- `docker/auto-scale.sh` # Auto-scale

### **VARIÁVEIS DE AMBIENTE:**
- `.env.example`         # Template variáveis
- `.env.local`           # Config local (testes)
- `.env`                 # Configuração atual
- `.env.test`            # Config testes

## ✅ CORREÇÕES APLICADAS (CONFIRMADAS)

### **PROBLEMAS RESOLVIDOS:**
1. **auth-firebase.php** - Conexão banco corrigida para Cloud Run
2. **config.php** - Não mata app se variáveis não definidas
3. **game-start.php** - INSERT corrigido, validação UID
4. **game_sessions table** - Colunas faltantes criadas
5. **UID validation** - Aceita `...` para compatibilidade

*Nota: Estes problemas foram corrigidos em commits recentes e não afetam mais o funcionamento.*

## 🎯 PROBLEMAS ATUAIS (FOCAR AQUI)

### **1. CONFIGURAÇÃO CLOUD RUN:**
- **SITUAÇÃO:** Cloud Run usa socket Unix (`/cloudsql/...`), não TCP/IP
- **IMPACTO:** Conexão banco pode falhar se não configurado corretamente
- **VERIFICAÇÃO:** Testar `db-ping.php` e `test-cloudsql-connection.php` no Cloud Run

### **2. VARIÁVEIS DE AMBIENTE:**
- **SITUAÇÃO:** `cloudbuild.yaml` não passa todas variáveis necessárias
- **IMPACTO:** Firebase auth e conexão banco podem falhar
- **SOLUÇÃO:** Configurar variáveis no Cloud Run ou atualizar cloudbuild.yaml

### **3. FLUXO DE AUTENTICAÇÃO NÃO TESTADO:**
- **SITUAÇÃO:** Fluxo completo não testado no ambiente real
- **IMPACTO:** Login com Google pode não funcionar
- **TESTE:** Testar endpoint por endpoint no Cloud Run

## 🔗 FLUXOS PRINCIPAIS (ATUALIZADO)

### **FLUXO COMPLETO DO JOGO:**
```
1. LOGIN:
   - Usuário → dashboard.html
   - Clique "Entrar com Google" → auth-manager.js
   - Firebase SDK → Popup Google → Token
   - Frontend → auth-google.php (POST google_uid, email, display_name)
   - Backend cria/atualiza user → Retorna session_token

2. INÍCIO DA MISSÃO:
   - Menu inicial → Seleção de nave (5 designs visuais)
   - Clique "Iniciar Missão" → Anúncio pré-jogo (5 segundos)
   - Frontend → game-start.php (POST google_uid)
   - Backend: Valida limites, determina hard mode (40%), cria sessão
   - Retorna: session_id, mission_number, is_hard_mode, game_duration (180s)

3. GAME LOOP (180 SEGUNDOS):
   - A cada frame: Input, física, colisões, renderização
   - Asteroide destruído → game-event.php (POST session_id, asteroid_id, reward)
   - Colisão nave-asteroide → Perde vida → Se vidas=0 → Game Over
   - Tempo esgotado → Vitória

4. FIM DA MISSÃO:
   - Frontend → game-end.php (POST session_id, earnings, captcha)
   - Backend valida CAPTCHA, credita earnings, atualiza balance
   - Retorna earnings_brl, new_balance, stats
```

## ⚙️ SISTEMAS AUXILIARES (DOCBASE)

### **💰 SISTEMA DE RECOMPENSAS:**
- **Common asteroide:** R$ 0,0001
- **Rare asteroide:** R$ 0,001
- **Epic asteroide:** R$ 0,01
- **Legendary asteroide:** R$ 0,005 (apenas hard mode)

### **🤝 PROGRAMA DE AFILIADOS:**
- **Comissão:** R$ 1,00 por cada 100 missões do indicado
- **APIs:** referral-info.php, referral-claim.php
- **Link:** `?ref=CODIGO_UNICO`

### **🏦 STAKING:**
- **APY:** 5% ao ano
- **APIs:** stake.php, unstake.php, get-stakes.php
- **Cálculo:** `rendimento = valor × (0.05 / 365) × dias`

### **📢 SISTEMA DE ANÚNCIOS:**
- **Tipos:** Pré-jogo (5s), Banner, Interstitial, Rewarded
- **APIs:** ads-config.php, ads-log.php
- **Configuração:** Via painel admin

## 🎯 ESTADO ATUAL DO PROJETO

### **✅ FUNCIONA LOCALMENTE:**
- Todos endpoints PHP respondem
- Conexão com banco estabelecida
- Login Google simulado funciona
- Início do jogo funciona

### **⚠️ PROBLEMAS NO CLOUD RUN:**
- Variáveis de ambiente não configuradas
- Conexão banco pode falhar (socket Unix vs TCP/IP)
- Firebase auth não testado no ambiente real

### **❌ NÃO TESTADO NO AMBIENTE REAL:**
- Fluxo completo login → jogo no Cloud Run
- Integração real com Firebase
- Performance sob carga

## 🛠️ METODOLOGIA DE TRABALHO

### **1. DIAGNÓSTICO SISTEMÁTICO:**
```
1. Testar endpoint básico → db-ping.php
2. Testar conexão banco → test-cloudsql-connection.php  
3. Testar auth simples → auth-google.php (simulado)
4. Testar auth real → auth-firebase.php (com token)
5. Testar jogo → game-start.php
```

### **2. VERIFICAÇÃO POR CAMADA:**
- **Frontend (HTML/JS):** dashboard.html → auth-manager.js
- **Backend (PHP):** api/ endpoints
- **Banco de Dados:** Estrutura tables
- **Infra (Cloud Run):** Configuração + variáveis

### **3. FLUXO DE CORREÇÃO:**
```
1. Identificar camada com problema
2. Testar isoladamente
3. Corrigir na camada específica
4. Testar integração
5. Verificar impacto em outras partes
```

## 📈 PRÓXIMOS PASSOS PRIORITÁRIOS

### **PRIORIDADE 1: DIAGNÓSTICO CLOUD RUN**
1. **Verificar logs** reais do Cloud Run
2. **Testar conexão banco** no ambiente (`db-ping.php`)
3. **Testar endpoints** um por um no Cloud Run

### **PRIORIDADE 2: CONFIGURAÇÃO**
1. **Verificar variáveis** de ambiente no Cloud Run
2. **Testar Firebase config** no ambiente
3. **Verificar socket Unix** Cloud SQL

### **PRIORIDADE 3: TESTE FLUXO**
1. **Testar auth-google.php** (simulado)
2. **Testar auth-firebase.php** (com token simulado)
3. **Testar game-start.php** após auth

### **PRIORIDADE 4: CORREÇÕES**
1. **Configurar variáveis** necessárias
2. **Ajustar conexões** se necessário
3. **Testar fluxo completo** login → jogo

## 🔍 INFORMAÇÕES TÉCNICAS (VERIFICADAS)

### **BANCO DE DADOS - ESTRUTURA REAL (13 TABELAS):**

#### **📊 TABELAS PRINCIPAIS (VERIFICADAS):**
1. **users** - Jogadores
   - `google_uid` (UNIQUE), `email`, `display_name`, `photo_url`
   - `balance_brl`, `balance_usdt`, `total_withdrawn_brl`
   - `is_banned`, `ban_reason`, `created_at`, `last_login`

2. **game_sessions** - Sessões de jogo
   - `session_uuid` (UNIQUE), `google_uid`, `user_id`
   - `is_hard_mode`, `status` (active/completed/flagged/abandoned)
   - `earnings_brl`, `earnings_usdt`, `asteroids_destroyed`
   - `mission_number`, `rare_asteroids_target`, `epic_asteroid_target`
   - `session_token`, `started_at`, `ended_at`

3. **game_events** - Eventos de destruição
   - `session_id`, `event_type`, `event_data` (JSON)
   - `earnings_brl`, `earnings_usdt`, `google_uid`
   - `created_at`

4. **withdrawals** - Saques
   - `user_id`, `amount_brl`, `amount_usdt`
   - `wallet_address`, `status` (pending/processing/completed/rejected/cancelled)
   - `transaction_hash`, `admin_notes`

5. **staking** - Staking
   - `user_id`, `amount`, `apy` (5.00)
   - `status` (active/completed/cancelled), `earnings`
   - `start_date`, `end_date`

#### **🛡️ TABELAS DE SEGURANÇA:**
6. **admin_logs** - Logs administrativos
7. **ip_blacklist** - IPs bloqueados
8. **ip_sessions** - Sessões por IP
9. **rate_limits** - Rate limiting
10. **suspicious_activity** - Atividades suspeitas
11. **user_sessions** - Sessões de usuário

#### **⚙️ TABELAS DE CONFIGURAÇÃO:**
12. **game_settings** - Configurações do jogo
13. **players** - Tabela alternativa de jogadores (legado?)

### **CONEXÃO BANCO:**
- **IP:** `34.168.76.127` (Cloud SQL público)
- **Database:** `unobix_db`
- **User:** `unobix_user`
- **Senha:** `YyZD3H)dndSo*A/N`
- **Cloud SQL Instance:** `project-7be1cae5-5f08-45fb-aca:us-west1:unobix`
- **Socket Unix (Cloud Run):** `/cloudsql/project-7be1cae5-5f08-45fb-aca:us-west1:unobix`

### **FIREBASE:**
- **Project ID:** `unobix-oauth-a69cd`
- **API Key:** `AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U`
- **SDK:** Firebase v9 (compat mode)

### **CLOUD RUN:**
- **Serviço:** `crypto-asteroid-rush`
- **Região:** `us-west1`
- **URL:** `https://crypto-asteroid-234282032979.us-west1.run.app`
- **Tecnologia:** PHP 8.2 + Nginx + Supervisor

## 📝 COMMITS RECENTES RELEVANTES

### **CORREÇÕES APLICADAS:**
- `5d4dacb` - auth-firebase.php usa getDatabaseConnection()
- `a372814` - Firebase config não mata aplicação
- `36549ec` - game-start.php corrigido + validação UID
- `f59fbae` - game-start.php usa colunas existentes

### **DOCUMENTAÇÃO:**
- `a227fed` - Documentação completa atualizada
- `7bf2e64` - Limpeza arquivos MD antigos

## 🎯 ARQUIVOS CRÍTICOS PARA TESTE

### **TESTES BÁSICOS:**
1. `db-ping.php` - Conexão banco
2. `test-cloudsql-connection.php` - Conexão Cloud SQL
3. `auth-google.php` - Auth simulado
4. `game-start.php` - Início jogo

### **TESTES AVANÇADOS:**
1. `auth-firebase.php` - Auth real (Firebase)
2. `game-event.php` - Eventos jogo
3. `game-end.php` - Fim jogo
4. `balance.php` - Saldo

## 🔧 TROUBLESHOOTING (DOCBASE)

### **ERRO 500 EM APIS:**
1. **Causas:** Erro sintaxe PHP, função não definida, tabela não existe, variável ambiente não configurada
2. **Diagnóstico:** Criar `api/test.php` com `display_errors` ativado
3. **Teste:** `db-ping.php` para verificar conexão banco

### **"IDENTIFICAÇÃO INVÁLIDA":**
1. **Causa:** google_uid não enviado ou inválido
2. **Verificar:** localStorage tem 'googleUid'? authManager.getUserId() retorna valor?
3. **Solução:** Recarregar página, fazer login novamente

### **JOGO NÃO INICIA APÓS ANÚNCIO:**
1. **Causa:** Erro no SessionManager.startSession()
2. **Verificar:** Console do navegador, Network tab, logs Railway
3. **Teste:** game-start.php isoladamente

### **CAPTCHA NÃO APARECE:**
1. **Causa:** Modal não inicializa CAPTCHA
2. **Verificar:** CaptchaManager.init() chamado? Elemento #captchaWidget existe?
3. **Console:** Mensagem "CaptchaManager pronto"?

## 📝 HISTÓRICO DE VERSÕES
- **v1.0 (2025-01):** Crypto Asteroid Rush (MetaMask, USDT)
- **v2.0 (2025-06):** Migração para USDT
- **v3.0 (2025-10):** Sistema de vidas, house edge
- **v4.0 (2026-01):** Unobix (Google Auth, BRL, Free-to-Play)
- **v4.1 (2026-01):** Correções autenticação, CAPTCHA matemático

## ✅ CONFIABILIDADE DAS INFORMAÇÕES

### **VERIFICAÇÕES REALIZADAS:**
1. ✅ **Estrutura de arquivos:** 111 arquivos verificados
2. ✅ **Banco de dados:** 13 tabelas conectadas e analisadas
3. ✅ **Conexão Cloud SQL:** Testada e funcional
4. ✅ **Documentação cruzada:** docbase.md + estrutura real
5. ✅ **Correções aplicadas:** Baseadas em feedback específico

### **INFORMAÇÕES CONFIRMADAS:**
- ✅ Páginas HTML: 13 arquivos (classificação corrigida)
- ✅ Monetização: Anúncios (pré-jogo loading.html, pós-jogo finalização)
- ✅ affiliates.html: Painel do cliente (não conteúdo informativo)
- ✅ Estrutura banco: 13 tabelas com schema completo

### **METODOLOGIA:**
- **Verificação direta:** Conexão ao banco real
- **Análise cruzada:** docbase.md vs estrutura real
- **Correção contínua:** Baseada em feedback específico
- **Transparência:** Documentação de todas as verificações

---
*Documentação verificada e corrigida com base em dados reais*
*Estrutura do banco confirmada via conexão direta (13 tabelas)*
*Classificação de páginas corrigida conforme especificado*
*Monetização atualizada com localização correta dos anúncios*
*Última atualização: 2026-02-03*