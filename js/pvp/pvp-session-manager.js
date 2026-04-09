// ============================================
// UNOBIX PvP - Session Manager (WebSocket)
// Gerencia conexão com o Game Server PvP
// ============================================

const PvPSessionManager = {
    socket: null,
    pvpToken: null,
    googleUid: null,
    displayName: null,

    /**
     * Autorizar com backend PHP e obter token JWT
     */
    async authorize() {
        const googleUid = localStorage.getItem('googleUid');
        if (!googleUid) {
            throw new Error('Não autenticado. Faça login primeiro.');
        }

        const res = await fetch('/api/pvp-authorize.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: googleUid })
        });

        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'Erro ao autorizar PvP');
        }

        this.pvpToken = data.pvp_token;
        this.googleUid = data.google_uid;
        this.displayName = data.display_name;

        return data;
    },

    /**
     * Conectar ao Game Server via WebSocket
     */
    connect() {
        return new Promise((resolve, reject) => {
            if (!this.pvpToken) {
                reject(new Error('Token não disponível. Autorize primeiro.'));
                return;
            }

            // Socket.io — URL é o host, path é /pvp-ws/socket.io em produção
            this.socket = io(PVP_CONFIG.GAME_SERVER_URL, {
                path: PVP_CONFIG.GAME_SERVER_PATH,
                transports: ['websocket'],
                reconnection: true,
                reconnectionAttempts: 10,
                reconnectionDelay: 1500,
                reconnectionDelayMax: 5000
            });

            // Autenticar ao conectar
            this.socket.on('connect', () => {
                pvpState.connected = true;
                this.socket.emit('authenticate', { token: this.pvpToken });
            });

            this.socket.on('authenticated', (data) => {
                pvpState.authenticated = true;
                resolve(data);
            });

            this.socket.on('auth_error', (data) => {
                pvpState.connected = false;
                reject(new Error(data.error));
            });

            // Eventos de matchmaking
            this.socket.on('queue_status', (data) => {
                if (data.status === 'waiting') {
                    pvpState.inQueue = true;
                    if (typeof onQueueUpdate === 'function') onQueueUpdate(data);
                } else if (data.status === 'matched') {
                    pvpState.inQueue = false;
                }
            });

            this.socket.on('matchmaking_timeout', (data) => {
                pvpState.inQueue = false;
                if (typeof onMatchmakingTimeout === 'function') onMatchmakingTimeout(data);
            });

            // Eventos de partida
            this.socket.on('match_found', (data) => {
                pvpState.inMatch = true;
                pvpState.matchId = data.matchId;
                if (typeof onMatchFound === 'function') onMatchFound(data);
            });

            this.socket.on('your_slot', (data) => {
                pvpState.mySlot = data.slot;
                // Agora que sabemos nosso slot, atualizar nome do oponente
                const matchData = pvpState._matchData;
                if (matchData) {
                    pvpState.opponentName = data.slot === 1
                        ? matchData.player2.displayName
                        : matchData.player1.displayName;
                    const el = document.getElementById('pvpOpponentName');
                    if (el) el.textContent = pvpState.opponentName;
                }
            });

            this.socket.on('countdown', (data) => {
                pvpState.countdownValue = data.count;
                if (typeof onCountdown === 'function') onCountdown(data);
            });

            this.socket.on('game_start', (data) => {
                if (typeof onGameStart === 'function') onGameStart(data);
            });

            this.socket.on('game_state', (state) => {
                // Validar estrutura mínima do state para evitar crashes no renderer
                if (!state || typeof state !== 'object') return;

                pvpState.previousState = pvpState.serverState;
                pvpState.serverState = state;

                // Salvar prev/curr do oponente para entity interpolation no renderer
                if (pvpState.mySlot) {
                    const opp = pvpState.mySlot === 1 ? state.player2 : state.player1;
                    if (opp && typeof opp.x === 'number' && typeof opp.y === 'number') {
                        pvpState.opponentPrev = pvpState.opponentCurr
                            ? { x: pvpState.opponentCurr.x, y: pvpState.opponentCurr.y }
                            : { x: opp.x, y: opp.y };
                        pvpState.opponentCurr = { x: opp.x, y: opp.y };
                        pvpState.opponentUpdatedAt = Date.now();
                    }
                }
            });

            this.socket.on('game_end', (result) => {
                pvpState.result = result;
                pvpState.inMatch = false;
                if (typeof onGameEnd === 'function') onGameEnd(result);
            });

            this.socket.on('reconnected', (data) => {
                if (typeof onReconnected === 'function') onReconnected(data);
            });

            this.socket.on('disconnect', () => {
                pvpState.connected = false;
            });

            // Timeout de conexão
            setTimeout(() => {
                if (!pvpState.connected) {
                    reject(new Error('Timeout ao conectar ao servidor PvP'));
                }
            }, 10000);
        });
    },

    /**
     * Entrar na fila de matchmaking
     */
    joinQueue() {
        if (!this.socket || !pvpState.authenticated) return;
        this.socket.emit('join_queue', { displayName: this.displayName });
        pvpState.inQueue = true;
    },

    /**
     * Sair da fila
     */
    leaveQueue() {
        if (!this.socket) return;
        this.socket.emit('leave_queue');
        pvpState.inQueue = false;
    },

    /**
     * Enviar input ao servidor
     */
    sendInput(keys) {
        if (!this.socket || !pvpState.inMatch) return;
        this.socket.emit('input', keys);
    },

    /**
     * Desconectar
     */
    disconnect() {
        if (this.socket) {
            this.socket.disconnect();
            this.socket = null;
        }
        pvpState.connected = false;
        pvpState.authenticated = false;
        pvpState.inQueue = false;
        pvpState.inMatch = false;
    }
};
