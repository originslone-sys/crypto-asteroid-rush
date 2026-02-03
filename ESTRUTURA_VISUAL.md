# 🗺️ ESTRUTURA VISUAL COMPLETA DO PROJETO

## 📊 ESTATÍSTICAS
- **Total:** 160 arquivos
- **PHP:** 46 arquivos
- **HTML:** 15 arquivos  
- **JS:** 19 arquivos
- **CSS:** 3 arquivos
- **Imagens:** 4 arquivos
- **Sons:** 4 arquivos

## 📁 ESTRUTURA DE PASTAS
```
crypto-asteroid-rush/
├── 📁 admin/                    # Painel administrativo
│   ├── 📁 css/                 # Estilos admin
│   ├── 📁 includes/            # Componentes reutilizáveis
│   ├── 📁 js/                  # JavaScript admin
│   └── 📁 pages/               # Páginas do admin
├── 📁 api/                     # Backend PHP (46 arquivos)
│   └── 📁 logs/                # Logs da API
├── 📁 css/                     # Estilos (3 arquivos)
├── 📁 docker/                  # Configuração Docker
├── 📁 img/                     # Imagens (4 arquivos)
├── 📁 js/                      # JavaScript (19 arquivos)
├── 📁 sounds/                  # Sons do jogo (4 arquivos)
└── 📁 thumbnails/              # Miniaturas (2 arquivos)
```

## 📄 ARQUIVOS PHP DA API (PRINCIPAIS)

### 🔐 AUTENTICAÇÃO
- `auth-google.php`       # Login Google (principal)
- `auth-firebase.php`     # Verificação Firebase
- `login.php`            # Login Wallet (legado)

### 🎮 JOGO
- `game-start.php`       # Iniciar sessão
- `game-event.php`       # Eventos do jogo
- `game-end.php`         # Finalizar jogo

### 💰 FINANCEIRO
- `balance.php`          # Saldo
- `stake.php`            # Staking
- `withdraw.php`         # Saques
- `transactions.php`     # Transações

### 🔗 REFERRAL
- `referral-info.php`    # Info referrals
- `referral-claim.php`   # Claim rewards
- `referral-register.php` # Registrar referral

### ⚙️ CONFIGURAÇÃO
- `config.php`           # Configuração completa
- `config-cloudrun.php`  # Config Cloud Run
- `rate-limiter.php`     # Rate limiting
- `maintenance.php`      # Manutenção

### 🛡️ SEGURANÇA
- `admin-security.php`   # Segurança admin
- `verify-captcha.php`   # CAPTCHA
- `report-suspicious.php` # Reportar atividades

## 🌐 PÁGINAS HTML (FRONTEND)

### 🏠 PRINCIPAIS
- `index.html`           # Página inicial
- `dashboard.html`       # Painel do usuário (LOGIN)
- `game.html`            # Jogo principal
- `wallet.html`          # Carteira
- `staking.html`         # Staking

### 📚 INFORMATIVAS
- `how-to-play.html`     # Como jogar
- `rules.html`           # Regras
- `faq.html`             # FAQ
- `roadmap.html`         # Roadmap
- `economy.html`         # Economia

### 📋 LEGAL
- `terms.html`           # Termos
- `privacy.html`         # Privacidade
- `affiliates.html`      # Afiliados

## 📜 ARQUIVOS JAVASCRIPT

### 🎮 JOGO
- `game-main.js`         # Principal
- `game-engine.js`       # Motor do jogo
- `game-renderer.js`     # Renderização
- `game-ui.js`           # Interface
- `game-session.js`      # Sessão
- `game-ships.js`        # Naves

### 🔐 AUTENTICAÇÃO
- `auth-manager.js`      # Gerenciador auth
- `firebase-config.js`   # Config Firebase

### 💰 FINANCEIRO
- `game-wallet.js`       # Carteira
- `game-session-manager.js` # Gerenciador sessão

### 🎵 MULTIMÍDIA
- `game-audio.js`        # Áudio
- `ship-renderer.js`     # Renderização naves

## 🎨 ARQUIVOS CSS
- `main.css`             # Estilos gerais
- `game.css`             # Estilos do jogo
- `dashboard.css`        # Estilos dashboard

## 🐳 DOCKER & DEPLOY
- `Dockerfile`           # Imagem Docker
- `cloudbuild.yaml`      # Build Cloud Run
- `docker/nginx.conf`    # Config Nginx
- `docker/entrypoint.sh` # Script inicialização

## 🔐 ARQUIVOS DE CONFIGURAÇÃO
- `.env.example`         # Template variáveis
- `.env.local`           # Config local
- `.htaccess`            # Config Apache

## 📊 ARQUIVOS DE ANÁLISE/DOC
- `ANALISE_*.md`         # Análises diversas
- `CHECKLIST.md`         # Checklist
- `PROGRESS.md`          # Progresso
- `DOCUMENTATION.md`     # Documentação

## 🖼️ MÍDIA
- `img/logo.png`         # Logo
- `img/logo-unobix.png`  # Logo Unobix
- `sounds/*.mp3`         # Sons do jogo
- `thumbnails/*.jpg`     # Miniaturas gameplay

## 🔗 FLUXO PRINCIPAL

### LOGIN COM GOOGLE:
```
1. dashboard.html → auth-manager.js
2. Firebase SDK → Popup Google
3. Token Firebase → auth-firebase.php
4. Verifica token → Cria/atualiza user
5. Retorna session_token → Frontend
6. game-start.php → Inicia sessão
```

### JOGO:
```
1. game.html → game-main.js
2. game-start.php → Cria sessão
3. game-event.php → Eventos jogo
4. game-end.php → Finaliza + salva
```

## 🎯 ARQUIVOS CRÍTICOS (PROBLEMAS ATUAIS)

### 🔴 PRIORIDADE 1 (NÃO FUNCIONAM):
1. `auth-firebase.php`    # Conexão banco Cloud Run
2. `config.php`           # Configuração variáveis
3. `game-start.php`       # Início sessão

### 🟡 PRIORIDADE 2 (VERIFICAR):
1. `auth-google.php`      # Login alternativo
2. `balance.php`          # Saldo
3. `stake.php`            # Staking

### 🟢 PRIORIDADE 3 (FUNCIONAM):
1. Frontend HTML/JS       # Interface
2. Docker/Cloud Run       # Deploy
3. Banco de dados         # Estrutura

## 📈 PRÓXIMOS PASSOS

1. **Diagnóstico completo** dos endpoints falhando
2. **Verificar logs Cloud Run** reais
3. **Testar conexão banco** no Cloud Run
4. **Corrigir configuração** variáveis ambiente
5. **Testar fluxo completo** login → jogo

---
*Última atualização: 2026-02-03*
*Total arquivos mapeados: 160*