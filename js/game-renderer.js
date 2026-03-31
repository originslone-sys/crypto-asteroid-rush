/* ============================================
   UNOBIX - Game Renderer v6.0
   File: js/game-renderer.js
   ARQUITETURA SEGURA: Sem eventos ao servidor
   Apenas tracking local de asteroides
   
   v6.0 CHANGES:
   - Fundo espacial AAA+ com nebulosas, estrelas multicamada, 
     estrelas cadentes e paralaxe
   - Otimizações iOS: offscreen canvas caching, 
     reduced shadowBlur, object pooling, throttled effects
   - Device detection para ajustar qualidade automaticamente
   ============================================ */

// ============================================
// DEVICE DETECTION & QUALITY SETTINGS
// ============================================
const DeviceProfile = (() => {
    const ua = navigator.userAgent || '';
    const isIOS = /iPad|iPhone|iPod/.test(ua) || 
                  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isAndroid = /Android/.test(ua);
    const isMobile = isIOS || isAndroid || ('ontouchstart' in window);
    
    // Detect older/weaker iOS devices
    const screenSize = Math.max(window.screen.width, window.screen.height);
    const isLowEndIOS = isIOS && (screenSize <= 812 || navigator.hardwareConcurrency <= 4);
    
    let quality = 'high'; // desktop default
    if (isLowEndIOS) quality = 'low';
    else if (isIOS) quality = 'medium';
    else if (isMobile) quality = 'medium';
    
    return Object.freeze({
        isIOS,
        isAndroid,
        isMobile,
        quality, // 'low' | 'medium' | 'high'
        // Tunable knobs per quality
        maxStars:       quality === 'low' ? 60  : quality === 'medium' ? 100 : 160,
        maxParticles:   quality === 'low' ? 30  : quality === 'medium' ? 50  : 80,
        nebulaLayers:   quality === 'low' ? 1   : quality === 'medium' ? 2   : 3,
        shootingStars:  quality === 'low' ? false : true,
        dustParticles:  quality === 'low' ? 0   : quality === 'medium' ? 15  : 30,
        glowEnabled:    quality !== 'low',
        maxShadowBlur:  quality === 'low' ? 0   : quality === 'medium' ? 10  : 25,
        parallaxLayers: quality === 'low' ? 2   : 3,
    });
})();

console.log(`🖥️ Device: quality=${DeviceProfile.quality}, iOS=${DeviceProfile.isIOS}, mobile=${DeviceProfile.isMobile}`);

// ============================================
// OFFSCREEN CANVAS CACHE (drawn once, reused)
// ============================================
let bgCache = null;        // static background (gradient + nebulas)
let starsCache = null;     // static dim star layer (parallax 0 - doesn't move)
let bgCacheSize = { w: 0, h: 0 };

function invalidateBgCache() {
    bgCache = null;
    starsCache = null;
    bgCacheSize = { w: 0, h: 0 };
}

// Rebuild caches when canvas size changes
function ensureBgCache(w, h) {
    if (bgCache && bgCacheSize.w === w && bgCacheSize.h === h) return;
    bgCacheSize = { w, h };
    
    // --- Layer 1: Deep space gradient + nebulas ---
    bgCache = document.createElement('canvas');
    bgCache.width = w;
    bgCache.height = h;
    const bctx = bgCache.getContext('2d');
    
    // Deep space gradient
    const gradient = bctx.createLinearGradient(0, 0, 0, h);
    gradient.addColorStop(0, '#05040e');
    gradient.addColorStop(0.25, '#0a0618');
    gradient.addColorStop(0.5, '#110824');
    gradient.addColorStop(0.75, '#0d0620');
    gradient.addColorStop(1, '#080412');
    bctx.fillStyle = gradient;
    bctx.fillRect(0, 0, w, h);
    
    // Nebula clouds (soft radial gradients)
    const nebulas = [
        { x: w * 0.2, y: h * 0.3, r: w * 0.45, c1: 'rgba(40,10,80,0.12)', c2: 'rgba(80,20,120,0.04)', c3: 'transparent' },
        { x: w * 0.75, y: h * 0.6, r: w * 0.5,  c1: 'rgba(10,20,60,0.10)', c2: 'rgba(20,40,100,0.04)', c3: 'transparent' },
        { x: w * 0.5, y: h * 0.15, r: w * 0.35, c1: 'rgba(60,10,40,0.08)', c2: 'rgba(100,20,60,0.03)', c3: 'transparent' },
    ];
    
    const layerCount = DeviceProfile.nebulaLayers;
    for (let i = 0; i < Math.min(layerCount, nebulas.length); i++) {
        const n = nebulas[i];
        const ng = bctx.createRadialGradient(n.x, n.y, 0, n.x, n.y, n.r);
        ng.addColorStop(0, n.c1);
        ng.addColorStop(0.5, n.c2);
        ng.addColorStop(1, n.c3);
        bctx.fillStyle = ng;
        bctx.fillRect(0, 0, w, h);
    }
    
    // Subtle noise texture (very sparse dots)
    bctx.fillStyle = 'rgba(255,255,255,0.008)';
    for (let i = 0; i < 300; i++) {
        const nx = Math.random() * w;
        const ny = Math.random() * h;
        bctx.fillRect(nx, ny, 1, 1);
    }
    
    // --- Layer 2: Static far-away stars (don't scroll, just twinkle) ---
    starsCache = document.createElement('canvas');
    starsCache.width = w;
    starsCache.height = h;
    const sctx = starsCache.getContext('2d');
    
    // Dim background stars (very small, many)
    const farStarCount = Math.floor(DeviceProfile.maxStars * 0.6);
    for (let i = 0; i < farStarCount; i++) {
        const sx = Math.random() * w;
        const sy = Math.random() * h;
        const brightness = 0.15 + Math.random() * 0.25;
        const size = 0.5 + Math.random() * 0.8;
        
        // Color variation: white, blue-white, warm-white
        const colors = ['255,255,255', '200,220,255', '255,240,220', '180,200,255'];
        const color = colors[Math.floor(Math.random() * colors.length)];
        
        sctx.fillStyle = `rgba(${color},${brightness})`;
        sctx.fillRect(sx, sy, size, size);
    }
    
    console.log('🌌 Background cache rebuilt:', w, 'x', h);
}

