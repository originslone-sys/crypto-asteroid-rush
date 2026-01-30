/* ============================================
   CRYPTO ASTEROID RUSH - Session Manager v2.2
   File: js/game-session-manager.js
   Complete session lifecycle management
   FIX: Mantém dados da sessão na queue para não perder eventos
   FIX: Log detalhado para debug
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
            
            // FIX: Os dados já estão no evento, não precisa de currentSession
            if (!event.data || !event.data.session_id || !event.data.wallet) {
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
                    console.warn('⚠️ Event recording failed:', result.error, '| Data:', event.data);
                    
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
    async startSession(wallet, txHash = '') {
        console.log('🎮 Starting new game session...');
        
        try {
            const response = await fetch('api/game-start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
    wallet: wallet,
    txHash: txHash,
    google_uid: localStorage.getItem('googleUid'),
    session_token: localStorage.getItem('sessionToken')
})
;
            
            const result = await response.json();
            
            if (result.success) {
                this.currentSession = {
                    id: result.session_id,
                    token: result.session_token,
                    wallet: wallet,
                    missionNumber: result.mission_number,
                    rareCount: result.rare_count,
                    hasEpic: result.has_epic,
                    rareIds: result.rare_ids,
                    epicId: result.epic_id,
                    startTime: Date.now(),
                    gameDuration: result.game_duration
                };
                
                // Clear event queue for new session
                this.eventQueue = [];
                
                // Store in gameState for backward compatibility
                if (typeof gameState !== 'undefined') {
                    gameState.sessionId = result.session_id;
                    gameState.sessionToken = result.session_token;
                    gameState.wallet = wallet;
                }
                
                console.log('✅ Session created:', {
                    id: result.session_id,
                    mission: result.mission_number,
                    rares: result.rare_count,
                    epic: result.has_epic
                });
                
                return result;
            } else {
                console.error('❌ Session start failed:', result.error);
                
                // Check if it's a rate limit error
                if (result.wait_seconds) {
                    throw new Error(`Wait ${result.wait_seconds}s before playing again`);
                }
                
                throw new Error(result.error || 'Failed to start session');
            }
        } catch (error) {
            console.error('❌ Error starting session:', error);
            throw error;
        }
    },
    
    // Register asteroid destruction event (queued)
    // FIX: Copia os dados da sessão para o evento (não depende de currentSession depois)
    recordEvent(asteroidId, rewardType, rewardAmount = 0) {
        if (!this.currentSession) {
            console.warn('⚠️ No active session for event recording');
            return;
        }
        
        // FIX: Copiar todos os dados necessários para o evento
        // Assim mesmo se a sessão for limpa, o evento ainda tem os dados
        const eventData = {
            session_id: this.currentSession.id,
            session_token: this.currentSession.token,
            wallet: this.currentSession.wallet,
            asteroid_id: asteroidId,
            reward_type: rewardType.toLowerCase(), // Garantir lowercase
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
        
        // FIX: Guardar referência da sessão antes de qualquer operação
        const sessionToEnd = { ...this.currentSession };
        
        console.log('🏁 Ending session...', {
            id: sessionToEnd.id,
            score: score,
            earnings: earnings,
            queuedEvents: this.eventQueue.length
        });
        
        // Wait for all queued events to finish (mas não limpa a sessão ainda)
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
            // Build request body
            const requestBody = {
                session_id: sessionToEnd.id,
                session_token: sessionToEnd.token,
                wallet: sessionToEnd.wallet,
                score: score,
                earnings: earnings
            };
            
            // Add stats if provided
            if (stats) {
                requestBody.stats = stats;
            }
            
            // Add destroyed asteroids list if provided
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
                
                // Agora sim, limpar sessão
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

