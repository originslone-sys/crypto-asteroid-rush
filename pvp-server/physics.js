// ============================================
// UNOBIX PvP Server - Physics & Collision
// ============================================

const config = require('./config');

/**
 * Distância entre dois pontos
 */
function distance(x1, y1, x2, y2) {
    const dx = x1 - x2;
    const dy = y1 - y2;
    return Math.sqrt(dx * dx + dy * dy);
}

/**
 * Verifica colisão circular
 */
function circleCollision(x1, y1, r1, x2, y2, r2) {
    return distance(x1, y1, x2, y2) < (r1 + r2);
}

/**
 * Spawna um asteroide em direção aleatória
 */
function spawnAsteroid(asteroidIdCounter) {
    const size = config.ASTEROID_SIZE_MIN +
        Math.random() * (config.ASTEROID_SIZE_MAX - config.ASTEROID_SIZE_MIN);
    const speed = config.ASTEROID_SPEED_MIN +
        Math.random() * (config.ASTEROID_SPEED_MAX - config.ASTEROID_SPEED_MIN);

    // Escolher direção de spawn: 0=topo, 1=base, 2=esquerda, 3=direita
    const spawnSide = Math.floor(Math.random() * 4);

    let x, y, vx, vy;

    switch (spawnSide) {
        case 0: // Topo → desce
            x = 50 + Math.random() * (config.ARENA_WIDTH - 100);
            y = -size;
            vx = (Math.random() - 0.5) * speed * 0.5;
            vy = speed;
            break;
        case 1: // Base → sobe
            x = 50 + Math.random() * (config.ARENA_WIDTH - 100);
            y = config.ARENA_HEIGHT + size;
            vx = (Math.random() - 0.5) * speed * 0.5;
            vy = -speed;
            break;
        case 2: // Esquerda → vai pra direita
            x = -size;
            y = 100 + Math.random() * (config.ARENA_HEIGHT - 200);
            vx = speed;
            vy = (Math.random() - 0.5) * speed * 0.5;
            break;
        case 3: // Direita → vai pra esquerda
            x = config.ARENA_WIDTH + size;
            y = 100 + Math.random() * (config.ARENA_HEIGHT - 200);
            vx = -speed;
            vy = (Math.random() - 0.5) * speed * 0.5;
            break;
    }

    return {
        id: asteroidIdCounter,
        x, y, vx, vy,
        size,
        hitRadius: size * 0.45,
        rotation: Math.random() * Math.PI * 2,
        rotationSpeed: (Math.random() - 0.5) * 0.08,
        alive: true
    };
}

/**
 * Atualiza posição de asteroides e remove os que saíram da tela
 */
function updateAsteroids(asteroids) {
    const margin = 80;
    for (let i = asteroids.length - 1; i >= 0; i--) {
        const a = asteroids[i];
        a.x += a.vx;
        a.y += a.vy;
        a.rotation += a.rotationSpeed;

        // Remover se saiu da tela com margem
        if (a.x < -margin || a.x > config.ARENA_WIDTH + margin ||
            a.y < -margin || a.y > config.ARENA_HEIGHT + margin || !a.alive) {
            asteroids.splice(i, 1);
        }
    }
}

/**
 * Processa TODAS as colisões do jogo
 * Retorna array de eventos (explosões, danos, etc.)
 */
function processCollisions(player1, player2, asteroids) {
    const events = [];

    // 1. Balas P1 vs Asteroides
    checkBulletsVsAsteroids(player1, asteroids, events);

    // 2. Balas P2 vs Asteroides
    checkBulletsVsAsteroids(player2, asteroids, events);

    // 3. Balas P1 vs Balas P2
    checkBulletsVsBullets(player1, player2, events);

    // 4. Balas P1 vs Nave P2
    checkBulletsVsShip(player1, player2, events);

    // 5. Balas P2 vs Nave P1
    checkBulletsVsShip(player2, player1, events);

    // 6. Asteroides vs Nave P1
    checkAsteroidsVsShip(asteroids, player1, events);

    // 7. Asteroides vs Nave P2
    checkAsteroidsVsShip(asteroids, player2, events);

    // Limpar balas e asteroides mortos
    player1.bullets = player1.bullets.filter(b => b.alive);
    player2.bullets = player2.bullets.filter(b => b.alive);
    for (let i = asteroids.length - 1; i >= 0; i--) {
        if (!asteroids[i].alive) asteroids.splice(i, 1);
    }

    return events;
}

function checkBulletsVsAsteroids(player, asteroids, events) {
    for (const bullet of player.bullets) {
        if (!bullet.alive) continue;
        for (const asteroid of asteroids) {
            if (!asteroid.alive) continue;
            if (circleCollision(bullet.x, bullet.y, config.BULLET_HITBOX,
                                asteroid.x, asteroid.y, asteroid.hitRadius)) {
                bullet.alive = false;
                asteroid.alive = false;
                player.asteroidsDestroyed++;
                events.push({
                    type: 'asteroid_destroyed',
                    x: asteroid.x,
                    y: asteroid.y,
                    size: asteroid.size,
                    by: player.slot
                });
                break; // Bala só destrói 1 asteroide
            }
        }
    }
}

function checkBulletsVsBullets(player1, player2, events) {
    for (const b1 of player1.bullets) {
        if (!b1.alive) continue;
        for (const b2 of player2.bullets) {
            if (!b2.alive) continue;
            if (circleCollision(b1.x, b1.y, config.BULLET_HITBOX,
                                b2.x, b2.y, config.BULLET_HITBOX)) {
                b1.alive = false;
                b2.alive = false;
                events.push({
                    type: 'bullet_collision',
                    x: (b1.x + b2.x) / 2,
                    y: (b1.y + b2.y) / 2
                });
                break;
            }
        }
    }
}

function checkBulletsVsShip(attacker, target, events) {
    if (target.invincibilityFrames > 0) return;

    for (const bullet of attacker.bullets) {
        if (!bullet.alive) continue;
        if (circleCollision(bullet.x, bullet.y, config.BULLET_HITBOX,
                            target.x, target.y, config.SHIP_HITBOX)) {
            bullet.alive = false;
            const damaged = target.takeDamage();
            if (damaged) {
                attacker.hits++;
                events.push({
                    type: 'ship_hit',
                    x: target.x,
                    y: target.y,
                    targetSlot: target.slot,
                    attackerSlot: attacker.slot,
                    livesRemaining: target.lives
                });
            }
            break;
        }
    }
}

function checkAsteroidsVsShip(asteroids, player, events) {
    if (player.invincibilityFrames > 0) return;

    for (const asteroid of asteroids) {
        if (!asteroid.alive) continue;
        if (circleCollision(asteroid.x, asteroid.y, asteroid.hitRadius,
                            player.x, player.y, config.SHIP_HITBOX)) {
            asteroid.alive = false;
            const damaged = player.takeDamage();
            if (damaged) {
                events.push({
                    type: 'asteroid_hit_ship',
                    x: player.x,
                    y: player.y,
                    targetSlot: player.slot,
                    livesRemaining: player.lives
                });
            }
            break;
        }
    }
}

module.exports = {
    spawnAsteroid,
    updateAsteroids,
    processCollisions,
    distance,
    circleCollision
};
