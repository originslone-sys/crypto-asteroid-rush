/* ============================================
   UNOBIX - Game Engine v8.0
   File: js/game-engine.js
   ARQUITETURA SEGURA: Sem eventos individuais
   Contagem local, envio apenas no final
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
        wobbleSpeed: type === 'LEGENDARY' ? 0.05 + Math.random() * 0.04 :
                     type === 'EPIC' ? 0.035 + Math.random() * 0.035 :
                     type === 'RARE' ? 0.025 + Math.random() * 0.02 :
                     Math.random() * 0.02,
        wobbleAmount: type === 'LEGENDARY' ? 5 + Math.random() * 4 :
                      type === 'EPIC' ? 3.5 + Math.random() * 3.5 :
                      type === 'RARE' ? 2.5 + Math.random() * 2 :
                      Math.random() * 0.5,
        hitRadius: baseSize * 0.45
    };
}

function fireBullet() {
    if (!gameState.gameActive || !gameState.ship) return;
    
    const now = Date.now();
    if (now - gameState.lastFireTime < CONFIG.FIRE_RATE) return;
    gameState.lastFireTime = now;
    
    if (typeof isAudioUnlocked !== 'undefined' && !isAudioUnlocked && typeof unlockAudio === 'function') {
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
    
    if (typeof isAudioUnlocked !== 'undefined' && isAudioUnlocked && gameState.audioEnabled) {
        if (typeof playSound === 'function') playSound('laser.mp3', 0.4);
    }
}

// ============================================
// EXPLOSÃO - NOTIFICAÇÕES EM BRL
// ============================================
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
    
    if (typeof playSound === 'function') playSound('explosion.mp3', 0.6);
    
    // Notificações em português e formato BRL
    const rewardText = `+R$ ${asteroid.reward.toFixed(2)}`.replace('.', ',');
    
    if (asteroid.type === 'LEGENDARY') {
        if (typeof showNotification === 'function') showNotification('⭐ LENDÁRIO!', rewardText, true);
        setTimeout(() => { if (typeof playSound === 'function') playSound('powerup.mp3', 1.0); }, 100);
    } else if (asteroid.type === 'EPIC') {
        if (typeof showNotification === 'function') showNotification('🔮 ÉPICO!', rewardText, true);
        setTimeout(() => { if (typeof playSound === 'function') playSound('powerup.mp3', 0.9); }, 100);
    } else if (asteroid.type === 'RARE') {
        if (typeof showNotification === 'function') showNotification('💎 RARO!', rewardText, true);
        setTimeout(() => { if (typeof playSound === 'function') playSound('powerup.mp3', 0.8); }, 100);
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
        gameState.invincibilityFrames = CONFIG.INVINCIBILITY_FRAMES || 120;
        
        createExplosion(ship.x, ship.y, { base: '#ff3366', dark: '#cc0033', light: '#ff6699' }, asteroid);
        
        if (typeof animateLifeLost === 'function') animateLifeLost();
        if (typeof showNotification === 'function') showNotification('⚠️ DANO!', `${gameState.lives} vidas restantes`, true);
        if (typeof playSound === 'function') playSound('explosion.mp3', 0.8);
        
        if (gameState.lives <= 0) {
            gameOver();
            return true;
        }
        
        if (typeof updateUI === 'function') updateUI();
        return true;
    }
    
    return false;
}

// ============================================
// HELPER: Obter estatísticas do jogo
// ============================================
function getGameStats() {
    const stats = {
        common: 0,
        rare: 0,
        epic: 0,
        legendary: 0
    };
    
    gameState.destroyedAsteroids.forEach(asteroid => {
        const type = asteroid.type.toLowerCase();
        if (stats.hasOwnProperty(type)) {
            stats[type]++;
        }
    });
    
    return stats;
}

// ============================================
// GAME OVER (perdeu todas as vidas)
// ============================================
async function gameOver() {
    gameState.gameActive = false;
    
    if (gameState.gameTimer) clearInterval(gameState.gameTimer);
    if (gameState.spawnTimer) clearInterval(gameState.spawnTimer);
    if (animationFrameId) cancelAnimationFrame(animationFrameId);
    
    if (typeof stopBackgroundMusic === 'function') stopBackgroundMusic();
    
    const lostEarnings = gameState.earnings;
    gameState.earnings = 0;
    
    const stats = getGameStats();
    
    console.log('💀 GAME OVER - Perdeu R$' + lostEarnings.toFixed(2));
    
    // Finalizar sessão no servidor (uma única requisição)
    if (typeof SessionManager !== 'undefined' && SessionManager.hasActiveSession()) {
        try {
            // Marcar como derrota
            gameState.lives = 0;
            
            const result = await SessionManager.endSession(
                gameState.score,
                0, // earnings = 0 em game over
                stats
            );
            
            console.log('✅ Game-over enviado ao servidor:', result);
            
        } catch (e) {
            console.error('❌ Erro ao enviar game-over:', e);
            SessionManager.clearSession();
        }
    }
    
    setTimeout(() => {
        if (typeof showGameOver === 'function') showGameOver(lostEarnings);
    }, 500);
}

function startSpawnTimer() {
    if (gameState.spawnTimer) clearInterval(gameState.spawnTimer);
    
    const interval = typeof getSpawnInterval === 'function' ? getSpawnInterval() : 500;
    const maxAsteroids = typeof getMaxAsteroids === 'function' ? getMaxAsteroids() : 10;
    
    gameState.spawnTimer = setInterval(() => {
        if (!gameState.gameActive) return;
        
        if (gameState.asteroids.length < maxAsteroids) {
            gameState.asteroids.push(createAsteroid(gameState.asteroidSpawnCounter++, true));
        }
    }, interval);
}

function stopSpawnTimer() {
    if (gameState.spawnTimer) {
        clearInterval(gameState.spawnTimer);
        gameState.spawnTimer = null;
    }
}

function startGameTimer() {
    gameState.timeLeft = CONFIG.GAME_DURATION;
    if (typeof updateUI === 'function') updateUI();
    
    if (gameState.gameTimer) clearInterval(gameState.gameTimer);
    
    gameState.gameTimer = setInterval(() => {
        if (gameState.gameActive) {
            gameState.timeLeft--;
            
            if (gameState.invincibilityFrames > 0) {
                gameState.invincibilityFrames--;
            }
            
            if (typeof updateUI === 'function') updateUI();
            
            if (gameState.timeLeft <= 0) {
                clearInterval(gameState.gameTimer);
                endGame();
            }
        }
    }, 1000);
}

function stopGameTimer() {
    if (gameState.gameTimer) {
        clearInterval(gameState.gameTimer);
        gameState.gameTimer = null;
    }
}

// ============================================
// END GAME (tempo acabou - vitória!)
// ============================================
async function endGame() {
    gameState.gameActive = false;
    if (gameState.gameTimer) clearInterval(gameState.gameTimer);
    if (gameState.spawnTimer) clearInterval(gameState.spawnTimer);
    
    if (typeof stopBackgroundMusic === 'function') stopBackgroundMusic();
    
    // Calcular estatísticas finais
    const stats = getGameStats();
    
    // Recalcular earnings do lado do cliente
    const calculatedEarnings = gameState.destroyedAsteroids.reduce((sum, a) => sum + a.reward, 0);
    gameState.earnings = calculatedEarnings;
    
    console.log(`🏆 MISSÃO COMPLETA - Ganhou R$${gameState.earnings.toFixed(2)}`);
    console.log(`📊 Stats:`, stats);
    
    // Variáveis para resposta do servidor
    let serverEarnings = gameState.earnings;
    let serverBalance = null;
    
    // Enviar ao servidor (UMA ÚNICA requisição)
    if (typeof SessionManager !== 'undefined' && SessionManager.hasActiveSession()) {
        try {
            if (typeof showNotification === 'function') {
                showNotification('⏳ PROCESSANDO', 'Salvando resultados...', true);
            }
            
            const result = await SessionManager.endSession(
                gameState.score,
                gameState.earnings,
                stats
            );
            
            console.log('✅ Resultado do servidor:', result);
            
            if (result && result.success) {
                // Verificar se precisa de CAPTCHA
                if (result.captcha_required) {
                    // CAPTCHA necessário - se frontend pensava que era premium, forçar exibição
                    if (typeof CaptchaManager !== 'undefined' && typeof CaptchaManager.forceShow === 'function') {
                        CaptchaManager.forceShow();
                    }
                    serverEarnings = result.pending_earnings || gameState.earnings;
                } else {
                    serverEarnings = parseFloat(result.final_earnings) || gameState.earnings;
                    serverBalance = result.new_balance !== null ? parseFloat(result.new_balance) : null;
                    
                    console.log(`💰 Ganhos confirmados: R$${serverEarnings}`);
                    console.log(`📦 Novo saldo: R$${serverBalance}`);
                    
                    gameState.earnings = serverEarnings;
                    gameState.serverConfirmedEarnings = serverEarnings;
                    gameState.newBalance = serverBalance;
                }
                
                if (result.warnings && result.warnings.length > 0) {
                    console.warn('⚠️ Avisos:', result.warnings);
                }
                
                if (result.flagged) {
                    console.warn('🚩 Sessão marcada para revisão');
                }
            } else if (result && result.error) {
                console.error('❌ Erro do servidor:', result.error);
            }
            
        } catch (e) {
            console.error('❌ Erro ao finalizar:', e);
            SessionManager.clearSession();
        }
    } else {
        console.error('❌ Sem SessionManager ou sessão ativa!');
    }
    
    setTimeout(() => {
        if (typeof showEndGameResults === 'function') {
            showEndGameResults(stats, serverEarnings, serverBalance);
        }
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

// ============================================
// EXPORTS
// ============================================
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
window.stopSpawnTimer = stopSpawnTimer;
window.startGameTimer = startGameTimer;
window.stopGameTimer = stopGameTimer;
window.endGame = endGame;
window.updateShipPosition = updateShipPosition;
window.getGameStats = getGameStats;

console.log('📦 game-engine.js v8.0 carregado (sem eventos individuais)');
