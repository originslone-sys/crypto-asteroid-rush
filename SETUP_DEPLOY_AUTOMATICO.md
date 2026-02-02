# 🚀 CONFIGURAÇÃO DE DEPLOY AUTOMÁTICO - CLOUD BUILD + CLOUD RUN

## 📋 PASSO A PASSO PARA CONFIGURAR NO CONSOLE WEB

### 🔧 ETAPA 1: CONFIGURAR CLOUD BUILD TRIGGER

1. **Acesse o Cloud Build:**
   ```
   https://console.cloud.google.com/cloud-build
   ```

2. **Clique em "Triggers" no menu lateral**

3. **Clique em "CREATE TRIGGER"**

4. **Configure o trigger:**
   - **Name:** `crypto-asteroid-auto-deploy`
   - **Event:** `Push to a branch`
   - **Source:** `GitHub (or originslone-sys/crypto-asteroid-rush)`
   - **Repository:** `originslone-sys/crypto-asteroid-rush`
   - **Branch:** `^main$` (apenas branch main)
   - **Build configuration:** `Cloud Build configuration file (yaml or json)`
   - **Cloud Build configuration file location:** `/cloudbuild-simple.yaml`
   - **Substitution variables:** (deixe padrão)

5. **Clique em CREATE**

---

### 🚀 ETAPA 2: TESTAR O TRIGGER

1. **Faça um pequeno commit para testar:**
   ```bash
   git commit --allow-empty -m "Teste deploy automático"
   git push origin main
   ```

2. **Monitore o build:**
   - Acesse: `https://console.cloud.google.com/cloud-build`
   - Veja o build em execução
   - Clique para ver logs detalhados

---

### ⚙️ ETAPA 3: CONFIGURAR PERMISSÕES (SE NECESSÁRIO)

Se o build falhar por permissões, adicione estas roles à service account:

1. **Acesse IAM:**
   ```
   https://console.cloud.google.com/iam-admin/iam
   ```

2. **Encontre a service account:** `[PROJECT_NUMBER]-compute@developer.gserviceaccount.com`

3. **Adicione estas roles:**
   - `Cloud Run Admin`
   - `Service Account User`
   - `Cloud Build Service Account`
   - `Cloud SQL Client`

---

### 🔍 ETAPA 4: VERIFICAR ERROS COMUNS

#### **Erro 1: Permissões insuficientes**
```
PERMISSION_DENIED: Request had insufficient authentication scopes
```
**Solução:** Adicione as roles acima no IAM.

#### **Erro 2: Cloud SQL connection failed**
```
Cloud SQL instance not found or no permission
```
**Solução:** Verifique se:
- Instância `unobix` existe
- Service account tem role `Cloud SQL Client`
- Cloud Run está configurado com `--add-cloudsql-instances`

#### **Erro 3: Docker build failed**
```
Dockerfile not found or build error
```
**Solução:** Use `cloudbuild-simple.yaml` que faz source deploy.

---

### 📊 ETAPA 5: MONITORAMENTO

#### **Logs do Cloud Build:**
```
https://console.cloud.google.com/cloud-build
```

#### **Logs do Cloud Run:**
```
https://console.cloud.google.com/run/detail/us-central1/crypto-asteroid/logs
```

#### **Métricas:**
```
https://console.cloud.google.com/run/detail/us-central1/crypto-asteroid/metrics
```

---

## 🎯 CONFIGURAÇÃO ALTERNATIVA: DEPLOY MANUAL

Se o automático falhar, use o script manual:

```bash
# 1. Faça login (se necessário)
gcloud auth login

# 2. Execute o deploy
./deploy-manual.sh
```

---

## ✅ VERIFICAÇÃO FINAL

Após configurar, teste:

1. **Aplicação principal:**
   ```
   https://crypto-asteroid-234282032979.us-central1.run.app
   ```

2. **phpMyAdmin (interface minimal):**
   ```
   https://crypto-asteroid-234282032979.us-central1.run.app/phpmyadmin
   Senha: Admin@Unobix2024!
   ```

3. **Banco de dados:**
   - Acesse Cloud SQL Console
   - Verifique se `unobix_db` existe com 12 tabelas

---

## 📞 SUPORTE

Se encontrar problemas:

1. **Verifique logs** no Cloud Build
2. **Compartilhe screenshots** dos erros
3. **Teste deploy manual** primeiro
4. **Verifique permissões** no IAM

---

**🎉 PRONTO!** Agora todo push na branch `main` vai disparar deploy automático no Cloud Run!