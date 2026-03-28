/* ============================================
   UNOBIX - Authentication Manager v5.0 CLEAN
   Adaptado para tabela users atual
   ============================================ */

class AuthManager {
    constructor() {
        this.currentUser = null;
        this.auth = null;
        this.provider = null;
        this.onAuthStateChangedCallbacks = [];
        this.isInitialized = false;
        this._logoutDebounceTimer = null;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }
    
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
            
            this.provider.addScope('profile');
            this.provider.addScope('email');
            this.provider.setCustomParameters({
                prompt: 'select_account'
            });
            
            this.auth.onAuthStateChanged((user) => {
                this.handleAuthStateChange(user);
            });
            
            this.isInitialized = true;
            console.log('🔐 AuthManager inicializado');
            
            // Capturar código de referral da URL (se houver)
            this.captureReferralCode();
            
        } catch (error) {
            console.error('❌ Erro ao inicializar AuthManager:', error);
        }
    }
    
    handleAuthStateChange(user) {
        const previousUser = this.currentUser;

        if (user) {
            // Cancelar qualquer logout pendente (era estado intermediário)
            if (this._logoutDebounceTimer) {
                clearTimeout(this._logoutDebounceTimer);
                this._logoutDebounceTimer = null;
                console.log('✅ Auth restaurada (cancelou logout pendente)');
            }

            this.currentUser = user;
            console.log('✅ Usuário autenticado:', user.displayName || user.email);

            // Salvar no localStorage
            localStorage.setItem('googleUid', user.uid);
            localStorage.setItem('userDisplayName', user.displayName || '');
            localStorage.setItem('userEmail', user.email || '');
            localStorage.setItem('userPhotoURL', user.photoURL || '');

            // Atualizar gameState
            if (typeof gameState !== 'undefined' && gameState !== null) {
                gameState.user = user;
                gameState.googleUid = user.uid;
                gameState.isConnected = true;
            } else if (typeof window !== 'undefined') {
                window.gameState = window.gameState || {};
                window.gameState.user = user;
                window.gameState.googleUid = user.uid;
                window.gameState.isConnected = true;
            }

            // Sincronizar com backend (apenas se é novo login)
            if (!previousUser) {
                this.syncUserWithBackend(user);
            }

            this.dispatchAuthEvent(user);
        } else {
            // Firebase disparou null — pode ser estado intermediário ou logout real.
            // Usar debounce: esperar 3s antes de tratar como logout real.
            // Se o Firebase restaurar o user dentro de 3s, o logout é cancelado.

            if (!previousUser) {
                // Nunca teve user — estado intermediário, ignorar
                console.log('⏳ Auth pendente (estado intermediário), mantendo cache');
                return;
            }

            // Se há jogo ativo, NÃO fazer logout — preservar sessão
            const hasActiveGame = window.SessionManager?.hasActiveSession?.() ||
                (typeof gameState !== 'undefined' && gameState?.sessionId);
            if (hasActiveGame) {
                console.log('🎮 Auth null durante jogo ativo — ignorando, mantendo sessão');
                // Não limpar currentUser nem localStorage durante jogo
                return;
            }

            console.log('⏳ Auth null detectado — aguardando 3s para confirmar logout...');
            this._logoutDebounceTimer = setTimeout(() => {
                this._logoutDebounceTimer = null;

                // Verificar novamente se user voltou (Firebase pode ter restaurado)
                if (this.auth && this.auth.currentUser) {
                    console.log('✅ Firebase restaurou auth, cancelando logout');
                    return;
                }

                console.log('👋 Logout confirmado após debounce');
                this.currentUser = null;

                localStorage.removeItem('googleUid');
                localStorage.removeItem('userDisplayName');
                localStorage.removeItem('userEmail');
                localStorage.removeItem('userPhotoURL');
                localStorage.removeItem('sessionToken');
                localStorage.removeItem('userData');
                localStorage.removeItem('userIsPremium');

                if (typeof gameState !== 'undefined' && gameState !== null) {
                    gameState.user = null;
                    gameState.googleUid = null;
                    gameState.isConnected = false;
                    gameState.sessionToken = null;
                }

                this.dispatchAuthEvent(null);
            }, 3000);
        }
    }
    
    async signIn() {
        if (!this.auth || !this.provider) {
            await this.init();
            if (!this.auth) {
                throw new Error('Firebase não inicializado');
            }
        }
        
        try {
            console.log('🔐 Tentando login com popup...');
            const result = await this.auth.signInWithPopup(this.provider);
            return result.user;
            
        } catch (error) {
            console.warn('⚠️ Popup falhou:', error.code);
            
            if (error.code === 'auth/popup-blocked' || 
                error.code === 'auth/popup-closed-by-user' ||
                error.code === 'auth/cancelled-popup-request') {
                
                console.log('🔄 Usando redirect como fallback...');
                sessionStorage.setItem('authRedirectPending', 'true');
                await this.auth.signInWithRedirect(this.provider);
                return null;
            }
            
            throw error;
        }
    }
    
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
    
    async signOut() {
        if (!this.auth) {
            throw new Error('Auth não inicializado');
        }

        try {
            // Cancelar debounce — este é logout explícito
            if (this._logoutDebounceTimer) {
                clearTimeout(this._logoutDebounceTimer);
                this._logoutDebounceTimer = null;
            }
            await this.auth.signOut();
            this.currentUser = null;
            return true;
        } catch (error) {
            console.error('❌ Erro ao fazer logout:', error);
            throw error;
        }
    }
    
    getUser() {
        return this.currentUser;
    }
    
    isLoggedIn() {
        return this.currentUser !== null;
    }
    
    getUserId() {
        return this.currentUser?.uid || localStorage.getItem('googleUid') || null;
    }
    
    async syncUserWithBackend(user, retryCount = 0) {
        if (!user) return;
        const MAX_RETRIES = 3;

        try {
            console.log('🔄 Sincronizando com backend...' + (retryCount > 0 ? ` (tentativa ${retryCount + 1})` : ''));

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
                    referral_code: this.getStoredReferralCode()
                })
            });

            // Verificar Content-Type
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

                    if (window.gameState) {
                        window.gameState.sessionToken = result.session_token;
                    }
                }

                // Salvar dados do usuário (usando apenas colunas que existem)
                if (result.user) {
                    const userData = {
                        id: result.user.id,
                        google_uid: result.user.google_uid,
                        email: result.user.email,
                        display_name: result.user.display_name,
                        balance_brl: result.user.balance_brl,
                        total_played: result.user.total_played,
                        total_earned_brl: result.user.total_earned_brl
                    };

                    localStorage.setItem('userData', JSON.stringify(userData));

                    if (window.gameState) {
                        window.gameState.userData = userData;
                        window.gameState.balance_brl = userData.balance_brl || 0;
                    }
                }

                // Limpar referral code do localStorage após registro bem-sucedido
                if (result.is_new_user || result.referral_registered) {
                    this.clearStoredReferralCode();
                }

            } else {
                console.warn('⚠️ Aviso do backend:', result.error);

                if (result.error && result.error.includes('suspensa')) {
                    alert('Sua conta foi suspensa. Entre em contato com o suporte.');
                    await this.signOut();
                }
            }
        } catch (error) {
            console.error('❌ Erro ao sincronizar com backend:', error);
            if (retryCount < MAX_RETRIES) {
                const delay = 2000 * (retryCount + 1);
                console.log(`🔄 Retry sync em ${delay / 1000}s...`);
                setTimeout(() => this.syncUserWithBackend(user, retryCount + 1), delay);
            } else {
                console.error('❌ Falha ao sincronizar após todas as tentativas');
            }
        }
    }
    
    /**
     * Capturar e armazenar código de referral da URL (?ref=ABC123)
     * Chamado na inicialização — armazena no localStorage para uso no login
     */
    captureReferralCode() {
        try {
            const params = new URLSearchParams(window.location.search);
            const refCode = (params.get('ref') || '').trim().toUpperCase();
            
            if (refCode && /^[A-Z0-9]{6}$/.test(refCode)) {
                localStorage.setItem('unobix_referral_code', refCode);
                console.log('🔗 Código de referral capturado:', refCode);
                
                // Limpar ?ref= da URL sem recarregar
                if (window.history.replaceState) {
                    const cleanSearch = window.location.search
                        .replace(/[?&]ref=[^&]+/, '')
                        .replace(/^\?$/, '');
                    const cleanUrl = window.location.pathname + cleanSearch + window.location.hash;
                    window.history.replaceState(null, '', cleanUrl);
                }
            }
        } catch (e) {
            console.error('Erro ao capturar referral:', e);
        }
    }
    
    /**
     * Obter código de referral armazenado
     * Verifica ambas as chaves para compatibilidade com main.js
     */
    getStoredReferralCode() {
        return localStorage.getItem('unobix_referral_code')
            || localStorage.getItem('unobix_referral')
            || localStorage.getItem('referralCode')
            || '';
    }
    
    /**
     * Limpar código de referral após uso
     */
    clearStoredReferralCode() {
        localStorage.removeItem('unobix_referral_code');
        localStorage.removeItem('unobix_referral');
        localStorage.removeItem('unobix_referral_time');
        localStorage.removeItem('referralCode');
        localStorage.removeItem('referralTimestamp');
    }
    
    dispatchAuthEvent(user) {
        const event = new CustomEvent('authStateChanged', {
            detail: { user: user }
        });
        document.dispatchEvent(event);
        
        this.onAuthStateChangedCallbacks.forEach(callback => {
            try {
                callback(user);
            } catch (e) {
                console.error('Erro em callback de auth:', e);
            }
        });
    }
    
    onAuthStateChanged(callback) {
        if (typeof callback === 'function') {
            this.onAuthStateChangedCallbacks.push(callback);
            
            if (this.currentUser !== undefined) {
                callback(this.currentUser);
            }
        }
    }
    
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
    
    // Aliases
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

// Criar instância global
window.authManager = new AuthManager();
window.AuthManager = AuthManager;

console.log('✅ AuthManager v5.0 CLEAN inicializado');

// Verificar redirect result
document.addEventListener('DOMContentLoaded', async () => {
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
