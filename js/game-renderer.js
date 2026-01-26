/* ============================================
   CRYPTO ASTEROID RUSH - Game Renderer v3.0
   File: js/game-renderer.js
   Collision detection and rendering
   ============================================ */

// Draw background with animated stars
function drawBackground() {
    const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
    gradient.addColorStop(0, '#0c0b1a');
    gradient.addColorStop(0.5, '#1a0b2e');
    gradient.addColorStop(1, '#0f0820');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    stars.forEach(star => {
        star.y += star.speed;
        if (star.y > canvas.height) {
            star.y = 0;
            star.x = Math.random() * canvas.width;
        }
        ctx.globalAlpha = star.opacity;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(star.x, star.y, star.size, star.size);
    });
    ctx.globalAlpha = 1;
}

// Draw explosion particles
function drawParticles() {
    for (let i = gameState.particles.length - 1; i >= 0; i--) {
        const particle = gameState.particles[i];
        particle.x += particle.vx;
        particle.y += particle.vy;
        particle.vy += 0.15;
        particle.life--;
        
        ctx.globalAlpha = particle.life / 40;
        ctx.fillStyle = particle.color;
        ctx.beginPath();
        ctx.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
        ctx.fill();
        
        if (particle.life <= 0) {
            gameState.particles.splice(i, 1);
        }
    }
    ctx.globalAlpha = 1;
}

// Draw ship (uses AAA renderer)
function drawShip() {
    if (typeof drawShipAAA === 'function') {
        drawShipAAA();
    }
}

// Draw laser bullets
function drawBullets() {
    for (let i = gameState.bullets.length - 1; i >= 0; i--) {
        const bullet = gameState.bullets[i];
        bullet.y -= bullet.speed;
        
        // Laser gradient
        const laser = ctx.createLinearGradient(bullet.x, bullet.y, bullet.x, bullet.y + 25);
        laser.addColorStop(0, '#ffffff');
        laser.addColorStop(0.3, '#00ff00');
        laser.addColorStop(1, '#00aa00');
        
        ctx.strokeStyle = laser;
        ctx.lineWidth = 4;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(bullet.x + 2, bullet.y);
        ctx.lineTo(bullet.x + 2, bullet.y + 25);
        ctx.stroke();
        
        // Glow tip
        ctx.shadowColor = '#00ff00';
        ctx.shadowBlur = 15;
        ctx.fillStyle = '#00ff00';
        ctx.fillRect(bullet.x, bullet.y - 2, 4, 4);
        ctx.shadowBlur = 0;
        
        if (bullet.y < -30) {
            gameState.bullets.splice(i, 1);
        }
    }
}

