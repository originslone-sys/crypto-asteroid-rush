/* ============================================
   UNOBIX - Session Manager v3.0
   File: js/game-session-manager.js
   Complete session lifecycle management
   FIX: Usa google_uid em vez de wallet
   ============================================ */

const SessionManager = {
    currentSession: null,
    eventQueue: [],
    isProcessingQueue: false,
    
    // Process event queue with rate limiting
    async processEventQueue() {
        if (this.isProcessingQueue || this.eventQueue.length === 0) return;
        
        this.isProcessingQueue = true;
        
        while (this.eventQueue.length > 0) {
            const event = this.eventQueue.shift();
            
            if (!event.data || !event.data.session_id || !event.data.google_uid) {
                console.warn('⚠️ Event missing required data, skipping:', event);
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
                    console.warn('⚠️ Event recording failed:', result.error);
                    
                    // If rate limited, put back in queue and wait
                    if (result.throttled) {
                        this.eventQueue.unshift(event);
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                }
                
                // Wait 350ms between events (max ~3 per second)
                await new Promise(resolve => setTimeout(resolve, 350));
                
            } catch (error) {
                console.error('❌ Error recording event:', error);
            }
        }
        
        this.isProcessingQueue = false;
    },
    
    // Initialize a new game session
    // IMPORTANTE: Recebe googleUid, NÃO wallet!
    async startSession(googleUid) {
        console.log('🎮 Starting new game session...');
        console.log('🔑 Using Google UID:', googleUid ? googleUid.substring(0, 10) + '...' : 'NONE');
        
        if (!googleUid) {
            throw new Error('Google UID é obrigatório');
        }
        
        try {
            const response = await fetch('api/game-start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    google_uid: googleUid
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.currentSession = {
                    id: result.session_id,
                    token: result.session_token,
                    googleUid: googleUid,
                    missionNumber: result.mission_number,
                    rareCount: result.rare_count,
                    hasEpic: result.has_epic,
                    rareIds: result.rare_ids,
                    epicId: result.epic_id,
                    startTime: Date.now(),
                    gameDuration: result.game_duration,
                    isHardMode: result.is_hard_mode || false
                };
                
                // Clear event queue for new session
                this.eventQueue = [];
                
                // Store in gameState for backward compatibility
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = result.session_id;
                    gameState.sessionToken = result.session_token;
                    gameState.googleUid = googleUid;
                }
                
                // Update mission stats
                if (typeof missionStats !== 'undefined') {
                    missionStats.isHardMode = result.is_hard_mode || false;
                }
                
                console.log('✅ Session created:', {
                    id: result.session_id,
                    mission: result.mission_number,
                    rares: result.rare_count,
                    epic: result.has_epic,
                    hardMode: result.is_hard_mode
                });
                
                return result;
            } else {
                console.error('❌ Session start failed:', result.error);
                
                if (result.wait_seconds) {
                    throw new Error(`Aguarde ${result.wait_seconds}s antes de jogar novamente`);
                }
                
                throw new Error(result.error || 'Falha ao iniciar sessão');
            }
        } catch (error) {
            console.error('❌ Error starting session:', error);
            throw error;
        }
    },
    
    // Register asteroid destruction event (queued)
    recordEvent(asteroidId, rewardType, rewardAmount = 0) {
        if (!this.currentSession) {
            console.warn('⚠️ No active session for event recording');
            return;
        }
        
        // Copiar dados da sessão para o evento
        const eventData = {
            session_id: this.currentSession.id,
            session_token: this.currentSession.token,
            google_uid: this.currentSession.googleUid,
            asteroid_id: asteroidId,
            reward_type: rewardType.toLowerCase(),
            reward_amount: rewardAmount,
            timestamp: Math.floor(Date.now() / 1000)
        };
        
        // Add to queue
        this.eventQueue.push({ data: eventData });
        
        // Start processing queue if not already processing
        this.processEventQueue();
    },
    
    // End the current session
    async endSession(score, earnings, stats = null, destroyedAsteroids = null) {
        if (!this.currentSession) {
            console.warn('⚠️ No active session to end');
            return null;
        }
        
        // Guardar referência da sessão
        const sessionToEnd = { ...this.currentSession };
        
        console.log('🏁 Ending session...', {
            id: sessionToEnd.id,
            score: score,
            earnings: earnings,
            queuedEvents: this.eventQueue.length
        });
        
        // Wait for all queued events to finish
        if (this.eventQueue.length > 0) {
            console.log(`⏳ Waiting for ${this.eventQueue.length} queued events...`);
            let waitCount = 0;
            while (this.eventQueue.length > 0 && waitCount < 30) {
                await new Promise(resolve => setTimeout(resolve, 500));
                waitCount++;
            }
            console.log('✅ Event queue processed');
        }
        
        try {
            const requestBody = {
                session_id: sessionToEnd.id,
                session_token: sessionToEnd.token,
                google_uid: sessionToEnd.googleUid,
                score: score,
                earnings: earnings
            };
            
            if (stats) {
                requestBody.stats = stats;
            }
            
            if (destroyedAsteroids && Array.isArray(destroyedAsteroids)) {
                requestBody.destroyed_asteroids = destroyedAsteroids;
            }
            
            const response = await fetch('api/game-end.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody)
            });
            
            const result = await response.json();
            
            if (result.success) {
                console.log('✅ Session ended successfully:', {
                    status: result.status,
                    finalEarnings: result.final_earnings,
                    newBalance: result.new_balance
                });
                
                // Limpar sessão
                this.currentSession = null;
                this.eventQueue = [];
                
                // Clear from gameState
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = null;
                    gameState.sessionToken = null;
                }
                
                return result;
            } else {
                console.error('❌ Session end failed:', result.error);
                throw new Error(result.error || 'Failed to end session');
            }
        } catch (error) {
            console.error('❌ Error ending session:', error);
            throw error;
        }
    },
    
    // Get current session info
    getSession() {
        return this.currentSession;
    },
    
    // Check if there's an active session
    hasActiveSession() {
        return this.currentSession !== null;
    },
    
    // Clear session (emergency cleanup)
    clearSession() {
        this.currentSession = null;
        this.eventQueue = [];
        if (typeof gameState !== 'undefined') {
            gameState.sessionId = null;
            gameState.sessionToken = null;
        }
        console.log('🧹 Session cleared');
    }
};

// Export for use in other files
window.SessionManager = SessionManager;
