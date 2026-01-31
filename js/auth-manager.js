/* ============================================
   UNOBIX - Authentication Manager v2.0
   File: js/auth-manager.js
   Google OAuth via Firebase
   Fix: Melhor tratamento de popup e redirect fallback
   ============================================ */

class AuthManager {
    constructor() {
        this.currentUser = null;
        this.auth = null;
        this.provider = null;
        this.onAuthStateChangedCallbacks = [];
        this.isInitialized = false;
        
        // Configuração do Firebase
        this.firebaseConfig = {
            apiKey: "AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U",
            authDomain: "unobix-oauth-a69cd.firebaseapp.com",
            projectId: "unobix-oauth-a69cd",
            storageBucket: "unobix-oauth-a69cd.firebasestorage.app",
            messagingSenderId: "1067767347117",
            appId: "1:1067767347117:web:26e1193bdef8e264409324"
        };
        
        // Aguardar DOM antes de inicializar
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }
    
    // Initialize auth
    async init() {
        if (this.isInitialized) return;
        
        // Aguardar Firebase carregar completamente
        await this.waitForFirebase();
        
        try {
            // Verificar se Firebase foi inicializado pelo firebase-config.js
            if (!firebase.apps.length) {
                console.warn('⚠️ Firebase não inicializado pelo firebase-config.js, inicializando agora...');
                
                const firebaseConfig = {
                    apiKey: "AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U",
                    authDomain: "unobix-oauth-a69cd.firebaseapp.com",
                    projectId: "unobix-oauth-a69cd",
                    storageBucket: "unobix-oauth-a69cd.firebasestorage.app",
                    messagingSenderId: "1067767347117",
                    appId: "1:1067767347117:web:26e1193bdef8e264409324"
                };
                
                firebase.initializeApp(firebaseConfig);
                console.log('✅ Firebase inicializado pelo AuthManager');
            }
            
            this.auth = firebase.auth();
            this.provider = new firebase.auth.GoogleAuthProvider();
            
            // Configurar provider
            this.provider.addScope('profile');
            this.provider.addScope('email');
            this.provider.setCustomParameters({
                prompt: 'select_account'
            });
            
            // Configurar persistência
            await this.auth.setPersistence(firebase.auth.Auth.Persistence.LOCAL);
            
            // Listener de estado de autenticação
            this.auth.onAuthStateChanged((user) => {
                this.handleAuthStateChange(user);
            });
            
            this.isInitialized = true;
            console.log('🔐 AuthManager inicializado com sucesso');
            
        } catch (error) {
            console.error('❌ Erro ao inicializar AuthManager:', error);
            // Tentar novamente após 1 segundo
            setTimeout(() => this.init(), 1000);
        }
    }
    
    // Aguardar Firebase carregar
    async waitForFirebase() {
        return new Promise((resolve) => {
            const checkFirebase = () => {
                if (typeof firebase !== 'undefined' && 
                    typeof firebase.initializeApp === 'function' &&
                    typeof firebase.auth === 'function') {
                    console.log('✅ Firebase SDK carregado');
                    resolve();
                } else {
                    console.log('⏳ Aguardando Firebase SDK...');
                    setTimeout(checkFirebase, 100);
                }
            };
            checkFirebase();
        });
    }
    
    // Handle auth state changes
    handleAuthStateChange(user) {
        const previousUser = this.currentUser;
        this.currentUser = user;
        
        if (user) {
            console.log('✅ Usuário autenticado:', user.displayName || user.email);
            
            // Salvar no localStorage
            localStorage.setItem('googleUid', user.uid);
            localStorage.setItem('userDisplayName', user.displayName || '');
            localStorage.setItem('userEmail', user.email || '');
            localStorage.setItem('userPhotoURL', user.photoURL || '');
            
            // Atualizar gameState - criar se não existir
            if (typeof gameState !== 'undefined' && gameState !== null) {
                gameState.user = user;
                gameState.googleUid = user.uid;
                gameState.isConnected = true;
            } else if (typeof window !== 'undefined') {
                // Criar gameState global se não existir
                window.gameState = window.gameState || {};
                window.gameState.user = user;
                window.gameState.googleUid = user.uid;
                window.gameState.isConnected = true;
            }
            
            // Sincronizar com backend (apenas se é novo login)
            if (!previousUser) {
                this.syncUserWithBackend(user);
            }
        } else {
            console.log('👋 Usuário deslogado');
            
            // Limpar localStorage
            localStorage.removeItem('googleUid');
            localStorage.removeItem('userDisplayName');
            localStorage.removeItem('userEmail');
            localStorage.removeItem('userPhotoURL');
            
            // Limpar gameState
            if (typeof gameState !== 'undefined' && gameState !== null) {
                gameState.user = null;
                gameState.googleUid = null;
                gameState.isConnected = false;
            }
        }
        
        // Disparar evento
        this.dispatchAuthEvent(user);
    }
    