// ============================================
// DYNAMIC STAR SYSTEM (parallax scrolling)
// ============================================
let dynamicStars = [];
let cosmicDust = [];
let shootingStarPool = [];
let _starsInitialized = false;

function initDynamicStars(w, h) {
    dynamicStars = [];
    cosmicDust = [];
    shootingStarPool = [];
    
    // Layer 1: Medium stars (slow scroll)
    const medCount = Math.floor(DeviceProfile.maxStars * 0.25);
    for (let i = 0; i < medCount; i++) {
        dynamicStars.push({
            x: Math.random() * w,
            y: Math.random() * h,
            size: 0.8 + Math.random() * 1.2,
            speed: 0.15 + Math.random() * 0.25,
            opacity: 0.3 + Math.random() * 0.4,
            twinkleSpeed: 1.5 + Math.random() * 3,
            twinkleOffset: Math.random() * Math.PI * 2,
            color: ['255,255,255', '220,230,255', '255,245,230'][Math.floor(Math.random() * 3)],
            layer: 1
        });
    }
    
    // Layer 2: Bright foreground stars (faster scroll)
    const brightCount = Math.floor(DeviceProfile.maxStars * 0.15);
    for (let i = 0; i < brightCount; i++) {
        dynamicStars.push({
            x: Math.random() * w,
            y: Math.random() * h,
            size: 1.2 + Math.random() * 1.8,
            speed: 0.4 + Math.random() * 0.6,
            opacity: 0.5 + Math.random() * 0.5,
            twinkleSpeed: 2 + Math.random() * 4,
            twinkleOffset: Math.random() * Math.PI * 2,
            color: ['255,255,255', '200,220,255', '255,200,180', '180,200,255'][Math.floor(Math.random() * 4)],
            layer: 2,
            // Some bright stars get a subtle cross/glow
            hasCross: Math.random() < 0.15
        });
    }
    
    // Cosmic dust particles (very subtle drifting motes)
    for (let i = 0; i < DeviceProfile.dustParticles; i++) {
        cosmicDust.push({
            x: Math.random() * w,
            y: Math.random() * h,
            size: 1 + Math.random() * 2.5,
            speed: 0.05 + Math.random() * 0.15,
            drift: (Math.random() - 0.5) * 0.3,
            opacity: 0.03 + Math.random() * 0.06,
            color: Math.random() < 0.5 ? '100,80,160' : '60,100,160'
        });
    }
    
    _starsInitialized = true;
}

// Shooting star system (sparse, dramatic)
let _lastShootingStar = 0;

function maybeSpawnShootingStar(time, w, h) {
    if (!DeviceProfile.shootingStars) return;
    if (shootingStarPool.length >= 2) return;
    if (time - _lastShootingStar < 4000) return; // min 4s between
    if (Math.random() > 0.008) return; // ~0.8% chance per frame
    
    _lastShootingStar = time;
    shootingStarPool.push({
        x: Math.random() * w * 0.8,
        y: Math.random() * h * 0.3,
        vx: 3 + Math.random() * 4,
        vy: 1.5 + Math.random() * 2,
        life: 1.0,
        decay: 0.015 + Math.random() * 0.01,
        length: 40 + Math.random() * 60,
        brightness: 0.6 + Math.random() * 0.4
    });
}

