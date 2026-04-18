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
    _resizeTimer: null,
    _resizeHandler: null,
    _contextLost: false,

    init(canvasId) {
        this.canvas = document.getElementById(canvasId);
        if (!this.canvas) return;
        this.ctx = this.canvas.getContext('2d');
        this._contextLost = false;

        // Detectar perda de contexto do canvas (mobile/tab background)
        this.canvas.addEventListener('contextlost', () => {
            this._contextLost = true;
            console.warn('[PvP] Canvas context lost');
        });
        this.canvas.addEventListener('contextrestored', () => {
            this._contextLost = false;
            this.ctx = this.canvas.getContext('2d');
            console.log('[PvP] Canvas context restored');
        });

        this.resize();
        this.initStars();

        // Debounce resize para evitar race condition com render
        if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
        this._resizeHandler = () => {
            clearTimeout(this._resizeTimer);
            this._resizeTimer = setTimeout(() => this.resize(), 100);
        };
        window.addEventListener('resize', this._resizeHandler);
    },

    resize() {
        if (!this.canvas) return;
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
        // Re-adquirir contexto após resize (resize limpa o canvas)
        this.ctx = this.canvas.getContext('2d');
        this._contextLost = !this.ctx;
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
        // Parar loop anterior se existir (previne loops duplicados)
        this.stopLoop();

        const loop = (now) => {
            try {
                // Se contexto perdido, tentar re-adquirir
                if (this._contextLost && this.canvas) {
                    this.ctx = this.canvas.getContext('2d');
                    if (this.ctx) this._contextLost = false;
                }
                if (!this._contextLost) {
                    this.render(now);
                }
            } catch (err) {
                console.error('[PvP] Render error:', err);
                // Tentar recuperar o contexto na próxima frame
                if (this.canvas) {
                    this.ctx = this.canvas.getContext('2d');
                    this._contextLost = !this.ctx;
                }
            }
            this.animationFrameId = requestAnimationFrame(loop);
        };
        this.animationFrameId = requestAnimationFrame(loop);
    },

    stopLoop() {
        if (this.animationFrameId) {
            cancelAnimationFrame(this.animationFrameId);
            this.animationFrameId = null;
        }
        if (this._resizeTimer) {
            clearTimeout(this._resizeTimer);
            this._resizeTimer = null;
        }
    },

    render(now) {
        // Física com delta-time — frame-rate independente
        if (PvPEngine.gameActive) PvPEngine.stepLocalPrediction(now || performance.now());

        const ctx = this.ctx;
        if (!ctx || !this.canvas) return;

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

        // Balas locais (predição imediata — aparecem antes da confirmação do servidor)
        if (pvpState.localBullets && pvpState.localBullets.length) {
            this.renderBullets(ctx, pvpState.localBullets, PVP_CONFIG.PLAYER_BULLET_COLOR,
                PVP_CONFIG.PLAYER_BULLET_COLOR, scaleX, scaleY);
        }

        // Balas confirmadas pelo servidor (oponente + as minhas já confirmadas)
        const mySlot = pvpState.mySlot;
        const myServerBullets   = mySlot === 1 ? (state.player1Bullets || []) : (state.player2Bullets || []);
        const oppServerBullets  = mySlot === 1 ? (state.player2Bullets || []) : (state.player1Bullets || []);

        // Renderizar balas do servidor somente se não há balas locais pendentes
        // (evita duplicação visual enquanto a predição ainda não foi confirmada)
        if (!pvpState.localBullets || pvpState.localBullets.length === 0) {
            this.renderBullets(ctx, myServerBullets, PVP_CONFIG.PLAYER_BULLET_COLOR,
                PVP_CONFIG.PLAYER_BULLET_COLOR, scaleX, scaleY);
        }
        this.renderBullets(ctx, oppServerBullets, PVP_CONFIG.OPPONENT_BULLET_COLOR,
            PVP_CONFIG.OPPONENT_BULLET_COLOR, scaleX, scaleY);

        // Minha nave: posição da predição local (sem lag), dados vitais do servidor
        const myPlayerServer = pvpState.mySlot === 1 ? state.player1 : state.player2;
        const opponentServer = pvpState.mySlot === 1 ? state.player2 : state.player1;

        const myPlayer = (pvpState.localPrediction && myPlayerServer)
            ? { ...myPlayerServer, x: pvpState.localPrediction.x, y: pvpState.localPrediction.y }
            : myPlayerServer;

        // Oponente: interpolação suave com extrapolação baseada em velocidade
        let opponent = opponentServer;
        if (opponentServer && pvpState.opponentPrev && pvpState.opponentCurr) {
            const elapsed = Date.now() - pvpState.opponentUpdatedAt;
            const tickMs = 33.33; // 30Hz broadcast interval
            const t = Math.min(1.5, elapsed / tickMs);

            if (t <= 1) {
                // Interpolação suave (smoothstep) entre posições conhecidas
                const s = t * t * (3 - 2 * t);
                opponent = {
                    ...opponentServer,
                    x: pvpState.opponentPrev.x + (pvpState.opponentCurr.x - pvpState.opponentPrev.x) * s,
                    y: pvpState.opponentPrev.y + (pvpState.opponentCurr.y - pvpState.opponentPrev.y) * s,
                };
            } else {
                // Extrapolação baseada na velocidade do servidor
                const extraMs = elapsed - tickMs;
                const extraFrames = extraMs / 16.667;
                const vx = pvpState.opponentVx || 0;
                const vy = pvpState.opponentVy || 0;
                opponent = {
                    ...opponentServer,
                    x: pvpState.opponentCurr.x + vx * extraFrames,
                    y: pvpState.opponentCurr.y + vy * extraFrames,
                };
                // Clampar na arena
                opponent.x = Math.max(50, Math.min(PVP_CONFIG.ARENA_WIDTH - 50, opponent.x));
                opponent.y = Math.max(50, Math.min(PVP_CONFIG.ARENA_HEIGHT - 50, opponent.y));
            }
        }

        // Slot 1 (baixo) aponta para cima; Slot 2 (cima) aponta para baixo
        const myInverted = pvpState.mySlot === 2;
        const opInverted = pvpState.mySlot === 1;

        this.renderShip(ctx, myPlayer,  PVP_CONFIG.PLAYER_COLOR,  myInverted, scaleX, scaleY);
        this.renderShip(ctx, opponent,  PVP_CONFIG.OPPONENT_COLOR, opInverted, scaleX, scaleY);

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
        const size = Math.max(22, 22 * Math.min(sx, sy));

        if (player.invincible && Math.floor(Date.now() / 100) % 2 === 0) {
            ctx.globalAlpha = 0.3;
        }

        ctx.save();
        ctx.translate(x, y);
        if (inverted) ctx.rotate(Math.PI);

        // Engine trail glow
        const trailGrad = ctx.createRadialGradient(0, size * 0.7, 0, 0, size * 0.7, size * 0.6);
        trailGrad.addColorStop(0, color + 'cc');
        trailGrad.addColorStop(0.4, color + '55');
        trailGrad.addColorStop(1, 'transparent');
        ctx.fillStyle = trailGrad;
        ctx.beginPath();
        ctx.ellipse(0, size * 0.7, size * 0.25, size * 0.55, 0, 0, Math.PI * 2);
        ctx.fill();

        // Left wing
        ctx.beginPath();
        ctx.moveTo(0, -size * 0.3);
        ctx.lineTo(-size * 1.1, size * 0.5);
        ctx.lineTo(-size * 0.6, size * 0.6);
        ctx.lineTo(-size * 0.2, size * 0.1);
        ctx.closePath();
        const wingGradL = ctx.createLinearGradient(-size, 0, 0, 0);
        wingGradL.addColorStop(0, color + '66');
        wingGradL.addColorStop(1, color + 'cc');
        ctx.fillStyle = wingGradL;
        ctx.fill();
        ctx.strokeStyle = color;
        ctx.lineWidth = 1;
        ctx.stroke();

        // Right wing
        ctx.beginPath();
        ctx.moveTo(0, -size * 0.3);
        ctx.lineTo(size * 1.1, size * 0.5);
        ctx.lineTo(size * 0.6, size * 0.6);
        ctx.lineTo(size * 0.2, size * 0.1);
        ctx.closePath();
        const wingGradR = ctx.createLinearGradient(0, 0, size, 0);
        wingGradR.addColorStop(0, color + 'cc');
        wingGradR.addColorStop(1, color + '66');
        ctx.fillStyle = wingGradR;
        ctx.fill();
        ctx.strokeStyle = color;
        ctx.lineWidth = 1;
        ctx.stroke();

        // Main body
        ctx.beginPath();
        ctx.moveTo(0, -size);
        ctx.lineTo(-size * 0.35, -size * 0.2);
        ctx.lineTo(-size * 0.3, size * 0.6);
        ctx.lineTo(0, size * 0.75);
        ctx.lineTo(size * 0.3, size * 0.6);
        ctx.lineTo(size * 0.35, -size * 0.2);
        ctx.closePath();
        const bodyGrad = ctx.createLinearGradient(0, -size, 0, size);
        bodyGrad.addColorStop(0, '#ffffff');
        bodyGrad.addColorStop(0.2, color);
        bodyGrad.addColorStop(1, color + '88');
        ctx.fillStyle = bodyGrad;
        ctx.fill();
        ctx.strokeStyle = '#ffffff44';
        ctx.lineWidth = 1;
        ctx.stroke();

        // Cockpit
        ctx.beginPath();
        ctx.ellipse(0, -size * 0.25, size * 0.18, size * 0.28, 0, 0, Math.PI * 2);
        const cockpitGrad = ctx.createRadialGradient(-size*0.05, -size*0.3, 0, 0, -size*0.25, size*0.18);
        cockpitGrad.addColorStop(0, '#ffffff');
        cockpitGrad.addColorStop(0.5, color + 'aa');
        cockpitGrad.addColorStop(1, '#00000088');
        ctx.fillStyle = cockpitGrad;
        ctx.fill();

        // Engine core
        const pulse = 0.7 + 0.3 * Math.sin(Date.now() * 0.008);
        ctx.beginPath();
        ctx.ellipse(0, size * 0.65, size * 0.12 * pulse, size * 0.12 * pulse, 0, 0, Math.PI * 2);
        ctx.fillStyle = '#ffffff';
        ctx.shadowColor = color;
        ctx.shadowBlur = 12;
        ctx.fill();
        ctx.shadowBlur = 0;

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
        this.renderLives(ctx, 15, 40, opponent?.lives || 0, PVP_CONFIG.OPPONENT_COLOR);

        // Info do jogador (base)
        ctx.textAlign = 'left';
        ctx.fillStyle = PVP_CONFIG.PLAYER_COLOR;
        ctx.fillText(myPlayer?.displayName || 'Você', 15, h - 40);

        // Vidas do jogador
        this.renderLives(ctx, 15, h - 25, myPlayer?.lives || 0, PVP_CONFIG.PLAYER_COLOR);
    },

    renderLives(ctx, x, y, lives, color) {
        for (let i = 0; i < PVP_CONFIG.LIVES; i++) {
            ctx.fillStyle = i < lives ? color : '#333333';
            ctx.font = '12px sans-serif';
            ctx.fillText('♥', x + i * 16, y);
        }
    }
};
