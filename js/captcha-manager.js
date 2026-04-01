/* ============================================
   UNOBIX - CAPTCHA Manager v6.0
   File: js/captcha-manager.js
   Google reCAPTCHA v2 (checkbox "Não sou um robô")
   INTEGRADO: Reenvia ao servidor após verificar
   ============================================ */

const CaptchaManager = {
    isVerified: false,
    lastToken: null,
    widgetId: null,
    siteKey: '6LcVtmgsAAAAAIoCvMa0Ou4Y72WchB0mSdZsmBbs',
    isInitialized: false,
    _initRetries: 0,

    /**
     * Inicializar reCAPTCHA v2 no container
     */
    init(containerId = 'captchaWidget') {
        const container = document.getElementById(containerId);
        if (!container) {
            console.log('🛡️ Container CAPTCHA não encontrado');
            return;
        }

        // Aguardar API carregar (máx 20 tentativas, ~15 segundos)
        if (typeof grecaptcha === 'undefined' || typeof grecaptcha.render !== 'function') {
            if (this._initRetries < 20) {
                this._initRetries++;
                // Intervalo progressivo: 500ms, 500ms... até 1500ms
                const delay = Math.min(500 + (this._initRetries * 50), 1500);
                console.log('⏳ Aguardando reCAPTCHA API... tentativa', this._initRetries);
                setTimeout(() => this.init(containerId), delay);
            } else {
                console.error('❌ reCAPTCHA API não carregou após 20 tentativas');
                this.showRetryButton(containerId);
            }
            return;
        }

        // Limpar container e criar div para o widget
        container.innerHTML = '<div id="recaptchaWidget"></div>';

        try {
            this.widgetId = grecaptcha.render('recaptchaWidget', {
                sitekey: this.siteKey,
                callback: (token) => this.onVerified(token),
                'expired-callback': () => this.onExpired(),
                'error-callback': () => this.onError(),
                theme: 'dark',
                size: 'normal'
            });

            this.isInitialized = true;
            this._initRetries = 0;
            console.log('🛡️ reCAPTCHA v2 inicializado');
        } catch (e) {
            console.error('❌ Erro ao renderizar reCAPTCHA:', e);
            this.enableClaimButton(); // Não bloquear o jogador
        }
    },

    /**
     * Callback quando usuário completou o reCAPTCHA
     */
    async onVerified(token) {
        this.isVerified = true;
        this.lastToken = token;
        console.log('✅ reCAPTCHA verificado');

        this.showStatus('✅ Verificação concluída! Processando...', 'success');
        await this.processAfterVerification();
    },

    /**
     * Callback quando o reCAPTCHA expirou
     */
    onExpired() {
        this.isVerified = false;
        this.lastToken = null;
        this.disableClaimButton();
        this.showStatus('⚠️ Verificação expirou. Clique novamente.', 'error');
    },

    /**
     * Callback de erro do reCAPTCHA
     */
    onError() {
        this.isVerified = false;
        this.lastToken = null;
        this.showStatus('❌ Erro na verificação. Tente novamente.', 'error');
    },

    /**
     * Processar após verificação do reCAPTCHA
     * Reenvia ao servidor para creditar ganhos
     */
    async processAfterVerification(retryCount = 0) {
        const MAX_RETRIES = 2;
        console.log('🔄 Processando crédito após CAPTCHA...' + (retryCount > 0 ? ` (tentativa ${retryCount + 1})` : ''));

        if (typeof SessionManager !== 'undefined' && SessionManager.hasPendingCaptcha()) {
            const token = this.getToken();

            if (token) {
                this.showStatus('💰 Creditando ganhos...', 'info');

                try {
                    const result = await SessionManager.resendAfterCaptcha(token);

                    if (result && result.success && result.credited) {
                        console.log('✅ Ganhos creditados!', result);
                        this.showStatus(`✅ R$ ${result.final_earnings.toFixed(2)} creditados!`, 'success');
                        this.enableClaimButton();
                        this.updateBalanceDisplay(result.new_balance);
                        this.updateEarningsDisplay(result.final_earnings, true);

                    } else if (result && result.already_completed) {
                        console.log('✅ Sessão já finalizada anteriormente');
                        this.showStatus('✅ Ganhos já creditados!', 'success');
                        this.enableClaimButton();

                    } else if (result && result.captcha_required) {
                        // Token expirou no Google ou premium expirou — resetar e pedir novo CAPTCHA
                        console.warn('⚠️ CAPTCHA necessário novamente, resetando...');
                        this.forceShow();
                        this.showStatus('⚠️ Verificação expirou. Complete novamente.', 'error');

                    } else if (result && result.error) {
                        console.error('❌ Erro ao creditar:', result.error);
                        if (result.error === 'Sessão expirada' || result.error.includes('expirada')) {
                            this.showStatus('❌ Tempo esgotado. Ganhos não creditados.', 'error');
                            this.enableClaimButton();
                        } else {
                            this.showStatus('❌ Erro: ' + result.error, 'error');
                        }
                    } else {
                        this.showStatus('✅ Verificado! Clique para resgatar.', 'success');
                        this.enableClaimButton();
                    }
                } catch (e) {
                    console.error('❌ Erro no processamento:', e);
                    if (retryCount < MAX_RETRIES) {
                        this.showStatus('🔄 Erro de conexão, tentando novamente...', 'info');
                        setTimeout(() => this.processAfterVerification(retryCount + 1), 2000 * (retryCount + 1));
                    } else {
                        this.showStatus('❌ Erro ao processar. Tente completar o CAPTCHA novamente.', 'error');
                        // Resetar para permitir nova tentativa
                        this.isVerified = false;
                        this.lastToken = null;
                        if (this.widgetId !== null && typeof grecaptcha !== 'undefined') {
                            try { grecaptcha.reset(this.widgetId); } catch (e2) {}
                        }
                        this.disableClaimButton();
                    }
                }
            }
        } else {
            // Sem pendência de CAPTCHA, apenas habilitar botão
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
                el.textContent = `R$ ${parseFloat(earnings).toFixed(2)}`;
                if (credited) {
                    el.classList.add('credited');
                    el.style.color = '#4CAF50';
                }
                break;
            }
        }
    },

    /**
     * Mostrar botão de tentar novamente quando reCAPTCHA falha ao carregar
     */
    showRetryButton(containerId) {
        this.showStatus('❌ Erro ao carregar verificação', 'error');

        const container = document.getElementById(containerId);
        if (container) {
            const retryBtn = document.createElement('button');
            retryBtn.textContent = '🔄 TENTAR NOVAMENTE';
            retryBtn.style.cssText = 'margin-top:10px;padding:12px 24px;background:linear-gradient(135deg,#00c8ff,#0088ff);color:#fff;border:none;border-radius:8px;font-size:0.95rem;font-weight:600;cursor:pointer;width:100%;font-family:inherit;';
            retryBtn.onclick = () => {
                retryBtn.remove();
                this.showStatus('⏳ Carregando verificação...', 'info');
                this._initRetries = 0;
                // Recarregar script do Google se necessário
                if (typeof grecaptcha === 'undefined') {
                    const script = document.createElement('script');
                    script.src = 'https://www.google.com/recaptcha/api.js';
                    script.async = true;
                    document.head.appendChild(script);
                }
                setTimeout(() => this.init(containerId), 1000);
            };
            container.appendChild(retryBtn);
        }
    },

    /**
     * Habilitar botão de resgate
     */
    enableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = false;
            claimBtn.innerHTML = '<i class="fas fa-check"></i> <span>RESGATAR GANHOS</span>';
        }
    },

    /**
     * Desabilitar botão de resgate
     */
    disableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = true;
            claimBtn.innerHTML = '<i class="fas fa-shield-alt"></i> <span>COMPLETE A VERIFICAÇÃO</span>';
            claimBtn.style.backgroundColor = '';
        }
    },

    /**
     * Mostrar mensagem de status
     */
    showStatus(message, type = 'info') {
        const statusEl = document.getElementById('captchaStatus');
        if (statusEl) {
            statusEl.textContent = message;
            statusEl.className = 'captcha-status ' + type;
        }
    },

    /**
     * Verificar se está completo
     */
    isComplete() {
        return this.isVerified;
    },

    /**
     * Obter token reCAPTCHA v2 para enviar ao backend
     */
    getToken() {
        if (this.isVerified && this.lastToken) {
            return this.lastToken;
        }
        // Tentar obter do widget diretamente
        if (this.widgetId !== null && typeof grecaptcha !== 'undefined') {
            try {
                const response = grecaptcha.getResponse(this.widgetId);
                if (response) return response;
            } catch (e) {}
        }
        return null;
    },

    /**
     * Forçar exibição do CAPTCHA (quando backend exige mas frontend pensava ser premium)
     * Atualiza status premium local e inicializa o widget
     */
    forceShow() {
        console.log('🛡️ Forçando exibição do CAPTCHA (premium expirou no backend)');

        // Atualizar status premium local
        window._userIsPremium = false;
        localStorage.setItem('userIsPremium', '0');

        // Mostrar container
        const captchaContainer = document.getElementById('captchaContainer');
        if (captchaContainer) captchaContainer.style.display = '';

        // Resetar e inicializar
        this.reset();
        setTimeout(() => this.init(), 300);
    },

    /**
     * Resetar CAPTCHA
     */
    reset() {
        this.isVerified = false;
        this.lastToken = null;
        this.isInitialized = false;
        this._initRetries = 0;

        // Resetar widget se existir
        if (this.widgetId !== null && typeof grecaptcha !== 'undefined') {
            try {
                grecaptcha.reset(this.widgetId);
            } catch (e) {}
        }
        this.widgetId = null;

        this.disableClaimButton();
    }
};

// Observar quando modal de fim de jogo abrir
const observeEndGameModal = () => {
    const endGameModal = document.getElementById('endGameModal');

    if (endGameModal) {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.attributeName === 'class') {
                    if (endGameModal.classList.contains('active')) {
                        // Premium: esconder CAPTCHA e habilitar botão direto
                        const isPremium = localStorage.getItem('userIsPremium') === '1';
                        const captchaContainer = document.getElementById('captchaContainer');

                        if (isPremium) {
                            if (captchaContainer) captchaContainer.style.display = 'none';
                            CaptchaManager.enableClaimButton();
                        } else {
                            if (captchaContainer) captchaContainer.style.display = '';
                            // Modal aberto, inicializar reCAPTCHA
                            CaptchaManager.reset();
                            setTimeout(() => CaptchaManager.init(), 300);
                        }
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

console.log('🛡️ CaptchaManager v6.0 carregado (reCAPTCHA v2 checkbox)');
