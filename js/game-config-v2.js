/* ============================================
   UNOBIX - Game Configuration v2
   File: js/game-config-v2.js
   v2.0 - 3 modos + treino, 3 vidas
   ============================================ */

const CONFIG = {
    // ============================================
    // CONFIGURAÇÕES BASE DO JOGO
    // ============================================
    GAME_DURATION: 180,          // 3 minutos (todos os modos)
    INITIAL_ASTEROIDS: 4,
    INITIAL_LIVES: 3,            // v2: 3 vidas para todos
    INVINCIBILITY_FRAMES: 60,

    // Nave
    SHIP_SPEED: 18,
    SHIP_ACCELERATION: 0.8,
    BULLET_SPEED: 16,
    FIRE_RATE: 150,

    // ============================================
    // RECOMPENSAS EM BRL (iguais para todos os modos)
    // ============================================
    REWARDS: {
        COMMON: 0,
        RARE: 0.02,
        EPIC: 0.05,
        LEGENDARY: 0.20
    },

    // ============================================
    // CONFIGURAÇÃO POR MODO
    // ============================================
    MODES: {
        normal: {
            label: 'NORMAL',
            credits: 1,
            speed_multiplier: 1.3,
            max_asteroids: 14,
            spawn_interval: 400,
            spawn_rates: {
                COMMON: 0.90,
                RARE: 0.07,
                EPIC: 0.02,
                LEGENDARY: 0.01
            }
        },
        hard: {
            label: 'DIFÍCIL',
            credits: 2,
            speed_multiplier: 1.7,
            max_asteroids: 18,
            spawn_interval: 200,
            spawn_rates: {
                COMMON: 0.80,
                RARE: 0.15,
                EPIC: 0.04,
                LEGENDARY: 0.01
            }
        },
        extreme: {
            label: 'EXTREME',
            credits: 3,
            speed_multiplier: 2.4,
            max_asteroids: 22,
            spawn_interval: 100,
            spawn_rates: {
                COMMON: 0.70,
                RARE: 0.20,
                EPIC: 0.08,
                LEGENDARY: 0.02
            }
        }
    },

    // Velocidade base dos asteroides
    ASTEROID_SPEED: {
        MIN: 1.0,
        MAX: 3.5
    }
};

// ============================================
// ESTADO DO MODO SELECIONADO
// ============================================
let selectedGameMode = null;   // 'normal', 'hard', 'extreme', 'training'
let trainingSubMode = null;    // Para treino: qual modo simular
let isTrainingMode = false;    // Flag rápida

// ============================================
// ESTADO DA MISSÃO
// ============================================
let missionStats = {
    totalMissions: parseInt(localStorage.getItem('totalMissions') || '0'),
    rareCount: 0,
    epicCount: 0,
    legendaryCount: 0,
    isHardMode: false,  // Legacy compat
    gameMode: null       // v2: modo real
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
// FUNÇÕES DE MODO
// ============================================

/**
 * Obter configuração do modo atual
 */
function getCurrentModeConfig() {
    const mode = isTrainingMode ? (trainingSubMode || 'normal') : (selectedGameMode || 'normal');
    return CONFIG.MODES[mode] || CONFIG.MODES.normal;
}

/**
 * Obter taxas de spawn baseado no modo selecionado
 */
function getSpawnRates() {
    return getCurrentModeConfig().spawn_rates;
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
    const multiplier = getCurrentModeConfig().speed_multiplier;
    return (baseMin + Math.random() * (baseMax - baseMin)) * multiplier;
}

/**
 * Obter máximo de asteroides na tela
 */
function getMaxAsteroids() {
    return getCurrentModeConfig().max_asteroids;
}

/**
 * Obter intervalo de spawn
 */
function getSpawnInterval() {
    return getCurrentModeConfig().spawn_interval;
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
    "DICA: 3 vidas por missão - proteja sua nave!",
    "DICA: Modo Extreme tem mais meteoros valiosos, mas é muito mais rápido!",
    "DICA: Use o modo Treino para praticar sem gastar créditos!",
    "DICA: Modos mais difíceis custam mais créditos, mas rendem mais!"
];

function getRandomTip() {
    return GAME_TIPS[Math.floor(Math.random() * GAME_TIPS.length)];
}

// ============================================
// FUNÇÕES DE FORMATAÇÃO
// ============================================

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4
    }).format(value || 0);
}

function formatEarnings(value) {
    return 'R$ ' + (value || 0).toFixed(2).replace('.', ',');
}

// ============================================
// EXPORTAR PARA ESCOPO GLOBAL
// ============================================
window.CONFIG = CONFIG;
window.missionStats = missionStats;
window.gameState = gameState;
window.selectedGameMode = selectedGameMode;
window.isTrainingMode = isTrainingMode;
window.getCurrentModeConfig = getCurrentModeConfig;
window.getRandomAsteroidType = getRandomAsteroidType;
window.getSpawnRates = getSpawnRates;
window.getAsteroidSpeed = getAsteroidSpeed;
window.getMaxAsteroids = getMaxAsteroids;
window.getSpawnInterval = getSpawnInterval;
window.getRandomTip = getRandomTip;
window.GAME_TIPS = GAME_TIPS;
window.formatBRL = formatBRL;
window.formatEarnings = formatEarnings;

console.log('📦 game-config-v2.js v2.0 carregado (3 modos + treino)');
