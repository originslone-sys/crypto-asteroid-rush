/* ============================================
   UNOBIX - Main Entry Point v4.2
   File: js/game-main.js
   
   MUDANÇAS v4.2:
   - CRÍTICO: Resolver race condition do auth listener
   - Auto-start agora usa waitForAuth() com polling
   - Flags consumidas ANTES de qualquer processamento
   - Evita loop de modais no mobile
   ============================================ */

// Keyboard controls
function handleKeyDown(e) {
    if (!gameState.gameActive) return;
    
    switch(e.key) {
        case 'ArrowLeft':
        case 'a':
        case 'A':
            gameState.keys.left = true;
            break;
        case 'ArrowRight':
        case 'd':
        case 'D':
            gameState.keys.right = true;
            break;
        case ' ':
        case 'Spacebar':
            e.preventDefault();
            gameState.keys.fire = true;
            break;
    }
}

function handleKeyUp(e) {
    switch(e.key) {
        case 'ArrowLeft':
        case 'a':
        case 'A':
            gameState.keys.left = false;
            break;
        case 'ArrowRight':
        case 'd':
        case 'D':
            gameState.keys.right = false;
            break;
        case ' ':
        case 'Spacebar':
            gameState.keys.fire = false;
            break;
    }
}

// Touch controls
let touchIntervals = {
    left: null,
    right: null
};

function startTouchMove(direction) {
    if (!gameState.gameActive || !gameState.ship) return;
    
    const speed = CONFIG.SHIP_SPEED;
    const minX = 50;
    const maxX = canvas.width - 50;
    
    if (touchIntervals[direction]) {
        clearInterval(touchIntervals[direction]);
    }
    
    if (direction === 'left') {
        gameState.ship.x = Math.max(minX, gameState.ship.x - speed);
    } else if (direction === 'right') {
        gameState.ship.x = Math.min(maxX, gameState.ship.x + speed);
    }
    
    touchIntervals[direction] = setInterval(() => {
        if (!gameState.gameActive || !gameState.ship) {
            clearInterval(touchIntervals[direction]);
            return;
        }
        
        if (direction === 'left') {
            gameState.ship.x = Math.max(minX, gameState.ship.x - speed);
        } else if (direction === 'right') {
            gameState.ship.x = Math.min(maxX, gameState.ship.x + speed);
        }
    }, 50);
}

function stopTouchMove(direction) {
    if (touchIntervals[direction]) {
        clearInterval(touchIntervals[direction]);
        touchIntervals[direction] = null;
    }
}

function setupMobileControls() {
    const leftBtn = document.getElementById('leftBtn');
    const rightBtn = document.getElementById('rightBtn');
    const fireBtn = document.getElementById('fireBtn');
    
    if (leftBtn) {
        leftBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            if (typeof unlockAudio === 'function') unlockAudio();
            startTouchMove('left');
        }, { passive: false });
        
        leftBtn.addEventListener('touchend', (e) => {
            e.preventDefault();
            stopTouchMove('left');
        }, { passive: false });
        
        leftBtn.addEventListener('touchcancel', () => stopTouchMove('left'));
        
        leftBtn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startTouchMove('left');
        });
        
        leftBtn.addEventListener('mouseup', () => stopTouchMove('left'));
        leftBtn.addEventListener('mouseleave', () => stopTouchMove('left'));
    }
    
    if (rightBtn) {
        rightBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            if (typeof unlockAudio === 'function') unlockAudio();
            startTouchMove('right');
        }, { passive: false });
        
        rightBtn.addEventListener('touchend', (e) => {
            e.preventDefault();
            stopTouchMove('right');
        }, { passive: false });
        
        rightBtn.addEventListener('touchcancel', () => stopTouchMove('right'));
        
        rightBtn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startTouchMove('right');
        });
        
        rightBtn.addEventListener('mouseup', () => stopTouchMove('right'));
        rightBtn.addEventListener('mouseleave', () => stopTouchMove('right'));
    }
    
    if (fireBtn) {
        let fireInterval = null;
        
        const startFiring = () => {
            if (typeof unlockAudio === 'function') unlockAudio();
            if (typeof fireBullet === 'function') fireBullet();
            
            fireInterval = setInterval(() => {
                if (gameState.gameActive && typeof fireBullet === 'function') {
                    fireBullet();
                } else {
                    clearInterval(fireInterval);
                }
            }, CONFIG.FIRE_RATE);
        };
        
        const stopFiring = () => {
            if (fireInterval) {
                clearInterval(fireInterval);
                fireInterval = null;
            }
        };
        
        fireBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            startFiring();
        }, { passive: false });
        
        fireBtn.addEventListener('touchend', (e) => {
            e.preventDefault();
            stopFiring();
        }, { passive: false });
        
        fireBtn.addEventListener('touchcancel', stopFiring);
        
        fireBtn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startFiring();
        });
        
        fireBtn.addEventListener('mouseup', stopFiring);
        fireBtn.addEventListener('mouseleave', stopFiring);
    }
}