// ============================================
// MAIN DRAW BACKGROUND (called every frame)
// ============================================
function drawBackground() {
    const w = canvas.width;
    const h = canvas.height;
    const time = Date.now();
    const timeSec = time * 0.001;
    
    // Ensure caches
    ensureBgCache(w, h);
    if (!_starsInitialized) initDynamicStars(w, h);
    
    // Draw cached static background (gradient + nebulas)
    ctx.drawImage(bgCache, 0, 0);
    
    // Draw cached far stars with global twinkle (very cheap)
    const twinkleMod = Math.sin(timeSec * 0.7) * 0.08 + 0.92;
    ctx.globalAlpha = twinkleMod;
    ctx.drawImage(starsCache, 0, 0);
    ctx.globalAlpha = 1;
    
    // Draw dynamic scrolling stars
    for (let i = 0; i < dynamicStars.length; i++) {
        const s = dynamicStars[i];
        s.y += s.speed;
        if (s.y > h + 5) {
            s.y = -5;
            s.x = Math.random() * w;
        }
        
        // Twinkle
        const twinkle = Math.sin(timeSec * s.twinkleSpeed + s.twinkleOffset);
        const alpha = s.opacity * (0.7 + twinkle * 0.3);
        
        if (alpha < 0.05) continue; // skip invisible stars
        
        ctx.fillStyle = `rgba(${s.color},${alpha})`;
        
        if (s.size <= 1.2) {
            // Small stars: just a pixel rect (fastest)
            ctx.fillRect(s.x, s.y, s.size, s.size);
        } else {
            // Larger stars: small circle
            ctx.beginPath();
            ctx.arc(s.x, s.y, s.size * 0.5, 0, Math.PI * 2);
            ctx.fill();
            
            // Cross flare on bright stars (no shadowBlur!)
            if (s.hasCross && DeviceProfile.glowEnabled) {
                const crossAlpha = alpha * 0.35;
                ctx.strokeStyle = `rgba(${s.color},${crossAlpha})`;
                ctx.lineWidth = 0.5;
                const cl = s.size * 2.5;
                ctx.beginPath();
                ctx.moveTo(s.x - cl, s.y);
                ctx.lineTo(s.x + cl, s.y);
                ctx.moveTo(s.x, s.y - cl);
                ctx.lineTo(s.x, s.y + cl);
                ctx.stroke();
            }
        }
    }
    
    // Cosmic dust
    for (let i = 0; i < cosmicDust.length; i++) {
        const d = cosmicDust[i];
        d.y += d.speed;
        d.x += d.drift;
        if (d.y > h + 10) { d.y = -10; d.x = Math.random() * w; }
        if (d.x < -10) d.x = w + 10;
        if (d.x > w + 10) d.x = -10;
        
        ctx.fillStyle = `rgba(${d.color},${d.opacity})`;
        ctx.beginPath();
        ctx.arc(d.x, d.y, d.size, 0, Math.PI * 2);
        ctx.fill();
    }
    
    // Shooting stars
    maybeSpawnShootingStar(time, w, h);
    for (let i = shootingStarPool.length - 1; i >= 0; i--) {
        const ss = shootingStarPool[i];
        ss.x += ss.vx;
        ss.y += ss.vy;
        ss.life -= ss.decay;
        
        if (ss.life <= 0) {
            shootingStarPool.splice(i, 1);
            continue;
        }
        
        const alpha = ss.life * ss.brightness;
        const tailX = ss.x - ss.vx * ss.length * 0.15;
        const tailY = ss.y - ss.vy * ss.length * 0.15;
        
        const grad = ctx.createLinearGradient(tailX, tailY, ss.x, ss.y);
        grad.addColorStop(0, `rgba(255,255,255,0)`);
        grad.addColorStop(0.7, `rgba(220,240,255,${alpha * 0.4})`);
        grad.addColorStop(1, `rgba(255,255,255,${alpha})`);
        
        ctx.strokeStyle = grad;
        ctx.lineWidth = 1.5;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(tailX, tailY);
        ctx.lineTo(ss.x, ss.y);
        ctx.stroke();
        
        // Bright head dot
        ctx.fillStyle = `rgba(255,255,255,${alpha})`;
        ctx.beginPath();
        ctx.arc(ss.x, ss.y, 1, 0, Math.PI * 2);
        ctx.fill();
    }
}

