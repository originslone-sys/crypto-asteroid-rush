// ============================================
// UNOBIX PvP Server - Matchmaking
// Fila de espera e emparelhamento de jogadores
// ============================================

const config = require('./config');
const GameRoom = require('./game-room');

class Matchmaking {
    constructor(io) {
        this.io = io;
        this.queue = []; // [{googleUid, displayName, socketId, joinedAt}]
        this.activeRooms = new Map(); // matchId → GameRoom
        this.playerToRoom = new Map(); // googleUid → matchId

        // Cleanup de timeout a cada 5s
        this.cleanupInterval = setInterval(() => this.cleanupQueue(), 5000);
    }

    addToQueue(googleUid, displayName, socketId) {
        // Verificar se já está na fila
        const existing = this.queue.find(p => p.googleUid === googleUid);
        if (existing) {
            existing.socketId = socketId;
            existing.joinedAt = Date.now();
            return { status: 'already_in_queue' };
        }

        // Verificar se já está em partida ativa
        if (this.playerToRoom.has(googleUid)) {
            const matchId = this.playerToRoom.get(googleUid);
            const room = this.activeRooms.get(matchId);
            if (room && !room.isEnded()) {
                return { status: 'already_in_match', matchId };
            }
            // Room finalizada, limpar
            this.playerToRoom.delete(googleUid);
        }

        this.queue.push({
            googleUid,
            displayName,
            socketId,
            joinedAt: Date.now()
        });

        console.log(`[MATCHMAKING] ${displayName} entrou na fila. Fila: ${this.queue.length}`);

        // Tentar emparelhar
        return this.tryMatch();
    }

    removeFromQueue(googleUid) {
        const idx = this.queue.findIndex(p => p.googleUid === googleUid);
        if (idx >= 0) {
            const removed = this.queue.splice(idx, 1)[0];
            console.log(`[MATCHMAKING] ${removed.displayName} saiu da fila. Fila: ${this.queue.length}`);
            return true;
        }
        return false;
    }

    removeBySocket(socketId) {
        const idx = this.queue.findIndex(p => p.socketId === socketId);
        if (idx >= 0) {
            const removed = this.queue.splice(idx, 1)[0];
            console.log(`[MATCHMAKING] ${removed.displayName} desconectou da fila. Fila: ${this.queue.length}`);
            return removed;
        }
        return null;
    }

    tryMatch() {
        if (this.queue.length < 2) {
            return { status: 'waiting', position: this.queue.length };
        }

        // Pegar os 2 primeiros da fila (FIFO)
        const player1Data = this.queue.shift();
        const player2Data = this.queue.shift();

        console.log(`[MATCHMAKING] Match encontrado: ${player1Data.displayName} vs ${player2Data.displayName}`);

        // Criar sala de jogo
        const room = new GameRoom(this.io, player1Data, player2Data);

        this.activeRooms.set(room.matchId, room);
        this.playerToRoom.set(player1Data.googleUid, room.matchId);
        this.playerToRoom.set(player2Data.googleUid, room.matchId);

        // Iniciar partida
        room.start();

        return { status: 'matched', matchId: room.matchId };
    }

    handleInput(socketId, input) {
        // Encontrar room do jogador pelo socket
        for (const room of this.activeRooms.values()) {
            if (room.isEnded()) continue;
            const player = room.getPlayerBySocket(socketId);
            if (player) {
                room.handleInput(socketId, input);
                return;
            }
        }
    }

    handleDisconnect(socketId) {
        // Remover da fila se estava esperando
        this.removeBySocket(socketId);

        // Notificar room se estava em partida
        for (const room of this.activeRooms.values()) {
            if (room.isEnded()) continue;
            const player = room.getPlayerBySocket(socketId);
            if (player) {
                room.handleDisconnect(socketId);
                return;
            }
        }
    }

    handleReconnect(socketId, googleUid) {
        const matchId = this.playerToRoom.get(googleUid);
        if (!matchId) return false;

        const room = this.activeRooms.get(matchId);
        if (!room || room.isEnded()) return false;

        return room.handleReconnect(socketId, googleUid);
    }

    cleanupQueue() {
        const now = Date.now();
        const timeout = config.MATCHMAKING_TIMEOUT * 1000;

        // Remover jogadores que esperaram demais
        for (let i = this.queue.length - 1; i >= 0; i--) {
            const player = this.queue[i];
            if (now - player.joinedAt > timeout) {
                this.queue.splice(i, 1);
                const socket = this.io.sockets.sockets.get(player.socketId);
                if (socket) {
                    socket.emit('matchmaking_timeout', {
                        message: 'Tempo de espera esgotado. Tente novamente.'
                    });
                }
                console.log(`[MATCHMAKING] ${player.displayName} timeout. Fila: ${this.queue.length}`);
            }
        }

        // Limpar rooms finalizadas há mais de 60s
        for (const [matchId, room] of this.activeRooms) {
            if (room.isEnded()) {
                room.cleanup();
                this.activeRooms.delete(matchId);
                // Limpar referências de jogadores
                if (this.playerToRoom.get(room.player1.googleUid) === matchId) {
                    this.playerToRoom.delete(room.player1.googleUid);
                }
                if (this.playerToRoom.get(room.player2.googleUid) === matchId) {
                    this.playerToRoom.delete(room.player2.googleUid);
                }
            }
        }
    }

    getStats() {
        return {
            queueSize: this.queue.length,
            activeMatches: [...this.activeRooms.values()].filter(r => !r.isEnded()).length,
            totalRooms: this.activeRooms.size
        };
    }

    destroy() {
        clearInterval(this.cleanupInterval);
        for (const room of this.activeRooms.values()) {
            room.cleanup();
        }
    }
}

module.exports = Matchmaking;
