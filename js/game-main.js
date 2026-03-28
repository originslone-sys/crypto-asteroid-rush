/* ============================================
   UNOBIX - Main Entry Point v4.1
   File: js/game-main.js
   Google Auth, Portuguese, Free-to-Play
   Fix: Removidas referências a MetaMask
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
        // Premium: pular anúncios, ir direto pro jogo
        if (window._userIsPremium) {
            window.location.href = 'game.html?start=true';
        } else {
            // Redirecionar para página de loading pré-jogo (com anúncios)
            window.location.href = 'pregame.html';
        }
        
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

// Main initialization
document.addEventListener('DOMContentLoaded', () => {
    console.log('🎮 Unobix v4.1 - Inicializando...');

    // Carregar flag premium do localStorage
    if (!window._userIsPremium && localStorage.getItem('userIsPremium') === '1') {
        window._userIsPremium = true;
    }

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
    
    // Listen for auth state changes
    document.addEventListener('authStateChanged', (e) => {
        const user = e.detail.user;

        if (user) {
            gameState.user = user;
            gameState.googleUid = user.uid;
            gameState.isConnected = true;

            updateUserUI(user);

            // Se a UI já foi mostrada pelo cache, não repetir navegação
            if (window._uiAlreadyShown) {
                window._uiAlreadyShown = false;
                return;
            }

            _handlePostAuthNavigation();
        } else {
            // Not logged in, show connect modal
            if (!window._uiAlreadyShown) {
                showModal('connectModal');
            }
        }
    });

    // Carregamento instantâneo: usar cache do localStorage enquanto Firebase inicializa
    const cachedUid = localStorage.getItem('googleUid');
    if (cachedUid && cachedUid.length > 10) {
        console.log('⚡ Auth cache: carregando UI instantaneamente');
        gameState.googleUid = cachedUid;
        gameState.isConnected = true;

        // Mostrar UI com dados em cache
        const cachedUser = {
            uid: cachedUid,
            displayName: localStorage.getItem('userDisplayName') || '',
            email: localStorage.getItem('userEmail') || '',
            photoURL: localStorage.getItem('userPhotoURL') || ''
        };
        updateUserUI(cachedUser);
        window._uiAlreadyShown = true;
        _handlePostAuthNavigation();
    }

    // Fallback: se o authStateChanged já disparou antes do listener acima,
    // o evento foi perdido. Verificar se auth já está pronto.
    if (window.authManager?.currentUser) {
        const user = window.authManager.currentUser;
        console.log('🔐 Auth já pronto (fallback), disparando manualmente');
        document.dispatchEvent(new CustomEvent('authStateChanged', { detail: { user } }));
    }

    // Lógica de navegação pós-auth (reutilizada por cache e auth real)
    function _handlePostAuthNavigation() {
        const params = new URLSearchParams(window.location.search);
        const shouldStart = params.get('start') === 'true';
        const loadingComplete = sessionStorage.getItem('loadingComplete') === 'true';
        const showResults = params.get('results') === 'true';
        const postgameComplete = sessionStorage.getItem('postgameComplete') === 'true';

        if (showResults && postgameComplete) {
            sessionStorage.removeItem('postgameComplete');
            window.history.replaceState({}, '', 'game.html');

            try {
                const pgData = JSON.parse(sessionStorage.getItem('postgameData') || '{}');
                sessionStorage.removeItem('postgameData');

                const pendingCaptcha = sessionStorage.getItem('pendingCaptchaSession');
                if (pendingCaptcha && typeof SessionManager !== 'undefined') {
                    try {
                        SessionManager.pendingEndSession = JSON.parse(pendingCaptcha);
                        console.log('🔐 Sessão pendente de CAPTCHA restaurada');
                    } catch (ce) {
                        console.warn('⚠️ Erro ao restaurar sessão CAPTCHA:', ce);
                    }
                    sessionStorage.removeItem('pendingCaptchaSession');
                }

                if (pgData.stats && typeof showEndGameResults === 'function') {
                    console.log('📊 Exibindo resultados do postgame');
                    sessionStorage.setItem('_showResultsDirect', 'true');
                    showEndGameResults(pgData.stats, pgData.serverEarnings, pgData.serverBalance);
                } else {
                    showModal('gameMenuModal');
                }
            } catch (e) {
                console.error('Erro ao restaurar resultados:', e);
                showModal('gameMenuModal');
            }
        } else if (shouldStart && loadingComplete) {
            sessionStorage.removeItem('loadingComplete');
            window.history.replaceState({}, '', 'game.html');
            console.log('🎮 Auto-starting game');
            setTimeout(() => {
                if (typeof startGameWithLoading === 'function') {
                    startGameWithLoading();
                }
            }, 500);
        } else {
            showModal('gameMenuModal');
        }
    }

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
