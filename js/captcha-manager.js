/* ============================================
   UNOBIX - CAPTCHA Manager v7.0
   File: js/captcha-manager.js
   Google reCAPTCHA v3 (invisível)
   
   MUDANÇAS v7.0:
   - Substituído math captcha por reCAPTCHA v3
   - Verificação invisível (sem interação do usuário)
   - Score-based: 0.0 (bot) a 1.0 (humano)
   - Integrado com SessionManager para game_end
   ============================================ */

const CaptchaManager = {
    isReady: false,
    isVerified: false,
    lastToken: null,
    lastScore: null,
    siteKey: '6Lck0GUsAAAAAPOseYXhn0G_QH6XqLTza0mZMNeg',
    
    /**
     * Inicializar reCAPTCHA v3
     * Carrega o script do Google se ainda não estiver presente
     */
    init() {
        if (this.isReady) {
            console.log('🛡️ reCAPTCHA já inicializado');
            return Promise.resolve();
        }
        
        return new Promise((resolve, reject) => {
            // Verificar se script já está carregado
            if (typeof grecaptcha !== 'undefined' && grecaptcha.ready) {
                grecaptcha.ready(() => {
                    this.isReady = true;
                    console.log('🛡️ reCAPTCHA v3 pronto');
                    resolve();
                });
                return;
            }
            
            // Carregar script do Google
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
                        reject(new Error('grecaptcha não disponível após carregar script'));
                    }
                };
                
                script.onerror = () => {
                    console.error('❌ Falha ao carregar reCAPTCHA');
                    // Não bloquear o jogo se reCAPTCHA falhar
                    this.isReady = false;
                    resolve(); // resolve mesmo assim para não travar
                };
                
                document.head.appendChild(script);
            } else {
                // Script já existe, aguardar ready
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
                
                // Timeout de 10s
                setTimeout(() => {
                    clearInterval(checkReady);
                    if (!this.isReady) {
                        console.warn('⚠️ Timeout aguardando reCAPTCHA');
                        resolve();
                    }
                }, 10000);
            }
        });
    },
    
    /**
     * Executar verificação reCAPTCHA v3
     * @param {string} action - Nome da ação (game_end, login, withdraw)
     * @returns {Promise<string|null>} Token reCAPTCHA ou null
     */
    async execute(action = 'game_end') {
        if (!this.isReady || typeof grecaptcha === 'undefined') {
            console.warn('⚠️ reCAPTCHA não está pronto, tentando inicializar...');
            try {
                await this.init();
            } catch (e) {
                console.error('❌ Falha ao inicializar reCAPTCHA:', e);
                return null;
            }
        }
        
        if (!this.isReady || typeof grecaptcha === 'undefined') {
            console.warn('⚠️ reCAPTCHA indisponível — continuando sem verificação');
            return null;
        }
        
        try {
            const token = await grecaptcha.execute(this.siteKey, { action: action });
            this.lastToken = token;
            this.isVerified = true;
            console.log(`🛡️ reCAPTCHA token gerado para action: ${action}`);
            return token;
        } catch (error) {
            console.error('❌ Erro ao executar reCAPTCHA:', error);
            this.lastToken = null;
            this.isVerified = false;
            return null;
        }
    },
    
    /**
     * Obter token para enviar ao backend
     * Se não tem token, tenta gerar um novo
     * @param {string} action - Ação para gerar token
     * @returns {Promise<string|null>}
     */
    async getToken(action = 'game_end') {
        // Tokens do reCAPTCHA v3 expiram em 2 minutos
        // Sempre gerar um novo para garantir validade
        return await this.execute(action);
    },
    
    /**
     * Obter token de forma síncrona (último token gerado)
     * Usar apenas se já executou execute() recentemente
     */
    getLastToken() {
        return this.lastToken;
    },
    
    /**
     * Verificar token com o backend (endpoint separado)
     * Usado quando precisa de verificação explícita (ex: após CAPTCHA required)
     */
    async verifyWithBackend(action = 'verify') {
        const token = await this.execute(action);
        if (!token) {
            return { verified: false, error: 'Não foi possível gerar token reCAPTCHA' };
        }
        
        try {
            const response = await fetch('api/verify-captcha.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    captcha_token: token,
                    captcha_action: action,
                    google_uid: this._getGoogleUid(),
                    session_id: this._getSessionId()
                })
            });
            
            const result = await response.json();
            
            if (result.success && result.verified) {
                this.lastScore = result.score || null;
                console.log(`✅ reCAPTCHA verificado pelo backend | Score: ${result.score}`);
                return { verified: true, score: result.score };
            } else {
                console.warn('❌ reCAPTCHA falhou no backend:', result.error);
                return { verified: false, error: result.error, score: result.score };
            }
        } catch (error) {
            console.error('❌ Erro ao verificar com backend:', error);
            return { verified: false, error: error.message };
        }
    },
    
    /**
     * Verificar se reCAPTCHA está disponível
     */
    isAvailable() {
        return this.isReady && typeof grecaptcha !== 'undefined';
    },
    
    /**
     * Verificar se está completo (compatibilidade com código antigo)
     */
    isComplete() {
        return this.isVerified;
    },
    
    // ============================================
    // MÉTODOS DE COMPATIBILIDADE COM UI ANTIGA
    // reCAPTCHA v3 é invisível, mas mantemos
    // interface para o modal de fim de jogo
    // ============================================
    
    /**
     * Renderizar estado no container (se existir)
     * reCAPTCHA v3 é invisível, então mostra apenas status
     */
    renderStatus(containerId = 'captchaWidget') {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        if (this.isReady) {
            container.innerHTML = `
                <div class="recaptcha-status" style="text-align: center; padding: 10px;">
                    <div style="color: #4CAF50; margin-bottom: 5px;">
                        <i class="fas fa-shield-alt"></i> Proteção ativa
                    </div>
                    <small style="color: #999;">Verificação automática de segurança</small>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="recaptcha-status" style="text-align: center; padding: 10px;">
                    <div style="color: #FF9800; margin-bottom: 5px;">
                        <i class="fas fa-spinner fa-spin"></i> Carregando verificação...
                    </div>
                </div>
            `;
        }
    },
    
    /**
     * Habilitar botão de resgate (compatibilidade)
     * Com reCAPTCHA v3, o botão já está habilitado por padrão
     */
    enableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = false;
        }
    },
    
    /**
     * Desabilitar botão de resgate (compatibilidade)
     */
    disableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = true;
        }
    },
    
    /**
     * Processar após verificação (compatibilidade com fluxo antigo)
     * Chamado pelo SessionManager quando CAPTCHA é required
     */
    async processAfterVerification() {
        console.log('🔄 Processando crédito com reCAPTCHA v3...');
        
        if (typeof SessionManager !== 'undefined' && SessionManager.hasPendingCaptcha()) {
            const token = await this.getToken('game_end');
            
            if (token) {
                try {
                    const result = await SessionManager.resendAfterCaptcha(token);
                    
                    if (result && result.success && result.credited) {
                        console.log('✅ Ganhos creditados!', result);
                        
                        // Notificação de sucesso
                        if (typeof NotificationSystem !== 'undefined') {
                            NotificationSystem.success(
                                'Ganhos Creditados!',
                                `R$ ${result.final_earnings.toFixed(4)} adicionados ao seu saldo`
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
                        NotificationSystem.error('Erro', 'Falha ao processar. Tente novamente.');
                    }
                }
            } else {
                console.warn('⚠️ Não foi possível gerar token reCAPTCHA');
                // Tentar mesmo sem token - o backend decidirá
                this.enableClaimButton();
            }
        } else {
            this.enableClaimButton();
        }
    },
    
    /**
     * Atualizar exibição do saldo
     */
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
    
    /**
     * Atualizar exibição de ganhos
     */
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
    
    /**
     * Reset (compatibilidade)
     */
    reset() {
        this.isVerified = false;
        this.lastToken = null;
        this.lastScore = null;
    },
    
    // Helpers internos
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

// ============================================
// AUTO-INICIALIZAÇÃO
// ============================================

// Inicializar assim que o DOM estiver pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        CaptchaManager.init().catch(e => console.warn('reCAPTCHA init warning:', e));
    });
} else {
    CaptchaManager.init().catch(e => console.warn('reCAPTCHA init warning:', e));
}

// Observar modal de fim de jogo para renderizar status
const observeEndGameModal = () => {
    const endGameModal = document.getElementById('endGameModal');
    if (endGameModal) {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.attributeName === 'class' && endGameModal.classList.contains('active')) {
                    CaptchaManager.renderStatus();
                    // Com reCAPTCHA v3, já podemos habilitar o botão
                    CaptchaManager.enableClaimButton();
                    // Auto-processar se tem pending
                    if (typeof SessionManager !== 'undefined' && SessionManager.hasPendingCaptcha()) {
                        CaptchaManager.processAfterVerification();
                    }
                }
            });
        });
        observer.observe(endGameModal, { attributes: true });
    } else {
        setTimeout(observeEndGameModal, 500);
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', observeEndGameModal);
} else {
    observeEndGameModal();
}

window.CaptchaManager = CaptchaManager;

console.log('🛡️ CaptchaManager v7.0 carregado (Google reCAPTCHA v3)');
