/* ============================================
   UNOBIX - Session Manager v7.1
   js/game-session-manager.js
   
   MUDANÇAS v7.1:
   - Timeout de 3s no CaptchaManager.getToken()
   - Timeout de 8s nas requisições fetch (AbortController)
   - Mantém toda lógica original
   ============================================ */

const SessionManager = {
    currentSession: null,
    pendingEndSession: null,
    
    // Timeouts
    CAPTCHA_TIMEOUT: 3000,
    FETCH_TIMEOUT: 8000,
    
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
    
    async startSession(googleUidParam = null) {
        console.log('🎮 Iniciando nova sessão...');
        
        const googleUid = googleUidParam || this.getGoogleUid();
        
        if (!googleUid) {
            this._notifyBlock('auth_required', { error: 'Usuário não autenticado. Faça login novamente.' });
            throw new Error('Usuário não autenticado. Faça login novamente.');
        }
        
        console.log('🔑 Usando Google UID:', googleUid.substring(0, 15) + '...');
        
        try {
            // Fetch COM timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.FETCH_TIMEOUT);
            
            const response = await fetch('api/game-start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ google_uid: googleUid }),
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
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
                
                this.pendingEndSession = null;
                
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = result.session_id;
                    gameState.sessionToken = result.session_token;
                    gameState.googleUid = this.currentSession.googleUid;
                }
                
                if (typeof missionStats !== 'undefined') {
                    missionStats.isHardMode = result.is_hard_mode || false;
                }
                
                if (result.warnings && result.warnings.length > 0) {
                    result.warnings.forEach(warning => {
                        if (typeof NotificationSystem !== 'undefined') {
                            NotificationSystem.warning('Aviso', warning);
                        }
                    });
                }
                
                if (result.missions_remaining !== undefined && result.missions_remaining <= 2) {
                    if (typeof NotificationSystem !== 'undefined') {
                        const remaining = result.missions_remaining;
                        NotificationSystem.info(
                            'Missões Restantes',
                            remaining === 0 
                                ? 'Esta é sua última missão desta hora!' 
                                : `Você tem mais ${remaining} missão(ões) esta hora.`
                        );
                    }
                }
                
                console.log('✅ Sessão criada:', {
                    id: result.session_id,
                    mission: result.mission_number,
                    hardMode: result.is_hard_mode,
                    remaining: result.missions_remaining
                });
                
                return result;
                
            } else {
                console.warn('🚫 Sessão bloqueada:', result.block_reason, result.error);
                this._notifyBlock(result.block_reason, result);
                throw new Error(result.error || 'Não foi possível iniciar a missão');
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.error('❌ Timeout ao iniciar sessão');
                this._notifyBlock('server_error', { error: 'Servidor demorou para responder.' });
                throw new Error('Timeout ao conectar');
            }
            if (!error._handled) {
                console.error('❌ Erro ao iniciar sessão:', error);
            }
            throw error;
        }
    },
    
    _notifyBlock(blockReason, result) {
        if (typeof NotificationSystem === 'undefined') {
            alert(result.error || 'Não é possível iniciar o jogo.');
            return;
        }
        
        const error = result.error || '';
        
        switch (blockReason) {
            case 'banned':
                NotificationSystem.banned(result.ban_reason || error);
                break;
            
            case 'ip_blocked':
                NotificationSystem.modal(
                    'Acesso Bloqueado',
                    error || 'Seu acesso foi bloqueado temporariamente.',
                    { icon: '🚫', btnText: 'Entendi', btnClass: 'danger', dismissable: false }
                );
                break;
            
            case 'vpn_detected':
                NotificationSystem.modal(
                    'VPN/Proxy Detectado',
                    error || 'Detectamos VPN ou proxy. Desative para jogar.',
                    {
                        icon: '🛡️',
                        btnText: 'Entendi',
                        btnClass: 'primary',
                        dismissable: true,
                        onClose: () => {
                            NotificationSystem.info('Dica', 'Após desativar a VPN, recarregue a página.');
                        }
                    }
                );
                break;
            
            case 'concurrent_session':
                NotificationSystem.modal(
                    'Sessão em Andamento',
                    'Já existe uma sessão ativa nesta rede. Aguarde terminar.',
                    { icon: '👥', btnText: 'OK', btnClass: 'primary' }
                );
                break;
            
            case 'own_active_session': {
                const remaining = result.remaining_seconds || 180;
                NotificationSystem.modal(
                    'Você já está em uma missão',
                    `Termine a missão atual ou aguarde ${Math.ceil(remaining / 60)} minuto(s).`,
                    { icon: '🎮', btnText: 'OK', btnClass: 'primary' }
                );
                break;
            }
            
            case 'cooldown':
                NotificationSystem.rateLimit(result.wait_seconds || 180);
                break;
            
            case 'hourly_limit': {
                const played = result.missions_played || '?';
                const limit = result.missions_limit || 10;
                const waitMin = Math.ceil((result.wait_seconds || 3600) / 60);
                
                NotificationSystem.modal(
                    'Limite de Missões Atingido',
                    `Você jogou ${played}/${limit} missões esta hora. Volte em ~${waitMin} min!`,
                    {
                        icon: '⏰',
                        btnText: 'OK',
                        btnClass: 'primary',
                        onClose: () => {
                            if (result.wait_seconds) {
                                NotificationSystem.banner(
                                    `Próxima missão em ${waitMin} min`,
                                    'info',
                                    result.wait_seconds * 1000
                                );
                            }
                        }
                    }
                );
                break;
            }
            
            case 'suspicious_activity':
                NotificationSystem.modal(
                    'Conta Temporariamente Restrita',
                    'Atividade incomum detectada. Aguarde 1 hora.',
                    { icon: '⚠️', btnText: 'Entendi', btnClass: 'danger' }
                );
                break;
            
            case 'auth_required':
                NotificationSystem.modal(
                    'Login Necessário',
                    error || 'Faça login com sua conta Google.',
                    {
                        icon: '🔑',
                        btnText: 'Login',
                        btnClass: 'primary',
                        onClose: () => {
                            if (typeof showModal === 'function') showModal('connectModal');
                        }
                    }
                );
                break;
            
            case 'server_error':
                NotificationSystem.error('Erro de Conexão', 'Tente novamente em alguns segundos.');
                break;
            
            default:
                NotificationSystem.warning('Não é possível jogar', error || 'Ocorreu um problema.');
                break;
        }
        
        if (typeof result === 'object') result._handled = true;
    },
    
    recordLocalStat(asteroidType) {
        if (!this.currentSession) return;
        console.log(`📝 Asteroide destruído: ${asteroidType}`);
    },
    
    async endSession(score, earnings, stats, destroyedAsteroids = null) {
        if (!this.currentSession && !this.pendingEndSession) {
            console.warn('⚠️ Sem sessão ativa para finalizar');
            return null;
        }
        
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
            
            // ============================================
            // CRÍTICO: reCAPTCHA v3 COM TIMEOUT
            // ============================================
            if (typeof CaptchaManager !== 'undefined' && CaptchaManager.isAvailable()) {
                try {
                    console.log('🛡️ Obtendo token reCAPTCHA...');
                    
                    const captchaPromise = CaptchaManager.getToken('game_end');
                    const timeoutPromise = new Promise((_, reject) => 
                        setTimeout(() => reject(new Error('captcha_timeout')), this.CAPTCHA_TIMEOUT)
                    );
                    
                    const recaptchaToken = await Promise.race([captchaPromise, timeoutPromise]);
                    
                    if (recaptchaToken) {
                        requestBody.captcha_token = recaptchaToken;
                        console.log('🛡️ Token reCAPTCHA incluído');
                    }
                } catch (e) {
                    console.warn('⚠️ Captcha timeout/falha:', e.message);
                    // Continuar sem token
                }
            }
            
            // ============================================
            // FETCH COM TIMEOUT
            // ============================================
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.FETCH_TIMEOUT);
            
            const response = await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody),
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            const result = await response.json();
            
            if (result.success) {
                if (result.captcha_required) {
                    console.log('🛡️ Verificação necessária...');
                    
                    this.pendingEndSession = {
                        session: sessionToEnd,
                        score: score,
                        earnings: earnings,
                        stats: stats,
                        pendingEarnings: result.pending_earnings || earnings
                    };
                    
                    // Tentar resolver automaticamente
                    if (typeof CaptchaManager !== 'undefined' && CaptchaManager.isAvailable()) {
                        try {
                            const retryPromise = CaptchaManager.getToken('game_end');
                            const retryTimeout = new Promise((_, reject) => 
                                setTimeout(() => reject(new Error('retry_timeout')), this.CAPTCHA_TIMEOUT)
                            );
                            
                            const retryToken = await Promise.race([retryPromise, retryTimeout]);
                            
                            if (retryToken) {
                                const retryResult = await this.resendAfterCaptcha(retryToken);
                                if (retryResult && retryResult.success && retryResult.credited) {
                                    return retryResult;
                                }
                            }
                        } catch (e) {
                            console.warn('⚠️ Retry captcha falhou:', e.message);
                        }
                    }
                    
                    if (typeof NotificationSystem !== 'undefined') {
                        NotificationSystem.warning('Verificação Necessária', 'Complete a verificação para receber seus ganhos.');
                    }
                    
                    return result;
                }
                
                console.log('✅ Sessão finalizada:', {
                    earnings: result.final_earnings,
                    newBalance: result.new_balance,
                    credited: result.credited
                });
                
                if (typeof NotificationSystem !== 'undefined') {
                    if (result.banned) {
                        NotificationSystem.banned(result.error || 'Conta suspensa');
                    } else if (result.flagged) {
                        if (result.final_earnings === 0 || result.final_earnings === '0') {
                            NotificationSystem.warning('Sessão em Revisão', 'Seus ganhos estão sendo analisados.');
                        } else {
                            NotificationSystem.warning('Sessão Marcada', 'Esta sessão foi marcada para revisão.');
                        }
                    } else if (result.credited && result.final_earnings > 0) {
                        NotificationSystem.success('Missão Completa!', `R$ ${parseFloat(result.final_earnings).toFixed(4)} creditados`);
                    }
                }
                
                this.currentSession = null;
                this.pendingEndSession = null;
                
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = null;
                    gameState.sessionToken = null;
                }
                
                if (result.new_balance !== null && result.new_balance !== undefined) {
                    localStorage.setItem('userBalance', result.new_balance.toString());
                }
                
                return result;
            } else {
                console.error('❌ Falha ao finalizar:', result.error);
                
                if (typeof NotificationSystem !== 'undefined') {
                    if (result.banned) {
                        NotificationSystem.banned(result.error || 'Conta suspensa');
                    } else if (result.error) {
                        NotificationSystem.error('Erro', result.error);
                    }
                }
                
                throw new Error(result.error || 'Falha ao finalizar sessão');
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.error('❌ Timeout ao finalizar sessão');
                throw new Error('Timeout');
            }
            console.error('❌ Erro ao finalizar sessão:', error);
            throw error;
        }
    },
    
    async resendAfterCaptcha(captchaToken) {
        if (!this.pendingEndSession) {
            console.warn('⚠️ Sem sessão pendente');
            return null;
        }
        
        console.log('🔄 Reenviando após CAPTCHA...');
        
        const pending = this.pendingEndSession;
        
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.FETCH_TIMEOUT);
            
            const response = await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: pending.session.id,
                    session_token: pending.session.token,
                    google_uid: pending.session.googleUid,
                    score: pending.score,
                    earnings: pending.earnings,
                    lives_remaining: typeof gameState !== 'undefined' ? gameState.lives : 0,
                    victory: true,
                    stats: pending.stats,
                    captcha_token: captchaToken
                }),
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            const result = await response.json();
            
            if (result.success && result.credited) {
                console.log('✅ Ganhos creditados após CAPTCHA');
                
                this.currentSession = null;
                this.pendingEndSession = null;
                
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = null;
                    gameState.sessionToken = null;
                }
                
                if (result.new_balance !== null && result.new_balance !== undefined) {
                    localStorage.setItem('userBalance', result.new_balance.toString());
                    if (typeof updateBalanceDisplay === 'function') {
                        updateBalanceDisplay(result.new_balance);
                    }
                }
                
                if (typeof showNotification === 'function') {
                    showNotification('💰 CREDITADO!', `+R$ ${result.final_earnings.toFixed(6)}`, true);
                }
                
                return result;
            } else {
                console.error('❌ Falha no reenvio:', result);
                return result;
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.error('❌ Timeout no reenvio');
                throw new Error('Timeout');
            }
            console.error('❌ Erro no reenvio:', error);
            throw error;
        }
    },
    
    hasPendingCaptcha() {
        return this.pendingEndSession !== null;
    },
    
    getPendingEarnings() {
        return this.pendingEndSession?.pendingEarnings || 0;
    },
    
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
    
    getSession() { return this.currentSession; },
    hasActiveSession() { return this.currentSession !== null; },
    
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

window.SessionManager = SessionManager;

console.log('📦 SessionManager v7.1 carregado (timeout em captcha e fetch)');
