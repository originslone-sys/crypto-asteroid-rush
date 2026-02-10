/* ============================================
   UNOBIX - CAPTCHA Manager v7.1
   File: js/captcha-manager.js
   Google reCAPTCHA v3 (invisível)
   
   MUDANÇAS v7.1:
   - CRÍTICO: Timeout de 3s no getToken() e execute()
   - Timeout do init() reduzido de 10s para 3s
   - Evita travamento no fim do jogo em conexões lentas
   ============================================ */

const CaptchaManager = {
    isReady: false,
    isVerified: false,
    lastToken: null,
    lastScore: null,
    siteKey: '6Lck0GUsAAAAAPOseYXhn0G_QH6XqLTza0mZMNeg',
    
    // Timeouts configuráveis
    INIT_TIMEOUT: 3000,      // 3s para init (era 10s)
    EXECUTE_TIMEOUT: 3000,   // 3s para execute
    
    /**
     * Inicializar reCAPTCHA v3
     */
    init() {
        if (this.isReady) {
            console.log('🛡️ reCAPTCHA já inicializado');
            return Promise.resolve();
        }
        
        return new Promise((resolve, reject) => {
            if (typeof grecaptcha !== 'undefined' && grecaptcha.ready) {
                grecaptcha.ready(() => {
                    this.isReady = true;
                    console.log('🛡️ reCAPTCHA v3 pronto');
                    resolve();
                });
                return;
            }
            
            if (!document.querySelector('script[src*="recaptcha/api.js"]')) {
                const script = document.createElement('script');
                script.src = `https://www.google.com/recaptcha/api.js?render=${this.siteKey}`;
                script.async = true;
                script.defer = true;
                
                script.onload = () => {
                    if (typeof grecaptcha !== 'undefined') {
                        grecaptcha.ready(() => {
                            this.isReady = true;
                            console.log('🛡️ reCAPTCHA v3 carregado e pronto');
                            resolve();
                        });
                    } else {
                        reject(new Error('grecaptcha não disponível'));
                    }
                };
                
                script.onerror = () => {
                    console.error('❌ Falha ao carregar reCAPTCHA');
                    this.isReady = false;
                    resolve(); // Não bloquear o jogo
                };
                
                document.head.appendChild(script);
            } else {
                const checkReady = setInterval(() => {
                    if (typeof grecaptcha !== 'undefined') {
                        clearInterval(checkReady);
                        grecaptcha.ready(() => {
                            this.isReady = true;
                            console.log('🛡️ reCAPTCHA v3 pronto (script existente)');
                            resolve();
                        });
                    }
                }, 200);
                
                // Timeout REDUZIDO para 3s
                setTimeout(() => {
                    clearInterval(checkReady);
                    if (!this.isReady) {
                        console.warn('⚠️ Timeout aguardando reCAPTCHA (3s)');
                        resolve();
                    }
                }, this.INIT_TIMEOUT);
            }
        });
    },
    
    /**
     * Executar verificação reCAPTCHA v3 COM TIMEOUT
     */
    async execute(action = 'game_end') {
        if (!this.isReady || typeof grecaptcha === 'undefined') {
            console.warn('⚠️ reCAPTCHA não está pronto, tentando inicializar...');
            try {
                await Promise.race([
                    this.init(),
                    new Promise((_, reject) => 
                        setTimeout(() => reject(new Error('init_timeout')), this.INIT_TIMEOUT)
                    )
                ]);
            } catch (e) {
                console.error('❌ Falha/timeout ao inicializar reCAPTCHA:', e.message);
                return null;
            }
        }
        
        if (!this.isReady || typeof grecaptcha === 'undefined') {
            console.warn('⚠️ reCAPTCHA indisponível — continuando sem verificação');
            return null;
        }
        
        try {
            // CRÍTICO: grecaptcha.execute COM timeout
            const executePromise = grecaptcha.execute(this.siteKey, { action: action });
            const timeoutPromise = new Promise((_, reject) => 
                setTimeout(() => reject(new Error('execute_timeout')), this.EXECUTE_TIMEOUT)
            );
            
            const token = await Promise.race([executePromise, timeoutPromise]);
            
            this.lastToken = token;
            this.isVerified = true;
            console.log(`🛡️ reCAPTCHA token gerado para action: ${action}`);
            return token;
            
        } catch (error) {
            console.error('❌ Erro/timeout ao executar reCAPTCHA:', error.message);
            this.lastToken = null;
            this.isVerified = false;
            return null;
        }
    },
    
    /**
     * Obter token para enviar ao backend COM TIMEOUT
     */
    async getToken(action = 'game_end') {
        try {
            return await this.execute(action);
        } catch (e) {
            console.warn('⚠️ getToken falhou:', e.message);
            return null;
        }
    },
    
    getLastToken() {
        return this.lastToken;
    },
    
    async verifyWithBackend(action = 'verify') {
        const token = await this.execute(action);
        if (!token) {
            return { verified: false, error: 'Não foi possível gerar token reCAPTCHA' };
        }
        
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);
            
            const response = await fetch('api/verify-captcha.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    captcha_token: token,
                    captcha_action: action,
                    google_uid: this._getGoogleUid(),
                    session_id: this._getSessionId()
                }),
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            const result = await response.json();
            
            if (result.success && result.verified) {
                this.lastScore = result.score || null;
                console.log(`✅ reCAPTCHA verificado | Score: ${result.score}`);
                return { verified: true, score: result.score };
            } else {
                console.warn('❌ reCAPTCHA falhou no backend:', result.error);
                return { verified: false, error: result.error, score: result.score };
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.error('❌ Timeout ao verificar com backend');
                return { verified: false, error: 'Timeout' };
            }
            console.error('❌ Erro ao verificar:', error);
            return { verified: false, error: error.message };
        }
    },
    
    isAvailable() {
        return this.isReady && typeof grecaptcha !== 'undefined';
    },
    
    isComplete() {
        return this.isVerified;
    },
    
    renderStatus(containerId = 'captchaWidget') {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        if (this.isReady) {
            container.innerHTML = `
                <div class="recaptcha-status" style="text-align: center; padding: 10px;">
                    <div style="color: #4CAF50; margin-bottom: 5px;">
                        <i class="fas fa-shield-alt"></i> Proteção ativa
                    </div>
                    <small style="color: #999;">Verificação automática</small>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="recaptcha-status" style="text-align: center; padding: 10px;">
                    <div style="color: #FF9800; margin-bottom: 5px;">
                        <i class="fas fa-spinner fa-spin"></i> Carregando...
                    </div>
                </div>
            `;
        }
    },
    
    enableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) claimBtn.disabled = false;
    },
    
    disableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) claimBtn.disabled = true;
    },
    
    async processAfterVerification() {
        console.log('🔄 Processando crédito com reCAPTCHA v3...');
        
        if (typeof SessionManager !== 'undefined' && SessionManager.hasPendingCaptcha()) {
            const token = await this.getToken('game_end');
            
            if (token) {
                try {
                    const result = await SessionManager.resendAfterCaptcha(token);
                    
                    if (result && result.success && result.credited) {
                        console.log('✅ Ganhos creditados!', result);
                        
                        if (typeof NotificationSystem !== 'undefined') {
                            NotificationSystem.success(
                                'Ganhos Creditados!',
                                `R$ ${result.final_earnings.toFixed(4)} adicionados`
                            );
                        }
                        
                        this.updateBalanceDisplay(result.new_balance);
                        this.updateEarningsDisplay(result.final_earnings, true);
                        this.enableClaimButton();
                        
                    } else if (result && result.error) {
                        console.error('❌ Erro ao creditar:', result.error);
                        if (typeof NotificationSystem !== 'undefined') {
                            NotificationSystem.error('Erro', result.error);
                        }
                    }
                } catch (e) {
                    console.error('❌ Erro no processamento:', e);
                    if (typeof NotificationSystem !== 'undefined') {
                        NotificationSystem.error('Erro', 'Falha ao processar.');
                    }
                }
            } else {
                console.warn('⚠️ Não foi possível gerar token');
                this.enableClaimButton();
            }
        } else {
            this.enableClaimButton();
        }
    },
    
    updateBalanceDisplay(newBalance) {
        if (newBalance === null || newBalance === undefined) return;
        
        const selectors = ['#userBalance', '#balance-display', '.balance-value', '.user-balance', '[data-balance]'];
        for (const selector of selectors) {
            const el = document.querySelector(selector);
            if (el) {
                el.textContent = `R$ ${parseFloat(newBalance).toFixed(2)}`;
                break;
            }
        }
        
        if (typeof updateBalanceDisplay === 'function') {
            updateBalanceDisplay(newBalance);
        }
        
        localStorage.setItem('userBalance', newBalance.toString());
    },
    
    updateEarningsDisplay(earnings, credited = false) {
        const selectors = ['#endGameEarnings', '.end-game-earnings', '.earnings-value', '.mission-earnings'];
        for (const selector of selectors) {
            const el = document.querySelector(selector);
            if (el) {
                el.textContent = `R$ ${parseFloat(earnings).toFixed(4)}`;
                if (credited) {
                    el.classList.add('credited');
                    el.style.color = '#4CAF50';
                }
                break;
            }
        }
    },
    
    reset() {
        this.isVerified = false;
        this.lastToken = null;
        this.lastScore = null;
    },
    
    _getGoogleUid() {
        if (typeof window.gameState !== 'undefined' && window.gameState?.googleUid) return window.gameState.googleUid;
        if (typeof window.authManager !== 'undefined') return window.authManager?.getUserId?.() || null;
        return localStorage.getItem('googleUid') || null;
    },
    
    _getSessionId() {
        if (typeof SessionManager !== 'undefined') return SessionManager.getSession()?.id || null;
        if (typeof window.gameState !== 'undefined') return window.gameState?.sessionId || null;
        return null;
    }
};

// Auto-inicialização
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        CaptchaManager.init().catch(e => console.warn('reCAPTCHA init warning:', e));
    });
} else {
    CaptchaManager.init().catch(e => console.warn('reCAPTCHA init warning:', e));
}

// Observer do modal de fim de jogo
const observeEndGameModal = () => {
    const endGameModal = document.getElementById('endGameModal');
    if (endGameModal) {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.attributeName === 'class' && endGameModal.classList.contains('active')) {
                    CaptchaManager.renderStatus();
                    CaptchaManager.enableClaimButton();
                    if (typeof SessionManager !== 'undefined' && SessionManager.hasPendingCaptcha()) {
                        CaptchaManager.processAfterVerification();
                    }
                }
            });
        });
        observer.observe(endGameModal, { attributes: true });
    } else {
        if (!observeEndGameModal._retries) observeEndGameModal._retries = 0;
        observeEndGameModal._retries++;
        if (observeEndGameModal._retries < 10) {
            setTimeout(observeEndGameModal, 500);
        }
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', observeEndGameModal);
} else {
    observeEndGameModal();
}

window.CaptchaManager = CaptchaManager;

console.log('🛡️ CaptchaManager v7.1 carregado (timeout 3s)');
