/* ============================================
   UNOBIX - Firebase Configuration
   Configuração completa do Firebase
   ============================================ */

// Configuração completa do Firebase
const firebaseConfig = {
  apiKey: "AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U",
  authDomain: "unobix-oauth-a69cd.firebaseapp.com",
  projectId: "unobix-oauth-a69cd",
  storageBucket: "unobix-oauth-a69cd.firebasestorage.app",
  messagingSenderId: "1067767347117",
  appId: "1:1067767347117:web:26e1193bdef8e264409324"
};

// Inicializar Firebase APENAS se já não inicializado
if (!firebase.apps.length) {
    firebase.initializeApp(firebaseConfig);
}

// Exportar auth para uso global
const auth = firebase.auth();

// Configurar persistência local (mantém login entre sessões)
auth.setPersistence(firebase.auth.Auth.Persistence.LOCAL)
    .catch((error) => {
        console.error('Erro ao configurar persistência:', error);
    });

// Provider do Google
const googleProvider = new firebase.auth.GoogleAuthProvider();
googleProvider.addScope('email');
googleProvider.addScope('profile');

// Configurar idioma para português
auth.languageCode = 'pt-BR';

// ============================================
// FUNÇÃO DE AUTENTICAÇÃO SEGURA (SERVER-SIDE)
// ============================================

/**
 * Autenticação segura via server-side
 * @param {string} firebaseToken - Token do Firebase
 * @returns {Promise<Object>} - Dados do usuário e sessão
 */
async function secureFirebaseAuth(firebaseToken) {
    try {
        const response = await fetch('/api/auth-firebase.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                firebase_token: firebaseToken
            })
        });
        
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.error || 'Erro na autenticação');
        }
        
        return await response.json();
        
    } catch (error) {
        console.error('❌ Erro na autenticação server-side:', error);
        throw error;
    }
}

/**
 * Login com Google usando autenticação server-side segura
 * @returns {Promise<Object>} - Dados do usuário autenticado
 */
async function loginWithGoogleSecure() {
    try {
        // 1. Obter token do Firebase (client-side)
        const result = await auth.signInWithPopup(googleProvider);
        const user = result.user;
        const firebaseToken = await user.getIdToken();
        
        // 2. Verificar token no server-side
        const authResult = await secureFirebaseAuth(firebaseToken);
        
        // 3. Salvar sessão localmente
        localStorage.setItem('user_session', JSON.stringify(authResult.session));
        localStorage.setItem('user_data', JSON.stringify(authResult.user));
        
        console.log('✅ Login seguro realizado:', authResult.user.email);
        
        return authResult;
        
    } catch (error) {
        console.error('❌ Erro no login seguro:', error);
        
        // Logout do Firebase em caso de erro
        await auth.signOut();
        localStorage.removeItem('user_session');
        localStorage.removeItem('user_data');
        
        throw error;
    }
}

/**
 * Verificar sessão existente
 * @returns {Promise<Object|null>} - Dados da sessão ou null
 */
async function checkExistingSession() {
    try {
        const sessionData = localStorage.getItem('user_session');
        const userData = localStorage.getItem('user_data');
        
        if (!sessionData || !userData) {
            return null;
        }
        
        const session = JSON.parse(sessionData);
        const user = JSON.parse(userData);
        
        // Verificar se sessão ainda é válida (opcional - pode fazer ping no servidor)
        // Por enquanto, assumimos que é válida se existe no localStorage
        
        return { user, session };
        
    } catch (error) {
        console.error('❌ Erro ao verificar sessão:', error);
        return null;
    }
}

/**
 * Logout seguro
 */
async function logoutSecure() {
    try {
        // 1. Logout do Firebase
        await auth.signOut();
        
        // 2. Limpar dados locais
        localStorage.removeItem('user_session');
        localStorage.removeItem('user_data');
        
        // 3. Redirecionar para página inicial
        window.location.href = '/index.html';
        
    } catch (error) {
        console.error('❌ Erro no logout:', error);
    }
}

// ============================================
// EXPORTAR FUNÇÕES PARA USO GLOBAL
// ============================================
window.UnobixAuth = {
    loginWithGoogleSecure,
    checkExistingSession,
    logoutSecure,
    auth, // Firebase auth instance (para uso limitado)
    googleProvider
};

console.log('🔥 Firebase configurado (modo seguro) - Unobix');