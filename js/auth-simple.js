/* ============================================
   UNOBIX - Authentication Simple v1.0
   Sistema de login SIMPLES sem Firebase
   ============================================ */

class AuthSimple {
    constructor() {
        this.currentUser = null;
        this.isInitialized = false;
        this.onAuthStateChangedCallbacks = [];
        
        // Inicializar automaticamente
        this.init();
    }
    
    init() {
        if (this.isInitialized) return;
        
        // Verificar se já está logado
        const googleUid = localStorage.getItem('googleUid');
        const userDisplayName = localStorage.getItem('userDisplayName');
        const userEmail = localStorage.getItem('userEmail');
        
        if (googleUid) {
            this.currentUser = {
                uid: googleUid,
                displayName: userDisplayName || 'Usuário',
                email: userEmail || '',
                photoURL: localStorage.getItem('userPhotoURL') || ''
            };
            console.log('✅ Usuário recuperado do localStorage:', this.currentUser.displayName);
        }
        
        this.isInitialized = true;
        console.log('🔐 AuthSimple inicializado');
        
        // Disparar evento inicial
        this.dispatchAuthEvent(this.currentUser);
    }
    
    // Login com Google (simulado)
    async signIn() {
        console.log('🔐 Iniciando login...');
        
        // Gerar ID único para o usuário
        const googleUid = 'user_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const userEmail = `user_${Date.now()}@unobix.com`;
        const displayName = 'Usuário Unobix';
        
        try {
            // Registrar no backend
            const response = await fetch('api/auth-google.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'login',
                    google_uid: googleUid,
                    email: userEmail,
                    display_name: displayName,
                    photo_url: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(displayName) + '&background=0f0c29&color=fff'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Criar objeto de usuário
                this.currentUser = {
                    uid: googleUid,
                    displayName: displayName,
                    email: userEmail,
                    photoURL: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(displayName) + '&background=0f0c29&color=fff'
                };
                
                // Salvar no localStorage
                localStorage.setItem('googleUid', googleUid);
                localStorage.setItem('userDisplayName', displayName);
                localStorage.setItem('userEmail', userEmail);
                localStorage.setItem('userPhotoURL', this.currentUser.photoURL);
                localStorage.setItem('sessionToken', result.session_token);
                
                console.log('✅ Login bem-sucedido:', displayName);
                
                // Disparar evento
                this.dispatchAuthEvent(this.currentUser);
                
                return this.currentUser;
            } else {
                throw new Error(result.error || 'Erro no backend');
            }
        } catch (error) {
            console.error('❌ Erro no login:', error);
            throw error;
        }
    }
    
    // Login com dados específicos (para testes)
    async loginWithCredentials(googleUid, email, displayName) {
        try {
            const response = await fetch('api/auth-google.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'login',
                    google_uid: googleUid,
                    email: email,
                    display_name: displayName,
                    photo_url: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(displayName) + '&background=0f0c29&color=fff'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.currentUser = {
                    uid: googleUid,
                    displayName: displayName,
                    email: email,
                    photoURL: 'https://ui-avatars.com/api/?name=' + encodeURIComponent(displayName) + '&background=0f0c29&color=fff'
                };
                
                localStorage.setItem('googleUid', googleUid);
                localStorage.setItem('userDisplayName', displayName);
                localStorage.setItem('userEmail', email);
                localStorage.setItem('userPhotoURL', this.currentUser.photoURL);
                localStorage.setItem('sessionToken', result.session_token);
                
                this.dispatchAuthEvent(this.currentUser);
                return this.currentUser;
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            console.error('Erro:', error);
            throw error;
        }
    }
    
    // Logout
    async signOut() {
        this.currentUser = null;
        
        // Limpar localStorage
        localStorage.removeItem('googleUid');
        localStorage.removeItem('userDisplayName');
        localStorage.removeItem('userEmail');
        localStorage.removeItem('userPhotoURL');
        localStorage.removeItem('sessionToken');
        
        console.log('👋 Usuário deslogado');
        
        // Disparar evento
        this.dispatchAuthEvent(null);
        
        return true;
    }
    
    // Verificar se está logado
    isLoggedIn() {
        return this.currentUser !== null;
    }
    
    // Obter usuário atual
    getUser() {
        return this.currentUser;
    }
    
    // Obter ID do usuário
    getUserId() {
        return this.currentUser?.uid || localStorage.getItem('googleUid') || null;
    }
    
    // Obter token de sessão
    getSessionToken() {
        return localStorage.getItem('sessionToken');
    }
    
    // Disparar evento de mudança de autenticação
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
    
    // Registrar callback
    onAuthStateChanged(callback) {
        if (typeof callback === 'function') {
            this.onAuthStateChangedCallbacks.push(callback);
            
            // Chamar imediatamente com estado atual
            if (this.currentUser !== undefined) {
                callback(this.currentUser);
            }
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

// Criar instância global
window.authManager = new AuthSimple();
window.AuthSimple = AuthSimple;

console.log('🔐 AuthSimple carregado - Sistema de login SIMPLES');