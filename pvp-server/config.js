// ============================================
// UNOBIX PvP Server - Configuração
// ============================================

module.exports = {
    // Servidor
    PORT: parseInt(process.env.PVP_PORT) || 3000,
    CORS_ORIGIN: process.env.CORS_ORIGIN || '*',

    // Backend PHP (comunicação interna VPC)
    BACKEND_URL: process.env.BACKEND_URL || 'http://localhost:8080',
    PVP_JWT_SECRET: process.env.PVP_JWT_SECRET || 'pvp_secret_key_change_in_production_2025',

    // Jogo
    TICK_RATE: 60,                      // Hz (ticks por segundo)
    TICK_INTERVAL: 1000 / 60,           // ~16.67ms por tick
    GAME_DURATION: 180,                 // Segundos
    LIVES: 6,                           // Vidas por jogador
    COUNTDOWN_SECONDS: 3,               // Countdown antes da partida

    // Arena
    ARENA_WIDTH: 1024,                  // Largura padrão da arena
    ARENA_HEIGHT: 768,                  // Altura padrão da arena

    // Nave
    SHIP_SPEED_X: 18,                   // Velocidade horizontal (px/frame)
    SHIP_SPEED_Y: 12,                   // Velocidade vertical (px/frame)
    SHIP_HITBOX: 25,                    // Raio de colisão da nave
    INVINCIBILITY_FRAMES: 60,           // Frames de invencibilidade pós-dano

    // Balas
    BULLET_SPEED: 15,                   // Velocidade da bala (px/frame)
    FIRE_RATE_MS: 350,                  // Intervalo mínimo entre tiros
    MAX_BULLETS_PER_PLAYER: 5,          // Máximo de balas simultâneas
    BULLET_HITBOX: 8,                   // Raio de colisão da bala
    BULLET_WIDTH: 4,
    BULLET_HEIGHT: 20,

    // Asteroides
    ASTEROID_SPAWN_INTERVAL: 700,       // ms entre spawns
    MAX_ASTEROIDS: 10,                  // Máximo na tela
    ASTEROID_SPEED_MIN: 1.0,            // Velocidade mínima
    ASTEROID_SPEED_MAX: 3.0,            // Velocidade máxima
    ASTEROID_SIZE_MIN: 25,              // Tamanho mínimo (px)
    ASTEROID_SIZE_MAX: 50,              // Tamanho máximo (px)
    ASTEROID_DAMAGE: 1,                 // Vidas perdidas por colisão

    // Matchmaking
    MATCHMAKING_TIMEOUT: 60,            // Segundos esperando oponente
    DISCONNECT_TIMEOUT: 10,             // Segundos para considerar desconexão
};
