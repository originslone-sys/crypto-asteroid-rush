// ============================================
// UNOBIX PvP - Game Engine (Client)
// Captura input, envia ao servidor, renderiza estado
// ============================================

const PvPEngine = {
    inputSendInterval: null,
    gameActive: false,

    startInputLoop() {
        this.gameActive = true;

        document.addEventListener('keydown', this.handleKeyDown);
        document.addEventListener('keyup', this.handleKeyUp);
        this.setupMobileControls();

        // Enviar input ao servidor a cada 16ms
        this.inputSendInterval = setInterval(() => {
            if (!this.gameActive) return;
            PvPSessionManager.sendInput(pvpState.keys);
        }, 16);

        // Predição local roda dentro do requestAnimationFrame do renderer
        // para ficar em perfeita sincronia com cada frame renderizado
    },

    stopInputLoop() {
        this.gameActive = false;
        if (this.inputSendInterval) {
            clearInterval(this.inputSendInterval);
            this.inputSendInterval = null;
        }
        document.removeEventListener('keydown', this.handleKeyDown);
        document.removeEventListener('keyup', this.handleKeyUp);

        pvpState.keys = { left: false, right: false, up: false, down: false, fire: false };
        pvpState.localPrediction = null;
    },

    /**
     * Simula física da nave localmente — chamado pelo render loop (RAF) a cada frame.
     * Roda em sincronia com o desenho para evitar tremor/estilingue.
     */
    stepLocalPrediction() {
        if (!pvpState.localPrediction || !pvpState.mySlot) return;

        const C = PVP_CONFIG;
        const pred = pvpState.localPrediction;
        const keys = pvpState.keys;

        if (keys.left)       pred.vx -= C.SHIP_ACCEL_X;
        else if (keys.right) pred.vx += C.SHIP_ACCEL_X;
        else                 pred.vx *= C.SHIP_FRICTION;

        if (keys.up)         pred.vy -= C.SHIP_ACCEL_Y;
        else if (keys.down)  pred.vy += C.SHIP_ACCEL_Y;
        else                 pred.vy *= C.SHIP_FRICTION;

        pred.vx = Math.max(-C.SHIP_MAX_VX, Math.min(C.SHIP_MAX_VX, pred.vx));
        pred.vy = Math.max(-C.SHIP_MAX_VY, Math.min(C.SHIP_MAX_VY, pred.vy));

        pred.x += pred.vx;
        pred.y += pred.vy;

        const W = C.ARENA_WIDTH, H = C.ARENA_HEIGHT;
        const slot = pvpState.mySlot;
        const yMin = slot === 1 ? H * 0.5 : 50;
        const yMax = slot === 1 ? H - 50 : H * 0.5;

        if (pred.x <= 50)      { pred.x = 50;      pred.vx = 0; }
        if (pred.x >= W - 50)  { pred.x = W - 50;  pred.vx = 0; }
        if (pred.y <= yMin)    { pred.y = yMin;     pred.vy = 0; }
        if (pred.y >= yMax)    { pred.y = yMax;     pred.vy = 0; }
    },

    handleKeyDown(e) {
        switch (e.key) {
            case 'ArrowLeft': case 'a': case 'A':
                pvpState.keys.left = true; e.preventDefault(); break;
            case 'ArrowRight': case 'd': case 'D':
                pvpState.keys.right = true; e.preventDefault(); break;
            case 'ArrowUp': case 'w': case 'W':
                pvpState.keys.up = true; e.preventDefault(); break;
            case 'ArrowDown': case 's': case 'S':
                pvpState.keys.down = true; e.preventDefault(); break;
            case ' ':
                pvpState.keys.fire = true; e.preventDefault(); break;
        }
    },

    handleKeyUp(e) {
        switch (e.key) {
            case 'ArrowLeft': case 'a': case 'A':
                pvpState.keys.left = false; break;
            case 'ArrowRight': case 'd': case 'D':
                pvpState.keys.right = false; break;
            case 'ArrowUp': case 'w': case 'W':
                pvpState.keys.up = false; break;
            case 'ArrowDown': case 's': case 'S':
                pvpState.keys.down = false; break;
            case ' ':
                pvpState.keys.fire = false; break;
        }
    },

    setupMobileControls() {
        const joystickEl = document.getElementById('pvpJoystick');
        const knobEl = document.getElementById('pvpJoystickKnob');
        const fireBtn = document.getElementById('pvpFireBtn');
        const gameArea = document.getElementById('pvpArena');
        if (!gameArea) return;

        let joyActive = false;
        let joyOriginX = 0, joyOriginY = 0;
        const MAX_RADIUS = 55;
        const DEADZONE = 18;

        const updateJoy = (cx, cy) => {
            const dx = cx - joyOriginX;
            const dy = cy - joyOriginY;
            const dist = Math.sqrt(dx * dx + dy * dy);
            const clamped = Math.min(dist, MAX_RADIUS);
            const angle = Math.atan2(dy, dx);
            const kx = Math.cos(angle) * clamped;
            const ky = Math.sin(angle) * clamped;
            if (knobEl) knobEl.style.transform = `translate(${kx}px, ${ky}px)`;
            pvpState.keys.left  = dx < -DEADZONE;
            pvpState.keys.right = dx > DEADZONE;
            pvpState.keys.up    = dy < -DEADZONE;
            pvpState.keys.down  = dy > DEADZONE;
        };

        const endJoy = () => {
            joyActive = false;
            pvpState.keys.left = pvpState.keys.right = pvpState.keys.up = pvpState.keys.down = false;
            if (knobEl) knobEl.style.transform = 'translate(0,0)';
            if (joystickEl) joystickEl.style.opacity = '0';
        };

        gameArea.addEventListener('touchstart', (e) => {
            for (const touch of e.changedTouches) {
                // Left 60% of screen = joystick, right 40% = fire
                if (touch.clientX < window.innerWidth * 0.6) {
                    if (!joyActive) {
                        joyActive = true;
                        joyOriginX = touch.clientX;
                        joyOriginY = touch.clientY;
                        if (joystickEl) {
                            joystickEl.style.left = (touch.clientX - 60) + 'px';
                            joystickEl.style.top  = (touch.clientY - 60) + 'px';
                            joystickEl.style.opacity = '1';
                        }
                        if (knobEl) knobEl.style.transform = 'translate(0,0)';
                    }
                } else {
                    pvpState.keys.fire = true;
                }
            }
            e.preventDefault();
        }, { passive: false });

        gameArea.addEventListener('touchmove', (e) => {
            for (const touch of e.changedTouches) {
                if (joyActive && touch.clientX < window.innerWidth * 0.6) {
                    updateJoy(touch.clientX, touch.clientY);
                }
            }
            e.preventDefault();
        }, { passive: false });

        gameArea.addEventListener('touchend', (e) => {
            for (const touch of e.changedTouches) {
                if (touch.clientX >= window.innerWidth * 0.6) {
                    pvpState.keys.fire = false;
                } else {
                    endJoy();
                }
            }
            e.preventDefault();
        }, { passive: false });

        gameArea.addEventListener('touchcancel', () => {
            endJoy();
            pvpState.keys.fire = false;
        }, { passive: false });

        // Fire button fallback (mouse)
        if (fireBtn) {
            fireBtn.addEventListener('mousedown', () => { pvpState.keys.fire = true; });
            fireBtn.addEventListener('mouseup',   () => { pvpState.keys.fire = false; });
        }
    },
};

