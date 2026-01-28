/* ============================================
   CRYPTO ASTEROID RUSH - Game Configuration
   File: js/game-config.js
   v3.2 - COMMON asteroids = $0 (no reward)
   Target earnings: $0.02-0.03, max $0.05
   Lives system + 90/10 house edge rule
   ============================================ */

const CONFIG = {
    // Game Settings
    GAME_DURATION: 180,
    INITIAL_ASTEROIDS: 4,
    MAX_ASTEROIDS_ON_SCREEN: 10,
    SPAWN_INTERVAL: 500,
    
    // Lives System
    INITIAL_LIVES: 6,
    INVINCIBILITY_FRAMES: 60, // Frames of invincibility after hit
    
    // Fees
    ENTRY_FEE: 0.00001,
    PROJECT_WALLET: "0x8417C9a00249Da8e4ff7414c5992C08511c28328",
    
    // Ship Settings
    SHIP_SPEED: 18,
    SHIP_ACCELERATION: 0.8,
    BULLET_SPEED: 16,
    FIRE_RATE: 150, // ms between shots
    
    // Network
    BSC_CHAIN_ID: "0x38",
    
    // ============================================
    // REWARD VALUES
    // v3.2: COMMON = 0 (asteroides comuns não valem nada)
    // ============================================
    REWARDS: {
        COMMON: 0,          // $0.00 - Sem valor!
        RARE: 0.0003,       // $0.0003 por asteroide raro
        EPIC: 0.0008,       // $0.0008 por asteroide épico
        LEGENDARY: 0.002    // $0.002 por asteroide lendário
    },
    
    // SPAWN RATES (normal missions - 90%)
    SPAWN_RATES_NORMAL: {
        COMMON: 0.86,
        RARE: 0.10,
        EPIC: 0.03,
        LEGENDARY: 0.01
    },
    
    // SPAWN RATES (house wins - 10%)
    // More common asteroids, faster, harder to avoid
    SPAWN_RATES_HARD: {
        COMMON: 0.95,
        RARE: 0.04,
        EPIC: 0.008,
        LEGENDARY: 0.002
    },
    
    // Asteroid Settings
    ASTEROID_SPEED: {
        MIN: 1.0,
        MAX: 3.5
    },
    
    // Hard mode settings (house always wins)
    HARD_MODE: {
        SPEED_MULTIPLIER: 1.4,
        SPAWN_RATE_MULTIPLIER: 0.7,
        MAX_ASTEROIDS: 14
    },
    
    // House Edge Rule: 10% of missions are "hard mode"
    HOUSE_EDGE_PERCENT: 10
};

// Mission tracking
let missionStats = {
    totalMissions: parseInt(localStorage.getItem('totalMissions') || '0'),
    rareCount: 0,
    epicCount: 0,
    legendaryCount: 0,
    isHardMode: false
};

// Game State
let gameState = {
    wallet: localStorage.getItem('connectedWallet') || null,
    isConnected: false,
    gameActive: false,
    timeLeft: CONFIG.GAME_DURATION,
    score: 0,
    earnings: 0,
    lives: CONFIG.INITIAL_LIVES,
    invincibilityFrames: 0,
    audioEnabled: true,
    particles: [],
    bullets: [],
    asteroids: [],
    destroyedAsteroids: [],
    transactionInProgress: false,
    gameTimer: null,
    spawnTimer: null,
    asteroidSpawnCounter: 0,
    lastX: 0,
    lastFireTime: 0,
    selectedShipDesign: null,
    currentSessionShip: null,
    ship: null,
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

// Determine if this mission is hard mode (house edge)
function determineHardMode() {
    const roll = Math.random() * 100;
    missionStats.isHardMode = roll < CONFIG.HOUSE_EDGE_PERCENT;
    
    if (missionStats.isHardMode) {
        console.log('🎰 Hard Mode Mission - House Edge Active');
        console.log('💡 Player can still win if they survive!');
    } else {
        console.log('🎮 Normal Mission - Player can win');
    }
    
    return missionStats.isHardMode;
}

// Get spawn rates based on mission type
function getSpawnRates() {
    return missionStats.isHardMode ? CONFIG.SPAWN_RATES_HARD : CONFIG.SPAWN_RATES_NORMAL;
}

// Get random asteroid type
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

// Get asteroid speed (faster in hard mode)
function getAsteroidSpeed() {
    const baseMin = CONFIG.ASTEROID_SPEED.MIN;
    const baseMax = CONFIG.ASTEROID_SPEED.MAX;
    
    if (missionStats.isHardMode) {
        return (baseMin + Math.random() * (baseMax - baseMin)) * CONFIG.HARD_MODE.SPEED_MULTIPLIER;
    }
    
    return baseMin + Math.random() * (baseMax - baseMin);
}

// Get max asteroids
function getMaxAsteroids() {
    return missionStats.isHardMode ? CONFIG.HARD_MODE.MAX_ASTEROIDS : CONFIG.MAX_ASTEROIDS_ON_SCREEN;
}

// Get spawn interval
function getSpawnInterval() {
    return missionStats.isHardMode ? 
        CONFIG.SPAWN_INTERVAL * CONFIG.HARD_MODE.SPAWN_RATE_MULTIPLIER : 
        CONFIG.SPAWN_INTERVAL;
}

// Loading tips
const GAME_TIPS = [
    "TIP: Destroy valuable asteroids to earn USDT!",
    "TIP: Rare asteroids glow blue and are worth more!",
    "TIP: Epic asteroids have a purple glow - high value!",
    "TIP: Legendary asteroids shine gold - maximum reward!",
    "TIP: Avoid collisions to keep your lives!",
    "TIP: Common asteroids have NO value - focus on colored ones!",
    "TIP: Use keyboard arrows or A/D to move, Space to fire",
    "TIP: Your earnings depend on your skill!",
    "TIP: 6 lives per mission - protect your ship!",
    "TIP: Watch out for fast asteroids!",
    "TIP: Hard mode? Survive and you still earn!",
    "TIP: You can play up to 5 missions per hour"
];

function getRandomTip() {
    return GAME_TIPS[Math.floor(Math.random() * GAME_TIPS.length)];
}

// Export to global scope
window.CONFIG = CONFIG;
window.missionStats = missionStats;
window.gameState = gameState;
window.getRandomAsteroidType = getRandomAsteroidType;
window.determineHardMode = determineHardMode;
window.getSpawnRates = getSpawnRates;
window.getAsteroidSpeed = getAsteroidSpeed;
window.getMaxAsteroids = getMaxAsteroids;
window.getSpawnInterval = getSpawnInterval;
window.getRandomTip = getRandomTip;
window.GAME_TIPS = GAME_TIPS;
