// ============================================
// UNOBIX PvP - Game Engine (Client)
// ============================================

const PvPEngine = {
    inputSendInterval: null,
    gameActive: false,
    _lastFrameTime: 0,

    startInputLoop() {
        this.gameActive = true;
        this._lastFrameTime = performance.now();

        document.addEventListener('keydown', this.handleKeyDown);
        document.addEventListener('keyup',   this.handleKeyUp);
        this.setupMobileControls();

        // Enviar input ao servidor a cada 16ms
        this.inputSendInterval = setInterval(() => {
            if (!this.gameActive) return;
            PvPSessionManager.sendInput(pvpState.keys);
        }, 16);
    },

    stopInputLoop() {
        this.gameActive = false;
        if (this.inputSendInterval) {
            clearInterval(this.inputSendInterval);
            this.inputSendInterval = null;
        }
        document.removeEventListener('keydown', this.handleKeyDown);
        document.removeEventListener('keyup',   this.handleKeyUp);
        pvpState.keys = { left: false, right: false, up: false, down: false, fire: false };
        pvpState.localPrediction = null;
        pvpState.localBullets = [];
    },

    /**
     * Física com delta-time: frame-rate independente.
     * Chamado pelo renderer a cada frame com o timestamp do RAF.
     */
    stepLocalPrediction(now) {
        if (!pvpState.localPrediction || !pvpState.mySlot) return;

        const dt = Math.min(now - this._lastFrameTime, 50); // cap 50ms (tab em background)
        this._lastFrameTime = now;
        const scale = dt / 16.667; // normalizado para 60fps

        const C    = PVP_CONFIG;
        const pred = pvpState.localPrediction;
        const keys = pvpState.keys;

        if (keys.left)       pred.vx -= C.SHIP_ACCEL_X * scale;
        else if (keys.right) pred.vx += C.SHIP_ACCEL_X * scale;
        else                 pred.vx *= Math.pow(C.SHIP_FRICTION, scale);

        if (keys.up)         pred.vy -= C.SHIP_ACCEL_Y * scale;
        else if (keys.down)  pred.vy += C.SHIP_ACCEL_Y * scale;
        else                 pred.vy *= Math.pow(C.SHIP_FRICTION, scale);

        pred.vx = Math.max(-C.SHIP_MAX_VX, Math.min(C.SHIP_MAX_VX, pred.vx));
        pred.vy = Math.max(-C.SHIP_MAX_VY, Math.min(C.SHIP_MAX_VY, pred.vy));

        pred.x += pred.vx * scale;
        pred.y += pred.vy * scale;

        const W    = C.ARENA_WIDTH, H = C.ARENA_HEIGHT;
        const slot = pvpState.mySlot;
        const yMin = slot === 1 ? H * 0.5 : 50;
        const yMax = slot === 1 ? H - 50   : H * 0.5;

        if (pred.x <= 50)     { pred.x = 50;     pred.vx = 0; }
        if (pred.x >= W - 50) { pred.x = W - 50; pred.vx = 0; }
        if (pred.y <= yMin)   { pred.y = yMin;   pred.vy = 0; }
        if (pred.y >= yMax)   { pred.y = yMax;   pred.vy = 0; }

        // Predição de balas locais
        this._updateLocalBullets(dt);
    },

    /**
     * Cria bala local imediatamente ao disparar — aparece sem esperar servidor.
     */
    fireLocalBullet() {
        if (!pvpState.localPrediction || !pvpState.mySlot) return;
        const now = performance.now();
        if (now - (pvpState._lastLocalFireTime || 0) < PVP_CONFIG.FIRE_RATE_MS) return;
        pvpState._lastLocalFireTime = now;

        const slot = pvpState.mySlot;
        const dir  = slot === 1 ? -1 : 1;
        pvpState.localBullets.push({
            x: pvpState.localPrediction.x,
            y: pvpState.localPrediction.y + dir * -34,
            vy: PVP_CONFIG.BULLET_SPEED * dir,
            ownerSlot: slot,
            born: now
        });
    },

    _updateLocalBullets(dt) {
        const scale = dt / 16.667;
        const now   = performance.now();
        const H     = PVP_CONFIG.ARENA_HEIGHT;
        pvpState.localBullets = pvpState.localBullets.filter(b => {
            b.y += b.vy * scale;
            // Remove após 1.2s ou se saiu da tela
            return (now - b.born) < 1200 && b.y > -40 && b.y < H + 40;
        });
    },

    handleKeyDown(e) {
        switch (e.key) {
            case 'ArrowLeft':  case 'a': case 'A': pvpState.keys.left  = true; e.preventDefault(); break;
            case 'ArrowRight': case 'd': case 'D': pvpState.keys.right = true; e.preventDefault(); break;
            case 'ArrowUp':    case 'w': case 'W': pvpState.keys.up    = true; e.preventDefault(); break;
            case 'ArrowDown':  case 's': case 'S': pvpState.keys.down  = true; e.preventDefault(); break;
            case ' ':
                if (!pvpState.keys.fire) PvPEngine.fireLocalBullet();
                pvpState.keys.fire = true;
                e.preventDefault();
                break;
        }
    },

    handleKeyUp(e) {
        switch (e.key) {
            case 'ArrowLeft':  case 'a': case 'A': pvpState.keys.left  = false; break;
            case 'ArrowRight': case 'd': case 'D': pvpState.keys.right = false; break;
            case 'ArrowUp':    case 'w': case 'W': pvpState.keys.up    = false; break;
            case 'ArrowDown':  case 's': case 'S': pvpState.keys.down  = false; break;
            case ' ':                              pvpState.keys.fire  = false; break;
        }
    },

    setupMobileControls() {
        const joystickEl = document.getElementById('pvpJoystick');
        const knobEl     = document.getElementById('pvpJoystickKnob');
        const gameArea   = document.getElementById('pvpArena');
        if (!gameArea) return;

        let joyActive = false, joyOriginX = 0, joyOriginY = 0;
        const MAX_RADIUS = 55, DEADZONE = 18;

        const updateJoy = (cx, cy) => {
            const dx = cx - joyOriginX;
            const dy = cy - joyOriginY;
            const dist   = Math.sqrt(dx * dx + dy * dy);
            const angle  = Math.atan2(dy, dx);
            const clamped = Math.min(dist, MAX_RADIUS);
            if (knobEl) knobEl.style.transform = `translate(${Math.cos(angle)*clamped}px,${Math.sin(angle)*clamped}px)`;
            pvpState.keys.left  = dx < -DEADZONE;
            pvpState.keys.right = dx >  DEADZONE;
            pvpState.keys.up    = dy < -DEADZONE;
            pvpState.keys.down  = dy >  DEADZONE;
        };

        const endJoy = () => {
            joyActive = false;
            pvpState.keys.left = pvpState.keys.right = pvpState.keys.up = pvpState.keys.down = false;
            if (knobEl)     knobEl.style.transform    = 'translate(0,0)';
            if (joystickEl) joystickEl.style.opacity  = '0';
        };

        // Rastrear qual touchId é o joystick e qual é o fogo
        let joyTouchId = null;

        gameArea.addEventListener('touchstart', (e) => {
            for (const touch of e.changedTouches) {
                if (touch.clientX < window.innerWidth * 0.6) {
                    // Joystick
                    if (!joyActive) {
                        joyActive   = true;
                        joyTouchId  = touch.identifier;
                        joyOriginX  = touch.clientX;
                        joyOriginY  = touch.clientY;
                        if (joystickEl) {
                            joystickEl.style.left    = (touch.clientX - 60) + 'px';
                            joystickEl.style.top     = (touch.clientY - 60) + 'px';
                            joystickEl.style.opacity = '1';
                        }
                        if (knobEl) knobEl.style.transform = 'translate(0,0)';
                    }
                } else {
                    // Fogo — resposta imediata: cria bala local e envia input agora
                    pvpState.keys.fire = true;
                    this.fireLocalBullet();
                    PvPSessionManager.sendInput(pvpState.keys); // envio imediato
                }
            }
            e.preventDefault();
        }, { passive: false });

        gameArea.addEventListener('touchmove', (e) => {
            for (const touch of e.changedTouches) {
                if (touch.identifier === joyTouchId && joyActive) {
                    updateJoy(touch.clientX, touch.clientY);
                }
            }
            e.preventDefault();
        }, { passive: false });

        gameArea.addEventListener('touchend', (e) => {
            for (const touch of e.changedTouches) {
                if (touch.identifier === joyTouchId) {
                    endJoy();
                    joyTouchId = null;
                } else if (touch.clientX >= window.innerWidth * 0.6) {
                    pvpState.keys.fire = false;
                }
            }
            e.preventDefault();
        }, { passive: false });

        gameArea.addEventListener('touchcancel', () => {
            endJoy();
            joyTouchId = null;
            pvpState.keys.fire = false;
        }, { passive: false });
    },
};

