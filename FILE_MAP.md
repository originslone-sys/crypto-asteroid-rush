# 🗺️ MAPEAMENTO COMPLETO DE ARQUIVOS

## 📊 Estatísticas Gerais
- **Total de arquivos**: 144
- **PHP**: 44 arquivos
- **HTML**: 20 arquivos  
- **JavaScript**: 20 arquivos
- **CSS**: 4 arquivos
- **Imagens**: 4 arquivos
- **Sons**: 4 arquivos
- **Outros**: 48 arquivos

## 📁 ESTRUTURA COMPLETA

### 🔧 RAÍZ DO PROJETO (37 arquivos)

#### 🎮 Páginas Principais do Jogo
1. `index.html` (11.7KB) - Página inicial
2. `game.html` (16.3KB) - Jogo principal (Canvas)
3. `play.html` (33.9KB) - Página de play com auth
4. `wallet.html` (26.9KB) - Carteira cripto
5. `staking.html` (21.9KB) - Staking
6. `dashboard.html` (12.3KB) - Dashboard usuário
7. `login-direct.html` (8.5KB) - Login direto
8. `loading.html` (23.6KB) - Tela de loading

#### 📚 Páginas de Conteúdo
9. `how-to-play.html` (18.6KB) - Tutorial
10. `gameplay.html` (23.9KB) - Mecânicas
11. `economy.html` (8.0KB) - Economia
12. `roadmap.html` (40.1KB) - Roadmap
13. `faq.html` (18.1KB) - FAQ
14. `rules.html` (26.7KB) - Regras
15. `affiliates.html` (18.2KB) - Afiliados
16. `privacy.html` (8.9KB) - Privacidade
17. `terms.html` (8.9KB) - Termos

#### 🧪 Páginas de Teste/Debug
18. `test.html` (3.3KB) - Testes gerais
19. `test-firebase.html` (6.9KB) - Teste Firebase
20. `debug.html` (6.0KB) - Debug

#### ⚙️ Arquivos de Configuração
21. `config.php` (110B) - Ponte para config API
22. `Dockerfile` (1.8KB) - Config Docker
23. `railway.json` (49B) - Config Railway
24. `.htaccess` (592B) - Config Apache
25. `htaccess` (98B) - Backup htaccess

#### 📦 Arquivos de Sistema
26. `adminer.php` (507.5KB) - Adminer PHP (ferramenta DB)
27. `fix_game_events.sql` (1.6KB) - Script SQL
28. `favicon.ico` (15.1KB) - Favicon
29. `cod.md` (6B) - Código de referência "230190"
30. `README.md` (22B) - "# crypto-asteroid-rush"

#### 📝 Documentação (nova)
31. `DOCUMENTATION.md` (10.7KB) - Esta documentação
32. `FILE_MAP.md` (este arquivo)

#### 🗑️ Arquivos para Análise (potencial lixo)
33. `.git/` - Controle de versão (não analisar)
34. `adminer.php` - MUITO grande (507KB), talvez desnecessário em produção
35. `htaccess` - Duplicado de `.htaccess`
36. `test.html`, `test-firebase.html`, `debug.html` - Talvez apenas desenvolvimento
37. Vários `.sample` files - Configurações de exemplo

### 🔌 API/Backend (12 arquivos + logs)

#### 📍 `api/` - Endpoints PHP
1. `config.php` (16.8KB) - **CONFIGURAÇÃO PRINCIPAL** (DB, segurança, jogo)
2. `auth.php` (7.5KB) - Autenticação Google/Firebase
3. `game.php` (11.8KB) - Endpoints do jogo (start, end, status)
4. `wallet.php` (4.5KB) - Operações de carteira
5. `staking.php` (5.0KB) - Staking (stake, unstake, info)
6. `withdraw.php` (5.8KB) - Saques (request, status, history)
7. `admin.php` (6.8KB) - Endpoints admin (players, transactions, security)
8. `stats.php` (2.3KB) - Estatísticas do sistema
9. `test.php` (1.6KB) - Testes de API
10. `cron.php` (2.8KB) - Tarefas agendadas (staking, withdrawals)

