// ============================================
// UNOBIX PvP Server - Game Room
// Gerencia uma partida PvP entre 2 jogadores
// ============================================

const config = require('./config');
const Player = require('./player');
const { spawnAsteroid, updateAsteroids, processCollisions } = require('./physics');
const crypto = require('crypto');

class GameRoom {
    constructor(io, player1Data, player2Data) {
        this.io = io;
        this.matchId = crypto.randomUUID();
        this.roomName = `pvp_${this.matchId}`;

        // Criar jogadores
        this.player1 = new Player(
            player1Data.googleUid,
            player1Data.displayName,
            player1Data.socketId,
            1 // slot bottom
        );
        this.player2 = new Player(
            player2Data.googleUid,
            player2Data.displayName,
            player2Data.socketId,
            2 // slot top
        );

        // Estado do jogo
        this.status = 'countdown'; // countdown → active → ended
        this.timeLeft = config.GAME_DURATION;
        this.countdownLeft = config.COUNTDOWN_SECONDS;
        this.asteroids = [];
        this.asteroidIdCounter = 0;
        this.lastAsteroidSpawn = 0;

        // Timers
        this.gameLoopInterval = null;
        this.timerInterval = null;
        this.countdownInterval = null;

        // Resultado
        this.result = null;
    }

    start() {
        // Ambos entram na room Socket.io
        const s1 = this.io.sockets.sockets.get(this.player1.socketId);
        const s2 = this.io.sockets.sockets.get(this.player2.socketId);
        if (s1) s1.join(this.roomName);
        if (s2) s2.join(this.roomName);

        // Notificar que match foi encontrado
        this.io.to(this.roomName).emit('match_found', {
            matchId: this.matchId,
            player1: {
                displayName: this.player1.displayName,
                slot: 1
            },
            player2: {
                displayName: this.player2.displayName,
                slot: 2
            },
            countdown: config.COUNTDOWN_SECONDS,
            gameDuration: config.GAME_DURATION,
            lives: config.LIVES,
            arena: {
                width: config.ARENA_WIDTH,
                height: config.ARENA_HEIGHT
            }
        });

        // Enviar slot a cada jogador
        if (s1) s1.emit('your_slot', { slot: 1 });
        if (s2) s2.emit('your_slot', { slot: 2 });

        // Countdown
        this.startCountdown();
    }

    startCountdown() {
        this.countdownInterval = setInterval(() => {
            this.countdownLeft--;
            this.io.to(this.roomName).emit('countdown', { count: this.countdownLeft });

            if (this.countdownLeft <= 0) {
                clearInterval(this.countdownInterval);
                this.startGame();
            }
        }, 1000);
    }

    startGame() {
        this.status = 'active';
        this.player1.initPosition();
        this.player2.initPosition();

        this.io.to(this.roomName).emit('game_start', {
            timeLeft: this.timeLeft
        });

        // Game loop a 60Hz
        this.gameLoopInterval = setInterval(() => {
            this.tick();
        }, config.TICK_INTERVAL);

        // Timer de 1 segundo
        this.timerInterval = setInterval(() => {
            this.timeLeft--;
            if (this.timeLeft <= 0) {
                this.endByTimeout();
            }
        }, 1000);
    }

    tick() {
        if (this.status !== 'active') return;

        const now = Date.now();

        // Atualizar posições
        this.player1.updatePosition();
        this.player2.updatePosition();

        // Processar tiros
        this.player1.tryFire(now);
        this.player2.tryFire(now);

        // Atualizar balas
        this.player1.updateBullets();
        this.player2.updateBullets();

        // Spawnar asteroides
        if (now - this.lastAsteroidSpawn >= config.ASTEROID_SPAWN_INTERVAL &&
            this.asteroids.length < config.MAX_ASTEROIDS) {
            this.asteroidIdCounter++;
            this.asteroids.push(spawnAsteroid(this.asteroidIdCounter));
            this.lastAsteroidSpawn = now;
        }

        // Atualizar asteroides
        updateAsteroids(this.asteroids);

        // Processar colisões
        const events = processCollisions(this.player1, this.player2, this.asteroids);

        // Verificar morte
        if (this.player1.isDead() && this.player2.isDead()) {
            this.endByDraw();
            return;
        }
        if (this.player1.isDead()) {
            this.endByElimination(this.player2, this.player1);
            return;
        }
        if (this.player2.isDead()) {
            this.endByElimination(this.player1, this.player2);
            return;
        }

        // Enviar estado para ambos
        this.broadcastState(events);
    }

    broadcastState(events) {
        const state = {
            timeLeft: this.timeLeft,
            player1: this.player1.getState(),
            player2: this.player2.getState(),
            player1Bullets: this.player1.getBulletsState(),
            player2Bullets: this.player2.getBulletsState(),
            asteroids: this.asteroids.map(a => ({
                id: a.id,
                x: Math.round(a.x),
                y: Math.round(a.y),
                size: Math.round(a.size),
                rotation: a.rotation,
                vx: a.vx,
                vy: a.vy
            })),
            events
        };

        this.io.to(this.roomName).emit('game_state', state);
    }

    handleInput(socketId, input) {
        if (this.status !== 'active') return;

        const player = this.getPlayerBySocket(socketId);
        if (!player) return;

        player.input = {
            left: !!input.left,
            right: !!input.right,
            up: !!input.up,
            down: !!input.down,
            fire: !!input.fire
        };
    }

