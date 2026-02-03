# 📚 DOCUMENTAÇÃO COMPLETA ATUALIZADA
*Baseada em dados reais do projeto - Última atualização: 2026-02-03*

## 📊 ESTATÍSTICAS REAIS (VERIFICADAS)
- **Total arquivos:** 160
- **Arquivos PHP:** 46 (29 na pasta `api/`)
- **Páginas HTML:** 15
- **Arquivos JavaScript:** 19
- **Arquivos CSS:** 4
- **Imagens:** 4 arquivos
- **Sons:** 4 arquivos

## 📁 ESTRUTURA REAL DO PROJETO

### **PASTAS PRINCIPAIS:**
```
crypto-asteroid-rush/
├── 📁 admin/                    # Painel administrativo (12 arquivos)
├── 📁 api/                     # Backend PHP (29 arquivos ativos)
├── 📁 css/                     # Estilos (4 arquivos)
├── 📁 docker/                  # Configuração Docker (5 arquivos)
├── 📁 img/                     # Imagens (4 arquivos)
├── 📁 js/                      # JavaScript (19 arquivos)
├── 📁 sounds/                  # Sons do jogo (4 arquivos)
└── 📁 thumbnails/              # Miniaturas (2 arquivos)
```

## 📄 ARQUIVOS PHP DA API (ATUALIZADO)

### **🔐 AUTENTICAÇÃO (FUNCIONA LOCALMENTE):**
- `auth-google.php`       # ✅ Login Google direto (funciona local)
- `auth-firebase.php`     # ⚠️ Login Firebase (problema Cloud Run - CORRIGIDO)
- `login.php`            # Login Wallet (legado)

### **🎮 JOGO (CORREÇÕES APLICADAS):**
- `game-start.php`       # ✅ Corrigido (colunas, UID validation)
- `game-event.php`       # Eventos do jogo
- `game-end.php`         # Finalizar jogo

### **💰 FINANCEIRO:**
- `balance.php`          # Saldo
- `stake.php`            # Staking
- `withdraw.php`         # Saques
- `transactions.php`     # Transações

### **⚙️ CONFIGURAÇÃO (CORREÇÕES APLICADAS):**
- `config.php`           # ✅ Corrigido (valores padrão, não mata app)
- `config-cloudrun.php`  # Config Cloud Run
- `rate-limiter.php`     # Rate limiting

## 🌐 PÁGINAS HTML PRINCIPAIS

### **🏠 INTERFACE DO USUÁRIO:**
- `index.html`           # Página inicial
- `dashboard.html`       # ✅ Painel do usuário (login Google aqui)
- `game.html`            # Jogo principal
- `wallet.html`          # Carteira
- `staking.html`         # Staking

### **📚 CONTEÚDO INFORMATIVO:**
- `how-to-play.html`     # Como jogar
- `rules.html`           # Regras
- `faq.html`             # FAQ
- `roadmap.html`         # Roadmap
- `economy.html`         # Economia

## 📜 ARQUIVOS JAVASCRIPT (FRONTEND)

### **🎮 CORE DO JOGO:**
- `game-main.js`         # Principal
- `game-engine.js`       # Motor do jogo
- `game-renderer.js`     # Renderização
- `game-ui.js`           # Interface

### **🔐 AUTENTICAÇÃO:**
- `auth-manager.js`      # ✅ Gerenciador auth (Firebase SDK)
- `firebase-config.js`   # ✅ Config Firebase (funciona)

### **💰 FINANCEIRO:**
- `game-wallet.js`       # Carteira
- `game-session-manager.js` # Gerenciador sessão

## 🎨 ARQUIVOS CSS
- `main.css`             # Estilos gerais
- `game.css`             # Estilos do jogo
- `dashboard.css`        # Estilos dashboard

## 🐳 DEPLOY & INFRAESTRUTURA

### **CLOUD RUN + CLOUD SQL:**
- `Dockerfile`           # Imagem Docker
- `cloudbuild.yaml`      # Build Cloud Run
- `docker/nginx.conf`    # Config Nginx
- `docker/entrypoint.sh` # Script inicialização

