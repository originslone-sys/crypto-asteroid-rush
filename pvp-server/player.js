// ============================================
// UNOBIX PvP Server - Player State
// ============================================

const config = require('./config');

class Player {
    constructor(googleUid, displayName, socketId, slot) {
        this.googleUid = googleUid;
        this.displayName = displayName;
        this.socketId = socketId;
        this.slot = slot; // 1 = bottom, 2 = top

        // Posição (será definida no init)
        this.x = config.ARENA_WIDTH / 2;
        this.y = slot === 1
            ? config.ARENA_HEIGHT - 120
            : 120;

        // Limites de movimento Y
        this.yMin = slot === 1 ? config.ARENA_HEIGHT * 0.5 : 50;
        this.yMax = slot === 1 ? config.ARENA_HEIGHT - 50 : config.ARENA_HEIGHT * 0.5;

        // Estado
        this.lives = config.LIVES;
        this.invincibilityFrames = 0;
        this.connected = true;
        this.disconnectedAt = null;

        // Stats
        this.asteroidsDestroyed = 0;
        this.shotsFired = 0;
        this.hits = 0; // Tiros que acertaram a nave inimiga

        // Balas
        this.bullets = [];
        this.lastFireTime = 0;

        // Input
        this.input = {
            left: false,
            right: false,
            up: false,
            down: false,
            fire: false
        };

        // Velocidade atual (aceleração/inércia)
        this.vx = 0;
        this.vy = 0;
    }

    initPosition() {
        this.x = config.ARENA_WIDTH / 2;
        this.y = this.slot === 1
            ? config.ARENA_HEIGHT - 120
            : 120;
        this.vx = 0;
        this.vy = 0;
    }

    updatePosition() {
        const ACCEL_X  = 3.5;   // px/frame² horizontal (gradual)
        const ACCEL_Y  = 2.8;   // px/frame² vertical (gradual)
        const MAX_VX   = config.SHIP_SPEED_X;  // 10
        const MAX_VY   = config.SHIP_SPEED_Y;  // 7
        const FRICTION = 0.90;  // amortecimento suave (mais inércia, glide natural)

        // Aceleração horizontal
        if (this.input.left)       this.vx -= ACCEL_X;
        else if (this.input.right) this.vx += ACCEL_X;
        else                       this.vx *= FRICTION;

        // Aceleração vertical
        if (this.input.up)         this.vy -= ACCEL_Y;
        else if (this.input.down)  this.vy += ACCEL_Y;
        else                       this.vy *= FRICTION;

        // Cortar velocidade residual muito baixa
        if (Math.abs(this.vx) < 0.15) this.vx = 0;
        if (Math.abs(this.vy) < 0.15) this.vy = 0;

        // Limitar velocidade máxima
        this.vx = Math.max(-MAX_VX, Math.min(MAX_VX, this.vx));
        this.vy = Math.max(-MAX_VY, Math.min(MAX_VY, this.vy));

        // Aplicar velocidade
        this.x += this.vx;
        this.y += this.vy;

        // Limites X — zerar velocidade ao bater na parede
        if (this.x <= 50)                       { this.x = 50;                       this.vx = 0; }
        if (this.x >= config.ARENA_WIDTH - 50)  { this.x = config.ARENA_WIDTH - 50;  this.vx = 0; }

        // Limites Y — zerar velocidade ao bater no limite da metade
        if (this.y <= this.yMin) { this.y = this.yMin; this.vy = 0; }
        if (this.y >= this.yMax) { this.y = this.yMax; this.vy = 0; }

        if (this.invincibilityFrames > 0) this.invincibilityFrames--;
    }

    tryFire(now) {
        if (!this.input.fire) return null;
        if (now - this.lastFireTime < config.FIRE_RATE_MS) return null;
        if (this.bullets.length >= config.MAX_BULLETS_PER_PLAYER) return null;

        this.lastFireTime = now;
        this.shotsFired++;

        // Direção do tiro: slot 1 atira pra cima, slot 2 atira pra baixo
        const direction = this.slot === 1 ? -1 : 1;

        const bullet = {
            id: `${this.googleUid}_${this.shotsFired}`,
            ownerSlot: this.slot,
            x: this.x,
            y: this.slot === 1 ? this.y - 34 : this.y + 34,
            vy: config.BULLET_SPEED * direction,
            alive: true
        };

        this.bullets.push(bullet);
        return bullet;
    }

    updateBullets() {
        for (let i = this.bullets.length - 1; i >= 0; i--) {
            const b = this.bullets[i];
            b.y += b.vy;

            // Remover se saiu da tela
            if (b.y < -30 || b.y > config.ARENA_HEIGHT + 30 || !b.alive) {
                this.bullets.splice(i, 1);
            }
        }
    }

    takeDamage() {
        if (this.invincibilityFrames > 0) return false;

        this.lives--;
        this.invincibilityFrames = config.INVINCIBILITY_FRAMES;
        return true;
    }

    isDead() {
        return this.lives <= 0;
    }

    getState() {
        return {
            x: Math.round(this.x * 10) / 10,
            y: Math.round(this.y * 10) / 10,
            vx: Math.round(this.vx * 100) / 100,
            vy: Math.round(this.vy * 100) / 100,
            lives: this.lives,
            invincible: this.invincibilityFrames > 0,
            asteroidsDestroyed: this.asteroidsDestroyed,
            displayName: this.displayName,
            connected: this.connected
        };
    }

    getBulletsState() {
        return this.bullets.map(b => ({
            x: Math.round(b.x),
            y: Math.round(b.y),
            ownerSlot: b.ownerSlot
        }));
    }
}

module.exports = Player;