// Start game session (Google Auth - no payment needed)
async function startGameSession() {
    const user = window.authManager?.currentUser;
    const googleUid = user?.uid || gameState.googleUid;
    
    if (!googleUid) {
        await gameAlert('Você precisa estar logado!', 'error', 'ERRO');
        showModal('connectModal');
        return;
    }
    
    const startBtn = document.getElementById('startGameBtn');
    if (!startBtn) return;
    
    const originalText = startBtn.innerHTML;
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Preparando...</span>';
    
    try {
        // Redirecionar para página de loading pré-jogo (com anúncios)
        window.location.href = 'pregame.html';
        
    } catch (error) {
        console.error('❌ Erro:', error);
        startBtn.disabled = false;
        startBtn.innerHTML = originalText;
        await gameAlert(error.message || 'Erro ao iniciar missão', 'error', 'ERRO');
    }
}

// Update user UI
function updateUserUI(user) {
    const userPhoto = document.getElementById('userPhoto');
    const userName = document.getElementById('userName');
    const userInfo = document.getElementById('connectedUserInfo');
    
    if (user) {
        if (userPhoto) {
            userPhoto.src = user.photoURL || '';
            userPhoto.style.display = user.photoURL ? 'block' : 'none';
        }
        if (userName) {
            userName.textContent = user.displayName || user.email?.split('@')[0] || 'Comandante';
        }
        if (userInfo) {
            userInfo.style.display = 'flex';
        }
    } else {
        if (userInfo) {
            userInfo.style.display = 'none';
        }
    }
}

// ============================================
// NOVA ABORDAGEM: waitForAuth com polling
// Resolve race condition do Firebase
// ============================================

/**
 * Aguardar autenticação com timeout
 * @param {number} maxWait - Tempo máximo de espera em ms
 * @returns {Promise<object|null>} - User object ou null
 */
async function waitForAuth(maxWait = 5000) {
    const start = Date.now();
    
    while (Date.now() - start < maxWait) {
        // Verificar múltiplas fontes
        const user = window.authManager?.currentUser;
        
        if (user && user.uid) {
            console.log('✅ Auth resolvido:', user.displayName || user.email);
            return user;
        }
        
        // Aguardar 100ms antes de verificar novamente
        await new Promise(r => setTimeout(r, 100));
    }
    
    console.warn('⚠️ Timeout aguardando auth após', maxWait, 'ms');
    return null;
}

/**
 * Processar auto-start (vindo do pregame.html)
 * @returns {Promise<boolean>} - true se auto-start foi executado
 */
async function handleAutoStart() {
    const params = new URLSearchParams(window.location.search);
    const shouldStart = params.get('start') === 'true';
    const loadingComplete = sessionStorage.getItem('loadingComplete') === 'true';
    
    if (!shouldStart || !loadingComplete) {
        return false;
    }
    
    console.log('🎮 Detectado retorno do pregame, iniciando auto-start...');
    
    // CRÍTICO: Consumir flags IMEDIATAMENTE
    window.history.replaceState({}, '', 'game.html');
    sessionStorage.removeItem('loadingComplete');
    
    // Mostrar loading enquanto aguarda auth
    if (typeof showLoading === 'function') {
        showLoading(true);
    }
    
    // Aguardar auth com timeout
    const user = await waitForAuth(5000);
    
    if (typeof showLoading === 'function') {
        showLoading(false);
    }
    
    if (user) {
        // Atualizar gameState
        gameState.user = user;
        gameState.googleUid = user.uid;
        gameState.isConnected = true;
        
        updateUserUI(user);
        
        // Iniciar jogo
        if (typeof startGameWithLoading === 'function') {
            startGameWithLoading();
        }
        return true;
    } else {
        // Auth falhou, mostrar login
        console.warn('⚠️ Auth não disponível, mostrando login');
        showModal('connectModal');
        return false;
    }
}

/**
 * Processar retorno do postgame.html (mostrar resultados)
 * @returns {Promise<boolean>} - true se resultados foram exibidos
 */
