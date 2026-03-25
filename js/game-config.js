/* ============================================
   UNOBIX - Game Configuration
   File: js/game-config.js
   v4.0 - BRL Only, valores corrigidos
   ============================================ */

const CONFIG = {
    // ============================================
    // CONFIGURAÇÕES DO JOGO
    // ============================================
    GAME_DURATION: 180,          // 3 minutos
    INITIAL_ASTEROIDS: 4,
    MAX_ASTEROIDS_ON_SCREEN: 10,
    SPAWN_INTERVAL: 500,         // ms entre spawns
    
    // Sistema de Vidas
    INITIAL_LIVES: 6,
    INVINCIBILITY_FRAMES: 60,    // Frames de invencibilidade após dano
    
    // Nave
    SHIP_SPEED: 18,
    SHIP_ACCELERATION: 0.8,
    BULLET_SPEED: 16,
    FIRE_RATE: 150,              // ms entre tiros
    
    // ============================================
    // RECOMPENSAS EM BRL - VALORES CORRETOS
    // ============================================
    REWARDS: {
        COMMON: 0,               // R$ 0,00 - Sem valor
        RARE: 0.02,              // R$ 0,02
        EPIC: 0.05,              // R$ 0,05
        LEGENDARY: 0.20          // R$ 0,20
    },
    
    // ============================================
    // TAXAS DE SPAWN (missões normais - 60%)
    // ============================================
    SPAWN_RATES_NORMAL: {
        COMMON: 0.86,
        RARE: 0.10,
        EPIC: 0.03,
        LEGENDARY: 0.01
    },
    
    // ============================================
    // TAXAS DE SPAWN (hard mode - 80%)
    // Mais asteroides comuns, mais rápidos
    // ============================================
    SPAWN_RATES_HARD: {
        COMMON: 0.95,
        RARE: 0.04,
        EPIC: 0.008,
        LEGENDARY: 0.002
    },
    
    // ============================================
    // VELOCIDADE DOS ASTEROIDES
    // ============================================
    ASTEROID_SPEED: {
        MIN: 1.0,
        MAX: 3.5
    },
    
    // ============================================
    // CONFIGURAÇÕES DO HARD MODE
    // ============================================
    HARD_MODE: {
        SPEED_MULTIPLIER: 1.4,
        SPAWN_RATE_MULTIPLIER: 0.7,
        MAX_ASTEROIDS: 14
    },
    
    // 80% das missões são hard mode
    HOUSE_EDGE_PERCENT: 80
};

// ============================================
// ESTADO DA MISSÃO
// ============================================
let missionStats = {
    totalMissions: parseInt(localStorage.getItem('totalMissions') || '0'),
    rareCount: 0,
    epicCount: 0,
    legendaryCount: 0,
    isHardMode: false  // Definido pelo SERVIDOR
};

// ============================================
// ESTADO DO JOGO
// ============================================
let gameState = {
    // Autenticação (Google)
    user: null,
    googleUid: null,
    isConnected: false,
    sessionToken: null,
    
    // Sessão de jogo
    sessionId: null,
    gameActive: false,
    timeLeft: CONFIG.GAME_DURATION,
    
    // Pontuação
    score: 0,
    earnings: 0,
    serverConfirmedEarnings: 0,
    newBalance: null,
    
    // Vidas
    lives: CONFIG.INITIAL_LIVES,
    invincibilityFrames: 0,
    
    // Áudio
    audioEnabled: true,
    
    // Objetos do jogo
    particles: [],
    bullets: [],
    asteroids: [],
    destroyedAsteroids: [],
    
    // Estado
    transactionInProgress: false,
    gameTimer: null,
    spawnTimer: null,
    asteroidSpawnCounter: 0,
    lastX: 0,
    lastFireTime: 0,
    
    // Nave
    selectedShipDesign: null,
    currentSessionShip: null,
    ship: null,
    
    // Controles
    keys: {
        left: false,
        right: false,
        fire: false
    },
    touchHold: {
        left: null,
        right: null
    }
};

// ============================================
// FUNÇÕES DE SPAWN
// ============================================

