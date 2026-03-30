/* ============================================
   UNOBIX - Main Entry Point v5.0
   File: js/game-main-v2.js
   Google Auth, Portuguese, Free-to-Play
   Mode Selection: Normal, Hard, Extreme, Training
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

// Fetch and display user credits
async function fetchAndDisplayCredits(googleUid) {
    if (!googleUid) return;

    try {
        const res = await fetch('api/balance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: googleUid })
        });
        const data = await res.json();

        if (data.success) {
            const credits = data.credits ?? data.balance ?? 0;
            window._userCredits = credits;

            const creditsEl = document.getElementById('userCreditsDisplay');
            if (creditsEl) {
                creditsEl.textContent = credits;
            }

            // Aguardar configs do admin antes de atualizar botões
            if (window._modesConfigReady) await window._modesConfigReady;
            updateModeButtonStates(credits);
        }
    } catch (err) {
        console.error('Erro ao buscar créditos:', err);
    }
}

// Enable/disable mode buttons based on credits and admin settings
function updateModeButtonStates(credits) {
    const modeMap = {
        modeNormalBtn: 'normal',
        modeHardBtn: 'hard',
        modeExtremeBtn: 'extreme'
    };

    for (const [btnId, modeKey] of Object.entries(modeMap)) {
        const btn = document.getElementById(btnId);
        if (!btn) continue;

        const modeCfg = CONFIG.MODES[modeKey];

        // Atualizar data-cost dinâmico do admin
        if (modeCfg) {
            btn.dataset.cost = modeCfg.credits;
            // Atualizar texto do custo no botão se houver span
            const costSpan = btn.querySelector('.mode-cost');
            if (costSpan) costSpan.textContent = modeCfg.credits + ' crédito' + (modeCfg.credits > 1 ? 's' : '');
        }

        // Modo desabilitado pelo admin
        if (modeCfg && modeCfg._disabled) {
            btn.disabled = true;
            btn.title = 'Modo desativado';
            btn.style.opacity = '0.4';
            continue;
        }

        // Créditos insuficientes
        const cost = parseInt(btn.dataset.cost || '0', 10);
        if (cost > 0 && credits < cost) {
            btn.disabled = true;
            btn.title = 'Créditos insuficientes';
            btn.style.opacity = '';
        } else {
            btn.disabled = false;
            btn.title = '';
            btn.style.opacity = '';
        }
    }

    // Training button
    const trainingBtn = document.getElementById('modeTrainingBtn');
    if (trainingBtn) {
        const trainingDisabled = CONFIG._trainingEnabled === false;
        trainingBtn.disabled = trainingDisabled;
        trainingBtn.style.opacity = trainingDisabled ? '0.4' : '';
        trainingBtn.title = trainingDisabled ? 'Modo desativado' : '';
    }
}

// Validate login and ship selection before starting
async function validateBeforeStart() {
    // Check if user is logged in
    if (!gameState.user && !gameState.googleUid) {
        await gameAlert('Você precisa fazer login primeiro!', 'warning', 'LOGIN NECESSÁRIO');
        showModal('connectModal');
        return false;
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
            return false;
        }
    }

    return true;
}

// Start game session (Google Auth - no payment needed)
// For real modes: redirect to pregame.html
async function startGameSession() {
    const user = window.authManager?.currentUser;
    const googleUid = user?.uid || gameState.googleUid;

    if (!googleUid) {
        await gameAlert('Você precisa estar logado!', 'error', 'ERRO');
        showModal('connectModal');
        return;
    }

    // Store selected mode in sessionStorage so pregame-v2.html can pass it along
    sessionStorage.setItem('selectedGameMode', window.selectedGameMode || 'normal');
    sessionStorage.setItem('isTrainingMode', window.isTrainingMode ? 'true' : 'false');
    if (window.trainingSubMode) {
        sessionStorage.setItem('trainingSubMode', window.trainingSubMode);
    }

    try {
        // Redirect to pregame loading page (v2)
        window.location.href = 'pregame-v2.html';

    } catch (error) {
        console.error('Erro:', error);
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
    console.log('🎮 Unobix v5.0 - Inicializando (mode selection)...');

    // Carregar flag premium do localStorage (instantâneo)
    if (!window._userIsPremium && localStorage.getItem('userIsPremium') === '1') {
        window._userIsPremium = true;
    }

    // Atualizar flag premium do servidor em background (pega ativação pelo admin)
    const premiumCheckUid = localStorage.getItem('googleUid');
    if (premiumCheckUid) {
        fetch('api/balance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: premiumCheckUid })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                window._userIsPremium = !!data.is_premium;
                localStorage.setItem('userIsPremium', data.is_premium ? '1' : '0');
            }
        }).catch(() => {});
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

                        // Fetch credits on login
                        fetchAndDisplayCredits(user.uid);

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

    // ========================
    // Mode Selection Buttons
    // ========================

    // Normal mode button
    const modeNormalBtn = document.getElementById('modeNormalBtn');
    if (modeNormalBtn) {
        modeNormalBtn.addEventListener('click', async function() {
            console.log('🎯 Modo Normal selecionado');
            if (typeof unlockAudio === 'function') unlockAudio();

            window.selectedGameMode = 'normal';
            window.isTrainingMode = false;
            window.trainingSubMode = null;

            if (!(await validateBeforeStart())) return;
            await startGameSession();
        });
    }

    // Hard mode button
    const modeHardBtn = document.getElementById('modeHardBtn');
    if (modeHardBtn) {
        modeHardBtn.addEventListener('click', async function() {
            console.log('🎯 Modo Hard selecionado');
            if (typeof unlockAudio === 'function') unlockAudio();

            window.selectedGameMode = 'hard';
            window.isTrainingMode = false;
            window.trainingSubMode = null;

            if (!(await validateBeforeStart())) return;
            await startGameSession();
        });
    }

    // Extreme mode button
    const modeExtremeBtn = document.getElementById('modeExtremeBtn');
    if (modeExtremeBtn) {
        modeExtremeBtn.addEventListener('click', async function() {
            console.log('🎯 Modo Extreme selecionado');
            if (typeof unlockAudio === 'function') unlockAudio();

            window.selectedGameMode = 'extreme';
            window.isTrainingMode = false;
            window.trainingSubMode = null;

            if (!(await validateBeforeStart())) return;
            await startGameSession();
        });
    }

    // Training mode button - opens training popup
    const modeTrainingBtn = document.getElementById('modeTrainingBtn');
    if (modeTrainingBtn) {
        modeTrainingBtn.addEventListener('click', function() {
            console.log('🎯 Training popup aberto');
            if (typeof unlockAudio === 'function') unlockAudio();

            const trainingPopup = document.getElementById('trainingPopup');
            if (trainingPopup) {
                trainingPopup.style.display = 'flex';
            }
        });
    }

    // ========================
    // Training Popup Buttons
    // ========================

    const trainingModeBtns = document.querySelectorAll('.training-mode-btn[data-training-mode]');
    trainingModeBtns.forEach(btn => {
        btn.addEventListener('click', async function() {
            const subMode = this.dataset.trainingMode;
            console.log('🎯 Training sub-mode:', subMode);
            if (typeof unlockAudio === 'function') unlockAudio();

            window.selectedGameMode = 'training';
            window.trainingSubMode = subMode;
            window.isTrainingMode = true;

            // Close training popup
            const trainingPopup = document.getElementById('trainingPopup');
            if (trainingPopup) {
                trainingPopup.style.display = 'none';
            }

            if (!(await validateBeforeStart())) return;

            // Training mode: start game directly (no redirect to pregame.html)
            sessionStorage.setItem('selectedGameMode', 'training');
            sessionStorage.setItem('isTrainingMode', 'true');
            sessionStorage.setItem('trainingSubMode', subMode);

            if (typeof startGameWithLoading === 'function') {
                startGameWithLoading();
            }
        });
    });

    // Training popup close button
    const trainingCloseBtn = document.getElementById('trainingCloseBtn');
    if (trainingCloseBtn) {
        trainingCloseBtn.addEventListener('click', function() {
            const trainingPopup = document.getElementById('trainingPopup');
            if (trainingPopup) {
                trainingPopup.style.display = 'none';
            }
        });
    }

    // ========================
    // Play again / Retry buttons - go back to mode selection
    // ========================

    const playAgainBtn = document.getElementById('playAgainBtn');
    if (playAgainBtn) {
        playAgainBtn.addEventListener('click', () => {
            if (typeof unlockAudio === 'function') unlockAudio();
            showModal('gameMenuModal');
            if (typeof addShipSelectionUI === 'function') addShipSelectionUI();
            // Refresh credits display
            const uid = gameState.googleUid || localStorage.getItem('googleUid');
            if (uid) fetchAndDisplayCredits(uid);
        });
    }

    const retryBtn = document.getElementById('retryBtn');
    if (retryBtn) {
        retryBtn.addEventListener('click', () => {
            if (typeof unlockAudio === 'function') unlockAudio();
            showModal('gameMenuModal');
            if (typeof addShipSelectionUI === 'function') addShipSelectionUI();
            // Refresh credits display
            const uid = gameState.googleUid || localStorage.getItem('googleUid');
            if (uid) fetchAndDisplayCredits(uid);
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
                    showNotification('RESGATADO', 'Ganhos adicionados ao saldo!');
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

            // Fetch and display credits on auth
            fetchAndDisplayCredits(user.uid);

            // Se a UI já foi mostrada pelo cache, não repetir navegação
            if (window._uiAlreadyShown) {
                window._uiAlreadyShown = false;
                return;
            }

            _handlePostAuthNavigation();
        } else {
            // Not logged in — só mostrar tela de login se não tem cache
            const hasCachedAuth = localStorage.getItem('googleUid');
            if (!window._uiAlreadyShown && !hasCachedAuth) {
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

        // Fetch credits with cached UID
        fetchAndDisplayCredits(cachedUid);

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
            window.history.replaceState({}, '', 'game-v2.html');

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
            window.history.replaceState({}, '', 'game-v2.html');

            // Restaurar modo selecionado do sessionStorage (perdido no redirect)
            const savedMode = sessionStorage.getItem('selectedGameMode') || 'normal';
            const savedTraining = sessionStorage.getItem('isTrainingMode') === 'true';
            const savedSubMode = sessionStorage.getItem('trainingSubMode');
            window.selectedGameMode = savedMode;
            window.isTrainingMode = savedTraining;
            if (savedSubMode) window.trainingSubMode = savedSubMode;
            console.log('🎮 Auto-starting game [mode:', savedMode, ']');

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
        console.log('✅ Jogo inicializado com sucesso (v5.0 mode selection)');
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
window.fetchAndDisplayCredits = fetchAndDisplayCredits;
window.validateBeforeStart = validateBeforeStart;