async function handlePostgameResults() {
    const params = new URLSearchParams(window.location.search);
    const showResults = params.get('results') === 'true';
    const postgameComplete = sessionStorage.getItem('postgameComplete') === 'true';
    
    if (!showResults || !postgameComplete) {
        return false;
    }
    
    console.log('📊 Detectado retorno do postgame, exibindo resultados...');
    
    // Consumir flags
    window.history.replaceState({}, '', 'game.html');
    sessionStorage.removeItem('postgameComplete');
    
    // Aguardar auth
    const user = await waitForAuth(3000);
    
    if (user) {
        gameState.user = user;
        gameState.googleUid = user.uid;
        gameState.isConnected = true;
        updateUserUI(user);
    }
    
    try {
        const pgData = JSON.parse(sessionStorage.getItem('postgameData') || '{}');
        sessionStorage.removeItem('postgameData');
        
        if (pgData.stats && typeof showEndGameResults === 'function') {
            sessionStorage.setItem('_showResultsDirect', 'true');
            showEndGameResults(pgData.stats, pgData.serverEarnings, pgData.serverBalance);
            return true;
        }
    } catch (e) {
        console.error('Erro ao restaurar resultados:', e);
    }
    
    showModal('gameMenuModal');
    return false;
}

/**
 * Handler para auth state change (fluxo normal, sem auto-start)
 */
function handleNormalAuthChange(user) {
    if (user) {
        gameState.user = user;
        gameState.googleUid = user.uid;
        gameState.isConnected = true;
        
        updateUserUI(user);
        showModal('gameMenuModal');
    } else {
        showModal('connectModal');
    }
}