/**
 * Obter taxas de spawn baseado no modo
 * NOTA: isHardMode é definido pelo SERVIDOR
 */
function getSpawnRates() {
    return missionStats.isHardMode ? CONFIG.SPAWN_RATES_HARD : CONFIG.SPAWN_RATES_NORMAL;
}

/**
 * Obter tipo aleatório de asteroide
 */
function getRandomAsteroidType() {
    const rates = getSpawnRates();
    const rand = Math.random();
    let cumulative = 0;
    
    cumulative += rates.LEGENDARY;
    if (rand < cumulative) return 'LEGENDARY';
    
    cumulative += rates.EPIC;
    if (rand < cumulative) return 'EPIC';
    
    cumulative += rates.RARE;
    if (rand < cumulative) return 'RARE';
    
    return 'COMMON';
}

/**
 * Obter velocidade do asteroide
 */
function getAsteroidSpeed() {
    const baseMin = CONFIG.ASTEROID_SPEED.MIN;
    const baseMax = CONFIG.ASTEROID_SPEED.MAX;
    
    if (missionStats.isHardMode) {
        return (baseMin + Math.random() * (baseMax - baseMin)) * CONFIG.HARD_MODE.SPEED_MULTIPLIER;
    }
    
    return baseMin + Math.random() * (baseMax - baseMin);
}

/**
 * Obter máximo de asteroides na tela
 */
function getMaxAsteroids() {
    return missionStats.isHardMode ? CONFIG.HARD_MODE.MAX_ASTEROIDS : CONFIG.MAX_ASTEROIDS_ON_SCREEN;
}

/**
 * Obter intervalo de spawn
 */
function getSpawnInterval() {
    return missionStats.isHardMode ? 
        CONFIG.SPAWN_INTERVAL * CONFIG.HARD_MODE.SPAWN_RATE_MULTIPLIER : 
        CONFIG.SPAWN_INTERVAL;
}

// ============================================
// DICAS DE CARREGAMENTO
// ============================================
const GAME_TIPS = [
    "DICA: Destrua asteroides valiosos para ganhar R$!",
    "DICA: Asteroides raros brilham em azul - valem mais!",
    "DICA: Asteroides épicos têm brilho roxo - alto valor!",
    "DICA: Asteroides lendários brilham dourado - recompensa máxima!",
    "DICA: Evite colisões para manter suas vidas!",
    "DICA: Asteroides comuns NÃO têm valor - foque nos coloridos!",
    "DICA: Use as setas ou A/D para mover, Espaço para atirar",
    "DICA: Seus ganhos dependem da sua habilidade!",
    "DICA: 6 vidas por missão - proteja sua nave!",
    "DICA: Cuidado com asteroides rápidos!",
    "DICA: No modo difícil, sobreviva e ainda ganhe!",
    "DICA: Jogue quantas missões quiser, sem limite diário!"
];

function getRandomTip() {
    return GAME_TIPS[Math.floor(Math.random() * GAME_TIPS.length)];
}

// ============================================
// FUNÇÕES DE FORMATAÇÃO
// ============================================

/**
 * Formatar valor em BRL
 */
function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4
    }).format(value || 0);
}

/**
 * Formatar ganhos (4 casas decimais)
 */
function formatEarnings(value) {
    return 'R$ ' + (value || 0).toFixed(2).replace('.', ',');
}

// ============================================
// EXPORTAR PARA ESCOPO GLOBAL
// ============================================
window.CONFIG = CONFIG;
window.missionStats = missionStats;
window.gameState = gameState;
window.getRandomAsteroidType = getRandomAsteroidType;
window.getSpawnRates = getSpawnRates;
window.getAsteroidSpeed = getAsteroidSpeed;
window.getMaxAsteroids = getMaxAsteroids;
window.getSpawnInterval = getSpawnInterval;
window.getRandomTip = getRandomTip;
window.GAME_TIPS = GAME_TIPS;
window.formatBRL = formatBRL;
window.formatEarnings = formatEarnings;

console.log('📦 game-config.js v4.0 carregado (BRL)');
