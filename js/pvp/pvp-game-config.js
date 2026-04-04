// ============================================
// UNOBIX PvP - Client Config
// ============================================

const PVP_CONFIG = {
    // Servidor — sempre usa proxy nginx /pvp-ws/ em produção
    // (evita mixed content: HTTPS página → nginx → HTTP game server internamente)
    // Não usa window.PVP_SERVER_URL pois pvp.unobix.com não tem SSL configurado
    // URL do host (sem path — o path é passado via GAME_SERVER_PATH)
    GAME_SERVER_URL: window.location.hostname === 'localhost'
        ? 'http://localhost:3000'
        : window.location.origin,

    // Path do Socket.io — nginx proxia /pvp-ws/ para o game server
    GAME_SERVER_PATH: window.location.hostname === 'localhost'
        ? '/socket.io'
        : '/pvp-ws/socket.io',

    // Arena
    ARENA_WIDTH: 1024,
    ARENA_HEIGHT: 768,

    // Jogo
    GAME_DURATION: 180,
    LIVES: 6,
    COUNTDOWN_SECONDS: 3,

    // Renderização
    BULLET_WIDTH: 4,
    BULLET_HEIGHT: 20,
    SHIP_SIZE: 50,

    // Cores
    PLAYER_BULLET_COLOR: '#00ff88',
    OPPONENT_BULLET_COLOR: '#ff4444',
    PLAYER_COLOR: '#00aaff',
    OPPONENT_COLOR: '#ff6600',

    // Interpolação
    INTERPOLATION_FACTOR: 0.3,
};

// Estado do jogo PvP (client-side)
const pvpState = {
    connected: false,
    authenticated: false,
    inQueue: false,
    inMatch: false,
    mySlot: null, // 1 = bottom, 2 = top

    // Match info
    matchId: null,
    opponentName: null,

    // Estado recebido do servidor
    serverState: null,
    previousState: null,

    // Countdown
    countdownValue: null,

    // Resultado
    result: null,

    // Input local
    keys: {
        left: false,
        right: false,
        up: false,
        down: false,
        fire: false
    }
};