// ============================================
// Callback handlers (chamados pelo PvPSessionManager)
// ============================================

function onMatchFound(data) {
    // Guardar dados do match — mySlot pode ainda não estar definido aqui
    pvpState._matchData = data;

    // Mostrar tela de countdown
    const lobby = document.getElementById('pvpLobby');
    const countdown = document.getElementById('pvpCountdown');
    if (lobby) lobby.style.display = 'none';
    if (countdown) countdown.style.display = 'flex';

    // Nome do oponente será atualizado quando your_slot chegar
    const opponentEl = document.getElementById('pvpOpponentName');
    if (opponentEl) opponentEl.textContent = 'Oponente';
}

function onCountdown(data) {
    const el = document.getElementById('pvpCountdownNumber');
    if (el) el.textContent = data.count > 0 ? data.count : 'FIGHT!';
}

function onGameStart(data) {
    // Esconder overlays, mostrar arena
    const countdown = document.getElementById('pvpCountdown');
    const arena = document.getElementById('pvpArena');
    if (countdown) countdown.style.display = 'none';
    if (arena) arena.style.display = 'block';

    // Inicializar predição local na posição de spawn
    const slot = pvpState.mySlot;
    const H = PVP_CONFIG.ARENA_HEIGHT;
    pvpState.localPrediction = {
        x: PVP_CONFIG.ARENA_WIDTH / 2,
        y: slot === 1 ? H - 120 : 120,
        vx: 0,
        vy: 0
    };

    // Iniciar rendering e input
    PvPRenderer.init('pvpCanvas');
    PvPRenderer.startLoop();
    PvPEngine.startInputLoop();
}

