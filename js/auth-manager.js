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
        this.currentUser = user;

        if (user) {
            console.log('✅ Usuário autenticado:', user.displayName || user.email);

            // Limpar flags de redirect
            sessionStorage.removeItem('authRedirectPending');

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
            // Durante redirect, onAuthStateChanged dispara com null transitório.
            // Não limpar localStorage imediatamente — esperar para confirmar.
            if (sessionStorage.getItem('authRedirectPending') === 'true') {
                console.log('🔄 Redirect pendente, ignorando null transitório');
                return;
            }

            // Debounce: esperar 2s antes de limpar, caso o Firebase restaure a sessão
            if (this._logoutTimer) clearTimeout(this._logoutTimer);
            this._logoutTimer = setTimeout(() => {
                // Verificar de novo se o usuário voltou nesse intervalo
                if (this.currentUser) return;

                console.log('👋 Usuário deslogado');

                localStorage.removeItem('googleUid');
                localStorage.removeItem('userDisplayName');
                localStorage.removeItem('userEmail');
                localStorage.removeItem('userPhotoURL');
                localStorage.removeItem('sessionToken');
                localStorage.removeItem('userData');

                if (typeof gameState !== 'undefined' && gameState !== null) {
                    gameState.user = null;
                    gameState.googleUid = null;
                    gameState.isConnected = false;
                    gameState.sessionToken = null;
                }

                this.dispatchAuthEvent(null);
            }, 2000);

            return; // Não disparar evento ainda — o debounce cuida
        }
    }
    
    async signIn() {
        if (!this.auth || !this.provider) {
            await this.init();
            if (!this.auth) {
                throw new Error('Firebase não inicializado');
            }
        }

        // Mobile/tablet → redirect direto (popups são instáveis em mobile)
        if (this._shouldUseRedirect()) {
            console.log('🔐 Login via redirect (mobile/COOP)...');
            sessionStorage.setItem('authRedirectPending', 'true');
            await this.auth.signInWithRedirect(this.provider);
            return null;
        }

        // Desktop → tentar popup com timeout de 30s, qualquer falha → redirect
        try {
            console.log('🔐 Tentando login com popup...');

            const popupPromise = this.auth.signInWithPopup(this.provider);
            const timeoutPromise = new Promise((_, reject) =>
                setTimeout(() => reject({ code: 'auth/popup-timeout' }), 30000)
            );

            const result = await Promise.race([popupPromise, timeoutPromise]);
            return result.user;

        } catch (error) {
            // Qualquer falha no popup → redirect como fallback universal
            console.warn('⚠️ Popup falhou, usando redirect:', error.code || error.message);
            sessionStorage.setItem('authRedirectPending', 'true');
            sessionStorage.setItem('popupFailed', 'true');
            await this.auth.signInWithRedirect(this.provider);
            return null;
        }
    }

    // Detectar se deve usar redirect em vez de popup
    _shouldUseRedirect() {
        // Mobile/tablet → redirect sempre
        if (/Android|iPhone|iPad|iPod|Opera Mini|IEMobile/i.test(navigator.userAgent)) return true;
        // Popup já falhou nesta sessão → não tentar de novo
        if (sessionStorage.getItem('popupFailed')) return true;
        return false;
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

                if (result.error_code === 'VPN_PROXY_DETECTED') {
                    alert('VPN ou Proxy detectado! Por segurança, desative sua VPN/Proxy e tente novamente. O uso de VPN/Proxy não é permitido.');
                    await this.signOut();
                    return;
                }

                if (result.error_code === 'IP_ACCOUNT_LIMIT') {
                    alert('Já existe uma conta conectada neste dispositivo/rede. Apenas uma conta por IP é permitida. Faça logout da outra conta primeiro.');
                    await this.signOut();
                    return;
                }

                if (result.error && result.error.includes('suspensa')) {
                    alert('Sua conta foi suspensa. Entre em contato com o suporte.');
                    await this.signOut();
                }
            }
        } catch (error) {
            console.error('❌ Erro ao sincronizar com backend:', error);
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

// Verificar redirect result SEMPRE no load (não apenas quando flag existe)
// getRedirectResult() retorna null seguramente se não houve redirect
document.addEventListener('DOMContentLoaded', async () => {
    // Esperar authManager inicializar
    const waitForAuth = () => new Promise((resolve) => {
        if (window.authManager?.auth) return resolve();
        const check = setInterval(() => {
            if (window.authManager?.auth) { clearInterval(check); resolve(); }
        }, 100);
        setTimeout(() => { clearInterval(check); resolve(); }, 5000);
    });

    await waitForAuth();
    if (!window.authManager?.auth) return;

    try {
        console.log('🔄 Verificando resultado de redirect...');
        const result = await window.authManager.auth.getRedirectResult();
        if (result?.user) {
            console.log('✅ Login via redirect bem-sucedido:', result.user.displayName);
            sessionStorage.removeItem('authRedirectPending');
            sessionStorage.removeItem('popupFailed');
        }
    } catch (error) {
        // Erros comuns: auth/credential-already-in-use, network errors
        console.warn('🔄 Redirect result:', error.code || error.message);
        sessionStorage.removeItem('authRedirectPending');
    }
});
