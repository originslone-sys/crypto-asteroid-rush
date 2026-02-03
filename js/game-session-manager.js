/* ============================================
   UNOBIX - Session Manager v4.0
   js/game-session-manager.js
   CORRIGIDO: UID completo, fila de eventos
   ============================================ */

const SessionManager = {
    currentSession: null,
    eventQueue: [],
    isProcessingQueue: false,
    
    /**
     * Obter Google UID de várias fontes
     */
    getGoogleUid() {
        // Tentar várias fontes
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
                    console.log('🔑 UID encontrado:', uid.substring(0, 15) + '...');
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
        
        // Obter UID
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
                // Usar UID retornado pelo servidor (completo)
                const serverUid = result.google_uid || googleUid;
                
                this.currentSession = {
                    id: result.session_id,
                    token: result.session_token,
                    googleUid: serverUid,
                    missionNumber: result.mission_number,
                    rareCount: result.rare_count,
                    hasEpic: result.has_epic,
                    hasLegendary: result.has_legendary,
                    rareIds: result.rare_ids,
                    epicId: result.epic_id,
                    legendaryId: result.legendary_id,
                    startTime: Date.now(),
                    gameDuration: result.game_duration,
                    isHardMode: result.is_hard_mode || false
                };
                
                // Limpar fila de eventos
                this.eventQueue = [];
                
                // Salvar em gameState
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = result.session_id;
                    gameState.sessionToken = result.session_token;
                    gameState.googleUid = serverUid;
                }
                
                // Atualizar missionStats
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
     * Registrar evento de asteroide destruído
     */
    recordEvent(asteroidId, rewardType, rewardAmount = 0) {
        if (!this.currentSession) {
            console.warn('⚠️ Sem sessão ativa para registrar evento');
            return;
        }
        
        const eventData = {
            session_id: this.currentSession.id,
            session_token: this.currentSession.token,
            google_uid: this.currentSession.googleUid,
            asteroid_id: asteroidId,
            reward_type: (rewardType || 'none').toLowerCase(),
            reward_amount: rewardAmount,
            timestamp: Math.floor(Date.now() / 1000)
        };
        
        // Adicionar à fila
        this.eventQueue.push({ data: eventData, attempts: 0 });
        
        // Processar fila
        this.processEventQueue();
    },
    
    /**
     * Processar fila de eventos com rate limiting
     */
    async processEventQueue() {
        if (this.isProcessingQueue || this.eventQueue.length === 0) return;
        
        this.isProcessingQueue = true;
        
        while (this.eventQueue.length > 0) {
            const event = this.eventQueue.shift();
            
            if (!event.data || !event.data.session_id || !event.data.google_uid) {
                console.warn('⚠️ Evento inválido, ignorando');
                continue;
            }
            
            try {
                const response = await fetch('api/game-event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(event.data)
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    console.warn('⚠️ Evento falhou:', result.error);
                    
                    // Se rate limited, colocar de volta na fila
                    if (result.throttled && event.attempts < 3) {
                        event.attempts++;
                        this.eventQueue.unshift(event);
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                }
                
                // Esperar 300ms entre eventos
                await new Promise(resolve => setTimeout(resolve, 300));
                
            } catch (error) {
                console.error('❌ Erro ao enviar evento:', error);
                
                // Tentar novamente se houver tentativas restantes
                if (event.attempts < 3) {
                    event.attempts++;
                    this.eventQueue.unshift(event);
                    await new Promise(resolve => setTimeout(resolve, 500));
                }
            }
        }
        
        this.isProcessingQueue = false;
    },
    
    /**
     * Finalizar sessão
     */
    async endSession(score, earnings, stats = null, destroyedAsteroids = null) {
        if (!this.currentSession) {
            console.warn('⚠️ Sem sessão ativa para finalizar');
            return null;
        }
        
        const sessionToEnd = { ...this.currentSession };
        
        console.log('🏁 Finalizando sessão...', {
            id: sessionToEnd.id,
            score: score,
            earnings: earnings,
            queuedEvents: this.eventQueue.length
        });
        
        // Aguardar eventos pendentes
        if (this.eventQueue.length > 0) {
            console.log(`⏳ Aguardando ${this.eventQueue.length} eventos...`);
            let waitCount = 0;
            while (this.eventQueue.length > 0 && waitCount < 30) {
                await new Promise(resolve => setTimeout(resolve, 500));
                waitCount++;
            }
            console.log('✅ Fila de eventos processada');
        }
        
        try {
            const requestBody = {
                session_id: sessionToEnd.id,
                session_token: sessionToEnd.token,
                google_uid: sessionToEnd.googleUid,
                score: score,
                earnings: earnings,
                lives_remaining: typeof gameState !== 'undefined' ? gameState.lives : 0,
                victory: typeof gameState !== 'undefined' ? gameState.lives > 0 : false
            };
            
            if (stats) requestBody.stats = stats;
            if (destroyedAsteroids) requestBody.destroyed_asteroids = destroyedAsteroids;
            
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
                this.eventQueue = [];
                
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = null;
                    gameState.sessionToken = null;
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
        this.currentSession = null;
        this.eventQueue = [];
        if (typeof gameState !== 'undefined') {
            gameState.sessionId = null;
            gameState.sessionToken = null;
        }
        console.log('🧹 Sessão limpa');
    }
};

// Exportar
window.SessionManager = SessionManager;

console.log('📦 SessionManager v4.0 carregado');
