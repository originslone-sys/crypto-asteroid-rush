/* ============================================
   UNOBIX - Game Start v4.0
   File: js/game-start.js
   Google Auth, BRL currency
   ============================================ */

// Start game with loading screen
function startGameWithLoading() {
    console.log('🎮 Starting game...');
    
    // Determine if this mission is hard mode (hidden from user)
    determineHardMode();
    
    // Go to actual start
    actualStartGame();
}

// Actually start the game
async function actualStartGame() {
    console.log('🚀 Starting mission', missionStats.totalMissions + 1);
    
    // Start server session
    try {
        showNotification('PREPARANDO', 'Criando sessão da missão...', true);
        
        // Buscar googleUid de várias fontes
        const googleUid = gameState.googleUid 
            || gameState.user?.uid 
            || window.authManager?.currentUser?.uid
            || window.authManager?.getUserId()
            || localStorage.getItem('googleUid');
        
        console.log('🔑 Google UID encontrado:', googleUid ? googleUid.substring(0, 10) + '...' : 'NENHUM');
        
        if (!googleUid) {
            throw new Error('Usuário não autenticado');
        }
        
        // Garantir que gameState tem o googleUid
        gameState.googleUid = googleUid;
        
        const sessionResult = await SessionManager.startSession(gameState.wallet || localStorage.getItem('wallet') || '');
        
        if (!sessionResult || !sessionResult.success) {
            throw new Error(sessionResult?.error || 'Falha ao criar sessão');
        }
        
        console.log('✅ Server session created:', sessionResult.session_id);
        
        // Update mission stats
        missionStats.totalMissions = sessionResult.mission_number;
        localStorage.setItem('totalMissions', missionStats.totalMissions.toString());
        
        // Check if server says this is hard mode
        if (sessionResult.is_hard_mode !== undefined) {
            missionStats.isHardMode = sessionResult.is_hard_mode;
        }
        
    } catch (error) {
        console.error('❌ Failed to start session:', error);
        await gameAlert('Falha ao iniciar missão: ' + error.message, 'error', 'ERRO');
        showModal('gameMenuModal');
        return;
    }
    
    // Reset mission stats
    missionStats.rareCount = 0;
    missionStats.epicCount = 0;
    missionStats.legendaryCount = 0;
    
    // Create initial asteroids
    gameState.asteroids = [];
    for (let i = 0; i < CONFIG.INITIAL_ASTEROIDS; i++) {
        const asteroid = createAsteroid(i, false);
        asteroid.y = -50 - (i * 80);
        gameState.asteroids.push(asteroid);
    }
    
    gameState.asteroidSpawnCounter = CONFIG.INITIAL_ASTEROIDS;
    
    // Reset game state
    gameState.gameActive = true;
    gameState.score = 0;
    gameState.earnings = 0;
    gameState.lives = CONFIG.INITIAL_LIVES;
    gameState.invincibilityFrames = 0;
    gameState.destroyedAsteroids = [];
    gameState.bullets = [];
    gameState.particles = [];
    gameState.lastFireTime = 0;
    gameState.keys = { left: false, right: false, fire: false };
    
    // Get ship for this mission
    const shipDesign = getShipForGame();
    gameState.currentSessionShip = shipDesign;
    
    console.log('🚀 Using ship:', shipDesign.name);
    
    gameState.ship = {
        x: canvas.width / 2,
        y: canvas.height - 120,
        width: 80,
        height: 70,
        speed: CONFIG.SHIP_SPEED,
        design: shipDesign
    };
    gameState.lastX = gameState.ship.x;
    
    showNotification('NAVE PRONTA', shipDesign.name, true);
    showModal('');
    
    // Update lives display
    resetLivesDisplay();
    updateUI();
    
    // Start timers
    startGameTimer();
    startSpawnTimer();
    
    // Start game loop
    gameLoop();
    
    // Audio
    if (gameState.audioEnabled) {
        setTimeout(() => {
            if (!isAudioUnlocked) {
                unlockAudio();
                setTimeout(() => {
                    if (isAudioUnlocked && backgroundMusic === null) playBackgroundMusic();
                }, 300);
            } else if (backgroundMusic === null) {
                playBackgroundMusic();
            }
        }, 500);
    }
    
    // Show mission info
    showMissionStartInfo();
}

// Reset lives display
function resetLivesDisplay() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;
    
    livesContainer.innerHTML = '';
    for (let i = 0; i < CONFIG.INITIAL_LIVES; i++) {
        const life = document.createElement('span');
        life.className = 'life active';
        livesContainer.appendChild(life);
    }
}

// Show mission start info
function showMissionStartInfo() {
    showNotification(`MISSÃO #${missionStats.totalMissions}`, 'Boa sorte, Comandante!', true);
    
    console.log('📊 Mission started:', { 
        number: missionStats.totalMissions,
        hardMode: missionStats.isHardMode
    });
}

// Export functions
window.startGameWithLoading = startGameWithLoading;
window.actualStartGame = actualStartGame;
window.resetLivesDisplay = resetLivesDisplay;
window.showMissionStartInfo = showMissionStartInfo;

