// ============================================
// UNOBIX PvP Server - Entry Point
// Node.js + Socket.io WebSocket Server
// ============================================
require('dotenv').config();

const http = require('http');
const { Server } = require('socket.io');
const config = require('./config');
const Matchmaking = require('./matchmaking');

// Criar servidor HTTP
const server = http.createServer((req, res) => {
    // Health check
    if (req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'ok',
            uptime: process.uptime(),
            ...matchmaking.getStats()
        }));
        return;
    }

    // Stats endpoint
    if (req.url === '/stats') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(matchmaking.getStats()));
        return;
    }

    res.writeHead(404);
    res.end('Not Found');
});

// Criar Socket.io
const io = new Server(server, {
    cors: {
        origin: config.CORS_ORIGIN,
        methods: ['GET', 'POST']
    },
    pingInterval: 10000,
    pingTimeout: 5000
});

// Iniciar matchmaking
const matchmaking = new Matchmaking(io);

// Validar token JWT do jogador
function validateToken(token) {
    if (!token) return null;

    const parts = token.split('.');
    if (parts.length !== 3) return null;

    const [headerEncoded, payloadEncoded, signatureEncoded] = parts;

    // Verificar assinatura
    const crypto = require('crypto');
    const expectedSignature = Buffer.from(
        crypto.createHmac('sha256', config.PVP_JWT_SECRET)
            .update(`${headerEncoded}.${payloadEncoded}`)
            .digest()
    ).toString('base64');

    if (expectedSignature !== signatureEncoded) return null;

    try {
        const payload = JSON.parse(Buffer.from(payloadEncoded, 'base64').toString());

        // Verificar expiração
        if ((payload.exp || 0) < Math.floor(Date.now() / 1000)) return null;

        return payload;
    } catch {
        return null;
    }
}

// Conexões Socket.io
io.on('connection', (socket) => {
    console.log(`[SOCKET] Nova conexão: ${socket.id}`);

    let authenticatedUid = null;

    // Autenticação
    socket.on('authenticate', (data) => {
        const payload = validateToken(data.token);
        if (!payload) {
            socket.emit('auth_error', { error: 'Token inválido ou expirado' });
            return;
        }

        authenticatedUid = payload.sub;
        socket.emit('authenticated', {
            googleUid: payload.sub,
            displayName: payload.name
        });

        console.log(`[SOCKET] ${payload.name} autenticado (${socket.id})`);

        // Tentar reconectar a partida em andamento
        const reconnected = matchmaking.handleReconnect(socket.id, authenticatedUid);
        if (reconnected) {
            socket.emit('reconnected', { message: 'Reconectado à partida em andamento' });
        }
    });

    // Entrar na fila de matchmaking
    socket.on('join_queue', (data) => {
        if (!authenticatedUid) {
            socket.emit('auth_error', { error: 'Não autenticado' });
            return;
        }

        const result = matchmaking.addToQueue(
            authenticatedUid,
            data.displayName || 'Jogador',
            socket.id
        );

        socket.emit('queue_status', result);
    });

    // Sair da fila
    socket.on('leave_queue', () => {
        if (authenticatedUid) {
            matchmaking.removeFromQueue(authenticatedUid);
            socket.emit('queue_status', { status: 'left' });
        }
    });

    // Input do jogo (enviado a cada frame pelo client)
    socket.on('input', (input) => {
        if (!authenticatedUid) return;
        matchmaking.handleInput(socket.id, input);
    });

    // Desconexão
    socket.on('disconnect', (reason) => {
        console.log(`[SOCKET] Desconectado: ${socket.id} (${reason})`);
        matchmaking.handleDisconnect(socket.id);
    });
});

// Iniciar servidor
server.listen(config.PORT, '0.0.0.0', () => {
    console.log(`\n🎮 UNOBIX PvP Server rodando na porta ${config.PORT}`);
    console.log(`   Tick rate: ${config.TICK_RATE}Hz`);
    console.log(`   Game duration: ${config.GAME_DURATION}s`);
    console.log(`   Lives: ${config.LIVES}`);
    console.log(`   Backend: ${config.BACKEND_URL}\n`);
});

// Graceful shutdown
process.on('SIGTERM', () => {
    console.log('[SERVER] Recebido SIGTERM, encerrando...');
    matchmaking.destroy();
    server.close(() => process.exit(0));
});

process.on('SIGINT', () => {
    console.log('[SERVER] Recebido SIGINT, encerrando...');
    matchmaking.destroy();
    server.close(() => process.exit(0));
});