// Draw single asteroid with irregular shape
function drawAsteroid(asteroid) {
    ctx.save();
    
    // Update position
    asteroid.y += asteroid.speed;
    asteroid.rotation += asteroid.rotationSpeed;
    asteroid.wobble += asteroid.wobbleSpeed;
    asteroid.x += Math.sin(asteroid.wobble) * asteroid.wobbleAmount;
    
    // Keep in bounds
    if (asteroid.x < 20) asteroid.x = 20;
    if (asteroid.x > canvas.width - 20) asteroid.x = canvas.width - 20;
    
    const centerX = asteroid.x;
    const centerY = asteroid.y;
    
    ctx.translate(centerX, centerY);
    ctx.rotate(asteroid.rotation);
    
    // Glow effect for valuable asteroids
    if (asteroid.glowColor) {
        if (asteroid.type === 'LEGENDARY') {
            const pulse = Math.sin(Date.now() / 150) * 0.4 + 0.6;
            ctx.shadowColor = asteroid.glowColor;
            ctx.shadowBlur = 30 + pulse * 25;
        } else if (asteroid.type === 'EPIC') {
            const pulse = Math.sin(Date.now() / 200) * 0.3 + 0.7;
            ctx.shadowColor = asteroid.glowColor;
            ctx.shadowBlur = 25 + pulse * 15;
        } else if (asteroid.type === 'RARE') {
            ctx.shadowColor = asteroid.glowColor;
            ctx.shadowBlur = 20;
        }
    }
    
    // Draw main irregular shape
    ctx.beginPath();
    if (asteroid.shape && asteroid.shape.length > 0) {
        ctx.moveTo(asteroid.shape[0].x, asteroid.shape[0].y);
        for (let i = 1; i < asteroid.shape.length; i++) {
            ctx.lineTo(asteroid.shape[i].x, asteroid.shape[i].y);
        }
        ctx.closePath();
    }
    
    // Gradient fill
    const grad = ctx.createRadialGradient(
        -asteroid.baseSize * 0.2, -asteroid.baseSize * 0.2, 0,
        0, 0, asteroid.baseSize * 0.6
    );
    grad.addColorStop(0, asteroid.colors.light);
    grad.addColorStop(0.5, asteroid.colors.base);
    grad.addColorStop(1, asteroid.colors.dark);
    ctx.fillStyle = grad;
    ctx.fill();
    
    // Outline
    ctx.strokeStyle = asteroid.colors.dark;
    ctx.lineWidth = 2;
    ctx.stroke();
    
    ctx.shadowBlur = 0;
    
    // Draw craters
    if (asteroid.craters) {
        ctx.fillStyle = asteroid.colors.dark;
        asteroid.craters.forEach(crater => {
            ctx.beginPath();
            ctx.arc(crater.x, crater.y, crater.radius, 0, Math.PI * 2);
            ctx.fill();
            
            ctx.fillStyle = 'rgba(0,0,0,0.3)';
            ctx.beginPath();
            ctx.arc(crater.x + 1, crater.y + 1, crater.radius * 0.6, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = asteroid.colors.dark;
        });
    }
    
    // Surface texture
    ctx.fillStyle = 'rgba(0,0,0,0.15)';
    for (let i = 0; i < 8; i++) {
        const tx = (Math.random() - 0.5) * asteroid.baseSize * 0.7;
        const ty = (Math.random() - 0.5) * asteroid.baseSize * 0.7;
        ctx.beginPath();
        ctx.arc(tx, ty, 1 + Math.random() * 2, 0, Math.PI * 2);
        ctx.fill();
    }
    
    ctx.restore();
    
    return { centerX, centerY };
}

// Draw all asteroids and check collisions
function drawAsteroids() {
    for (let i = gameState.asteroids.length - 1; i >= 0; i--) {
        const asteroid = gameState.asteroids[i];
        const pos = drawAsteroid(asteroid);
        
        // Check collision with ship FIRST
        if (gameState.ship && gameState.invincibilityFrames <= 0) {
            const dx = gameState.ship.x - pos.centerX;
            const dy = gameState.ship.y - pos.centerY;
            const distance = Math.sqrt(dx * dx + dy * dy);
            const collisionDist = asteroid.hitRadius + 25;
            
            if (distance < collisionDist) {
                // Ship collision!
                gameState.lives--;
                gameState.invincibilityFrames = CONFIG.INVINCIBILITY_FRAMES;
                
                createExplosion(gameState.ship.x, gameState.ship.y - 10, 
                    { base: '#ff3366', dark: '#cc0033', light: '#ff6699' }, asteroid);
                
                // Remove the asteroid that hit the ship
                gameState.asteroids.splice(i, 1);
                
                animateLifeLost();
                showNotification('⚠️ COLLISION!', `${gameState.lives} lives left`, true);
                playSound('explosion.mp3', 0.8);
                
                if (gameState.lives <= 0) {
                    gameOver();
                    return;
                }
                
                updateUI();
                continue;
            }
        }
        
        // Collision detection with bullets
        for (let j = gameState.bullets.length - 1; j >= 0; j--) {
            const bullet = gameState.bullets[j];
            const dx = (bullet.x + 2) - pos.centerX;
            const dy = (bullet.y + 12) - pos.centerY;
            const distance = Math.sqrt(dx * dx + dy * dy);
            
            if (distance < asteroid.hitRadius + 8) {
                // Create explosion
                createExplosion(pos.centerX, pos.centerY, asteroid.colors, asteroid);
                
                // Update score
                gameState.score++;
                
                // Update earnings ONLY for valuable asteroids
                if (asteroid.reward > 0) {
                    gameState.earnings += asteroid.reward;
                }
                
                // Track destroyed
                gameState.destroyedAsteroids.push(asteroid);
                
                // ============================================
                // CRITICAL: Register event with server
                // ============================================
                if (typeof SessionManager !== 'undefined' && SessionManager.hasActiveSession()) {
                    // Determine reward type from asteroid type
                    let rewardType = 'none';
                    if (asteroid.type === 'LEGENDARY') {
                        rewardType = 'legendary';
                    } else if (asteroid.type === 'EPIC') {
                        rewardType = 'epic';
                    } else if (asteroid.type === 'RARE') {
                        rewardType = 'rare';
                    } else if (asteroid.reward > 0) {
                        rewardType = 'common';
                    }
                    
                    // Add to queue (non-blocking)
                    SessionManager.recordEvent(asteroid.id, rewardType);
                }
                
                // Remove bullet and asteroid
                gameState.bullets.splice(j, 1);
                gameState.asteroids.splice(i, 1);
                
                // Update UI
                updateUI();
                break;
            }
        }
        
        // Remove if off screen
        if (asteroid.y > canvas.height + 100) {
            gameState.asteroids.splice(i, 1);
        }
    }
}

// Draw collision hitbox (debug mode)
function drawHitboxes() {
    // Ship hitbox
    if (gameState.ship) {
        ctx.strokeStyle = gameState.invincibilityFrames > 0 ? 'yellow' : 'lime';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.arc(gameState.ship.x, gameState.ship.y, 25, 0, Math.PI * 2);
        ctx.stroke();
    }
    
    // Asteroid hitboxes
    ctx.strokeStyle = 'red';
    gameState.asteroids.forEach(asteroid => {
        ctx.beginPath();
        ctx.arc(asteroid.x, asteroid.y, asteroid.hitRadius, 0, Math.PI * 2);
        ctx.stroke();
    });
}

// Main game loop
function gameLoop() {
    if (!gameState.gameActive) return;
    
    // Update ship position based on input
    updateShipPosition();
    
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawBackground();
    drawParticles();
    drawAsteroids();
    drawBullets();
    drawShip();
    
    // Debug hitboxes (uncomment to see)
    // drawHitboxes();
    
    animationFrameId = requestAnimationFrame(gameLoop);
}

// Exports
window.drawBackground = drawBackground;
window.drawParticles = drawParticles;
window.drawShip = drawShip;
window.drawBullets = drawBullets;
window.drawAsteroids = drawAsteroids;
window.drawAsteroid = drawAsteroid;
window.drawHitboxes = drawHitboxes;
window.gameLoop = gameLoop;