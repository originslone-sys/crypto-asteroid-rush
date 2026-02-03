/* ============================================
   UNOBIX - Authentication Manager v3.0 (CORRIGIDO)
   Google OAuth via Firebase
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
            localStorage.removeItem('sessionToken');
            
            // Limpar gameState
            if (typeof gameState !== 'undefined' && gameState !== null) {
                gameState.user = null;
                gameState.googleUid = null;
                gameState.isConnected = false;
                gameState.sessionToken = null;
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
            // Tentar popup primeiro
            console.log('🔐 Tentando login com popup...');
            const result = await this.auth.signInWithPopup(this.provider);
            return result.user;
            
        } catch (error) {
            console.warn('⚠️ Popup falhou:', error.code);
            
            // Se popup foi bloqueado ou fechado, tentar redirect
            if (error.code === 'auth/popup-blocked' || 
                error.code === 'auth/popup-closed-by-user' ||
                error.code === 'auth/cancelled-popup-request') {
                
                console.log('🔄 Usando redirect como fallback...');
                
                // Salvar estado para recuperar após redirect
                sessionStorage.setItem('authRedirectPending', 'true');
                
                // Usar redirect
                await this.auth.signInWithRedirect(this.provider);
                return null; // Página vai recarregar
            }
            
            throw error;
        }
    }
    
    // Verificar resultado de redirect (chamar no início da página)
    async checkRedirectResult() {
        if (!this.auth) return null;
        
        try {
            const result = await this.auth.getRedirectResult();
            
            if (result && result.user) {
                console.log('✅ Login via redirect bem-sucedido');
                sessionStorage.removeItem('authRedirectPending');
                return result.user;
            }
            
            return null;
        } catch (error) {
            console.error('❌ Erro no redirect result:', error);
            sessionStorage.removeItem('authRedirectPending');
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
    
    // Sync user with backend - CORRIGIDO
    async syncUserWithBackend(user) {
        if (!user) return;
        
        try {
            console.log('🔄 Sincronizando com backend...');
            
            const response = await fetch('/api/auth-google.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    action: 'login',
                    google_uid: user.uid,
                    email: user.email,
                    display_name: user.displayName,
                    photo_url: user.photoURL
                })
            });
            
            // Verificar se resposta é JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('❌ Resposta não é JSON:', text.substring(0, 200));
                throw new Error('Backend retornou resposta inválida');
            }
            
            const result = await response.json();
            
            if (result.success) {
                console.log('✅ Usuário sincronizado com backend');
                
                // Salvar session token
                if (result.session_token) {
                    localStorage.setItem('sessionToken', result.session_token);
                    
                    // Manter gameState em sincronia
                    window.gameState = window.gameState || {};
                    window.gameState.sessionToken = result.session_token;
                }
                
                // Salvar dados do usuário
                if (result.user) {
                    localStorage.setItem('userData', JSON.stringify(result.user));
                    
                    if (window.gameState) {
                        window.gameState.userData = result.user;
                        window.gameState.balance_brl = result.user.balance_brl || 0;
                    }
                }
                
                // Verificar referral
                this.checkReferral(user.uid);
                
            } else {
                console.warn('⚠️ Aviso do backend:', result.error);
                
                // Se usuário está banido, fazer logout
                if (result.error && result.error.includes('suspensa')) {
                    alert('Sua conta foi suspensa. Entre em contato com o suporte.');
                    await this.signOut();
                }
            }
        } catch (error) {
            console.error('❌ Erro ao sincronizar com backend:', error);
            
            // Tentar endpoint alternativo (login.php para compatibilidade)
            try {
                console.log('🔄 Tentando endpoint alternativo...');
                
                const fallbackResponse = await fetch('/api/login.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        google_uid: user.uid,
                        email: user.email,
                        display_name: user.displayName
                    })
                });
                
                const fallbackContentType = fallbackResponse.headers.get('content-type');
                if (fallbackContentType && fallbackContentType.includes('application/json')) {
                    const fallbackResult = await fallbackResponse.json();
                    
                    if (fallbackResult.success) {
                        console.log('✅ Sincronizado via endpoint alternativo');
                        
                        if (fallbackResult.player) {
                            localStorage.setItem('userData', JSON.stringify(fallbackResult.player));
                            
                            if (window.gameState) {
                                window.gameState.userData = fallbackResult.player;
                                window.gameState.balance_brl = fallbackResult.player.balance_brl || 0;
                            }
                        }
                    }
                }
            } catch (fallbackError) {
                console.error('❌ Fallback também falhou:', fallbackError);
            }
        }
    }
    
    // Check and apply referral code
    async checkReferral(googleUid) {
        const params = new URLSearchParams(window.location.search);
        const refCode = params.get('ref');
        
        if (refCode) {
            try {
                const response = await fetch('/api/apply-referral.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        google_uid: googleUid,
                        referral_code: refCode
                    })
                });
                
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const result = await response.json();
                    
                    if (result.success) {
                        console.log('✅ Código de indicação aplicado:', refCode);
                    }
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
    
    // Get balance from backend
    async getBalance() {
        const googleUid = this.getUserId();
        if (!googleUid) return null;
        
        try {
            const response = await fetch('/api/auth-google.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    action: 'balance',
                    google_uid: googleUid
                })
            });
            
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const result = await response.json();
                
                if (result.success) {
                    return result.balance_brl || 0;
                }
            }
            
            return null;
        } catch (error) {
            console.error('Erro ao obter saldo:', error);
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

console.log('✅ AuthManager criado globalmente (versão corrigida v3.0)');

// Verificar redirect result ao carregar
document.addEventListener('DOMContentLoaded', async () => {
    // Esperar um pouco para Firebase carregar
    setTimeout(async () => {
        if (sessionStorage.getItem('authRedirectPending') === 'true') {
            console.log('🔄 Verificando resultado de redirect...');
            try {
                await window.authManager.checkRedirectResult();
            } catch (error) {
                console.error('Erro ao verificar redirect:', error);
                sessionStorage.removeItem('authRedirectPending');
            }
        }
    }, 1000);
});
