/* ============================================
   CRYPTO ASTEROID RUSH - Game Engine v5.0
   File: js/game-engine.js
   Envia stats detalhados + lista de asteroides
   ============================================ */

let canvas, ctx;
let animationFrameId = null;
let stars = [];

function initCanvas() {
    canvas = document.getElementById('gameCanvas');
    ctx = canvas.getContext('2d');
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
}

function resizeCanvas() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    
    if (gameState.ship) {
        gameState.ship.y = canvas.height - 120;
        gameState.ship.x = Math.min(Math.max(gameState.ship.x, 50), canvas.width - 50);
    }
    
    stars = [];
    for (let i = 0; i < 150; i++) {
        stars.push({
            x: Math.random() * canvas.width,
            y: Math.random() * canvas.height,
            size: Math.random() * 2 + 0.5,
            speed: Math.random() * 0.3 + 0.1,
            opacity: Math.random() * 0.8 + 0.2
        });
    }
}

function generateAsteroidShape(baseRadius) {
    const points = [];
    const numPoints = 8 + Math.floor(Math.random() * 5);
    
    for (let i = 0; i < numPoints; i++) {
        const angle = (i / numPoints) * Math.PI * 2;
        const radiusVariation = 0.6 + Math.random() * 0.4;
        const r = baseRadius * radiusVariation;
        points.push({
            x: Math.cos(angle) * r,
            y: Math.sin(angle) * r
        });
    }
    
    return points;
}

function generateCraters(baseRadius, count) {
    const craters = [];
    for (let i = 0; i < count; i++) {
        const angle = Math.random() * Math.PI * 2;
        const dist = Math.random() * baseRadius * 0.6;
        craters.push({
            x: Math.cos(angle) * dist,
            y: Math.sin(angle) * dist,
            radius: 2 + Math.random() * 4
        });
    }
    return craters;
}

function createAsteroid(id, spawnAtTop = true) {
    const type = getRandomAsteroidType();
    const reward = CONFIG.REWARDS[type];
    
    let baseSize;
    switch(type) {
        case 'LEGENDARY': baseSize = 45 + Math.random() * 15; break;
        case 'EPIC': baseSize = 40 + Math.random() * 12; break;
        case 'RARE': baseSize = 35 + Math.random() * 10; break;
        default: baseSize = 25 + Math.random() * 20;
    }
    
    const speed = getAsteroidSpeed();
    const x = 50 + Math.random() * (canvas.width - 100);
    const y = spawnAtTop ? -baseSize - Math.random() * 50 : -baseSize;
    
    let colors, glowColor;
    switch(type) {
        case 'LEGENDARY':
            colors = { base: '#DAA520', dark: '#B8860B', light: '#FFD700' };
            glowColor = '#FFD700';
            break;
        case 'EPIC':
            colors = { base: '#8B008B', dark: '#4B0082', light: '#DA70D6' };
            glowColor = '#9932CC';
            break;
        case 'RARE':
            colors = { base: '#1E90FF', dark: '#0000CD', light: '#87CEEB' };
            glowColor = '#4169E1';
            break;
        default:
            const browns = [
                { base: '#6B4423', dark: '#4A2F17', light: '#8B6914' },
                { base: '#5D4E37', dark: '#3D3226', light: '#7D6E57' },
                { base: '#4A4A4A', dark: '#2D2D2D', light: '#6A6A6A' },
                { base: '#594438', dark: '#3A2A22', light: '#796458' },
                { base: '#52443B', dark: '#322824', light: '#72645B' }
            ];
            colors = browns[Math.floor(Math.random() * browns.length)];
            glowColor = null;
    }
    
    const shape = generateAsteroidShape(baseSize / 2);
    const craterCount = type === 'COMMON' ? 3 + Math.floor(Math.random() * 4) : 5 + Math.floor(Math.random() * 5);
    const craters = generateCraters(baseSize / 2, craterCount);
    
    return {
        id: id,
        x: x,
        y: y,
        baseSize: baseSize,
        speed: speed,
        type: type,
        reward: reward,
        colors: colors,
        glowColor: glowColor,
        shape: shape,
        craters: craters,
        rotation: Math.random() * Math.PI * 2,
        rotationSpeed: (Math.random() - 0.5) * 0.04,
        wobble: Math.random() * Math.PI * 2,
        wobbleSpeed: Math.random() * 0.02,
        wobbleAmount: Math.random() * 0.5,
        hitRadius: baseSize * 0.45
    };
}

