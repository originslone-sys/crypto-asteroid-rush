# ✅ CHECKLIST DE TESTE MANUAL - LOGIN E SESSÃO DE JOGO

## 🎯 OBJETIVO
Validar todo o fluxo do usuário do login até o jogo completo.

## 📍 URL DA APLICAÇÃO
**Produção:** `https://crypto-asteroid-6dczkl3zma-uc.a.run.app`

---

## 🔧 PREPARAÇÃO
- [ ] **Abrir Console do Navegador** (F12 → Console)
- [ ] **Abrir Network Tab** (F12 → Network)
- [ ] **Limpar Console** (Ctrl+L no console)
- [ ] **Habilitar Preserve Log** (Network tab)

---

## 🔐 TESTE 1: LOGIN COM GOOGLE

### ✅ ETAPAS:
1. [ ] **Acessar** a página inicial
2. [ ] **Clicar** em "Login com Google"
3. [ ] **Selecionar** conta Google para login
4. [ ] **Aguardar** redirecionamento

### 🎯 VALIDAÇÕES:
- [ ] **Redireciona** para `/dashboard.html` ou `/game.html`
- [ ] **Console sem erros** (vermelho)
- [ ] **Network:** Requisição para `/api/auth-firebase.php` com status 200
- [ ] **Session criada:** Cookie ou localStorage com token
- [ ] **UI atualizada:** Mostra nome/avatar do usuário

### ⚠️ PROBLEMAS COMUNS:
- ❌ **Firebase não configurado** → Verificar `.env` e `config.php`
- ❌ **CORS errors** → Verificar headers no PHP
- ❌ **Redirect loop** → Verificar lógica de autenticação

---

## 🎮 TESTE 2: INÍCIO DE SESSÃO DE JOGO

### ✅ ETAPAS:
1. [ ] **Na dashboard**, clicar em "Iniciar Missão" ou similar
2. [ ] **Aguardar** carregamento do jogo (`game.html`)
3. [ ] **Verificar** se o jogo inicia corretamente

### 🎯 VALIDAÇÕES:
- [ ] **Game carrega:** Canvas/UI do jogo visível
- [ ] **Assets carregados:** Sons, imagens, sem 404s
- [ ] **Timer inicia:** Contagem regressiva visível
- [ ] **Controles funcionam:** Teclado/mouse respondem
- [ ] **Console:** `Game session started` ou similar

### 📊 MONITORAR BANCO DE DADOS:
```sql
-- Executar no Cloud SQL após iniciar jogo:
SELECT * FROM game_sessions ORDER BY created_at DESC LIMIT 1;
SELECT * FROM game_events WHERE session_id = [ID_DA_SESSÃO];
```

---

## 💰 TESTE 3: GAMEPLAY E GANHOS

### ✅ ETAPAS:
1. [ ] **Jogar** por 30-60 segundos
2. [ ] **Destruir** alguns asteroides
3. [ ] **Verificar** se pontuação aumenta
4. [ ] **Finalizar** missão (timer acaba ou manual)

### 🎯 VALIDAÇÕES:
- [ ] **Pontuação atualizada** em tempo real
- [ ] **Eventos registrados:** Cada asteroide destruído gera evento
- [ ] **Saldo atualizado:** BRL aumenta no wallet
- [ ] **Missão finalizada:** Tela de resultados aparece

### 📊 MONITORAR BANCO:
```sql
-- Durante o jogo:
SELECT COUNT(*) as eventos FROM game_events WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE);

-- Após jogo:
SELECT * FROM users WHERE id = [SEU_USER_ID];
UPDATE users SET balance_brl = balance_brl + 1.00 WHERE id = [SEU_USER_ID]; -- Teste manual
```

---

## 📱 TESTE 4: DASHBOARD E WALLET

### ✅ ETAPAS:
1. [ ] **Voltar** para dashboard após jogo
2. [ ] **Verificar** saldo atualizado
3. [ ] **Acessar** wallet/staking pages
4. [ ] **Testar** funcionalidades de saque

