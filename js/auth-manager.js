/* ============================================
   UNOBIX - Auth Manager
   Gerenciador de autenticação com Google
   ============================================ */

class AuthManager {
    constructor() {
        this.currentUser = null;
        this.sessionToken = null;
        this.isInitialized = false;
        this.listeners = [];
        
        this.init();
    }

    async init() {
        // Observar mudanças no estado de autenticação
        auth.onAuthStateChanged(async (user) => {
            if (user) {
                this.currentUser = user;
                await this.handleUserLogin(user);
            } else {
                this.currentUser = null;
                this.sessionToken = null;
                this.handleUserLogout();
            }
            
            this.isInitialized = true;
            this.notifyListeners();
        });
    }

    // Login com Google
    async loginWithGoogle() {
        try {
            const result = await auth.signInWithPopup(googleProvider);
            return { success: true, user: result.user };
        } catch (error) {
            console.error('Erro no login:', error);
            
            // Tratar erros específicos
            let message = 'Erro ao fazer login. Tente novamente.';
            
            switch (error.code) {
                case 'auth/popup-closed-by-user':
                    message = 'Login cancelado.';
                    break;
                case 'auth/popup-blocked':
                    message = 'Popup bloqueado. Permita popups e tente novamente.';
                    break;
                case 'auth/network-request-failed':
                    message = 'Erro de conexão. Verifique sua internet.';
                    break;
                case 'auth/cancelled-popup-request':
                    message = 'Requisição cancelada.';
                    break;
            }
            
            return { success: false, error: message };
        }
    }

    // Login com redirect (alternativa para mobile)
    async loginWithRedirect() {
        try {
            await auth.signInWithRedirect(googleProvider);
        } catch (error) {
            console.error('Erro no redirect:', error);
            return { success: false, error: 'Erro ao redirecionar para login.' };
        }
    }

    // Processar login do usuário
    async handleUserLogin(user) {
        try {
            // Registrar/verificar usuário no backend
            const response = await fetch('/api/auth-google.php', {
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

            const data = await response.json();

            if (data.success) {
                this.sessionToken = data.session_token;
                
                // Verificar código de referral na URL
                this.checkReferralCode(user.uid);
                
                console.log('✅ Usuário autenticado:', user.displayName);
            } else {
                console.error('Erro ao registrar no backend:', data.error);
            }
        } catch (error) {
            console.error('Erro ao processar login:', error);
        }
    }

    // Verificar código de referral
    async checkReferralCode(googleUid) {
        const urlParams = new URLSearchParams(window.location.search);
        const refCode = urlParams.get('ref');
        
        if (refCode) {
            try {
                await fetch('/api/referral-register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        google_uid: googleUid,
                        referral_code: refCode
                    })
                });
                
                // Limpar URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } catch (error) {
                console.error('Erro ao registrar referral:', error);
            }
        }
    }

    // Processar logout
    handleUserLogout() {
        this.sessionToken = null;
        console.log('👋 Usuário deslogado');
    }

    // Logout
    async logout() {
        try {
            // Notificar backend
            if (this.currentUser) {
                await fetch('/api/auth-google.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'logout',
                        google_uid: this.currentUser.uid,
                        session_token: this.sessionToken
                    })
                });
            }
            
            await auth.signOut();
            return { success: true };
        } catch (error) {
            console.error('Erro no logout:', error);
            return { success: false, error: 'Erro ao fazer logout.' };
        }
    }

    // Verificar se está logado
    isLoggedIn() {
        return this.currentUser !== null;
    }

    // Obter dados do usuário
    getUserData() {
        if (!this.currentUser) return null;
        
        return {
            uid: this.currentUser.uid,
            email: this.currentUser.email,
            displayName: this.currentUser.displayName,
            photoURL: this.currentUser.photoURL
        };
    }

    // Obter UID do Google
    getGoogleUid() {
        return this.currentUser?.uid || null;
    }

    // Obter token de sessão
    getSessionToken() {
        return this.sessionToken;
    }

    // Adicionar listener para mudanças de auth
    addListener(callback) {
        this.listeners.push(callback);
        
        // Se já inicializado, chamar imediatamente
        if (this.isInitialized) {
            callback(this.currentUser);
        }
    }

    // Remover listener
    removeListener(callback) {
        this.listeners = this.listeners.filter(l => l !== callback);
    }

    // Notificar listeners
    notifyListeners() {
        // Disparar evento customizado
        const event = new CustomEvent('authStateChanged', {
            detail: { user: this.currentUser }
        });
        document.dispatchEvent(event);
        
        // Chamar listeners registrados
        this.listeners.forEach(callback => {
            try {
                callback(this.currentUser);
            } catch (error) {
                console.error('Erro em listener de auth:', error);
            }
        });
    }

    // Verificar sessão válida no backend
    async verifySession() {
        if (!this.currentUser || !this.sessionToken) {
            return false;
        }

        try {
            const response = await fetch('/api/auth-google.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'check_session',
                    google_uid: this.currentUser.uid,
                    session_token: this.sessionToken
                })
            });

            const data = await response.json();
            return data.success && data.valid;
        } catch (error) {
            console.error('Erro ao verificar sessão:', error);
            return false;
        }
    }

    // Obter perfil completo do backend
    async getProfile() {
        if (!this.currentUser) return null;

        try {
            const response = await fetch('/api/auth-google.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'profile',
                    google_uid: this.currentUser.uid
                })
            });

            const data = await response.json();
            return data.success ? data.player : null;
        } catch (error) {
            console.error('Erro ao obter perfil:', error);
            return null;
        }
    }
}

// Criar instância global
window.authManager = new AuthManager();

console.log('🔐 AuthManager inicializado');
