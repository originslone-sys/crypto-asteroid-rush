/* ============================================
   UNOBIX - Main Entry Point v4.3
   File: js/game-main.js

   MUDANÇAS v4.3:
   - FIX BUG-001: waitForAuth híbrido (polling + event) com timeout 10s
   - FIX BUG-004: Suporte a sessão pré-criada pelo pregame.html
   - Flags consumidas ANTES de qualquer processamento
   - Fallback robusto quando auth demora no mobile
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
 * Aguardar autenticação com abordagem HÍBRIDA
 * Combina polling + event listener para resolver o mais rápido possível
 * FIX BUG-001: Timeout aumentado para 10s (mobile pode demorar)
 *
 * @param {number} maxWait - Tempo máximo de espera em ms
 * @returns {Promise<object|null>} - User object ou null
 */
function waitForAuth(maxWait = 10000) {
    return new Promise((resolve) => {
        // 1. Já disponível? (cache do Firebase ainda válido)
        const current = window.authManager?.currentUser;
        if (current && current.uid) {
            console.log('✅ Auth já disponível:', current.displayName || current.email);
            resolve(current);
            return;
        }

        let resolved = false;

        const finish = (user) => {
            if (resolved) return;
            resolved = true;
            document.removeEventListener('authStateChanged', onAuthEvent);
            clearInterval(pollId);
            clearTimeout(timeoutId);
            if (user) {
                console.log('✅ Auth resolvido:', user.displayName || user.email);
            } else {
                console.warn('⚠️ Timeout aguardando auth após', maxWait, 'ms');
            }
            resolve(user);
        };

        // 2. Escutar evento authStateChanged (resolve mais rápido quando Firebase dispara)
        const onAuthEvent = (e) => {
            const user = e.detail?.user;
            if (user && user.uid) finish(user);
        };
        document.addEventListener('authStateChanged', onAuthEvent);

        // 3. Polling como backup (caso evento já tenha disparado antes do listener)
        const pollId = setInterval(() => {
            const user = window.authManager?.currentUser;
            if (user && user.uid) finish(user);
        }, 150);

        // 4. Timeout final
        const timeoutId = setTimeout(() => finish(null), maxWait);
    });
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
        // FIX BUG-001: Auth falhou após timeout.
        // NÃO mostrar connectModal imediatamente — isso causava o flicker.
        // Sinalizar para o auth listener tentar iniciar o jogo quando auth resolver.
        console.warn('⚠️ Auth timeout no auto-start, aguardando auth listener...');

        // Marcar que auto-start está pendente (auth listener vai tentar)
        sessionStorage.setItem('_autoStartPending', 'true');

        // Retornar false para que o fluxo normal (auth listener) assuma
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
 * Flag para indicar que auto-start falhou por timeout de auth
 * e que o auth listener deve tentar iniciar o jogo quando auth resolver
 */
let _pendingAutoStartAfterAuth = false;

/**
 * Handler para auth state change (fluxo normal, sem auto-start)
 * FIX BUG-001: Se auto-start falhou por timeout, tenta iniciar aqui
 */
function handleNormalAuthChange(user) {
    if (user) {
        gameState.user = user;
        gameState.googleUid = user.uid;
        gameState.isConnected = true;

        updateUserUI(user);

        // FIX BUG-001: Se auto-start falhou por timeout, tenta iniciar agora
        if (_pendingAutoStartAfterAuth) {
            _pendingAutoStartAfterAuth = false;
            console.log('🎮 Auth resolveu após timeout, iniciando jogo agora...');
            if (typeof startGameWithLoading === 'function') {
                startGameWithLoading();
                return;
            }
        }

        showModal('gameMenuModal');
    } else {
        // Firebase disparou com null (ainda carregando) — NÃO mostrar connectModal
        // Esperar o próximo disparo com user real
        if (_pendingAutoStartAfterAuth) {
            console.log('🔄 Auth ainda carregando, aguardando...');
            return;
        }
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

    // FIX BUG-001: Se handleAutoStart retornou false MAS detectou as flags
    // (ou seja, era auto-start mas auth demorou), marcar para retry via auth listener
    const wasAutoStartAttempt = !handledAutoStart &&
        !new URLSearchParams(window.location.search).has('start');
    // Se a URL já foi limpa pelo handleAutoStart mas retornou false = timeout de auth
    // Nesse caso, o auth listener deve tentar iniciar o jogo
    if (wasAutoStartAttempt && sessionStorage.getItem('_autoStartPending') === 'true') {
        _pendingAutoStartAfterAuth = true;
        sessionStorage.removeItem('_autoStartPending');
        console.log('🔄 Auto-start pendente, aguardando auth listener...');
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

console.log('📦 game-main.js v4.3 carregado (BUG-001 + BUG-004 fix)');
