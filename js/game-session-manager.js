/* ============================================
   UNOBIX - Session Manager v6.0
   js/game-session-manager.js
   ARQUITETURA SEGURA: Sem eventos individuais
   Apenas início e fim de sessão
   ============================================ */

const SessionManager = {
    currentSession: null,
    
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
     * Chamado durante o jogo para tracking local
     */
    recordLocalStat(asteroidType) {
        if (!this.currentSession) return;
        
        // Apenas para logging local, não envia nada
        console.log(`📝 Asteroide destruído: ${asteroidType}`);
    },
    
    /**
     * Finalizar sessão - ÚNICA requisição ao servidor
     */
    async endSession(score, earnings, stats, destroyedAsteroids = null) {
        if (!this.currentSession) {
            console.warn('⚠️ Sem sessão ativa para finalizar');
            return null;
        }
        
        const sessionToEnd = { ...this.currentSession };
        
        console.log('🏁 Finalizando sessão...', {
            id: sessionToEnd.id,
            score: score,
            earnings: earnings,
            stats: stats
        });
        
        try {
            // Gerar hash de verificação (opcional, para anti-cheat adicional)
            const gameHash = this.generateGameHash(sessionToEnd, stats);
            
            const requestBody = {
                session_id: sessionToEnd.id,
                session_token: sessionToEnd.token,
                google_uid: sessionToEnd.googleUid,
                score: score,
                earnings: earnings,
                lives_remaining: typeof gameState !== 'undefined' ? gameState.lives : 0,
                victory: typeof gameState !== 'undefined' ? gameState.lives > 0 : false,
                stats: stats,
                game_hash: gameHash
            };
            
            // Obter token CAPTCHA se disponível
            if (typeof CaptchaManager !== 'undefined' && CaptchaManager.isComplete()) {
                requestBody.captcha_token = CaptchaManager.getToken() || '';
            }
            
            const response = await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log('✅ Sessão finalizada:', {
                    earnings: result.final_earnings,
                    newBalance: result.new_balance,
                    credited: result.credited
                });
                
                // Limpar sessão
                this.currentSession = null;
                
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
                
                // Se precisa de CAPTCHA, retornar para tratamento
                if (result.captcha_required) {
                    return result;
                }
                
                throw new Error(result.error || 'Falha ao finalizar sessão');
            }
        } catch (error) {
            console.error('❌ Erro ao finalizar sessão:', error);
            throw error;
        }
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
            
            // Simple hash for verification
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
        if (typeof gameState !== 'undefined') {
            gameState.sessionId = null;
            gameState.sessionToken = null;
        }
    }
};

// Exportar
window.SessionManager = SessionManager;

console.log('📦 SessionManager v6.0 carregado (sem eventos individuais)');