function fireBullet() {
    if (!gameState.gameActive || !gameState.ship) return;
    
    const now = Date.now();
    if (now - gameState.lastFireTime < CONFIG.FIRE_RATE) return;
    gameState.lastFireTime = now;
    
    if (!isAudioUnlocked) {
        unlockAudio();
    }
    
    [-16, 16].forEach(offsetX => {
        gameState.bullets.push({
            x: gameState.ship.x + offsetX,
            y: gameState.ship.y - 34,
            width: 4,
            height: 20,
            speed: CONFIG.BULLET_SPEED
        });
    });
    
    if (isAudioUnlocked && gameState.audioEnabled) {
        try {
            const laserSound = new Audio('sounds/laser.mp3');
            laserSound.volume = 0.4;
            laserSound.play().catch(() => {});
        } catch (e) {}
    }
}

function createExplosion(x, y, colors, asteroid) {
    const particleColors = [colors.base, colors.dark, colors.light, '#FFF'];
    
    for (let i = 0; i < 18; i++) {
        gameState.particles.push({
            x: x,
            y: y,
            vx: (Math.random() - 0.5) * 10,
            vy: (Math.random() - 0.5) * 10,
            radius: Math.random() * 4 + 1,
            color: particleColors[Math.floor(Math.random() * particleColors.length)],
            life: 25 + Math.floor(Math.random() * 15)
        });
    }
    
    playSound('explosion.mp3', 0.6);
    
    if (asteroid.type === 'LEGENDARY') {
        showNotification('⭐ LEGENDARY!', `+$${asteroid.reward.toFixed(4)} USDT`, true);
        setTimeout(() => playSound('powerup.mp3', 1.0), 100);
    } else if (asteroid.type === 'EPIC') {
        showNotification('🔮 EPIC!', `+$${asteroid.reward.toFixed(4)} USDT`, true);
        setTimeout(() => playSound('powerup.mp3', 0.9), 100);
    } else if (asteroid.type === 'RARE') {
        showNotification('💎 RARE!', `+$${asteroid.reward.toFixed(4)} USDT`, true);
        setTimeout(() => playSound('powerup.mp3', 0.8), 100);
    }
}

function handleShipCollision(asteroid) {
    if (gameState.invincibilityFrames > 0) return false;
    
    const ship = gameState.ship;
    if (!ship) return false;
    
    const dx = ship.x - asteroid.x;
    const dy = ship.y - asteroid.y;
    const distance = Math.sqrt(dx * dx + dy * dy);
    const collisionDist = asteroid.hitRadius + 25;
    
    if (distance < collisionDist) {
        gameState.lives--;
        gameState.invincibilityFrames = CONFIG.INVINCIBILITY_FRAMES;
        
        createExplosion(ship.x, ship.y, { base: '#ff3366', dark: '#cc0033', light: '#ff6699' }, asteroid);
        
        animateLifeLost();
        showNotification('⚠️ HIT!', `${gameState.lives} lives remaining`, true);
        playSound('explosion.mp3', 0.8);
        
        if (gameState.lives <= 0) {
            gameOver();
            return true;
        }
        
        updateUI();
        return true;
    }
    
    return false;
}

// Game Over (lost all lives)
async function gameOver() {
    gameState.gameActive = false;
    
    if (gameState.gameTimer) clearInterval(gameState.gameTimer);
    if (gameState.spawnTimer) clearInterval(gameState.spawnTimer);
    if (animationFrameId) cancelAnimationFrame(animationFrameId);
    
    stopBackgroundMusic();
    
    const lostEarnings = gameState.earnings;
    gameState.earnings = 0;
    
    const stats = {
        common: gameState.destroyedAsteroids.filter(a => a.type === 'COMMON').length,
        rare: gameState.destroyedAsteroids.filter(a => a.type === 'RARE').length,
        epic: gameState.destroyedAsteroids.filter(a => a.type === 'EPIC').length,
        legendary: gameState.destroyedAsteroids.filter(a => a.type === 'LEGENDARY').length
    };
    
    console.log('💀 GAME OVER - Lost $' + lostEarnings.toFixed(4));
    
    if (gameState.sessionId && gameState.sessionToken) {
        try {
            await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: gameState.sessionId,
                    session_token: gameState.sessionToken,
                    wallet: gameState.wallet,
                    score: gameState.score,
                    earnings: 0,
                    stats: stats,
                    destroyed_asteroids: []
                })
            });
        } catch (e) {
            console.error('❌ Error:', e);
        }
    }
    
    setTimeout(() => {
        showGameOver(lostEarnings);
    }, 500);
}