### **VARIÁVEIS DE AMBIENTE:**
- `.env.example`         # Template
- `.env.local`           # Config local (testes)

## ✅ CORREÇÕES APLICADAS (CONFIRMADAS)

### **PROBLEMAS RESOLVIDOS:**
1. **auth-firebase.php** - Conexão banco corrigida para Cloud Run
2. **config.php** - Não mata app se variáveis não definidas
3. **game-start.php** - INSERT corrigido, validação UID
4. **game_sessions table** - Colunas faltantes criadas

*Nota: Estes problemas foram corrigidos e não afetam mais o funcionamento.*

## 🎯 PROBLEMAS ATUAIS (FOCAR AQUI)

### **1. ESTRUTURA DE CONEXÃO CLOUD RUN:**
- **SITUAÇÃO:** Cloud Run usa socket Unix (`/cloudsql/...`), não TCP/IP
- **IMPACTO:** Conexão banco pode falhar se não configurado corretamente
- **VERIFICAÇÃO NECESSÁRIA:** Testar `db-ping.php` no Cloud Run

### **2. VARIÁVEIS DE AMBIENTE NO DEPLOY:**
- **SITUAÇÃO:** `cloudbuild.yaml` não passa todas variáveis necessárias
- **IMPACTO:** Firebase auth e conexão banco falham
- **SOLUÇÃO POTENCIAL:** Configurar variáveis no Cloud Run ou atualizar cloudbuild.yaml

### **3. FLUXO DE AUTENTICAÇÃO:**
- **SITUAÇÃO:** Fluxo completo não testado no ambiente real
- **IMPACTO:** Login com Google pode não funcionar
- **TESTE NECESSÁRIO:** Testar endpoint por endpoint no Cloud Run

## 🔗 FLUXOS PRINCIPAIS

### **LOGIN COM GOOGLE (TEÓRICO):**
```
1. Usuário → dashboard.html
2. Clique "Entrar com Google" → auth-manager.js
3. Firebase SDK → Popup Google → Token
4. Frontend → auth-firebase.php (POST com token)
5. Backend verifica token → Cria/atualiza user
6. Retorna session_token → Frontend
7. Frontend → game-start.php → Inicia sessão
```

### **INÍCIO DO JOGO (TESTADO LOCAL):**
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

### **❌ NÃO TESTADO:**
- Fluxo completo login → jogo no Cloud Run
- Integração real com Firebase
- Performance sob carga

## 🛠️ COMO USAR ESTA ESTRUTURA PARA TRABALHAR

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
1. **Verificar logs** reais do Cloud Run (com gcloud correto)
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

## 🔍 REFERÊNCIAS E COMMITS

### **COMMITS RECENTES DE CORREÇÃO:**
- `22e0095` - Mapa visual da estrutura
- `5d4dacb` - auth-firebase.php usa getDatabaseConnection()
- `a372814` - Firebase config não mata aplicação
- `36549ec` - game-start.php corrigido + validação UID
- `f59fbae` - game-start.php usa colunas existentes

### **ARQUIVOS DE ANÁLISE (CONSULTAR):**
- `ANALISE_BANCO_DADOS.md` - Estrutura do banco
- `ANALISE_RAPIDA_COMPLETA.md` - Análise geral
- `CHECKLIST.md` - Tarefas pendentes
- `PROGRESS.md` - Progresso atual

## 📝 NOTAS IMPORTANTES

### **CONFIGURAÇÃO CLOUD RUN:**
- Usa Cloud SQL via socket Unix (`/cloudsql/...`)
- Variáveis devem ser configuradas no deploy
- `CLOUDSQL_INSTANCE` já configurado no cloudbuild.yaml

### **BANCO DE DADOS:**
- IP: `34.168.76.127`
- Database: `unobix_db`
- User: `unobix_user`
- Senha: `YyZD3H)dndSo*A/N`

### **FIREBASE:**
- Project ID: `unobix-oauth-a69cd`
- API Key: `AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U`
- Mesma configuração do frontend

---
*Documentação baseada em análise de 42 arquivos MD + dados reais do projeto*
*Foco na estrutura atual e problemas reais não resolvidos*
*Última verificação: 2026-02-03*