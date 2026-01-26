/* ============================================
   CRYPTO ASTEROID RUSH - Game Start v3.0
   File: js/game-start.js
   Pre-game loading screen with ads support
   ============================================ */

// Start game with loading screen (skip if auto-start)
function startGameWithLoading() {
    console.log('🎮 Starting game...');
    
    // Determine if this is a hard mode mission (house edge)
    determineHardMode();
    
    // Go directly to game (loading was already shown)
    actualStartGame();
}

// Actually start the game
async function actualStartGame() {
    console.log('🚀 Starting mission', missionStats.totalMissions + 1);
    console.log('🎰 Hard Mode:', missionStats.isHardMode);
    
    // ============================================
    // CRITICAL: Start server session FIRST
    // ============================================
    try {
        showNotification('PREPARING', 'Creating mission session...', true);
        
        const sessionResult = await SessionManager.startSession(gameState.wallet);
        
        if (!sessionResult || !sessionResult.success) {
            throw new Error(sessionResult?.error || 'Failed to create session');
        }
        
        console.log('✅ Server session created:', sessionResult.session_id);
        
        // Update mission stats with server data
        missionStats.totalMissions = sessionResult.mission_number;
        localStorage.setItem('totalMissions', missionStats.totalMissions.toString());
        
    } catch (error) {
        console.error('❌ Failed to start session:', error);
        await gameAlert('Failed to start mission: ' + error.message, 'error', 'ERROR');
        return;
    }
    
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
    
    // Setup ship
    const shipDesign = getShipForGame();
    gameState.currentSessionShip = shipDesign;
    
    gameState.ship = {
        x: canvas.width / 2,
        y: canvas.height - 120,
        width: 80,
        height: 70,
        speed: CONFIG.SHIP_SPEED,
        design: shipDesign
    };
    gameState.lastX = gameState.ship.x;
    
    showNotification('SHIP READY', shipDesign.name, true);
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
    
    // Reset ship selection
    gameState.selectedShipDesign = null;
    updateShipSelectionUI(null);
    
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

// Show mission start info (non-blocking notification)
function showMissionStartInfo() {
    const modeText = missionStats.isHardMode ? 'HARD MODE' : 'NORMAL MODE';
    
    // Show notification instead of blocking alert
    showNotification(`MISSION #${missionStats.totalMissions}`, modeText, true);
    
    console.log('📊 Mission started:', { 
        number: missionStats.totalMissions,
        hardMode: missionStats.isHardMode,
        rewards: CONFIG.REWARDS
    });
}

// Export functions
window.startGameWithLoading = startGameWithLoading;
window.actualStartGame = actualStartGame;
window.resetLivesDisplay = resetLivesDisplay;
window.showMissionStartInfo = showMissionStartInfo;