function startSpawnTimer() {
    if (gameState.spawnTimer) clearInterval(gameState.spawnTimer);
    
    const interval = getSpawnInterval();
    const maxAsteroids = getMaxAsteroids();
    
    gameState.spawnTimer = setInterval(() => {
        if (!gameState.gameActive) return;
        
        if (gameState.asteroids.length < maxAsteroids) {
            gameState.asteroids.push(createAsteroid(gameState.asteroidSpawnCounter++, true));
        }
    }, interval);
}

function startGameTimer() {
    gameState.timeLeft = CONFIG.GAME_DURATION;
    updateUI();
    
    if (gameState.gameTimer) clearInterval(gameState.gameTimer);
    
    gameState.gameTimer = setInterval(() => {
        if (gameState.gameActive) {
            gameState.timeLeft--;
            
            if (gameState.invincibilityFrames > 0) {
                gameState.invincibilityFrames--;
            }
            
            updateUI();
            
            if (gameState.timeLeft <= 0) {
                clearInterval(gameState.gameTimer);
                endGame();
            }
        }
    }, 1000);
}

// End game (time up - success)
async function endGame() {
    gameState.gameActive = false;
    if (gameState.gameTimer) clearInterval(gameState.gameTimer);
    if (gameState.spawnTimer) clearInterval(gameState.spawnTimer);
    
    stopBackgroundMusic();
    
    // Contar por tipo
    const stats = {
        common: gameState.destroyedAsteroids.filter(a => a.type === 'COMMON').length,
        rare: gameState.destroyedAsteroids.filter(a => a.type === 'RARE').length,
        epic: gameState.destroyedAsteroids.filter(a => a.type === 'EPIC').length,
        legendary: gameState.destroyedAsteroids.filter(a => a.type === 'LEGENDARY').length
    };
    
    // Recalcular earnings
    const calculatedEarnings = gameState.destroyedAsteroids.reduce((sum, a) => sum + a.reward, 0);
    gameState.earnings = calculatedEarnings;
    
    console.log(`🏆 MISSION COMPLETE - Earned $${gameState.earnings.toFixed(4)}`);
    console.log(`📊 Stats:`, stats);
    
    // Preparar lista simplificada de asteroides
    const destroyedList = gameState.destroyedAsteroids.map(a => ({
        id: a.id,
        type: a.type,
        reward: a.reward
    }));
    
    if (gameState.sessionId && gameState.sessionToken) {
        try {
            const response = await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: gameState.sessionId,
                    session_token: gameState.sessionToken,
                    wallet: gameState.wallet,
                    score: gameState.score,
                    earnings: gameState.earnings,
                    stats: stats,
                    destroyed_asteroids: destroyedList
                })
            });
            
            const result = await response.json();
            console.log('✅ Game-end response:', result);
            
            if (result.success) {
                console.log(`💰 Final earnings: $${result.final_earnings}`);
                console.log(`📦 New balance: $${result.new_balance}`);
                
                if (result.warning) {
                    console.warn(`⚠️ Warning: ${result.warning}`);
                }
            } else {
                console.error('❌ Error:', result.error);
                
                if (result.banned) {
                    showNotification('⛔ CONTA SUSPENSA', result.error, false);
                }
            }
        } catch (e) {
            console.error('❌ Network error:', e);
        }
    }
    
    setTimeout(() => {
        showEndGameResults(stats);
    }, 500);
    
    if (animationFrameId) cancelAnimationFrame(animationFrameId);
}

function updateShipPosition() {
    if (!gameState.ship || !gameState.gameActive) return;
    
    const speed = CONFIG.SHIP_SPEED;
    const minX = 50;
    const maxX = canvas.width - 50;
    
    if (gameState.keys.left) {
        gameState.ship.x = Math.max(minX, gameState.ship.x - speed);
    }
    if (gameState.keys.right) {
        gameState.ship.x = Math.min(maxX, gameState.ship.x + speed);
    }
    
    if (gameState.keys.fire) {
        fireBullet();
    }
    
    if (gameState.invincibilityFrames > 0) {
        gameState.invincibilityFrames--;
    }
}

// Exports
window.canvas = canvas;
window.ctx = ctx;
window.stars = stars;
window.initCanvas = initCanvas;
window.resizeCanvas = resizeCanvas;
window.createAsteroid = createAsteroid;
window.fireBullet = fireBullet;
window.createExplosion = createExplosion;
window.handleShipCollision = handleShipCollision;
window.gameOver = gameOver;
window.startSpawnTimer = startSpawnTimer;
window.startGameTimer = startGameTimer;
window.endGame = endGame;
window.updateShipPosition = updateShipPosition;