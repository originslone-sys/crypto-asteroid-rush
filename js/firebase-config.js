/* ============================================
   UNOBIX - Firebase Configuration
   Configuração do Firebase para autenticação Google
   ============================================ */

// Configuração do Firebase (substituir pelos valores reais)
const firebaseConfig = {
    apiKey: "YOUR_API_KEY",
    authDomain: "unobix-app.firebaseapp.com",
    projectId: "unobix-app",
    storageBucket: "unobix-app.appspot.com",
    messagingSenderId: "YOUR_SENDER_ID",
    appId: "YOUR_APP_ID"
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
