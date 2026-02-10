/* ============================================
   UNOBIX - Session Manager v7.0
   js/game-session-manager.js
   BARREIRA PRÉ-JOGO: Verifica tudo ANTES de iniciar
   Feedback claro para cada tipo de bloqueio
   ============================================ */

const SessionManager = {
    currentSession: null,
    pendingEndSession: null,
    
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
    
    // ============================================
    // INICIAR SESSÃO — BARREIRA PRÉ-JOGO
    // Todas as verificações acontecem AQUI, ANTES do jogo
    // ============================================
    async startSession(googleUidParam = null) {
        console.log('🎮 Iniciando nova sessão...');
        
        const googleUid = googleUidParam || this.getGoogleUid();
        
        if (!googleUid) {
            this._notifyBlock('auth_required', { error: 'Usuário não autenticado. Faça login novamente.' });
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
                // ✅ Todas as verificações passaram — sessão criada
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
                
                // Mostrar avisos não-bloqueantes (ex: atividade suspeita leve)
                if (result.warnings && result.warnings.length > 0) {
                    result.warnings.forEach(warning => {
                        if (typeof NotificationSystem !== 'undefined') {
                            NotificationSystem.warning('Aviso', warning);
                        }
                    });
                }
                
                // Mostrar missões restantes se poucas
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
                // ❌ Barreira pré-jogo BLOQUEOU — mostrar feedback específico
                console.warn('🚫 Sessão bloqueada:', result.block_reason, result.error);
                
                this._notifyBlock(result.block_reason, result);
                
                // Lançar erro com mensagem amigável
                throw new Error(result.error || 'Não foi possível iniciar a missão');
            }
        } catch (error) {
            // Se não é um erro nosso (ex: rede), mostrar erro genérico
            if (!error._handled) {
                console.error('❌ Erro ao iniciar sessão:', error);
            }
            throw error;
        }
    },
    
    // ============================================
    // SISTEMA DE NOTIFICAÇÕES PRÉ-JOGO
    // Cada block_reason tem seu próprio feedback visual
    // ============================================
    _notifyBlock(blockReason, result) {
        if (typeof NotificationSystem === 'undefined') {
            // Fallback se NotificationSystem não está disponível
            alert(result.error || 'Não é possível iniciar o jogo.');
            return;
        }
        
        const error = result.error || '';
        
        switch (blockReason) {
            
            // ── CONTA BANIDA ──
            case 'banned':
                NotificationSystem.banned(result.ban_reason || error);
                break;
            
            // ── IP BLOQUEADO ──
            case 'ip_blocked':
                NotificationSystem.modal(
                    'Acesso Bloqueado',
                    error || 'Seu acesso foi bloqueado temporariamente. Se acredita que é um erro, entre em contato com o suporte.',
                    { icon: '🚫', btnText: 'Entendi', btnClass: 'danger', dismissable: false }
                );
                break;
            
            // ── VPN / PROXY / TOR ──
            case 'vpn_detected':
                NotificationSystem.modal(
                    'VPN/Proxy Detectado',
                    error || 'Detectamos que você está usando VPN ou proxy. Desative para jogar.',
                    {
                        icon: '🛡️',
                        btnText: 'Entendi, vou desativar',
                        btnClass: 'primary',
                        dismissable: true,
                        onClose: () => {
                            NotificationSystem.info(
                                'Dica',
                                'Após desativar a VPN, recarregue a página e tente novamente.'
                            );
                        }
                    }
                );
                break;
            
            // ── SESSÃO SIMULTÂNEA (OUTRO USUÁRIO NO MESMO IP) ──
            case 'concurrent_session':
                NotificationSystem.modal(
                    'Sessão em Andamento',
                    'Já existe uma sessão ativa neste dispositivo/rede. Aguarde a sessão atual terminar antes de iniciar outra.',
                    {
                        icon: '👥',
                        btnText: 'OK',
                        btnClass: 'primary'
                    }
                );
                break;
            
            // ── PRÓPRIA SESSÃO ATIVA ──
            case 'own_active_session':
                const remaining = result.remaining_seconds || 180;
                NotificationSystem.modal(
                    'Você já está em uma missão',
                    `Você tem uma sessão ativa. Termine a missão atual ou aguarde ${Math.ceil(remaining / 60)} minuto(s) para ela expirar.`,
                    {
                        icon: '🎮',
                        btnText: 'Voltar ao jogo',
                        btnClass: 'primary'
                    }
                );
                break;
            
            // ── COOLDOWN ENTRE JOGOS ──
            case 'cooldown':
                NotificationSystem.rateLimit(result.wait_seconds || 180);
                break;
            
            // ── LIMITE HORÁRIO ATINGIDO ──
            case 'hourly_limit': {
                const played = result.missions_played || '?';
                const limit = result.missions_limit || MAX_MISSIONS_PER_HOUR || 10;
                const waitMin = Math.ceil((result.wait_seconds || 3600) / 60);
                
                NotificationSystem.modal(
                    'Limite de Missões Atingido',
                    `Você jogou ${played}/${limit} missões esta hora. Descanse um pouco e volte em ~${waitMin} minutos!`,
                    {
                        icon: '⏰',
                        btnText: 'OK',
                        btnClass: 'primary',
                        onClose: () => {
                            // Iniciar countdown no banner
                            if (result.wait_seconds) {
                                NotificationSystem.banner(
                                    `Próxima missão disponível em ${waitMin} min`,
                                    'info',
                                    (result.wait_seconds * 1000)
                                );
                            }
                        }
                    }
                );
                break;
            }
            
            // ── ATIVIDADE SUSPEITA ──
            case 'suspicious_activity':
                NotificationSystem.modal(
                    'Conta Temporariamente Restrita',
                    'Detectamos atividade incomum na sua conta. Por segurança, aguarde 1 hora antes de jogar novamente. Se acredita que é um erro, entre em contato com o suporte.',
                    {
                        icon: '⚠️',
                        btnText: 'Entendi',
                        btnClass: 'danger'
                    }
                );
                break;
            
            // ── LOGIN NECESSÁRIO ──
            case 'auth_required':
                NotificationSystem.modal(
                    'Login Necessário',
                    error || 'Você precisa estar logado para jogar. Faça login com sua conta Google.',
                    {
                        icon: '🔑',
                        btnText: 'Fazer Login',
                        btnClass: 'primary',
                        onClose: () => {
                            if (typeof showModal === 'function') {
                                showModal('connectModal');
                            }
                        }
                    }
                );
                break;
            
            // ── ERRO DE SERVIDOR ──
            case 'server_error':
                NotificationSystem.error(
                    'Erro de Conexão',
                    'Não foi possível conectar ao servidor. Tente novamente em alguns segundos.'
                );
                break;
            
            // ── FALLBACK GENÉRICO ──
            default:
                NotificationSystem.warning(
                    'Não é possível jogar',
                    error || 'Ocorreu um problema. Tente novamente.'
                );
                break;
        }
        
        // Marcar como handled para não mostrar erro duplicado
        if (typeof result === 'object') {
            result._handled = true;
        }
    },
    
    /**
     * Registrar estatísticas localmente (NÃO envia ao servidor)
     */
    recordLocalStat(asteroidType) {
        if (!this.currentSession) return;
        console.log(`📝 Asteroide destruído: ${asteroidType}`);
    },
    
    // ============================================
    // FINALIZAR SESSÃO
    // ============================================
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
            
            // reCAPTCHA v3
            if (typeof CaptchaManager !== 'undefined' && CaptchaManager.isAvailable()) {
                try {
                    const recaptchaToken = await CaptchaManager.getToken('game_end');
                    if (recaptchaToken) {
                        requestBody.captcha_token = recaptchaToken;
                        console.log('🛡️ Token reCAPTCHA v3 incluído');
                    }
                } catch (e) {
                    console.warn('⚠️ Falha ao obter token reCAPTCHA:', e);
                }
            }
            
            const response = await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });
            
            const result = await response.json();
            
            if (result.success) {
                // CAPTCHA required
                if (result.captcha_required) {
                    console.log('🛡️ Verificação de segurança necessária...');
                    
                    if (typeof CaptchaManager !== 'undefined' && CaptchaManager.isAvailable()) {
                        console.log('🔄 Tentando resolver automaticamente com reCAPTCHA v3...');
                        
                        this.pendingEndSession = {
                            session: sessionToEnd,
                            score: score,
                            earnings: earnings,
                            stats: stats,
                            pendingEarnings: result.pending_earnings || earnings
                        };
                        
                        const retryToken = await CaptchaManager.getToken('game_end');
                        if (retryToken) {
                            const retryResult = await this.resendAfterCaptcha(retryToken);
                            if (retryResult && retryResult.success && retryResult.credited) {
                                return retryResult;
                            }
                        }
                    }
                    
                    this.pendingEndSession = {
                        session: sessionToEnd,
                        score: score,
                        earnings: earnings,
                        stats: stats,
                        pendingEarnings: result.pending_earnings || earnings
                    };
                    
                    if (typeof NotificationSystem !== 'undefined') {
                        NotificationSystem.warning(
                            'Verificação Necessária',
                            'Complete a verificação de segurança para receber seus ganhos.'
                        );
                    }
                    
                    return result;
                }
                
                console.log('✅ Sessão finalizada:', {
                    earnings: result.final_earnings,
                    newBalance: result.new_balance,
                    credited: result.credited
                });
                
                // ── Notificações pós-jogo ──
                if (typeof NotificationSystem !== 'undefined') {
                    if (result.banned) {
                        // Ban detectado durante sessão
                        NotificationSystem.banned(result.error || 'Conta suspensa');
                    } else if (result.flagged) {
                        // Sessão flagged — explicar claramente
                        if (result.final_earnings === 0 || result.final_earnings === '0') {
                            NotificationSystem.warning(
                                'Sessão em Revisão',
                                'Seus ganhos desta sessão estão sendo analisados pela nossa equipe. Se estiver tudo certo, serão creditados automaticamente.'
                            );
                        } else {
                            NotificationSystem.warning(
                                'Sessão Marcada',
                                'Esta sessão foi marcada para revisão. Se você acredita que é um erro, entre em contato com o suporte.'
                            );
                        }
                    } else if (result.credited && result.final_earnings > 0) {
                        NotificationSystem.success(
                            'Missão Completa!',
                            `R$ ${parseFloat(result.final_earnings).toFixed(4)} creditados ao seu saldo`
                        );
                    }
                }
                
                // Limpar sessão
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
                
                // Tratar erros específicos do game-end
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
            console.error('❌ Erro ao finalizar sessão:', error);
            throw error;
        }
    },
    
    /**
     * Reenviar após CAPTCHA
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

// Exportar
window.SessionManager = SessionManager;

console.log('📦 SessionManager v7.0 carregado (barreira pré-jogo)');