function onGameEnd(result) {
    PvPEngine.stopInputLoop();
    PvPRenderer.stopLoop();
    pvpState.opponentLerp = null;

    const myUid = PvPSessionManager.googleUid;
    const isWinner = result.winnerUid === myUid;
    const isDraw = result.winCondition === 'draw';

    // Mostrar tela de resultado
    const arena = document.getElementById('pvpArena');
    const resultScreen = document.getElementById('pvpResult');
    if (arena) arena.style.display = 'none';
    if (resultScreen) resultScreen.style.display = 'flex';

    // Preencher resultado
    const titleEl = document.getElementById('pvpResultTitle');
    const iconEl = document.getElementById('pvpResultIcon');

    if (isDraw) {
        if (titleEl) titleEl.textContent = 'EMPATE';
        if (iconEl) iconEl.innerHTML = '<i class="fas fa-handshake"></i>';
    } else if (isWinner) {
        if (titleEl) titleEl.textContent = 'VITÓRIA!';
        if (iconEl) iconEl.innerHTML = '<i class="fas fa-trophy"></i>';
    } else {
        if (titleEl) titleEl.textContent = 'DERROTA';
        if (iconEl) iconEl.innerHTML = '<i class="fas fa-skull-crossbones"></i>';
    }

    // Stats do jogador
    const myStats = (pvpState.mySlot === 1) ? result.player1 : result.player2;
    const opStats = (pvpState.mySlot === 1) ? result.player2 : result.player1;

    const setResult = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    };

    setResult('pvpMyLives', myStats.lives);
    setResult('pvpMyAsteroids', myStats.asteroidsDestroyed);
    setResult('pvpMyShots', myStats.shotsFired);
    setResult('pvpMyHits', myStats.hits);
    setResult('pvpOpLives', opStats.lives);
    setResult('pvpOpAsteroids', opStats.asteroidsDestroyed);
    setResult('pvpOpShots', opStats.shotsFired);
    setResult('pvpOpHits', opStats.hits);

    const condEl = document.getElementById('pvpWinCondition');
    if (condEl) {
        const conditions = {
            'elimination': 'Eliminação',
            'time_lives': 'Tempo - Mais vidas',
            'time_asteroids': 'Tempo - Mais asteroides',
            'disconnect': 'Desconexão do oponente',
            'draw': 'Empate total'
        };
        condEl.textContent = conditions[result.winCondition] || result.winCondition;
    }
}

function onQueueUpdate(data) {
    const el = document.getElementById('pvpQueueStatus');
    if (el) el.textContent = 'Buscando oponente...';
}

function onMatchmakingTimeout(data) {
    const el = document.getElementById('pvpQueueStatus');
    if (el) el.textContent = 'Nenhum oponente encontrado. Tente novamente.';
    pvpState.inQueue = false;

    const btn = document.getElementById('pvpSearchBtn');
    if (btn) {
        btn.textContent = 'BUSCAR NOVAMENTE';
        btn.disabled = false;
    }
}

function onReconnected(data) {
    // Reconectou à partida em andamento
    const arena = document.getElementById('pvpArena');
    if (arena) arena.style.display = 'block';
    PvPRenderer.init('pvpCanvas');
    PvPRenderer.startLoop();
    PvPEngine.startInputLoop();
}
