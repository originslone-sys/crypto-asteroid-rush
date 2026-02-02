# 📚 DOCUMENTAÇÃO COMPLETA - Crypto Asteroid Rush

## 📊 Visão Geral do Projeto
- **Nome**: Crypto Asteroid Rush
- **Tipo**: Jogo Web com Economia Cripto
- **Tecnologias**: PHP, HTML, CSS, JavaScript, MySQL
- **Deploy**: Docker + Railway
- **Status**: Produção

## 🏗️ Estrutura do Projeto

### 📁 Raiz do Projeto
```
crypto-asteroid-rush/
├── 📄 index.html              # Página inicial
├── 📄 game.html              # Jogo principal
├── 📄 play.html              # Página de play
├── 📄 wallet.html            # Carteira cripto
├── 📄 staking.html           # Staking
├── 📄 dashboard.html         # Dashboard do usuário
├── 📄 login-direct.html      # Login direto
├── 📄 loading.html           # Tela de loading
├── 📄 test.html              # Página de testes
├── 📄 test-firebase.html     # Teste Firebase
├── 📄 debug.html             # Debug
├── 📄 how-to-play.html       # Tutorial
├── 📄 gameplay.html          # Mecânicas do jogo
├── 📄 economy.html           # Economia
├── 📄 roadmap.html           # Roadmap
├── 📄 faq.html               # FAQ
├── 📄 rules.html             # Regras
├── 📄 affiliates.html        # Afiliados
├── 📄 privacy.html           # Privacidade
├── 📄 terms.html             # Termos
├── 📄 cod.md                 # Código de referência
├── 📄 README.md              # Descrição mínima
├── 📄 config.php             # Ponte para config principal
├── 📄 adminer.php            # Adminer (507KB!)
├── 📄 Dockerfile             # Configuração Docker
├── 📄 railway.json           # Config Railway
├── 📄 .htaccess              # Config Apache
├── 📄 htaccess               # Backup htaccess
├── 📄 fix_game_events.sql    # Script SQL
├── 📄 favicon.ico            # Favicon
├── 📄 DOCUMENTATION.md       # Esta documentação
```

### 📁 API (Backend PHP)
```
api/
├── 📄 config.php             # Configuração principal (DB, segurança, jogo)
├── 📄 auth.php               # Autenticação Google/Firebase
├── 📄 game.php               # Endpoints do jogo
├── 📄 wallet.php             # Operações de carteira
├── 📄 staking.php            # Staking
├── 📄 withdraw.php           # Saques
├── 📄 admin.php              # Endpoints admin
├── 📄 stats.php              # Estatísticas
├── 📄 test.php               # Testes
├── 📄 cron.php               # Tarefas agendadas
├── 📄 logs/                  # Logs do sistema
```

### 📁 Admin (Painel Administrativo)
```
admin/
├── 📄 index.php              # Login admin
├── 📄 dashboard.php          # Dashboard admin
├── 📄 players.php            # Gerenciar jogadores
├── 📄 transactions.php       # Transações
├── 📄 withdrawals.php        # Saques
├── 📄 settings.php           # Configurações
├── 📄 security.php           # Segurança
├── 📄 logs.php               # Logs
├── 📄 css/                   # Estilos admin
├── 📄 js/                    # Scripts admin
├── 📄 includes/              # Includes PHP
├── 📄 pages/                 # Páginas auxiliares
```

