/* ============================================
   UNOBIX - Game Start v4.4
   File: js/game-start.js

   MUDANÇAS v4.4:
   - FIX BUG-004: Aceitar sessão pré-criada pelo pregame.html
   - Se preCreatedSession existe no sessionStorage, usa direto (skip HTTP)
   - Fallback: cria sessão normalmente se pré-criação falhou
   ============================================ */

let _startGameLock = false;

function startGameWithLoading() {
    if (_startGameLock) {
        console.warn('⚠️ startGameWithLoading já em execução');
        return;
    }
    _startGameLock = true;
    console.log('🎮 startGameWithLoading iniciando...');

    if (typeof determineHardMode === 'function') {
        determineHardMode();
    }

    actualStartGame().finally(() => {
        _startGameLock = false;
    });
}

function getGoogleUidFromSources() {
    const sources = [
        () => gameState?.googleUid,
        () => gameState?.user?.uid,
        () => window.authManager?.currentUser?.uid,
        () => window.authManager?.getUserId?.(),
        () => localStorage.getItem('googleUid'),
        () => sessionStorage.getItem('googleUid')
    ];
    
    for (const source of sources) {
        try {
            const uid = source();
            if (uid && typeof uid === 'string' && uid.length >= 10) {
                return uid;
            }
        } catch (e) {}
    }
    
    return null;
}