// ============================================
// EXPLOSION PARTICLES (optimized pool)
// ============================================
function drawParticles() {
    const maxP = DeviceProfile.maxParticles;
    
    // Trim excess particles on low-end devices
    if (gameState.particles.length > maxP) {
        gameState.particles.splice(0, gameState.particles.length - maxP);
    }
    
    for (let i = gameState.particles.length - 1; i >= 0; i--) {
        const particle = gameState.particles[i];
        particle.x += particle.vx;
        particle.y += particle.vy;
        particle.vy += 0.15;
        particle.life--;
        
        const lifeRatio = particle.life / 40;
        if (lifeRatio < 0.02) {
            gameState.particles.splice(i, 1);
            continue;
        }
        
        ctx.globalAlpha = lifeRatio;
        ctx.fillStyle = particle.color;
        ctx.beginPath();
        ctx.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
        ctx.fill();
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
    const useShadow = DeviceProfile.glowEnabled;
    
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
        if (useShadow) {
            ctx.shadowColor = '#00ff00';
            ctx.shadowBlur = DeviceProfile.maxShadowBlur * 0.6;
        }
        ctx.fillStyle = '#00ff00';
        ctx.fillRect(bullet.x, bullet.y - 2, 4, 4);
        if (useShadow) ctx.shadowBlur = 0;
        
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
    
    // Glow effect for valuable asteroids (reduced on iOS)
    if (asteroid.glowColor && DeviceProfile.glowEnabled) {
        const maxBlur = DeviceProfile.maxShadowBlur;
        if (asteroid.type === 'LEGENDARY') {
            const pulse = Math.sin(Date.now() / 150) * 0.4 + 0.6;
            ctx.shadowColor = asteroid.glowColor;
            ctx.shadowBlur = Math.min(30 + pulse * 25, maxBlur);
        } else if (asteroid.type === 'EPIC') {
            const pulse = Math.sin(Date.now() / 200) * 0.3 + 0.7;
            ctx.shadowColor = asteroid.glowColor;
            ctx.shadowBlur = Math.min(25 + pulse * 15, maxBlur);
        } else if (asteroid.type === 'RARE') {
            ctx.shadowColor = asteroid.glowColor;
            ctx.shadowBlur = Math.min(20, maxBlur);
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
    
    // Surface texture (reduced on mobile)
    const textureDots = DeviceProfile.isMobile ? 4 : 8;
    ctx.fillStyle = 'rgba(0,0,0,0.15)';
    for (let i = 0; i < textureDots; i++) {
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
                
                if (typeof animateLifeLost === 'function') animateLifeLost();
                if (typeof showNotification === 'function') showNotification('⚠️ COLISÃO!', `${gameState.lives} vidas restantes`, true);
                if (typeof playSound === 'function') playSound('explosion.mp3', 0.8);
                
                if (gameState.lives <= 0) {
                    gameOver();
                    return;
                }
                
                if (typeof updateUI === 'function') updateUI();
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
                
                // Update earnings for valuable asteroids
                if (asteroid.reward > 0) {
                    gameState.earnings += asteroid.reward;
                }
                
                // Track destroyed asteroid LOCALLY (NÃO envia ao servidor!)
                gameState.destroyedAsteroids.push({
                    id: asteroid.id,
                    type: asteroid.type,
                    reward: asteroid.reward
                });
                
                // Log local apenas (sem requisição HTTP)
                if (typeof SessionManager !== 'undefined') {
                    SessionManager.recordLocalStat(asteroid.type);
                }
                
                // Remove bullet and asteroid
                gameState.bullets.splice(j, 1);
                gameState.asteroids.splice(i, 1);
                
                // Update UI
                if (typeof updateUI === 'function') updateUI();
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

// ============================================
// MAIN GAME LOOP
// ============================================
function gameLoop() {
    if (!gameState.gameActive) return;

    // Anti-cheat: pausar se zoom out ou janela muito pequena
    if (typeof checkZoomExploit === 'function' && checkZoomExploit()) {
        animationFrameId = requestAnimationFrame(gameLoop);
        return;
    }

    // Update ship position based on input
    if (typeof updateShipPosition === 'function') updateShipPosition();

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

// Handle resize: invalidate caches
window.addEventListener('resize', () => {
    invalidateBgCache();
    _starsInitialized = false;
});

// Exports
window.DeviceProfile = DeviceProfile;
window.drawBackground = drawBackground;
window.drawParticles = drawParticles;
window.drawShip = drawShip;
window.drawBullets = drawBullets;
window.drawAsteroids = drawAsteroids;
window.drawAsteroid = drawAsteroid;
window.drawHitboxes = drawHitboxes;
window.gameLoop = gameLoop;
window.invalidateBgCache = invalidateBgCache;

console.log('📦 game-renderer.js v6.0 carregado (AAA+ background, iOS optimized)');
