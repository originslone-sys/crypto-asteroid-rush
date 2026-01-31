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
        
        // Aguardar DOM antes de inicializar
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }
    
    // Initialize auth
    init() {
        if (this.isInitialized) return;
        
        if (typeof firebase === 'undefined') {
            console.error('❌ Firebase não carregado');
            setTimeout(() => this.init(), 500);
            return;
        }
        
        try {
            this.auth = firebase.auth();
            this.provider = new firebase.auth.GoogleAuthProvider();
            
            // Configurar provider
            this.provider.addScope('profile');
            this.provider.addScope('email');
            this.provider.setCustomParameters({
                prompt: 'select_account'
            });
            
            // Listener de estado de autenticação
            this.auth.onAuthStateChanged((user) => {
                this.handleAuthStateChange(user);
            });
            
            this.isInitialized = true;
            console.log('🔐 AuthManager inicializado');
            
        } catch (error) {
            console.error('❌ Erro ao inicializar AuthManager:', error);
        }
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
    
    // Sign in with Google - tenta popup, fallback para redirect
    async signIn() {
        if (!this.auth || !this.provider) {
            await this.init();
            if (!this.auth) {
                throw new Error('Firebase não inicializado');
            }
        }
        
        try {
            // Tentar popup primeiro (pode ser bloqueado no Railway)
            console.log('🔐 Tentando login com popup...');
            const result = await this.auth.signInWithPopup(this.provider);
            return result.user;
            
        } catch (error) {
            console.warn('⚠️ Popup falhou:', error.code);
            
            // Se popup foi bloqueado ou fechado, tentar redirect
            if (error.code === 'auth/popup-blocked' || 
                error.code === 'auth/popup-closed-by-user' ||
                error.code === 'auth/cancelled-popup-request' ||
                error.code === 'auth/network-request-failed') {
                
                console.log('🔄 Usando redirect como fallback...');
                
                // Salvar estado para recuperar após redirect
                sessionStorage.setItem('authRedirectPending', 'true');
                console.log('📝 Flag authRedirectPending definida');
                
                // Usar redirect - NÃO usar await, redireciona imediatamente
                this.auth.signInWithRedirect(this.provider);
                
                // Não retornar nada - a página será redirecionada
                // Se o código chegou aqui, o redirect não funcionou
                return null;
            }
            
            throw error;
        }
    }
    
    // Verificar resultado de redirect (chamar no início da página)
    async checkRedirectResult() {
        if (!this.auth) return null;
        
        // Limpar flag imediatamente para evitar loops
        const wasRedirectPending = sessionStorage.getItem('authRedirectPending') === 'true';
        sessionStorage.removeItem('authRedirectPending');
        
        if (!wasRedirectPending) {
            return null;
        }
        
        try {
            const result = await this.auth.getRedirectResult();
            
            if (result && result.user) {
                console.log('✅ Login via redirect bem-sucedido');
                return result.user;
            }
            
            console.log('ℹ️ Nenhum resultado de redirect encontrado');
            return null;
        } catch (error) {
            console.error('❌ Erro no redirect result:', error);
            return null;
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

// Criar instância global (se não existir)
if (!window.authManager) {
    window.authManager = new AuthManager();
    console.log('✅ AuthManager criado globalmente');
}

// Verificar redirect result ao carregar (com timeout para evitar loops)
document.addEventListener('DOMContentLoaded', async () => {
    // Esperar um pouco para Firebase carregar
    setTimeout(async () => {
        if (sessionStorage.getItem('authRedirectPending') === 'true') {
            console.log('🔄 Verificando resultado de redirect...');
            try {
                await window.authManager.checkRedirectResult();
            } catch (error) {
                console.error('Erro ao verificar redirect:', error);
                // Limpar flag em caso de erro
                sessionStorage.removeItem('authRedirectPending');
            }
        }
    }, 1000);
});

// Alias para compatibilidade
window.AuthManager = AuthManager;

// Inicialização forçada após 2 segundos (fallback)
setTimeout(() => {
    if (window.authManager && !window.authManager.isInitialized) {
        console.log('🔄 Inicialização forçada do AuthManager...');
        window.authManager.init();
    }
}, 2000);