async function actualStartGame() {
    const missionNum = (typeof missionStats !== 'undefined' ? missionStats.totalMissions : 0) + 1;
    console.log('🚀 Iniciando missão', missionNum);

    try {
        const googleUid = getGoogleUidFromSources();
        console.log('🔑 Google UID:', googleUid ? googleUid.substring(0, 15) + '...' : 'NENHUM');

        if (!googleUid) {
            throw new Error('Usuário não autenticado. Faça login novamente.');
        }

        if (typeof gameState !== 'undefined') {
            gameState.googleUid = googleUid;
        }

        // ============================================
        // FIX BUG-004: Verificar sessão pré-criada pelo pregame.html
        // Se existe, usar direto (pula chamada HTTP, economiza 1-8s)
        // ============================================
        let sessionResult = null;
        const preCreatedRaw = sessionStorage.getItem('preCreatedSession');

        if (preCreatedRaw) {
            sessionStorage.removeItem('preCreatedSession');
            try {
                const preCreated = JSON.parse(preCreatedRaw);
                if (preCreated && preCreated.success && preCreated.session_id) {
                    console.log('🚀 Usando sessão pré-criada:', preCreated.session_id);

                    // Inicializar SessionManager com a sessão pré-criada
                    SessionManager.currentSession = {
                        id: preCreated.session_id,
                        token: preCreated.session_token,
                        seed: preCreated.session_seed,
                        googleUid: preCreated.google_uid || googleUid,
                        missionNumber: preCreated.mission_number,
                        startTime: Date.now(),
                        isHardMode: preCreated.is_hard_mode || false,
                        limits: preCreated.limits || {}
                    };
                    SessionManager.pendingEndSession = null;

                    if (typeof gameState !== 'undefined') {
                        gameState.sessionId = preCreated.session_id;
                        gameState.sessionToken = preCreated.session_token;
                        gameState.googleUid = SessionManager.currentSession.googleUid;
                    }

                    sessionResult = preCreated;

                    if (typeof showNotification === 'function') {
                        showNotification('PREPARANDO', 'Sessão pronta!', true);
                    }
                } else if (preCreated && !preCreated.success) {
                    // Sessão pré-criada falhou (bloqueio, rate limit, etc.)
                    // Notificar o jogador usando _notifyBlock se disponível
                    console.warn('⚠️ Sessão pré-criada falhou:', preCreated.error);
                    if (typeof SessionManager._notifyBlock === 'function') {
                        SessionManager._notifyBlock(preCreated.block_reason, preCreated);
                    }
                    throw new Error(preCreated.error || 'Não foi possível iniciar a missão');
                }
            } catch (parseError) {
                if (parseError.message && !parseError.message.includes('JSON')) {
                    throw parseError; // Re-throw erros de negócio (bloqueio, etc.)
                }
                console.warn('⚠️ Erro ao ler sessão pré-criada, criando nova...');
                sessionResult = null;
            }
        }

        // Fallback: criar sessão normalmente se pré-criação não disponível
        if (!sessionResult) {
            if (typeof showNotification === 'function') {
                showNotification('PREPARANDO', 'Criando sessão...', true);
            }

            sessionResult = await SessionManager.startSession(googleUid);

            if (!sessionResult || !sessionResult.success) {
                throw new Error(sessionResult?.error || 'Falha ao criar sessão');
            }
        }

        console.log('✅ Sessão ativa:', sessionResult.session_id);

        if (typeof missionStats !== 'undefined') {
            missionStats.totalMissions = sessionResult.mission_number;
            localStorage.setItem('totalMissions', missionStats.totalMissions.toString());
            if (sessionResult.is_hard_mode !== undefined) {
                missionStats.isHardMode = sessionResult.is_hard_mode;
            }
        }

    } catch (error) {
        console.error('❌ Falha ao iniciar sessão:', error);

        if (typeof NotificationSystem !== 'undefined') {
            NotificationSystem.error('Erro', error.message || 'Falha ao iniciar missão');
        }

        if (typeof showModal === 'function') {
            showModal('gameMenuModal');
        }
        return;
    }

    // Resetar stats
    if (typeof missionStats !== 'undefined') {
        missionStats.rareCount = 0;
        missionStats.epicCount = 0;
        missionStats.legendaryCount = 0;
    }

    // Criar asteroides iniciais
    if (typeof gameState !== 'undefined') {
        gameState.asteroids = [];
        const initialAsteroids = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_ASTEROIDS : 5;
        
        for (let i = 0; i < initialAsteroids; i++) {
            if (typeof createAsteroid === 'function') {
                const asteroid = createAsteroid(i, false);
                asteroid.y = -50 - (i * 80);
                gameState.asteroids.push(asteroid);
            }
        }
        
        gameState.asteroidSpawnCounter = initialAsteroids;
        gameState.gameActive = true;
        gameState.score = 0;
        gameState.earnings = 0;
        gameState.lives = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_LIVES : 6;
        gameState.invincibilityFrames = 0;
        gameState.destroyedAsteroids = [];
        gameState.bullets = [];
        gameState.particles = [];
        gameState.lastFireTime = 0;
        gameState.keys = { left: false, right: false, fire: false };
        
        const shipDesign = typeof getShipForGame === 'function' ? getShipForGame() : { name: 'Default Ship' };
        gameState.currentSessionShip = shipDesign;
        
        if (typeof canvas !== 'undefined') {
            gameState.ship = {
                x: canvas.width / 2,
                y: canvas.height - 120,
                width: 80,
                height: 70,
                speed: typeof CONFIG !== 'undefined' ? CONFIG.SHIP_SPEED : 8,
                design: shipDesign
            };
            gameState.lastX = gameState.ship.x;
        }
    }

    if (typeof showNotification === 'function') {
        showNotification('NAVE PRONTA', gameState?.currentSessionShip?.name || 'Pronto', true);
    }
    
    if (typeof showModal === 'function') {
        showModal('');
    }

    if (typeof resetLivesDisplay === 'function') resetLivesDisplay();
    if (typeof updateUI === 'function') updateUI();
    if (typeof startGameTimer === 'function') startGameTimer();
    if (typeof startSpawnTimer === 'function') startSpawnTimer();
    if (typeof gameLoop === 'function') gameLoop();

    if (typeof gameState !== 'undefined' && gameState.audioEnabled) {
        setTimeout(() => {
            if (typeof isAudioUnlocked !== 'undefined' && !isAudioUnlocked) {
                if (typeof unlockAudio === 'function') unlockAudio();
                setTimeout(() => {
                    if (typeof playBackgroundMusic === 'function') playBackgroundMusic();
                }, 300);
            } else if (typeof playBackgroundMusic === 'function') {
                playBackgroundMusic();
            }
        }, 500);
    }

    if (typeof showMissionStartInfo === 'function') {
        showMissionStartInfo();
    }
}

function resetLivesDisplay() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;

    const initialLives = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_LIVES : 6;
    livesContainer.innerHTML = '';
    
    for (let i = 0; i < initialLives; i++) {
        const life = document.createElement('span');
        life.className = 'life active';
        livesContainer.appendChild(life);
    }
}

function showMissionStartInfo() {
    const missionNum = typeof missionStats !== 'undefined' ? missionStats.totalMissions : 1;
    
    if (typeof showNotification === 'function') {
        showNotification(`MISSÃO #${missionNum}`, 'Boa sorte, Comandante!', true);
    }
}

window.startGameWithLoading = startGameWithLoading;
window.actualStartGame = actualStartGame;
window.resetLivesDisplay = resetLivesDisplay;
window.showMissionStartInfo = showMissionStartInfo;
window.getGoogleUidFromSources = getGoogleUidFromSources;

console.log('📦 game-start.js v4.4 carregado (sessão pré-criada)');