// ============================================
// Callback handlers
// ============================================

function onMatchFound(data) {
    pvpState._matchData = data;
    document.getElementById('pvpLobby').style.display    = 'none';
    document.getElementById('pvpCountdown').style.display = 'flex';
    const el = document.getElementById('pvpOpponentName');
    if (el) el.textContent = 'Oponente';
}

function onCountdown(data) {
    const el = document.getElementById('pvpCountdownNumber');
    if (el) el.textContent = data.count > 0 ? data.count : 'FIGHT!';
}

function onGameStart(data) {
    document.getElementById('pvpCountdown').style.display = 'none';
    document.getElementById('pvpArena').style.display     = 'block';

    const slot = pvpState.mySlot;
    const H    = PVP_CONFIG.ARENA_HEIGHT;
    pvpState.localPrediction = {
        x: PVP_CONFIG.ARENA_WIDTH / 2,
        y: slot === 1 ? H - 120 : 120,
        vx: 0, vy: 0
    };
    pvpState.localBullets        = [];
    pvpState._lastLocalFireTime  = 0;

    PvPRenderer.init('pvpCanvas');
    PvPRenderer.startLoop();
    PvPEngine.startInputLoop();
}

function onGameEnd(result) {
    PvPEngine.stopInputLoop();
    PvPRenderer.stopLoop();
    pvpState.opponentPrev      = null;
    pvpState.opponentCurr      = null;
    pvpState.opponentUpdatedAt = 0;

    const myUid    = PvPSessionManager.googleUid;
    const isWinner = result.winnerUid === myUid;
    const isDraw   = result.winCondition === 'draw';

    document.getElementById('pvpArena').style.display    = 'none';
    document.getElementById('pvpResult').style.display   = 'flex';

    const titleEl = document.getElementById('pvpResultTitle');
    const iconEl  = document.getElementById('pvpResultIcon');
    if (isDraw)        { if (titleEl) titleEl.textContent = 'EMPATE';   if (iconEl) iconEl.innerHTML = '<i class="fas fa-handshake"></i>'; }
    else if (isWinner) { if (titleEl) titleEl.textContent = 'VITÓRIA!'; if (iconEl) iconEl.innerHTML = '<i class="fas fa-trophy"></i>'; }
    else               { if (titleEl) titleEl.textContent = 'DERROTA';  if (iconEl) iconEl.innerHTML = '<i class="fas fa-skull-crossbones"></i>'; }

    const myStats = (pvpState.mySlot === 1) ? result.player1 : result.player2;
    const opStats = (pvpState.mySlot === 1) ? result.player2 : result.player1;
    const setR = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    setR('pvpMyLives', myStats.lives);     setR('pvpMyAsteroids', myStats.asteroidsDestroyed);
    setR('pvpMyShots', myStats.shotsFired); setR('pvpMyHits', myStats.hits);
    setR('pvpOpLives', opStats.lives);     setR('pvpOpAsteroids', opStats.asteroidsDestroyed);
    setR('pvpOpShots', opStats.shotsFired); setR('pvpOpHits', opStats.hits);

    const condEl = document.getElementById('pvpWinCondition');
    if (condEl) {
        const conds = { elimination: 'Eliminação', time_lives: 'Tempo - Mais vidas',
                        disconnect: 'Desconexão do oponente', draw: 'Empate total' };
        condEl.textContent = conds[result.winCondition] || result.winCondition;
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
    if (btn) { btn.textContent = 'BUSCAR NOVAMENTE'; btn.disabled = false; }
}

function onReconnected(data) {
    document.getElementById('pvpArena').style.display = 'block';
    PvPRenderer.init('pvpCanvas');
    PvPRenderer.startLoop();
    PvPEngine.startInputLoop();
}