### 📁 Assets Frontend
```
css/                          # Estilos principais
├── 📄 style.css              # Estilo principal
├── 📄 game.css               # Estilos do jogo
├── 📄 wallet.css             # Estilos carteira
├── 📄 admin.css              # Estilos admin

js/                           # JavaScript
├── 📄 game.js                # Lógica do jogo
├── 📄 auth.js                # Autenticação
├── 📄 wallet.js              # Carteira
├── 📄 staking.js             # Staking
├── 📄 utils.js               # Utilitários
├── 📄 firebase.js            # Firebase config
├── 📄 hcaptcha.js            # hCaptcha
├── 📄 admin.js               # Admin scripts

img/                          # Imagens
├── 🖼️  asteroid.png          # Asteroides
├── 🖼️  ship.png              # Nave
├── 🖼️  background.jpg        # Fundo
├── 🖼️  icons/                # Ícones

sounds/                       # Sons do jogo
├── 🔊 explosion.mp3          # Explosão
├── 🔊 laser.mp3              # Laser
├── 🔊 background.mp3         # Música
├── 🔊 coin.mp3               # Moeda

thumbnails/                   # Thumbnails
```

### 📁 Docker
```
docker/
├── 📄 nginx.conf             # Config Nginx
├── 📄 php.ini                # Config PHP
├── 📄 Dockerfile             # Docker específico
├── 📄 entrypoint.sh          # Script de entrada
```

## 🗄️ Banco de Dados (MySQL)

### 📊 Tabelas Principais
```
players                       # Jogadores (3 registros)
├── id (int)                  # ID único
├── google_uid (varchar)      # Google UID
├── email (varchar)           # Email
├── display_name (varchar)    # Nome exibido
├── photo_url (text)          # Foto
├── wallet_address (varchar)  # Carteira Ethereum
├── balance_brl (decimal)     # Saldo BRL
├── balance_usdt (decimal)    # Saldo USDT
├── total_played (int)        # Total jogado
├── total_earned_brl (decimal)# Total ganho BRL
├── staked_balance_brl (decimal)# Staking BRL
├── is_banned (tinyint)       # Banido?
├── created_at (timestamp)    # Data criação

game_sessions                 # Sessões de jogo (2 registros)
├── id (int)
├── player_id (int)
├── session_token (varchar)
├── start_time (datetime)
├── end_time (datetime)
├── asteroids_destroyed (int)
├── earnings_brl (decimal)
├── status (varchar)

transactions                  # Transações (1 registro)
├── id (int)
├── player_id (int)
├── type (varchar)
├── amount_brl (decimal)
├── description (text)
├── created_at (timestamp)

withdrawals                   # Saques (0 registros)
├── id (int)
├── player_id (int)
├── amount_brl (decimal)
├── method (varchar)
├── status (varchar)
├── created_at (timestamp)

stakes                        # Staking
├── id (int)
├── player_id (int)
├── amount_brl (decimal)
├── apy (decimal)
├── start_date (datetime)
├── end_date (datetime)

system_config                 # Configurações do sistema
├── config_key (varchar)
├── config_value (text)
├── is_public (tinyint)

security_logs                 # Logs de segurança
ip_blacklist                  # IPs bloqueados
suspicious_activity           # Atividade suspeita
rate_limits                   # Rate limiting
user_sessions                 # Sessões de usuário
```

## 🔧 Tecnologias e Dependências

### Backend (PHP)
- **PHP 7.4+** (com PDO MySQL)
- **Firebase Authentication** (Google OAuth)
- **hCaptcha** (proteção contra bots)
- **MySQL 8.0+** (banco de dados)

### Frontend
- **HTML5** + **CSS3** + **JavaScript ES6**
- **Canvas API** (jogo)
- **Firebase Web SDK** (auth)
- **hCaptcha Widget** (frontend)
- **Chart.js** (gráficos no admin)

### Infraestrutura
- **Docker** (containerização)
- **Nginx** (web server)
- **Railway** (deploy/hosting)
- **MySQL Railway** (banco hospedado)

### Segurança
- **PDO Prepared Statements** (SQL injection)
- **Rate Limiting** (10 eventos/segundo)
- **IP Blacklisting**
- **Input Validation**
- **Secure Session Tokens**

## 🎮 Mecânicas do Jogo

### Fluxo Principal
1. **Login** → Google Auth ou Wallet
2. **Jogar** → 3 minutos de missão
3. **Destruir Asteroides** → 4 tipos (Common, Rare, Epic, Legendary)
4. **Receber Recompensa** → BRL baseado no tipo
5. **Acumular** → Staking ou Saque