// ============================================
// MAIN INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', async () => {
    console.log('🎮 Unobix v4.2 - Inicializando...');
    
    // Initialize UI elements
    if (typeof initUIElements === 'function') {
        initUIElements();
    }
    
    if (typeof initCanvas === 'function') {
        initCanvas();
    }
    
    // Load audio preference
    const savedAudio = localStorage.getItem('audioEnabled');
    if (savedAudio !== null) {
        gameState.audioEnabled = savedAudio === 'true';
        const audioIcon = document.getElementById('audioIcon');
        if (audioIcon) {
            audioIcon.className = gameState.audioEnabled ? 'fas fa-volume-up' : 'fas fa-volume-mute';
        }
    }
    
    // Connect button (Google Auth)
    const connectBtn = document.getElementById('connectBtn');
    if (connectBtn) {
        connectBtn.addEventListener('click', async () => {
            if (typeof unlockAudio === 'function') unlockAudio();
            
            if (window.authManager) {
                try {
                    if (typeof showLoading === 'function') showLoading(true);
                    
                    const user = await window.authManager.signIn();
                    
                    if (user) {
                        gameState.user = user;
                        gameState.googleUid = user.uid;
                        gameState.isConnected = true;
                        
                        updateUserUI(user);
                        showModal('gameMenuModal');
                        
                        if (typeof showNotification === 'function') {
                            showNotification('CONECTADO', 'Bem-vindo, ' + (user.displayName || 'Comandante') + '!');
                        }
                    }
                } catch (error) {
                    console.error('Erro no login:', error);
                    if (typeof gameAlert === 'function') {
                        await gameAlert('Erro ao fazer login: ' + error.message, 'error', 'ERRO');
                    }
                } finally {
                    if (typeof showLoading === 'function') showLoading(false);
                }
            } else {
                console.error('AuthManager não disponível');
            }
        });
    }
    
    // Audio toggle
    const audioToggle = document.getElementById('audioToggle');
    if (audioToggle && typeof toggleAudio === 'function') {
        audioToggle.addEventListener('click', toggleAudio);
    }
    
    // Add ship selection UI
    if (typeof addShipSelectionUI === 'function') {
        addShipSelectionUI();
    }
    
    // Start game button
    const startGameBtn = document.getElementById('startGameBtn');
    if (startGameBtn) {
        startGameBtn.addEventListener('click', async function() {
            console.log('🎯 Botão "Iniciar Missão" clicado');
            if (typeof unlockAudio === 'function') unlockAudio();
            
            // Check if user is logged in
            if (!gameState.user && !gameState.googleUid) {
                await gameAlert('Você precisa fazer login primeiro!', 'warning', 'LOGIN NECESSÁRIO');
                showModal('connectModal');
                return;
            }
            
            // Check if ship selected
            if (!gameState.selectedShipDesign) {
                const useRandom = await gameConfirm(
                    'Você não selecionou uma nave.\nDeseja usar uma nave aleatória?',
                    'NENHUMA NAVE SELECIONADA'
                );
                
                if (!useRandom) {
                    if (typeof showNotification === 'function') {
                        showNotification('AVISO', 'Selecione uma nave primeiro!', true);
                    }
                    return;
                }
            }
            
            await startGameSession();
        });
    }
    
    // Play again button
    const playAgainBtn = document.getElementById('playAgainBtn');
    if (playAgainBtn) {
        playAgainBtn.addEventListener('click', () => {
            if (typeof unlockAudio === 'function') unlockAudio();
            showModal('gameMenuModal');
            if (typeof addShipSelectionUI === 'function') addShipSelectionUI();
        });
    }
    
    // Retry button (game over)
    const retryBtn = document.getElementById('retryBtn');
    if (retryBtn) {
        retryBtn.addEventListener('click', () => {
            if (typeof unlockAudio === 'function') unlockAudio();
            showModal('gameMenuModal');
            if (typeof addShipSelectionUI === 'function') addShipSelectionUI();
        });
    }
    
    // Exit button
    const exitBtn = document.getElementById('exitBtn');
    if (exitBtn) {
        exitBtn.addEventListener('click', () => {
            window.location.href = 'index.html';
        });
    }
    
    // Wallet button
    const walletBtn = document.getElementById('walletBtn');
    if (walletBtn) {
        walletBtn.addEventListener('click', () => {
            window.location.href = 'wallet.html';
        });
    }
    
    // Claim reward button (end game modal)
    const claimRewardBtn = document.getElementById('claimRewardBtn');
    if (claimRewardBtn) {
        claimRewardBtn.addEventListener('click', async () => {
            const btn = claimRewardBtn;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
            
            // Show success after brief delay
            setTimeout(() => {
                btn.style.display = 'none';
                const playAgain = document.getElementById('playAgainBtn');
                const wallet = document.getElementById('walletBtn');
                if (playAgain) playAgain.style.display = 'inline-flex';
                if (wallet) wallet.style.display = 'inline-flex';
                
                if (typeof showNotification === 'function') {
                    showNotification('✅ RESGATADO', 'Ganhos adicionados ao saldo!');
                }
            }, 1500);
        });
    }
    
    // Keyboard controls
    window.addEventListener('keydown', (e) => {
        if (typeof unlockAudio === 'function') unlockAudio();
        handleKeyDown(e);
    });
    
    window.addEventListener('keyup', handleKeyUp);
    
    // Canvas click to fire
    const gameCanvas = document.getElementById('gameCanvas');
    if (gameCanvas) {
        gameCanvas.addEventListener('click', () => {
            if (typeof unlockAudio === 'function') unlockAudio();
            if (gameState.gameActive && typeof fireBullet === 'function') {
                fireBullet();
            }
        });
    }
    
    // Setup mobile controls
    setupMobileControls();
    
    // ============================================
    // FLUXO PRINCIPAL DE INICIALIZAÇÃO
    // ============================================
    
    // 1. Verificar se é retorno do postgame (prioridade máxima)
    const handledPostgame = await handlePostgameResults();
    if (handledPostgame) {
        console.log('✅ Resultados do postgame exibidos');
        return;
    }
    
    // 2. Verificar se é auto-start (vindo do pregame)
    const handledAutoStart = await handleAutoStart();
    if (handledAutoStart) {
        console.log('✅ Auto-start executado');
        return;
    }
    
    // 3. Fluxo normal: escutar auth state
    console.log('🔄 Fluxo normal: aguardando auth state...');
    
    document.addEventListener('authStateChanged', (e) => {
        const user = e.detail.user;
        handleNormalAuthChange(user);
    });
    
    // Unlock audio on any interaction
    const unlockOnInteraction = () => {
        if (typeof unlockAudio === 'function' && typeof isAudioUnlocked !== 'undefined' && !isAudioUnlocked) {
            unlockAudio();
        }
    };
    
    document.addEventListener('click', unlockOnInteraction);
    document.addEventListener('touchstart', unlockOnInteraction);
    
    // Remove loading screen
    setTimeout(() => {
        if (typeof showLoading === 'function') {
            showLoading(false);
        }
        console.log('✅ Jogo inicializado com sucesso');
    }, 1500);
});

// Prevent context menu on game
document.addEventListener('contextmenu', (e) => {
    if (gameState.gameActive) {
        e.preventDefault();
    }
});

// Handle visibility change
document.addEventListener('visibilitychange', () => {
    if (document.hidden && gameState.gameActive) {
        gameState.keys = { left: false, right: false, fire: false };
        stopTouchMove('left');
        stopTouchMove('right');
    }
});

// Export functions
window.startGameSession = startGameSession;
window.updateUserUI = updateUserUI;
window.handleKeyDown = handleKeyDown;
window.handleKeyUp = handleKeyUp;
window.waitForAuth = waitForAuth;

console.log('📦 game-main.js v4.2 carregado (race condition fix)');
