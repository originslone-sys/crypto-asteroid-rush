/* ============================================
   UNOBIX - Session Manager v6.1
   js/game-session-manager.js
   ARQUITETURA SEGURA: Sem eventos individuais
   CORRIGIDO: Reenvia após CAPTCHA
   ============================================ */

const SessionManager = {
    currentSession: null,
    pendingEndSession: null, // Armazena dados para reenvio após CAPTCHA
    
    /**
     * Obter Google UID de várias fontes
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
     * Iniciar nova sessão de jogo
     */
    async startSession(googleUidParam = null) {
        console.log('🎮 Iniciando nova sessão...');
        
        const googleUid = googleUidParam || this.getGoogleUid();
        
        if (!googleUid) {
            throw new Error('Usuário não autenticado. Faça login novamente.');
        }
        
        console.log('🔑 Usando Google UID:', googleUid.substring(0, 15) + '...');
        
        try {
            const response = await fetch('api/game-start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ google_uid: googleUid })
            });
            
            if (!response.ok) {
                const text = await response.text();
                console.error('❌ Resposta não-OK:', response.status, text);
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
                
                console.log('✅ Sessão criada:', {
                    id: result.session_id,
                    mission: result.mission_number,
                    hardMode: result.is_hard_mode
                });
                
                return result;
            } else {
                console.error('❌ Falha ao criar sessão:', result.error);

                if (result.error_code === 'ACTIVE_SESSION_EXISTS') {
                    throw new Error(
                        'Você já tem uma partida em andamento! ' +
                        'Finalize a partida atual antes de iniciar outra.'
                    );
                }

                if (result.error_code === 'VPN_PROXY_DETECTED') {
                    throw new Error(
                        'VPN ou Proxy detectado! Por segurança, desative sua VPN/Proxy para jogar. ' +
                        'O uso de VPN/Proxy não é permitido.'
                    );
                }

                if (result.error_code === 'DAILY_LIMIT_REACHED') {
                    const hours = Math.ceil((result.wait_seconds || 86400) / 3600);
                    throw new Error(
                        `Você atingiu o limite de ${result.daily_limit || 50} missões diárias! ` +
                        `Tente novamente em aproximadamente ${hours}h.`
                    );
                }

                if (result.wait_seconds) {
                    throw new Error(`Aguarde ${Math.ceil(result.wait_seconds / 60)} minuto(s) antes de jogar novamente`);
                }

                throw new Error(result.error || 'Falha ao iniciar sessão');
            }
        } catch (error) {
            console.error('❌ Erro ao iniciar sessão:', error);
            throw error;
        }
    },
    
    /**
     * Registrar estatísticas localmente (NÃO envia ao servidor)
     */
    recordLocalStat(asteroidType) {
        if (!this.currentSession) return;
        console.log(`📝 Asteroide destruído: ${asteroidType}`);
    },
    
    /**
     * Finalizar sessão - envia ao servidor
     */
    async endSession(score, earnings, stats, destroyedAsteroids = null) {
        // Se não tem sessão ativa, verificar se tem pending
        if (!this.currentSession && !this.pendingEndSession) {
            console.warn('⚠️ Sem sessão ativa para finalizar');
            return null;
        }
        
        // Usar sessão atual ou pending
        const sessionToEnd = this.currentSession 
            ? { ...this.currentSession }
            : this.pendingEndSession.session;
        
        console.log('🏁 Finalizando sessão...', {
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
            
            // Obter token CAPTCHA se disponível
            if (typeof CaptchaManager !== 'undefined' && CaptchaManager.isComplete()) {
                requestBody.captcha_token = CaptchaManager.getToken() || '';
                console.log('🔐 Enviando com token CAPTCHA');
            }
            
            const response = await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Verificar se precisa de CAPTCHA
                if (result.captcha_required) {
                    console.log('🔐 CAPTCHA necessário - aguardando...');
                    
                    // Salvar dados para reenvio após CAPTCHA
                    this.pendingEndSession = {
                        session: sessionToEnd,
                        score: score,
                        earnings: earnings,
                        stats: stats,
                        pendingEarnings: result.pending_earnings || earnings
                    };
                    
                    // NÃO limpar sessão ainda
                    return result;
                }
                
                console.log('✅ Sessão finalizada:', {
                    earnings: result.final_earnings,
                    newBalance: result.new_balance,
                    credited: result.credited
                });
                
                // Limpar sessão e pending
                this.currentSession = null;
                this.pendingEndSession = null;
                
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
                throw new Error(result.error || 'Falha ao finalizar sessão');
            }
        } catch (error) {
            console.error('❌ Erro ao finalizar sessão:', error);
            throw error;
        }
    },
    
    /**
     * Reenviar após CAPTCHA ser completado
     * Chamado pelo CaptchaManager ou game-ui após verificação
     */
    async resendAfterCaptcha(captchaToken) {
        if (!this.pendingEndSession) {
            console.warn('⚠️ Sem sessão pendente para reenviar');
            return null;
        }
        
        console.log('🔄 Reenviando após CAPTCHA...');
        
        const pending = this.pendingEndSession;
        
        try {
            const requestBody = {
                session_id: pending.session.id,
                session_token: pending.session.token,
                google_uid: pending.session.googleUid,
                score: pending.score,
                earnings: pending.earnings,
                lives_remaining: typeof gameState !== 'undefined' ? gameState.lives : 0,
                victory: true,
                stats: pending.stats,
                captcha_token: captchaToken
            };
            
            const response = await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });
            
            const result = await response.json();
            
            if (result.success && result.credited) {
                console.log('✅ Ganhos creditados após CAPTCHA:', {
                    earnings: result.final_earnings,
                    newBalance: result.new_balance
                });
                
                // Limpar tudo
                this.currentSession = null;
                this.pendingEndSession = null;
                
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = null;
                    gameState.sessionToken = null;
                }
                
                // Atualizar saldo local
                if (result.new_balance !== null && result.new_balance !== undefined) {
                    localStorage.setItem('userBalance', result.new_balance.toString());
                    
                    // Atualizar UI se possível
                    if (typeof updateBalanceDisplay === 'function') {
                        updateBalanceDisplay(result.new_balance);
                    }
                }
                
                // Mostrar notificação de sucesso
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
     * Verificar se tem sessão pendente de CAPTCHA
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
     * Gerar hash de verificação do jogo
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
     * Obter sessão atual
     */
    getSession() {
        return this.currentSession;
    },
    
    /**
     * Verificar se há sessão ativa
     */
    hasActiveSession() {
        return this.currentSession !== null;
    },
    
    /**
     * Limpar sessão (emergência)
     */
    clearSession() {
        console.log('🧹 Sessão limpa');
        this.currentSession = null;
        this.pendingEndSession = null;
        if (typeof gameState !== 'undefined') {
            gameState.sessionId = null;
            gameState.sessionToken = null;
        }
    }
};

// Exportar
window.SessionManager = SessionManager;

console.log('📦 SessionManager v6.1 carregado (com reenvio após CAPTCHA)');
