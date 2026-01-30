/* ============================================
   UNOBIX - Firebase Configuration
   Configuração do Firebase para autenticação Google
   ============================================ */

// Configuração do Firebase (substituir pelos valores reais)
const firebaseConfig = {
  apiKey: "AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U",
  authDomain: "unobix-oauth-a69cd.firebaseapp.com",
  projectId: "unobix-oauth-a69cd",
  storageBucket: "unobix-oauth-a69cd.firebasestorage.app",
  messagingSenderId: "1067767347117",
  appId: "1:1067767347117:web:26e1193bdef8e264409324"
};

// Inicializar Firebase
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

console.log('🔥 Firebase inicializado - Unobix');