    handleDisconnect(socketId) {
        const player = this.getPlayerBySocket(socketId);
        if (!player) return;

        player.connected = false;
        player.disconnectedAt = Date.now();

        if (this.status === 'countdown') {
            // Cancelar partida se alguém desconecta no countdown
            this.endByCancellation();
            return;
        }

        if (this.status === 'active') {
            // Dar tempo para reconectar
            setTimeout(() => {
                if (!player.connected && this.status === 'active') {
                    const winner = (player === this.player1) ? this.player2 : this.player1;
                    this.endByDisconnect(winner, player);
                }
            }, config.DISCONNECT_TIMEOUT * 1000);
        }
    }

    handleReconnect(socketId, googleUid) {
        const player = (this.player1.googleUid === googleUid) ? this.player1 :
                       (this.player2.googleUid === googleUid) ? this.player2 : null;
        if (!player) return false;

        player.socketId = socketId;
        player.connected = true;
        player.disconnectedAt = null;

        const socket = this.io.sockets.sockets.get(socketId);
        if (socket) {
            socket.join(this.roomName);
            socket.emit('your_slot', { slot: player.slot });
        }

        return true;
    }

    // --- End conditions ---

    endByElimination(winner, loser) {
        this.endGame('completed', 'elimination', winner.googleUid);
    }

    endByTimeout() {
        let winnerUid = null;
        let winCondition = 'draw';

        if (this.player1.lives > this.player2.lives) {
            winnerUid = this.player1.googleUid;
            winCondition = 'time_lives';
        } else if (this.player2.lives > this.player1.lives) {
            winnerUid = this.player2.googleUid;
            winCondition = 'time_lives';
        } else if (this.player1.asteroidsDestroyed > this.player2.asteroidsDestroyed) {
            winnerUid = this.player1.googleUid;
            winCondition = 'time_asteroids';
        } else if (this.player2.asteroidsDestroyed > this.player1.asteroidsDestroyed) {
            winnerUid = this.player2.googleUid;
            winCondition = 'time_asteroids';
        }

        this.endGame('completed', winCondition, winnerUid);
    }

    endByDraw() {
        this.endGame('completed', 'draw', null);
    }

    endByDisconnect(winner, disconnected) {
        this.endGame('completed', 'disconnect', winner.googleUid);
    }

    endByCancellation() {
        this.endGame('cancelled', 'cancelled', null);
    }

    endGame(status, winCondition, winnerUid) {
        if (this.status === 'ended') return;
        this.status = 'ended';

        // Parar loops
        if (this.gameLoopInterval) clearInterval(this.gameLoopInterval);
        if (this.timerInterval) clearInterval(this.timerInterval);
        if (this.countdownInterval) clearInterval(this.countdownInterval);

        const gameDuration = config.GAME_DURATION - this.timeLeft;

        this.result = {
            matchId: this.matchId,
            status,
            winCondition,
            winnerUid,
            gameDuration,
            player1: {
                googleUid: this.player1.googleUid,
                displayName: this.player1.displayName,
                lives: this.player1.lives,
                asteroidsDestroyed: this.player1.asteroidsDestroyed,
                shotsFired: this.player1.shotsFired,
                hits: this.player1.hits
            },
            player2: {
                googleUid: this.player2.googleUid,
                displayName: this.player2.displayName,
                lives: this.player2.lives,
                asteroidsDestroyed: this.player2.asteroidsDestroyed,
                shotsFired: this.player2.shotsFired,
                hits: this.player2.hits
            }
        };

        // Notificar clientes
        this.io.to(this.roomName).emit('game_end', this.result);

        // Reportar ao backend PHP
        this.reportToBackend();
    }

    async reportToBackend() {
        const payload = {
            match_id: this.result.matchId,
            player1_uid: this.result.player1.googleUid,
            player2_uid: this.result.player2.googleUid,
            winner_uid: this.result.winnerUid,
            status: this.result.status,
            win_condition: this.result.winCondition,
            player1_lives: this.result.player1.lives,
            player2_lives: this.result.player2.lives,
            player1_asteroids_destroyed: this.result.player1.asteroidsDestroyed,
            player2_asteroids_destroyed: this.result.player2.asteroidsDestroyed,
            player1_shots_fired: this.result.player1.shotsFired,
            player2_shots_fired: this.result.player2.shotsFired,
            player1_hits: this.result.player1.hits,
            player2_hits: this.result.player2.hits,
            game_duration: this.result.gameDuration
        };

        try {
            const url = `${config.BACKEND_URL}/api/pvp-result.php`;
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-PVP-Server-Token': config.PVP_JWT_SECRET
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (!data.success) {
                console.error(`[PVP] Backend report failed for match ${this.matchId}:`, data.error);
            } else {
                console.log(`[PVP] Match ${this.matchId} reported to backend. Winner: ${this.result.winnerUid || 'DRAW'}`);
            }
        } catch (err) {
            console.error(`[PVP] Failed to report match ${this.matchId} to backend:`, err.message);
        }
    }

    // --- Helpers ---

    getPlayerBySocket(socketId) {
        if (this.player1.socketId === socketId) return this.player1;
        if (this.player2.socketId === socketId) return this.player2;
        return null;
    }

    hasPlayer(googleUid) {
        return this.player1.googleUid === googleUid || this.player2.googleUid === googleUid;
    }

    isEnded() {
        return this.status === 'ended';
    }

    cleanup() {
        if (this.gameLoopInterval) clearInterval(this.gameLoopInterval);
        if (this.timerInterval) clearInterval(this.timerInterval);
        if (this.countdownInterval) clearInterval(this.countdownInterval);
    }
}

module.exports = GameRoom;
