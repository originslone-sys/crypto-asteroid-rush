// ============================================
// UNOBIX PvP - Game Renderer
// Renderiza o estado recebido do servidor
// ============================================

const PvPRenderer = {
    canvas: null,
    ctx: null,
    animationFrameId: null,
    starsCache: null,
    dynamicStars: [],

    init(canvasId) {
        this.canvas = document.getElementById(canvasId);
        this.ctx = this.canvas.getContext('2d');
        this.resize();
        this.initStars();
        window.addEventListener('resize', () => this.resize());
    },

    resize() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
        this.starsCache = null;
        this.initStars();
    },

    initStars() {
        const count = Math.floor((this.canvas.width * this.canvas.height) / 4000);
        this.dynamicStars = [];
        for (let i = 0; i < count; i++) {
            this.dynamicStars.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height,
                size: Math.random() * 2 + 0.5,
                speed: Math.random() * 0.3 + 0.1,
                alpha: Math.random() * 0.5 + 0.2
            });
        }
    },

    startLoop() {
        const loop = () => {
            this.render();
            this.animationFrameId = requestAnimationFrame(loop);
        };
        loop();
    },

    stopLoop() {
        if (this.animationFrameId) {
            cancelAnimationFrame(this.animationFrameId);
            this.animationFrameId = null;
        }
    },

    render() {
        const ctx = this.ctx;
        const w = this.canvas.width;
        const h = this.canvas.height;
        const state = pvpState.serverState;

        // Background
        ctx.fillStyle = '#0a0a1a';
        ctx.fillRect(0, 0, w, h);

        // Estrelas
        this.renderStars(ctx, w, h);

        // Divisória central (sutil)
        ctx.strokeStyle = 'rgba(255,255,255,0.05)';
        ctx.setLineDash([10, 20]);
        ctx.beginPath();
        ctx.moveTo(0, h / 2);
        ctx.lineTo(w, h / 2);
        ctx.stroke();
        ctx.setLineDash([]);

        if (!state) return;

        // Escala relativa à arena do servidor
        const scaleX = w / PVP_CONFIG.ARENA_WIDTH;
        const scaleY = h / PVP_CONFIG.ARENA_HEIGHT;

        // Asteroides
        this.renderAsteroids(ctx, state.asteroids, scaleX, scaleY);

        // Balas Player 1 (verde)
        this.renderBullets(ctx, state.player1Bullets, PVP_CONFIG.PLAYER_BULLET_COLOR,
            PVP_CONFIG.OPPONENT_BULLET_COLOR, scaleX, scaleY);

        // Balas Player 2 (vermelho)
        this.renderBullets(ctx, state.player2Bullets, PVP_CONFIG.PLAYER_BULLET_COLOR,
            PVP_CONFIG.OPPONENT_BULLET_COLOR, scaleX, scaleY);

        // Naves
        const myPlayer = pvpState.mySlot === 1 ? state.player1 : state.player2;
        const opponent = pvpState.mySlot === 1 ? state.player2 : state.player1;

        this.renderShip(ctx, myPlayer, PVP_CONFIG.PLAYER_COLOR, false, scaleX, scaleY);
        this.renderShip(ctx, opponent, PVP_CONFIG.OPPONENT_COLOR, true, scaleX, scaleY);

        // Eventos (explosões)
        if (state.events) {
            for (const evt of state.events) {
                this.renderEvent(ctx, evt, scaleX, scaleY);
            }
        }

        // HUD
        this.renderHUD(ctx, w, h, state);
    },

    renderStars(ctx, w, h) {
        for (const star of this.dynamicStars) {
            star.y += star.speed;
            if (star.y > h) {
                star.y = 0;
                star.x = Math.random() * w;
            }
            ctx.fillStyle = `rgba(255,255,255,${star.alpha})`;
            ctx.beginPath();
            ctx.arc(star.x, star.y, star.size, 0, Math.PI * 2);
            ctx.fill();
        }
    },

    renderAsteroids(ctx, asteroids, sx, sy) {
        if (!asteroids) return;
        for (const a of asteroids) {
            const x = a.x * sx;
            const y = a.y * sy;
            const size = a.size * Math.min(sx, sy);

            ctx.save();
            ctx.translate(x, y);
            ctx.rotate(a.rotation || 0);

            // Corpo do asteroide
            const gradient = ctx.createRadialGradient(0, 0, size * 0.1, 0, 0, size * 0.5);
            gradient.addColorStop(0, '#8B7355');
            gradient.addColorStop(0.5, '#6B5340');
            gradient.addColorStop(1, '#4A3728');

            ctx.beginPath();
            const points = 8;
            for (let i = 0; i < points; i++) {
                const angle = (i / points) * Math.PI * 2;
                const radius = size * 0.45 * (0.7 + Math.random() * 0.3);
                const px = Math.cos(angle) * radius;
                const py = Math.sin(angle) * radius;
                if (i === 0) ctx.moveTo(px, py);
                else ctx.lineTo(px, py);
            }
            ctx.closePath();
            ctx.fillStyle = gradient;
            ctx.fill();
            ctx.strokeStyle = '#3A2718';
            ctx.lineWidth = 1;
            ctx.stroke();

            ctx.restore();
        }
    },

    renderBullets(ctx, bullets, playerColor, opponentColor, sx, sy) {
        if (!bullets) return;
        for (const b of bullets) {
            const x = b.x * sx;
            const y = b.y * sy;
            const isMyBullet = b.ownerSlot === pvpState.mySlot;
            const color = isMyBullet ? playerColor : opponentColor;

            // Glow
            ctx.shadowColor = color;
            ctx.shadowBlur = 8;

            // Bala
            const gradient = ctx.createLinearGradient(x, y - 10, x, y + 10);
            gradient.addColorStop(0, '#ffffff');
            gradient.addColorStop(0.3, color);
            gradient.addColorStop(1, color + '44');

            ctx.fillStyle = gradient;
            ctx.fillRect(x - 2, y - 10, 4, 20);

            ctx.shadowBlur = 0;
        }
    },

    renderShip(ctx, player, color, inverted, sx, sy) {
        if (!player) return;

        const x = player.x * sx;
        const y = player.y * sy;
        const size = 20 * Math.min(sx, sy);

        // Flashing se invencível
        if (player.invincible && Math.floor(Date.now() / 100) % 2 === 0) {
            ctx.globalAlpha = 0.4;
        }

        ctx.save();
        ctx.translate(x, y);
        if (inverted) ctx.rotate(Math.PI);

        // Corpo da nave
        ctx.beginPath();
        ctx.moveTo(0, -size);
        ctx.lineTo(-size * 0.7, size * 0.6);
        ctx.lineTo(-size * 0.3, size * 0.4);
        ctx.lineTo(0, size * 0.5);
        ctx.lineTo(size * 0.3, size * 0.4);
        ctx.lineTo(size * 0.7, size * 0.6);
        ctx.closePath();

        const gradient = ctx.createLinearGradient(0, -size, 0, size);
        gradient.addColorStop(0, color);
        gradient.addColorStop(1, color + '88');
        ctx.fillStyle = gradient;
        ctx.fill();

        // Contorno
        ctx.strokeStyle = '#ffffff44';
        ctx.lineWidth = 1;
        ctx.stroke();

        // Cockpit
        ctx.beginPath();
        ctx.arc(0, -size * 0.2, size * 0.2, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff66';
        ctx.fill();

        // Engine glow
        const glowSize = size * 0.3 + Math.sin(Date.now() * 0.01) * 3;
        ctx.beginPath();
        ctx.arc(0, size * 0.5, glowSize, 0, Math.PI * 2);
        const engineGradient = ctx.createRadialGradient(0, size * 0.5, 0, 0, size * 0.5, glowSize);
        engineGradient.addColorStop(0, '#ffffffcc');
        engineGradient.addColorStop(0.5, color + 'aa');
        engineGradient.addColorStop(1, color + '00');
        ctx.fillStyle = engineGradient;
        ctx.fill();

        ctx.restore();
        ctx.globalAlpha = 1;
    },

    renderEvent(ctx, evt, sx, sy) {
        const x = evt.x * sx;
        const y = evt.y * sy;

        if (evt.type === 'asteroid_destroyed' || evt.type === 'bullet_collision') {
            // Mini explosão
            const colors = ['#ff8800', '#ffaa00', '#ffffff', '#ff4400'];
            for (let i = 0; i < 6; i++) {
                const angle = Math.random() * Math.PI * 2;
                const dist = Math.random() * 15;
                ctx.fillStyle = colors[Math.floor(Math.random() * colors.length)];
                ctx.beginPath();
                ctx.arc(
                    x + Math.cos(angle) * dist,
                    y + Math.sin(angle) * dist,
                    Math.random() * 3 + 1, 0, Math.PI * 2
                );
                ctx.fill();
            }
        }

        if (evt.type === 'ship_hit' || evt.type === 'asteroid_hit_ship') {
            // Explosão maior
            ctx.shadowColor = '#ff0000';
            ctx.shadowBlur = 20;
            for (let i = 0; i < 12; i++) {
                const angle = Math.random() * Math.PI * 2;
                const dist = Math.random() * 25;
                ctx.fillStyle = `rgba(255, ${Math.floor(Math.random() * 100)}, 0, 0.8)`;
                ctx.beginPath();
                ctx.arc(
                    x + Math.cos(angle) * dist,
                    y + Math.sin(angle) * dist,
                    Math.random() * 4 + 2, 0, Math.PI * 2
                );
                ctx.fill();
            }
            ctx.shadowBlur = 0;
        }
    },

    renderHUD(ctx, w, h, state) {
        if (!state) return;

        const myPlayer = pvpState.mySlot === 1 ? state.player1 : state.player2;
        const opponent = pvpState.mySlot === 1 ? state.player2 : state.player1;

        ctx.font = '600 14px Orbitron, monospace';

        // Timer central
        const mins = Math.floor(state.timeLeft / 60);
        const secs = state.timeLeft % 60;
        const timeStr = `${mins}:${secs.toString().padStart(2, '0')}`;

        ctx.textAlign = 'center';
        ctx.fillStyle = state.timeLeft <= 30 ? '#ff4444' : '#ffffff';
        ctx.font = '700 24px Orbitron, monospace';
        ctx.fillText(timeStr, w / 2, 35);

        ctx.font = '600 13px Rajdhani, sans-serif';

        // Info do oponente (topo)
        ctx.textAlign = 'left';
        ctx.fillStyle = PVP_CONFIG.OPPONENT_COLOR;
        ctx.fillText(opponent?.displayName || 'Oponente', 15, 25);

        // Vidas do oponente
        this.renderLives(ctx, 15, 35, opponent?.lives || 0, PVP_CONFIG.OPPONENT_COLOR);

        // Asteroides do oponente
        ctx.fillStyle = '#aaaaaa';
        ctx.fillText(`☄ ${opponent?.asteroidsDestroyed || 0}`, 15, 62);

        // Info do jogador (base)
        ctx.textAlign = 'left';
        ctx.fillStyle = PVP_CONFIG.PLAYER_COLOR;
        ctx.fillText(myPlayer?.displayName || 'Você', 15, h - 55);

        // Vidas do jogador
        this.renderLives(ctx, 15, h - 45, myPlayer?.lives || 0, PVP_CONFIG.PLAYER_COLOR);

        // Asteroides do jogador
        ctx.fillStyle = '#aaaaaa';
        ctx.fillText(`☄ ${myPlayer?.asteroidsDestroyed || 0}`, 15, h - 18);
    },

    renderLives(ctx, x, y, lives, color) {
        for (let i = 0; i < PVP_CONFIG.LIVES; i++) {
            ctx.fillStyle = i < lives ? color : '#333333';
            ctx.font = '12px sans-serif';
            ctx.fillText('♥', x + i * 16, y);
        }
    }
};
