// ============================================
// UNOBIX PvP - Game Engine (Client)
// Captura input, envia ao servidor, renderiza estado
// ============================================

const PvPEngine = {
    inputSendInterval: null,
    gameActive: false,

    /**
     * Inicia captura de input e envio ao servidor
     */
    startInputLoop() {
        this.gameActive = true;

        // Captura teclado
        document.addEventListener('keydown', this.handleKeyDown);
        document.addEventListener('keyup', this.handleKeyUp);

        // Setup mobile controls
        this.setupMobileControls();

        // Enviar input a cada 16ms (~60fps)
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
        document.removeEventListener('keyup', this.handleKeyUp);

        // Reset keys
        pvpState.keys = { left: false, right: false, up: false, down: false, fire: false };
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
        const bind = (id, key) => {
            const el = document.getElementById(id);
            if (!el) return;

            const start = () => { pvpState.keys[key] = true; };
            const stop = () => { pvpState.keys[key] = false; };

            el.addEventListener('touchstart', (e) => { e.preventDefault(); start(); });
            el.addEventListener('touchend', (e) => { e.preventDefault(); stop(); });
            el.addEventListener('touchcancel', (e) => { e.preventDefault(); stop(); });
            el.addEventListener('mousedown', start);
            el.addEventListener('mouseup', stop);
            el.addEventListener('mouseleave', stop);
        };

        bind('pvpLeftBtn', 'left');
        bind('pvpRightBtn', 'right');
        bind('pvpUpBtn', 'up');
        bind('pvpDownBtn', 'down');
        bind('pvpFireBtn', 'fire');
    }
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

    // Iniciar rendering e input
    PvPRenderer.init('pvpCanvas');
    PvPRenderer.startLoop();
    PvPEngine.startInputLoop();
}

function onGameEnd(result) {
    PvPEngine.stopInputLoop();
    PvPRenderer.stopLoop();

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
