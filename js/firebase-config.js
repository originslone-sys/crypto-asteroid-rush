/* ============================================
   UNOBIX - Firebase Configuration v3.0 (SIMPLIFICADO)
   Configuração completa do Firebase (compat mode)
   ============================================ */

// Configuração completa do Firebase
const firebaseConfig = {
  apiKey: "AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U",
  authDomain: "unobix-oauth-a69cd.firebaseapp.com",
  projectId: "unobix-oauth-a69cd",
  storageBucket: "unobix-oauth-a69cd.firebasestorage.app",
  messagingSenderId: "1067767347117",
  appId: "1:1067767347117:web:26e1193bdef8e264409324",
  measurementId: "G-3LQT1LYRG1"
};

// Inicializar Firebase APENAS se já não inicializado
// Usando compat mode para v10.7.1
if (firebase.apps.length === 0) {
    firebase.initializeApp(firebaseConfig);
    console.log('🔥 Firebase v10.7.1 inicializado (compat mode)');
}

// Inicializar Google Analytics (com check de suporte para evitar erro IndexedDB)
if (typeof firebase.analytics === 'function') {
    try {
        // Em ambientes sem IndexedDB (ex: navegadores com storage restrito),
        // analytics lança erro. Verificar suporte antes.
        if (typeof indexedDB !== 'undefined') {
            firebase.analytics();
            console.log('📊 Google Analytics ativo (G-3LQT1LYRG1)');
        } else {
            console.log('📊 Analytics desativado (IndexedDB indisponível)');
        }
    } catch (e) {
        console.log('📊 Analytics não disponível neste ambiente');
    }
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

console.log('🔥 Firebase configurado - Unobix v3.0');
