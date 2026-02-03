# 📚 DOCUMENTAÇÃO ÚNICA DO PROJETO
*Baseada em revisão completa - Última atualização: 2026-02-03*

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
- `affiliates.html`      # Programa de afiliados

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
- `game-config.js`       # Configuração do jogo
- `game-start.js`        # Início do jogo
- `main.js`              # Script principal global

### **📢 ANÚNCIOS:**
- `ads-manager.js`       # Gerenciador de anúncios

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

## 🔗 FLUXOS PRINCIPAIS

### **LOGIN COM GOOGLE:**
```
1. Usuário → dashboard.html
2. Clique "Entrar com Google" → auth-manager.js
3. Firebase SDK → Popup Google → Token
4. Frontend → auth-firebase.php (POST com token)
5. Backend verifica token → Cria/atualiza user
6. Retorna session_token → Frontend
7. Frontend → game-start.php → Inicia sessão
```

### **INÍCIO DO JOGO:**
```
1. Frontend → game-start.php (POST google_uid)
2. Backend busca user → Cria game_session
3. Retorna session_id, player_id, mission_number
4. Frontend inicia jogo com dados
```

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

## 🔍 INFORMAÇÕES TÉCNICAS

### **BANCO DE DADOS:**
- **IP:** `34.168.76.127`
- **Database:** `unobix_db`
- **User:** `unobix_user`
- **Senha:** `YyZD3H)dndSo*A/N`
- **Cloud SQL Instance:** `project-7be1cae5-5f08-45fb-aca:us-west1:unobix`

### **FIREBASE:**
- **Project ID:** `unobix-oauth-a69cd`
- **API Key:** `AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U`
- **Mesma configuração do frontend**

### **CLOUD RUN:**
- **Serviço:** `crypto-asteroid-rush`
- **Região:** `us-west1`
- **URL:** `https://crypto-asteroid-234282032979.us-west1.run.app`

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

---
*Documentação única baseada em revisão completa do projeto*
*Informações verificadas contra estrutura real (111 arquivos)*
*Foco em problemas atuais e metodologia de trabalho*
*Última atualização: 2026-02-03*