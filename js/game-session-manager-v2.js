/* ============================================
   UNOBIX - Session Manager v2 (Multi-Mode)
   js/game-session-manager-v2.js
   ARQUITETURA SEGURA: Sem eventos individuais
   CORRIGIDO: Reenvia apos CAPTCHA
   NOVO: Suporte a game_mode (normal/hard/extreme)
   ============================================ */

const SessionManager = {
    currentSession: null,
    pendingEndSession: null, // Armazena dados para reenvio apos CAPTCHA

    /**
     * Restaurar sessao pendente de CAPTCHA do localStorage (sobrevive refresh)
     */
    restorePending() {
        try {
            const saved = localStorage.getItem('pendingEndSession');
            if (saved) {
                this.pendingEndSession = JSON.parse(saved);
                console.log('🔄 Sessao pendente restaurada do localStorage');
            }
        } catch (e) {}
    },

    /**
     * Obter Google UID de varias fontes
     */
    getGoogleUid() {
        const sources = [
            () => window.gameState?.googleUid,
            () => window.gameState?.user?.uid,
            () => window.authManager?.currentUser?.uid,
            () => window.authManager?.getUserId?.(),
            () => localStorage.getItem('googleUid'),
            () => sessionStorage.getItem('googleUid')
        ];

        for (const source of sources) {
            try {
                const uid = source();
                if (uid && typeof uid === 'string' && uid.length >= 10) {
                    return uid;
                }
            } catch (e) {}
        }

        console.warn('⚠️ Nenhum Google UID encontrado!');
        return null;
    },

    /**
     * Iniciar nova sessao de jogo
     * @param {string|null} googleUidParam - Google UID (opcional)
     * @param {boolean} forceStart - Forcar inicio mesmo com sessao ativa
     * @param {string} gameMode - Modo de jogo: 'normal', 'hard', 'extreme'
     */
    async startSession(googleUidParam = null, forceStart = false, gameMode = 'normal') {
        console.log('🎮 Iniciando nova sessao...', forceStart ? '(force)' : '', `[mode: ${gameMode}]`);

        const googleUid = googleUidParam || this.getGoogleUid();

        if (!googleUid) {
            throw new Error('Usuario nao autenticado. Faca login novamente.');
        }

        console.log('🔑 Usando Google UID:', googleUid.substring(0, 15) + '...');

        try {
            const body = { google_uid: googleUid, game_mode: gameMode };
            if (forceStart) body.force_start = true;

            const response = await fetch('api/game-start-v2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });

            if (!response.ok) {
                const text = await response.text();
                console.error('❌ Resposta nao-OK:', response.status, text);
                throw new Error('Erro de servidor: ' + response.status);
            }

            const result = await response.json();

            if (result.success) {
                this.currentSession = {
                    id: result.session_id,
                    token: result.session_token,
                    seed: result.session_seed,
                    googleUid: result.google_uid || googleUid,
                    missionNumber: result.mission_number,
                    startTime: Date.now(),
                    isHardMode: result.is_hard_mode || false,
                    gameMode: gameMode,
                    isPremium: result.is_premium || false,
                    limits: result.limits || {}
                };

                // Limpar pending anterior
                this.pendingEndSession = null;

                // Salvar em gameState
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = result.session_id;
                    gameState.sessionToken = result.session_token;
                    gameState.googleUid = this.currentSession.googleUid;
                }

                if (typeof missionStats !== 'undefined') {
                    missionStats.isHardMode = result.is_hard_mode || false;
                }

                console.log('✅ Sessao criada:', {
                    id: result.session_id,
                    mission: result.mission_number,
                    hardMode: result.is_hard_mode,
                    gameMode: gameMode,
                    isPremium: result.is_premium || false
                });

                return result;
            } else {
                console.error('❌ Falha ao criar sessao:', result.error);

                if (result.error_code === 'NO_CREDITS') {
                    const creditsRequired = result.credits_required || 0;
                    const err = new Error(
                        'Creditos insuficientes! Voce precisa de ' + creditsRequired +
                        ' credito(s) para jogar no modo ' + gameMode + '. ' +
                        'Compre creditos na Carteira.'
                    );
                    err.errorCode = 'NO_CREDITS';
                    err.credits = result.credits || 0;
                    err.creditsRequired = creditsRequired;
                    err.gameMode = gameMode;
                    throw err;
                }

                if (result.error_code === 'ACTIVE_SESSION_EXISTS') {
                    const err = new Error(
                        'Voce ja tem uma partida em andamento! ' +
                        'Finalize a partida atual antes de iniciar outra.'
                    );
                    err.errorCode = 'ACTIVE_SESSION_EXISTS';
                    err.canForce = !!result.can_force;
                    err.elapsedSeconds = result.elapsed_seconds || 0;
                    throw err;
                }

                if (result.wait_seconds) {
                    throw new Error(`Aguarde ${Math.ceil(result.wait_seconds / 60)} minuto(s) antes de jogar novamente`);
                }

                throw new Error(result.error || 'Falha ao iniciar sessao');
            }
        } catch (error) {
            console.error('❌ Erro ao iniciar sessao:', error);
            throw error;
        }
    },

    /**
     * Registrar estatisticas localmente (NAO envia ao servidor)
     */
    recordLocalStat(asteroidType) {
        if (!this.currentSession) return;
        console.log(`📝 Asteroide destruido: ${asteroidType}`);
    },

    /**
     * Finalizar sessao - envia ao servidor
     */
    async endSession(score, earnings, stats, destroyedAsteroids = null) {
        // Se nao tem sessao ativa, verificar se tem pending
        if (!this.currentSession && !this.pendingEndSession) {
            console.warn('⚠️ Sem sessao ativa para finalizar');
            return null;
        }

        // Usar sessao atual ou pending
        const sessionToEnd = this.currentSession
            ? { ...this.currentSession }
            : this.pendingEndSession.session;

        console.log('🏁 Finalizando sessao...', {
            id: sessionToEnd.id,
            score: score,
            earnings: earnings,
            stats: stats
        });

        try {
            const gameHash = this.generateGameHash(sessionToEnd, stats);

            const requestBody = {
                session_id: sessionToEnd.id,
                session_token: sessionToEnd.token,
                google_uid: sessionToEnd.googleUid,
                score: score,
                earnings: earnings,
                lives_remaining: typeof gameState !== 'undefined' ? gameState.lives : 0,
                victory: typeof gameState !== 'undefined' ? gameState.lives > 0 : true,
                stats: stats,
                game_hash: gameHash
            };

            // Obter token CAPTCHA se disponivel
            if (typeof CaptchaManager !== 'undefined' && CaptchaManager.isComplete()) {
                requestBody.captcha_token = CaptchaManager.getToken() || '';
                console.log('🔐 Enviando com token CAPTCHA');
            }

            const response = await fetch('api/game-end-v2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });

            const result = await response.json();

            if (result.success) {
                // Verificar se precisa de CAPTCHA
                if (result.captcha_required) {
                    console.log('🔐 CAPTCHA necessario - aguardando...');

                    // Salvar dados para reenvio apos CAPTCHA
                    this.pendingEndSession = {
                        session: sessionToEnd,
                        score: score,
                        earnings: earnings,
                        stats: stats,
                        victory: typeof gameState !== 'undefined' ? gameState.lives > 0 : true,
                        pendingEarnings: result.pending_earnings || earnings
                    };

                    // Persistir no localStorage para sobreviver refresh/crash
                    try {
                        localStorage.setItem('pendingEndSession', JSON.stringify(this.pendingEndSession));
                    } catch (e) {}

                    // NAO limpar sessao ainda
                    return result;
                }

                console.log('✅ Sessao finalizada:', {
                    earnings: result.final_earnings,
                    newBalance: result.new_balance,
                    credited: result.credited
                });

                // Limpar sessao e pending
                this.currentSession = null;
                this.pendingEndSession = null;
                localStorage.removeItem('pendingEndSession');

                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = null;
                    gameState.sessionToken = null;
                }

                // Atualizar saldo local
                if (result.new_balance !== null && result.new_balance !== undefined) {
                    localStorage.setItem('userBalance', result.new_balance.toString());
                }

                return result;
            } else {
                console.error('❌ Falha ao finalizar:', result.error);
                throw new Error(result.error || 'Falha ao finalizar sessao');
            }
        } catch (error) {
            console.error('❌ Erro ao finalizar sessao:', error);
            throw error;
        }
    },

    /**
     * Reenviar apos CAPTCHA ser completado
     * Chamado pelo CaptchaManager ou game-ui apos verificacao
     */
    async resendAfterCaptcha(captchaToken) {
        if (!this.pendingEndSession) {
            console.warn('⚠️ Sem sessao pendente para reenviar');
            return null;
        }

        console.log('🔄 Reenviando apos CAPTCHA...');

        const pending = this.pendingEndSession;

        try {
            const requestBody = {
                session_id: pending.session.id,
                session_token: pending.session.token,
                google_uid: pending.session.googleUid,
                score: pending.score,
                earnings: pending.earnings,
                lives_remaining: typeof gameState !== 'undefined' ? gameState.lives : 0,
                victory: pending.victory !== undefined ? pending.victory : true,
                stats: pending.stats,
                captcha_token: captchaToken
            };

            const response = await fetch('api/game-end-v2.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });

            const result = await response.json();

            if (result.success) {
                console.log('✅ Sessao finalizada apos CAPTCHA:', {
                    earnings: result.final_earnings,
                    newBalance: result.new_balance,
                    credited: result.credited
                });

                // Limpar tudo
                this.currentSession = null;
                this.pendingEndSession = null;
                localStorage.removeItem('pendingEndSession');

                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = null;
                    gameState.sessionToken = null;
                }

                // Atualizar saldo local
                if (result.new_balance !== null && result.new_balance !== undefined) {
                    localStorage.setItem('userBalance', result.new_balance.toString());

                    // Atualizar UI se possivel
                    if (typeof updateBalanceDisplay === 'function') {
                        updateBalanceDisplay(result.new_balance);
                    }
                }

                // Mostrar notificacao de sucesso
                if (typeof showNotification === 'function') {
                    showNotification('💰 CREDITADO!', `+R$ ${result.final_earnings.toFixed(6)}`, true);
                }

                return result;
            } else {
                console.error('❌ Falha no reenvio:', result);
                return result;
            }
        } catch (error) {
            console.error('❌ Erro no reenvio:', error);
            throw error;
        }
    },

    /**
     * Verificar se tem sessao pendente de CAPTCHA
     */
    hasPendingCaptcha() {
        return this.pendingEndSession !== null;
    },

    /**
     * Obter ganhos pendentes
     */
    getPendingEarnings() {
        return this.pendingEndSession?.pendingEarnings || 0;
    },

    /**
     * Gerar hash de verificacao do jogo
     */
    generateGameHash(session, stats) {
        try {
            const data = JSON.stringify({
                token: session.token,
                seed: session.seed,
                stats: stats
            });

            let hash = 0;
            for (let i = 0; i < data.length; i++) {
                const char = data.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }

            return Math.abs(hash).toString(16);
        } catch (e) {
            return '';
        }
    },

    /**
     * Obter sessao atual
     */
    getSession() {
        return this.currentSession;
    },

    /**
     * Verificar se ha sessao ativa
     */
    hasActiveSession() {
        return this.currentSession !== null;
    },

    /**
     * Limpar sessao (emergencia)
     */
    clearSession() {
        console.log('🧹 Sessao limpa');
        this.currentSession = null;
        this.pendingEndSession = null;
        if (typeof gameState !== 'undefined') {
            gameState.sessionId = null;
            gameState.sessionToken = null;
        }
    }
};

// Exportar e restaurar pendentes
window.SessionManager = SessionManager;
SessionManager.restorePending();

console.log('📦 SessionManager v2 carregado (multi-mode com reenvio apos CAPTCHA)');
