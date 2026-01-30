/* ============================================
   UNOBIX - Game Start v4.1
   File: js/game-start.js
   Google Auth, BRL currency
   FIX: Passa googleUid corretamente para SessionManager
   ============================================ */

// Start game with loading screen
function startGameWithLoading() {
    console.log('🎮 Starting game...');
    
    // Determine if this mission is hard mode (hidden from user)
    if (typeof determineHardMode === 'function') {
        determineHardMode();
    }
    
    // Go to actual start
    actualStartGame();
}

// Actually start the game
async function actualStartGame() {
    console.log('🚀 Starting mission', (missionStats?.totalMissions || 0) + 1);
    
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
        
        console.log('🔑 Google UID encontrado:', googleUid ? googleUid.substring(0, 10) + '...' : 'NENHUM');
        
        if (!googleUid) {
            throw new Error('Usuário não autenticado. Faça login novamente.');
        }
        
        // Garantir que gameState tem o googleUid
        gameState.googleUid = googleUid;
        
        // IMPORTANTE: Passar googleUid, NÃO wallet!
        const sessionResult = await SessionManager.startSession(googleUid);
        
        if (!sessionResult || !sessionResult.success) {
            throw new Error(sessionResult?.error || 'Falha ao criar sessão');
        }
        
        console.log('✅ Server session created:', sessionResult.session_id);
        
        // Update mission stats
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
        if (typeof gameAlert === 'function') {
            await gameAlert('Falha ao iniciar missão: ' + error.message, 'error', 'ERRO');
        }
        if (typeof showModal === 'function') {
            showModal('gameMenuModal');
        }
        return;
    }
    
    // Reset mission stats
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
    
    // Start game loop
    if (typeof gameLoop === 'function') {
        gameLoop();
    }
    
    // Audio
    if (gameState.audioEnabled) {
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
                playBackgroundMusic();
            }
        }, 500);
    }
    
    // Show mission info
    if (typeof showMissionStartInfo === 'function') {
        showMissionStartInfo();
    }
}

// Reset lives display
function resetLivesDisplay() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;
    
    const initialLives = CONFIG?.INITIAL_LIVES || 6;
    livesContainer.innerHTML = '';
    for (let i = 0; i < initialLives; i++) {
        const life = document.createElement('span');
        life.className = 'life active';
        livesContainer.appendChild(life);
    }
}

// Show mission start info
function showMissionStartInfo() {
    const missionNum = missionStats?.totalMissions || 1;
    if (typeof showNotification === 'function') {
        showNotification(`MISSÃO #${missionNum}`, 'Boa sorte, Comandante!', true);
    }
    
    console.log('📊 Mission started:', { 
        number: missionNum,
        hardMode: missionStats?.isHardMode || false
    });
}

// Export functions
window.startGameWithLoading = startGameWithLoading;
window.actualStartGame = actualStartGame;
window.resetLivesDisplay = resetLivesDisplay;
window.showMissionStartInfo = showMissionStartInfo;