### Tipos de Asteroides
| Tipo | Spawn Rate | Recompensa BRL | Descrição |
|------|------------|----------------|-----------|
| Common | 95% | R$ 0,00 | Sem recompensa |
| Rare | 3% | R$ 0,001 | Pequena recompensa |
| Epic | 1.5% | R$ 0,005 | Recompensa média |
| Legendary | 0.5% | R$ 0,02 | Grande recompensa |

### Hard Mode (40% das missões)
- **Velocidade**: +40%
- **Spawn Rate**: -30%
- **Ativado aleatoriamente**

### Sistema Econômico
- **Staking APY**: 5% ao ano
- **Saque mínimo**: R$ 1,00
- **Saque máximo**: R$ 10.000,00
- **Limite semanal**: 1 saque
- **Métodos**: PIX, PayPal, USDT BEP-20

## 🔐 Segurança e Anti-Fraude

### Proteções Implementadas
1. **Rate Limiting**: 10 eventos/segundo por IP
2. **hCaptcha**: Em vitórias suspeitas
3. **Alertas Automáticos**:
   - > R$ 0,30 por missão → Alerta
   - > R$ 0,50 por missão → Suspeito
   - > R$ 1,00 por missão → Bloqueio
4. **Auto-ban**: 5 alertas = ban automático
5. **IP Tracking**: Sessões por IP

### Validações
- **Google UID**: 10-128 caracteres (a-z, A-Z, 0-9, _, -)
- **Wallet Ethereum**: `0x` + 40 hex chars
- **Input Sanitization**: Todos os endpoints
- **Session Tokens**: SHA-256 com timestamp

## 🚀 Deploy e Infraestrutura

### Railway Config
```json
{
  "build": {
    "builder": "DOCKERFILE"
  },
  "deploy": {
    "startCommand": "docker-entrypoint.sh",
    "healthcheckPath": "/health"
  }
}
```

### Docker Setup
- **Nginx**: Web server
- **PHP-FPM**: Processamento PHP
- **MySQL Client**: Conexão com DB externo
- **Cron**: Tarefas agendadas

### Variáveis de Ambiente
```
MYSQL_PUBLIC_URL          # URL completa do DB
MYSQLHOST, MYSQLPORT      # Host/Port alternativos
MYSQLDATABASE, MYSQLUSER  # Database/User
MYSQLPASSWORD             # Senha
FIREBASE_PROJECT_ID       # Firebase
FIREBASE_API_KEY          # Firebase API Key
GAME_SECRET_KEY           # Chave secreta do jogo
ADMIN_PASSWORD            # Senha admin
HCAPTCHA_SITE_KEY         # hCaptcha
HCAPTCHA_SECRET_KEY       # hCaptcha secreto
```

## 📈 Estatísticas Atuais
- **Jogadores**: 3 registrados
- **Sessões**: 2 jogadas
- **Transações**: 1 registrada
- **Saques**: 0 realizados
- **Staking**: Sistema implementado
- **Banimentos**: 0 jogadores

## 📊 Análises Realizadas (2026-02-01)

### 🎨 Frontend HTML Analisados (9 arquivos)
1. **`index.html`** (11.7KB) - Página inicial com hero section e CTA
2. **`play.html`** (33.9KB) - Página de entrada/login com múltiplas opções
3. **`game.html`** (16.3KB) - Jogo principal (canvas ausente identificado)
4. **`wallet.html`** (26.9KB) - Interface da carteira com saldo e transações
5. **`staking.html`** (21.9KB) - Sistema de staking com APY 5%/12%
6. **`dashboard.html`** (12.3KB) - Dashboard do usuário com stats
7. **`how-to-play.html`** (18.6KB) - Tutorial completo para novos jogadores
8. **`gameplay.html`** (23.9KB) - Guia avançado de mecânicas e estratégias
9. **`affiliates.html`** (18.2KB) - Programa de afiliados/referral