#### 📁 `api/logs/` - Logs do sistema
11. `game_security.log` (0B) - Log vazio
12. Vazio atualmente

### 👑 ADMIN/Painel (16 arquivos)

#### 📍 `admin/` - Painel Administrativo
1. `index.php` (2.0KB) - Login admin
2. `dashboard.php` (5.7KB) - Dashboard admin
3. `players.php` (7.3KB) - Gerenciar jogadores
4. `transactions.php` (5.5KB) - Transações
5. `withdrawals.php` (5.8KB) - Saques
6. `settings.php` (4.2KB) - Configurações
7. `security.php` (5.8KB) - Segurança (IPs, logs)
8. `logs.php` (4.5KB) - Logs do sistema

#### 📁 `admin/includes/` - Includes PHP
9. `header.php` (2.2KB) - Cabeçalho admin
10. `footer.php` (0.5KB) - Rodapé admin
11. `auth.php` (1.5KB) - Autenticação admin
12. `db.php` (0.7KB) - Conexão DB admin

#### 📁 `admin/css/` - Estilos Admin
13. `style.css` (3.0KB) - Estilos admin

#### 📁 `admin/js/` - JavaScript Admin
14. `main.js` (2.3KB) - Scripts admin
15. `charts.js` (1.5KB) - Gráficos (Chart.js)

#### 📁 `admin/pages/` - Páginas Auxiliares
16. `help.php` (1.7KB) - Ajuda/admin guide

### 🎨 FRONTEND/Assets (48 arquivos)

#### 📁 `css/` - Estilos (4 arquivos)
1. `style.css` (5.0KB) - Estilo principal
2. `game.css` (3.5KB) - Estilos do jogo
3. `wallet.css` (2.7KB) - Estilos carteira
4. `admin.css` (1.8KB) - Estilos admin

#### 📁 `js/` - JavaScript (20 arquivos)
5. `game.js` (12.5KB) - **LÓGICA PRINCIPAL DO JOGO** (Canvas, física, colisões)
6. `auth.js` (4.8KB) - Autenticação (Google, Firebase)
7. `wallet.js` (5.5KB) - Carteira (saldo, transações)
8. `staking.js` (4.5KB) - Staking (calcular, aplicar)
9. `utils.js` (3.2KB) - Utilitários (formatação, validação)
10. `firebase.js` (1.5KB) - Config Firebase
11. `hcaptcha.js` (1.2KB) - hCaptcha integration
12. `admin.js` (2.8KB) - Scripts admin

#### 🎵 `sounds/` - Sons do Jogo (4 arquivos)
13. `explosion.mp3` (56KB) - Som de explosão
14. `laser.mp3` (28KB) - Som de laser
15. `background.mp3` (3.1MB) - Música de fundo (GRANDE!)
16. `coin.mp3` (28KB) - Som de moeda

#### 🖼️ `img/` - Imagens (4 arquivos)
17. `asteroid.png` (15KB) - Sprite asteroide
18. `ship.png` (8.5KB) - Sprite nave
19. `background.jpg` (102KB) - Fundo do jogo
20. `icons/` - Vazio

#### 🖼️ `thumbnails/` - Thumbnails (0 arquivos)
21. Vazio

### 🐳 DOCKER/Infra (4 arquivos)

#### 📁 `docker/` - Configuração Docker
1. `nginx.conf` (1.1KB) - Config Nginx
2. `php.ini` (0B) - Vazio (problema!)
3. `Dockerfile` (1.1KB) - Docker específico
4. `entrypoint.sh` (1.3KB) - Script de entrada

### 📁 `.git/` - Controle de Versão (não analisar conteúdo)
- Git repository padrão
- Manter para versionamento

## 🔍 ANÁLISE DE ARQUIVOS PROBLEMÁTICOS/POTENCIAL LIXO

### 🚨 Arquivos com Problemas

