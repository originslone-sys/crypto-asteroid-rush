   UNOBIX - Game Start v4.1
   File: js/game-start.js
   Google Auth, BRL currency
   FIX: Passa googleUid corretamente para SessionManager
   UNOBIX - Game Start v4.2
   js/game-start.js
   CORRIGIDO: UID handling
   ============================================ */

// Start game with loading screen
/**
 * Iniciar jogo com tela de carregamento
 */
function startGameWithLoading() {
    console.log('🎮 Starting game...');
    console.log('🎮 Iniciando jogo...');

    // Determine if this mission is hard mode (hidden from user)
    // Determinar hard mode (oculto do jogador)
    if (typeof determineHardMode === 'function') {
        determineHardMode();
    }

    // Go to actual start
    actualStartGame();
}

// Actually start the game
/**
 * Efetivamente iniciar o jogo
 */
async function actualStartGame() {
    console.log('🚀 Starting mission', (missionStats?.totalMissions || 0) + 1);
    const missionNum = (typeof missionStats !== 'undefined' ? missionStats.totalMissions : 0) + 1;
    console.log('🚀 Iniciando missão', missionNum);

    // Start server session
    try {
        if (typeof showNotification === 'function') {
            showNotification('PREPARANDO', 'Criando sessão da missão...', true);
        }

        // Buscar googleUid de várias fontes
        const googleUid = gameState.googleUid 
            || gameState.user?.uid 
            || window.authManager?.currentUser?.uid
            || window.authManager?.getUserId()
            || localStorage.getItem('googleUid');
        // Obter Google UID
        const googleUid = getGoogleUidFromSources();

        console.log('🔑 Google UID encontrado:', googleUid ? googleUid.substring(0, 10) + '...' : 'NENHUM');
        console.log('🔑 Google UID:', googleUid ? googleUid.substring(0, 15) + '...' : 'NENHUM');

        if (!googleUid) {
            throw new Error('Usuário não autenticado. Faça login novamente.');
        }

        // Garantir que gameState tem o googleUid
        gameState.googleUid = googleUid;
        // Salvar no gameState
        if (typeof gameState !== 'undefined') {
            gameState.googleUid = googleUid;
        }

        // IMPORTANTE: Passar googleUid, NÃO wallet!
        // Criar sessão no servidor
        const sessionResult = await SessionManager.startSession(googleUid);

        if (!sessionResult || !sessionResult.success) {
            throw new Error(sessionResult?.error || 'Falha ao criar sessão');
        }

        console.log('✅ Server session created:', sessionResult.session_id);
        console.log('✅ Sessão criada:', sessionResult.session_id);

        // Update mission stats
        // Atualizar stats
        if (typeof missionStats !== 'undefined') {
            missionStats.totalMissions = sessionResult.mission_number;
            localStorage.setItem('totalMissions', missionStats.totalMissions.toString());

            // Check if server says this is hard mode
            if (sessionResult.is_hard_mode !== undefined) {
                missionStats.isHardMode = sessionResult.is_hard_mode;
            }
        }

    } catch (error) {
        console.error('❌ Failed to start session:', error);
        console.error('❌ Falha ao iniciar sessão:', error);
        
        if (typeof gameAlert === 'function') {
            await gameAlert('Falha ao iniciar missão: ' + error.message, 'error', 'ERRO');
        } else {
            alert('Erro: ' + error.message);
        }
        
        if (typeof showModal === 'function') {
            showModal('gameMenuModal');
        }
        return;
    }

    // Reset mission stats
    // Resetar stats da missão
    if (typeof missionStats !== 'undefined') {
        missionStats.rareCount = 0;
        missionStats.epicCount = 0;
        missionStats.legendaryCount = 0;
    }

    // Create initial asteroids
    gameState.asteroids = [];
    const initialAsteroids = CONFIG?.INITIAL_ASTEROIDS || 5;
    for (let i = 0; i < initialAsteroids; i++) {
        if (typeof createAsteroid === 'function') {
            const asteroid = createAsteroid(i, false);
            asteroid.y = -50 - (i * 80);
            gameState.asteroids.push(asteroid);
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
        
        // Resetar estado do jogo
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
        
        // Nave
        const shipDesign = typeof getShipForGame === 'function' ? getShipForGame() : { name: 'Default Ship' };
        gameState.currentSessionShip = shipDesign;
        
        console.log('🚀 Usando nave:', shipDesign.name);
        
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

    gameState.asteroidSpawnCounter = initialAsteroids;
    
    // Reset game state
    gameState.gameActive = true;
    gameState.score = 0;
    gameState.earnings = 0;
    gameState.lives = CONFIG?.INITIAL_LIVES || 6;
    gameState.invincibilityFrames = 0;
    gameState.destroyedAsteroids = [];
    gameState.bullets = [];
    gameState.particles = [];
    gameState.lastFireTime = 0;
    gameState.keys = { left: false, right: false, fire: false };
    
    // Get ship for this mission
    const shipDesign = typeof getShipForGame === 'function' ? getShipForGame() : { name: 'Default Ship' };
    gameState.currentSessionShip = shipDesign;
    
    console.log('🚀 Using ship:', shipDesign.name);
    
    gameState.ship = {
        x: canvas.width / 2,
        y: canvas.height - 120,
        width: 80,
        height: 70,
        speed: CONFIG?.SHIP_SPEED || 8,
        design: shipDesign
    };
    gameState.lastX = gameState.ship.x;
    
    if (typeof showNotification === 'function') {
        showNotification('NAVE PRONTA', shipDesign.name, true);
        showNotification('NAVE PRONTA', gameState?.currentSessionShip?.name || 'Pronto', true);
    }
    
    if (typeof showModal === 'function') {
        showModal('');
    }

    // Update lives display
    if (typeof resetLivesDisplay === 'function') {
        resetLivesDisplay();
    }
    if (typeof updateUI === 'function') {
        updateUI();
    }
    
    // Start timers
    if (typeof startGameTimer === 'function') {
        startGameTimer();
    }
    if (typeof startSpawnTimer === 'function') {
        startSpawnTimer();
    }
    // Atualizar UI
    if (typeof resetLivesDisplay === 'function') resetLivesDisplay();
    if (typeof updateUI === 'function') updateUI();
    if (typeof startGameTimer === 'function') startGameTimer();
    if (typeof startSpawnTimer === 'function') startSpawnTimer();
    if (typeof gameLoop === 'function') gameLoop();

    // Start game loop
    if (typeof gameLoop === 'function') {
        gameLoop();
    }
    
    // Audio
    if (gameState.audioEnabled) {
    // Áudio
    if (typeof gameState !== 'undefined' && gameState.audioEnabled) {
        setTimeout(() => {
            if (typeof isAudioUnlocked !== 'undefined' && !isAudioUnlocked) {
                if (typeof unlockAudio === 'function') unlockAudio();
                setTimeout(() => {
                    if (typeof isAudioUnlocked !== 'undefined' && isAudioUnlocked && 
                        typeof backgroundMusic !== 'undefined' && backgroundMusic === null &&
                        typeof playBackgroundMusic === 'function') {
                        playBackgroundMusic();
                    }
                }, 300);
            } else if (typeof backgroundMusic !== 'undefined' && backgroundMusic === null &&
                       typeof playBackgroundMusic === 'function') {
            if (typeof playBackgroundMusic === 'function') {
                playBackgroundMusic();
            }
        }, 500);
    }

    // Show mission info
    // Info da missão
    if (typeof showMissionStartInfo === 'function') {
        showMissionStartInfo();
    }
}

// Reset lives display
/**
 * Obter Google UID de várias fontes
 */
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

/**
 * Resetar display de vidas
 */
function resetLivesDisplay() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;

    const initialLives = CONFIG?.INITIAL_LIVES || 6;
    const initialLives = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_LIVES : 6;
    livesContainer.innerHTML = '';
    
    for (let i = 0; i < initialLives; i++) {
        const life = document.createElement('span');
        life.className = 'life active';
        livesContainer.appendChild(life);
    }
}

// Show mission start info
/**
 * Mostrar info de início da missão
 */
function showMissionStartInfo() {
    const missionNum = missionStats?.totalMissions || 1;
    const missionNum = typeof missionStats !== 'undefined' ? missionStats.totalMissions : 1;
    
    if (typeof showNotification === 'function') {
        showNotification(`MISSÃO #${missionNum}`, 'Boa sorte, Comandante!', true);
    }

    console.log('📊 Mission started:', { 
    console.log('📊 Missão iniciada:', { 
        number: missionNum,
        hardMode: missionStats?.isHardMode || false
        hardMode: typeof missionStats !== 'undefined' ? missionStats.isHardMode : false
    });
}

// Export functions
// Exportar funções
window.startGameWithLoading = startGameWithLoading;
window.actualStartGame = actualStartGame;
window.resetLivesDisplay = resetLivesDisplay;
window.showMissionStartInfo = showMissionStartInfo;
window.getGoogleUidFromSources = getGoogleUidFromSources;

console.log('📦 game-start.js v4.2 carregado');