### 🔧 API Endpoints Analisados (16 arquivos)
1. **`auth-google.php`** - Autenticação Google OAuth
2. **`debug.php`** - Sistema de diagnóstico
3. **`events.php`** - Eventos do jogo
4. **`game-event.php`** - Eventos individuais
5. **`get-stakes.php`** - Consulta de stakes
6. **`login.php`** - Sistema de login
7. **`stake.php`** - Aplicar staking
8. **`transactions.php`** - Transações
9. **`unstake.php`** - Remover staking
10. **`update-stakes.php`** - Atualizar stakes
11. **`wallet-info.php`** - Informações da carteira
12. **`withdraw.php`** - Sistema de saques
13. **`maintenance.php`** - Manutenção diária do banco
14. **`rate-limiter.php`** - Sistema completo de rate limiting
15. **`referral-helper.php`** - Funções helper de referral
16. **`referral-register.php`** - Registrar indicações

### 📝 Documentação Gerada
- **Total de análises**: 17 arquivos (14 anteriores + 3 novos HTML)
- **Tamanho total**: ~200KB+ de documentação técnica
- **Análises detalhadas**: Cada arquivo com problemas, soluções e recomendações

### ⚠️ Problemas Críticos Identificados
1. **Canvas ausente** em `game.html`
2. **Senhas hardcoded** em `config.php`
3. **Tabelas de referral não definidas** (`referrals`, `referral_codes`)
4. **Stored Procedure `sp_cleanup_old_data` não existe**
5. **XSS vulnerabilities** (innerHTML sem sanitização)
6. **Inconsistência APY**: 12% (marketing) vs 5% (real)
7. **Sistema de upgrades não implementado** (apenas UI)
8. **CSS duplicado/conflitante** em `gameplay.html`

### ✅ Pontos Fortes do Frontend
1. **Design consistente** com tema cyberpunk
2. **UX otimizado para conversão** (CTAs claros)
3. **Conteúdo educacional rico** (tutoriais completos)
4. **Mobile responsive** (grids flexíveis)
5. **Monetização clara** (valores explícitos em R$)

## 🔄 Fluxos de Trabalho

### Novo Jogador
1. Login com Google → `auth.php`
2. Criar registro → `players` table
3. Gerar session token → `game.php`
4. Iniciar jogo → `game.html`

### Jogar Missão
1. Iniciar sessão → `game.php?action=start`
2. Jogar 3 min → `game.js`
3. Enviar resultados → `game.php?action=end`
4. Calcular recompensa → baseado em asteroids
5. Atualizar saldo → `players.balance_brl`

### Staking
1. Aplicar valor → `staking.php?action=stake`
2. Calcular APY → 5% ao ano
3. Atualizar diariamente → `cron.php`
4. Resgatar → `staking.php?action=unstake`

### Saque
1. Solicitar → `withdraw.php?action=request`
2. Validar mínimo/máximo → R$ 1-10.000
3. Processar 20-25 mês → `cron.php`
4. Atualizar status → `withdrawals.status`

## 🛠️ Manutenção e Monitoramento

### Tarefas Agendadas (Cron)
- **Diário**: Atualizar staking APY
- **Diário**: Limpar logs antigos
- **Mensal**: Processar saques (dias 20-25)
- **Contínuo**: Monitorar segurança

### Logs Importantes
- `security_logs`: Tentativas de fraude
- `game_events`: Eventos do jogo
- `captcha_log`: Uso de CAPTCHA
- `rate_limits`: Rate limiting hits

### Monitoramento
- **Saldo suspeito**: > R$ 0,30/missão
- **IP suspeito**: Muitas sessões
- **Player suspeito**: Muitos alertas
- **System health**: DB connection, API response

---

*Documentação criada em: 2026-02-01 01:13 UTC*
*Última atualização: 2026-02-01 01:13 UTC*
*Manter atualizada conforme mudanças no projeto*