#### 1. `adminer.php` (507.5KB)
- **Problema**: ENORME, ferramenta de admin DB
- **Risco**: Exposição de banco de dados se acessível
- **Sugestão**: Remover de produção, usar apenas dev

#### 2. `background.mp3` (3.1MB)
- **Problema**: MUITO grande para web
- **Impacto**: Performance de carregamento
- **Sugestão**: Otimizar/compressão ou remover

#### 3. `php.ini` (0B)
- **Problema**: Arquivo vazio
- **Impacto**: Config PHP não aplicada
- **Sugestão**: Preencher ou remover

#### 4. `test.html`, `test-firebase.html`, `debug.html`
- **Problema**: Páginas de teste em produção
- **Risco**: Exposição de funcionalidades internas
- **Sugestão**: Mover para dev ou remover

#### 5. Vários `.sample` files (13 arquivos)
- **Problema**: Configurações de exemplo
- **Impacto**: Confusão, possíveis security issues
- **Sugestão**: Remover todos

#### 6. `htaccess` (duplicado)
- **Problema**: Duplicata de `.htaccess`
- **Sugestão**: Remover

### 📊 Arquivos por Tamanho (Top 10)
1. `adminer.php` - 507.5KB ⚠️
2. `background.mp3` - 3.1MB ⚠️
3. `api/config.php` - 16.8KB ✅
4. `play.html` - 33.9KB ✅
5. `roadmap.html` - 40.1KB ✅
6. `game.js` - 12.5KB ✅
7. `game.html` - 16.3KB ✅
8. `wallet.html` - 26.9KB ✅
9. `staking.html` - 21.9KB ✅
10. `rules.html` - 26.7KB ✅

### 🎯 Arquivos Críticos (NÃO REMOVER)

#### Backend Essencial
- `api/config.php` - TUDO depende disso
- `api/auth.php` - Autenticação
- `api/game.php` - Jogo funciona aqui
- `api/wallet.php` - Carteira
- `api/staking.php` - Staking
- `api/withdraw.php` - Saques

#### Frontend Essencial
- `game.js` - Lógica do jogo
- `game.html` - Jogo principal
- `play.html` - Entry point
- `css/style.css` - Estilos
- `js/auth.js` - Auth frontend

#### Admin Essencial
- `admin/index.php` - Login admin
- `admin/dashboard.php` - Dashboard
- `admin/includes/db.php` - Conexão DB

## 🧹 RECOMENDAÇÕES DE LIMPEZA

### Fase 1: Remover Imediatamente (baixo risco)
1. `adminer.php` - Mover para dev se necessário
2. `htaccess` (duplicado)
3. `php.ini` (vazio) - criar config real ou remover
4. Todos `.sample` files (13 arquivos)

### Fase 2: Otimizar (médio risco)
1. `background.mp3` - Comprimir para <500KB
2. Páginas de teste - Mover para `/dev/` ou remover

### Fase 3: Revisar (alto risco - testar antes)
1. Verificar se `admin/` está acessível publicamente
2. Testar todos endpoints da API após limpeza
3. Backup antes de remover qualquer arquivo crítico

## 🔄 FLUXO DE ARQUIVOS

### Login Flow
```
usuário → play.html → auth.js → api/auth.php → DB players
```

### Game Flow  
```
play.html → game.html → game.js → api/game.php → DB game_sessions
```

### Wallet Flow
```
wallet.html → wallet.js → api/wallet.php → DB transactions
```

### Admin Flow
```
admin/index.php → admin/auth.php → admin/dashboard.php → várias páginas
```

## 📈 PRÓXIMOS PASSOS

1. **Backup completo** antes de qualquer remoção
2. **Testar cada funcionalidade** após limpeza
3. **Documentar alterações** no versionamento
4. **Criar script de deploy** limpo (sem arquivos desnecessários)

---

*Mapeamento criado em: 2026-02-01 01:13 UTC*
*Manter atualizado conforme análise de cada arquivo*