    // Sign in with Google - Redirect com fallback manual
    async signIn() {
        if (!this.auth || !this.provider) {
            await this.init();
            if (!this.auth) {
                throw new Error('Firebase não inicializado');
            }
        }
        
        console.log('🔐 Usando redirect (popup bloqueado no Railway)...');
        
        // Salvar estado para recuperar após redirect
        sessionStorage.setItem('authRedirectPending', 'true');
        console.log('📝 Flag authRedirectPending definida');
        
        try {
            // Tentar redirect do Firebase
            console.log('🔄 Iniciando signInWithRedirect do Firebase...');
            this.auth.signInWithRedirect(this.provider);
            
            // Se chegou aqui, o Firebase não redirecionou
            // Tentar fallback manual após 500ms
            setTimeout(() => {
                if (!sessionStorage.getItem('redirectStarted')) {
                    console.warn('⚠️ Firebase não redirecionou, tentando fallback manual...');
                    this.manualGoogleRedirect();
                }
            }, 500);
            
        } catch (error) {
            console.error('❌ Erro no signInWithRedirect:', error);
            console.log('🔄 Tentando fallback manual...');
            this.manualGoogleRedirect();
        }
        
        return null;
    }
    
    // Fallback manual para redirect do Google
    manualGoogleRedirect() {
        // Marcar que redirect foi iniciado
        sessionStorage.setItem('redirectStarted', 'true');
        
        // URL de login do Google OAuth manual
        const clientId = '1067767347117-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com';
        const redirectUri = encodeURIComponent(window.location.origin + '/__/auth/handler');
        const scope = encodeURIComponent('email profile');
        
        // URL do Google OAuth
        const googleAuthUrl = `https://accounts.google.com/o/oauth2/v2/auth?` +
            `client_id=${firebaseConfig.apiKey}&` + // Usar apiKey como client_id
            `redirect_uri=${redirectUri}&` +
            `scope=${scope}&` +
            `response_type=id_token&` +
            `nonce=${Date.now()}&` +
            `prompt=select_account`;
        
        console.log('🔗 Redirecionando manualmente para Google OAuth...');
        window.location.href = googleAuthUrl;
    }
    
    // Verificar resultado de redirect (chamar no início da página)
    async checkRedirectResult() {
        if (!this.auth) {
            console.log('ℹ️ Auth não disponível para checkRedirectResult');
            return null;
        }
        
        // Verificar se há flag de redirect pendente
        const wasRedirectPending = sessionStorage.getItem('authRedirectPending') === 'true';
        
        if (!wasRedirectPending) {
            console.log('ℹ️ Nenhum redirect pendente');
            return null;
        }
        
        console.log('🔄 Verificando resultado de redirect...');
        
        try {
            // Limpar flag ANTES de verificar (evita loops)
            sessionStorage.removeItem('authRedirectPending');
            
            const result = await this.auth.getRedirectResult();
            
            if (result && result.user) {
                console.log('✅ Login via redirect bem-sucedido:', result.user.email);
                return result.user;
            }
            
            console.log('ℹ️ Nenhum resultado de redirect encontrado');
            return null;
        } catch (error) {
            console.error('❌ Erro no redirect result:', error.code || error.message);
            
            // Em caso de erro comum, apenas ignorar
            if (error.code === 'auth/network-request-failed' || 
                error.code === 'auth/internal-error') {
                console.log('⚠️ Erro de rede ignorado para redirect');
                return null;
            }
            
            throw error;
        }
    }
    
