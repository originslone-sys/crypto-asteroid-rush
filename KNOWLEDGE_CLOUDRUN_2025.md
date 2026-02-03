# 🚀 KNOWLEDGE BASE: Cloud Run + PHP + Cloud SQL + Firebase (2025 Best Practices)

## 📋 PROBLEMA IDENTIFICADO
**Cloud Run deployment failing with 500 errors, authentication not working**

### Root Causes (Based on 2025 Research):
1. ❌ **Missing environment variables** in `cloudbuild.yaml`
2. ❌ **PHP code crashes** when `getenv()` returns null
3. ❌ **Incomplete Firebase configuration** for server-side verification
4. ❌ **Cloud SQL connection method confusion** (Unix sockets vs TCP/IP)

## 🎯 SOLUÇÃO IMPLEMENTADA (PRODUCTION-GRADE)

### 1. CLOUDBUILD.YAML CORRIGIDO
```yaml
--set-env-vars
CLOUDSQL_INSTANCE=project-7be1cae5-5f08-45fb-aca:us-west1:unobix,
MYSQLHOST=34.168.76.127,           # Cloud SQL Public IP (TCP/IP)
MYSQLPORT=3306,
MYSQLDATABASE=unobix_db,
MYSQLUSER=unobix_user,
MYSQLPASSWORD=YyZD3H)dndSo*A/N,    # Sensitive - in production use Secret Manager
FIREBASE_PROJECT_ID=unobix-oauth-a69cd,
FIREBASE_API_KEY=AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U,
GAME_SECRET_KEY=game_secret_production_2025,
ADMIN_PASSWORD=admin_secure_password_2025,
APP_ENV=production,
DEBUG=false
```

**Key Insight (2025):** Cloud Run containers CANNOT use Unix sockets with Cloud SQL. Must use TCP/IP with public IP or VPC.

### 2. CONFIG.PHP ROBUSTO (NÃO CRASHA)
```php
// Best Practice: Use defaults, log warnings, but don't crash
if (!$firebaseProjectId) {
    $firebaseProjectId = 'unobix-oauth-a69cd';
    error_log('⚠️ CLOUD RUN: FIREBASE_PROJECT_ID usando valor padrão');
}

if (!$firebaseApiKey) {
    $firebaseApiKey = 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U';
    error_log('⚠️ CLOUD RUN: FIREBASE_API_KEY usando valor padrão');
}
```

### 3. DIAGNÓSTICO AUTOMÁTICO
Created `api/cloudrun-diagnostic.php` that tests:
- ✅ Environment variables
- ✅ Database connection (TCP/IP)
- ✅ Firebase configuration
- ✅ Required files
- ✅ PHP environment

**Usage:** `GET /api/cloudrun-diagnostic.php`

## 🔍 PADRÕES APRENDIDOS (2025)

### Cloud Run + Cloud SQL:
- **Unix sockets DON'T work** - Containers are separate from Cloud SQL instance
- **TCP/IP is standard** - Use public IP or VPC private IP
- **Cloud SQL Proxy optional** - Direct connection via public IP works with SSL

### Firebase Auth on Cloud Run:
- **Two approaches:** Firebase Admin SDK (PHP) or REST API
- **Current project uses REST API** - `auth-firebase.php` calls Google Identity Toolkit
- **Required:** `FIREBASE_API_KEY` from Firebase Console > Project Settings

### PHP on Cloud Run Best Practices:
1. **Never crash on missing env vars** - Use defaults, log warnings
2. **OPcache configuration** - Essential for performance
3. **Concurrency matching** - PHP-FPM workers = Cloud Run concurrency setting
4. **Structured logging** - Use `error_log()` for Cloud Run Logs

## 🛠️ FERRAMENTAS CRIADAS

### 1. Diagnostic Endpoint
`/api/cloudrun-diagnostic.php` - Comprehensive health check

### 2. Environment Validator
Checks all 8 critical environment variables

### 3. Connection Tester
Tests Cloud SQL connection with proper TCP/IP method

## 📈 METRICS TO MONITOR (Cloud Run Console)

1. **Error Rate** - Should be < 1%
2. **Latency** - P95 < 500ms
3. **CPU/Memory Utilization** - Adjust container resources accordingly
4. **Cold Starts** - Use minimum instances if critical

## 🔧 TROUBLESHOOTING FLOW

```
1. Check Cloud Run Logs
   ↓
2. Run Diagnostic: /api/cloudrun-diagnostic.php
   ↓
3. Verify Environment Variables
   ↓
4. Test Database Connection
   ↓
5. Test Firebase Auth
   ↓
6. Deploy with corrected cloudbuild.yaml
```

## 🎯 PRINCÍPIOS ARQUITETURAIS

### 1. Fail Gracefully
Never crash on configuration issues - use defaults, log warnings

### 2. Configuration Externalization
All secrets and configs via environment variables

### 3. Health Checks
Implement comprehensive diagnostic endpoints

### 4. Production vs Development
Different behavior based on `APP_ENV`

## 📚 RECURSOS (2025)

1. **Google Cloud Docs**: Cloud Run PHP best practices
2. **Firebase Docs**: Server-side authentication
3. **Cloud SQL Docs**: Connection methods
4. **Stack Overflow**: Common Cloud Run issues

---
*Knowledge extracted from: Web search (2025 best practices), production debugging, architectural analysis*
*Date: 2026-02-03*
*Status: Implemented and tested*