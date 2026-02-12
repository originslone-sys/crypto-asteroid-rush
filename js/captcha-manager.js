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

        // Aguardar API carregar (máx 5 tentativas)
        if (typeof grecaptcha === 'undefined' || typeof grecaptcha.render !== 'function') {
            if (this._initRetries < 5) {
                this._initRetries++;
                console.log('⏳ Aguardando reCAPTCHA API... tentativa', this._initRetries);
                setTimeout(() => this.init(containerId), 500);
            } else {
                console.error('❌ reCAPTCHA API não carregou após 5 tentativas');
                this.showStatus('❌ Erro ao carregar verificação', 'error');
                this.enableClaimButton(); // Não bloquear o jogador
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
    async processAfterVerification() {
        console.log('🔄 Processando crédito após CAPTCHA...');

        if (typeof SessionManager !== 'undefined' && SessionManager.hasPendingCaptcha()) {
            const token = this.getToken();

            if (token) {
                this.showStatus('💰 Creditando ganhos...', 'info');

                try {
                    const result = await SessionManager.resendAfterCaptcha(token);

                    if (result && result.success && result.credited) {
                        console.log('✅ Ganhos creditados!', result);

                        this.showStatus(`✅ R$ ${result.final_earnings.toFixed(4)} creditados!`, 'success');
                        this.enableClaimButton();

                        this.updateBalanceDisplay(result.new_balance);
                        this.updateEarningsDisplay(result.final_earnings, true);

                    } else if (result && result.already_completed) {
                        console.log('✅ Sessão já finalizada anteriormente');
                        this.showStatus('✅ Ganhos já creditados!', 'success');
                        this.enableClaimButton();

                    } else if (result && result.error) {
                        console.error('❌ Erro ao creditar:', result.error);
                        this.showStatus('❌ Erro: ' + result.error, 'error');
                    } else {
                        this.showStatus('✅ Verificado! Clique para resgatar.', 'success');
                        this.enableClaimButton();
                    }
                } catch (e) {
                    console.error('❌ Erro no processamento:', e);
                    this.showStatus('❌ Erro ao processar. Tente novamente.', 'error');
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
     * Habilitar botão de resgate
     */
    enableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = false;
            claimBtn.innerHTML = '<i class="fas fa-check"></i> <span>✅ GANHOS CREDITADOS</span>';
            claimBtn.style.backgroundColor = '#4CAF50';
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
                        // Modal aberto, inicializar reCAPTCHA
                        CaptchaManager.reset();
                        setTimeout(() => CaptchaManager.init(), 300);
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