    // Sign out
    async signOut() {
        if (!this.auth) {
            throw new Error('Auth não inicializado');
        }
        
        try {
            await this.auth.signOut();
            this.currentUser = null;
            return true;
        } catch (error) {
            console.error('❌ Erro ao fazer logout:', error);
            throw error;
        }
    }
    
    // Get current user
    getUser() {
        return this.currentUser;
    }
    
    // Check if logged in
    isLoggedIn() {
        return this.currentUser !== null;
    }
    
    // Get user ID
    getUserId() {
        return this.currentUser?.uid || localStorage.getItem('googleUid') || null;
    }
    
    // Sync user with backend
    async syncUserWithBackend(user) {
        if (!user) return;
        
        try {
            const response = await fetch('api/auth-google.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'login',
                    google_uid: user.uid,
                    email: user.email,
                    display_name: user.displayName,
                    photo_url: user.photoURL
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log('✅ Usuário sincronizado com backend');
               
               if (result.session_token) {
  localStorage.setItem('sessionToken', result.session_token);

  // manter gameState em sincronia
  window.gameState = window.gameState || {};
  window.gameState.sessionToken = result.session_token;
}

                
                // Verificar referral
                this.checkReferral(user.uid);
            } else {
                console.warn('⚠️ Aviso do backend:', result.error);
            }
        } catch (error) {
            console.error('❌ Erro ao sincronizar com backend:', error);
        }
    }
    
    // Check and apply referral code
    async checkReferral(googleUid) {
        const params = new URLSearchParams(window.location.search);
        const refCode = params.get('ref');
        
        if (refCode) {
            try {
                const response = await fetch('api/apply-referral.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        google_uid: googleUid,
                        referral_code: refCode
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    console.log('✅ Código de indicação aplicado:', refCode);
                }
                
                // Limpar código da URL
                window.history.replaceState({}, '', window.location.pathname);
            } catch (error) {
                console.error('Erro ao aplicar referral:', error);
            }
        }
    }
    
    // Dispatch auth state changed event
    dispatchAuthEvent(user) {
        const event = new CustomEvent('authStateChanged', {
            detail: { user: user }
        });
        document.dispatchEvent(event);
        
        // Chamar callbacks registrados
        this.onAuthStateChangedCallbacks.forEach(callback => {
            try {
                callback(user);
            } catch (e) {
                console.error('Erro em callback de auth:', e);
            }
        });
    }
    
    // Register auth state change callback
    onAuthStateChanged(callback) {
        if (typeof callback === 'function') {
            this.onAuthStateChangedCallbacks.push(callback);
            
            // Chamar imediatamente com estado atual
            if (this.currentUser !== undefined) {
                callback(this.currentUser);
            }
        }
    }
    
    // Get ID token for API calls
    async getIdToken() {
        if (!this.currentUser) {
            return null;
        }
        
        try {
            return await this.currentUser.getIdToken();
        } catch (error) {
            console.error('Erro ao obter ID token:', error);
            return null;
        }
    }
    
    // Aliases para compatibilidade
    async loginWithGoogle() {
        return this.signIn();
    }
    
    async login() {
        return this.signIn();
    }
    
    async logout() {
        return this.signOut();
    }
}

// Criar instância global IMEDIATAMENTE
window.authManager = new AuthManager();
window.AuthManager = AuthManager;

console.log('✅ AuthManager criado globalmente');

// Verificar redirect result ao carregar (UMA VEZ apenas)
setTimeout(async () => {
    // Verificar apenas se há flag E se authManager está inicializado
    if (sessionStorage.getItem('authRedirectPending') === 'true' && 
        window.authManager && 
        window.authManager.isInitialized) {
        
        console.log('🔄 Verificando resultado de redirect (timeout)...');
        try {
            await window.authManager.checkRedirectResult();
        } catch (error) {
            console.error('Erro ao verificar redirect:', error);
            // Limpar flag em caso de erro
            sessionStorage.removeItem('authRedirectPending');
        }
    }
}, 1500); // Aguardar mais para Firebase carregar completamente

// Inicialização forçada após 3 segundos (fallback seguro)
setTimeout(() => {
    if (window.authManager && !window.authManager.isInitialized) {
        console.log('🔄 Inicialização forçada do AuthManager (fallback)...');
        window.authManager.init();
    }
}, 3000);