### 🎯 VALIDAÇÕES:
- [ ] **Dashboard carrega:** Estatísticas do usuário
- [ ] **Saldo correto:** Reflete ganhos do jogo
- [ ] **Histórico:** Mostra sessões de jogo recentes
- [ ] **Staking funciona:** Pode aplicar/retirar staking

---

## 🐛 TESTE 5: CENÁRIOS DE ERRO

### ✅ TESTAR:
1. [ ] **Login com conta inválida**
2. [ ] **Refresh durante jogo** (F5)
3. [ ] **Conexão lenta** (Slow 3G no DevTools)
4. [ ] **Múltiplas abas** jogando simultaneamente
5. [ ] **Logout e login novamente**

### 🎯 VALIDAÇÕES:
- [ ] **Recuperação de sessão:** Continua onde parou
- [ ] **Dados preservados:** Saldo não é perdido
- [ ] **Erros tratados:** Mensagens amigáveis ao usuário
- [ ] **Sem race conditions:** Múltiplas requisições não quebram

---

## 📈 TESTE 6: PERFORMANCE

### ✅ METRICS:
- [ ] **Load time:** < 3s para página inicial
- [ ] **Time to interactive:** < 5s para jogo
- [ ] **FPS do jogo:** > 30fps constante
- [ ] **Memory usage:** < 200MB no navegador
- [ ] **API response time:** < 500ms

### 🔧 FERRAMENTAS:
- Chrome DevTools → Performance tab
- Lighthouse report
- WebPageTest.org

---

## 📋 CHECKLIST RÁPIDO DE BUGS COMUNS

### 🚨 CRÍTICOS:
- [ ] **Login não funciona** - Firebase config
- [ ] **Jogo não inicia** - JS errors
- [ ] **Saldo não atualiza** - Database issues
- [ ] **Sessão perdida** - Cookie/session config

### ⚠️ FUNCIONAIS:
- [ ] **UI quebrada** - CSS issues
- [ ] **Controles não respondem** - JS bugs
- [ ] **Sons não tocam** - Audio context
- [ ] **Mobile não funciona** - Responsive issues

### 🔧 TÉCNICOS:
- [ ] **Console errors** - JS/PHP errors
- [ ] **Network errors** - API failures
- [ ] **Database errors** - SQL issues
- [ ] **Cache problems** - Old assets

---

## 📝 TEMPLATE DE RELATÓRIO

```markdown
## RELATÓRIO DE TESTE - [DATA]

### ✅ FUNCIONANDO:
1. Login com Google
2. Início do jogo
3. Gameplay básico
4. Atualização de saldo

### ⚠️ PROBLEMAS IDENTIFICADOS:
1. [Descrição do problema]
   - Impacto: [Alto/Médio/Baixo]
   - Passos para reproduzir
   - Console errors

### 📊 MÉTRICAS:
- Load time: Xs
- API response: Xms
- FPS médio: X

### 🚀 PRÓXIMOS PASSOS:
1. Corrigir [problema 1]
2. Otimizar [performance issue]
3. Testar [cenário não testado]
```

---

## 🔧 FERRAMENTAS ÚTEIS

### PARA DEBUG:
```bash
# Logs do Cloud Run
gcloud logging read "resource.type=cloud_run_revision" --limit=20

# Database queries
mysql -h 34.168.76.127 -u unobix_user -p"YyZD3H)dndSo*A/N" unobix_db

# Teste de endpoints
curl -v https://crypto-asteroid-6dczkl3zma-uc.a.run.app/api/auth-firebase.php
```

### MONITORAMENTO:
- **Cloud Run Logs:** Console Google Cloud
- **Database:** Cloud SQL Console
- **Performance:** Chrome DevTools, Lighthouse
- **Errors:** Sentry (se configurado)

---

**🎯 BOA SORTE NOS TESTES!** Documente tudo que encontrar. 🐛➡️✅