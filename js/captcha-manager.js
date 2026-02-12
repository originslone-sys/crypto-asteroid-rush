/* ============================================
   UNOBIX - CAPTCHA Manager v6.0
   File: js/captcha-manager.js
   Google reCAPTCHA v2 ("Não sou um robô")
   INTEGRADO: Reenvia ao servidor após verificar
   ============================================ */

const CaptchaManager = {
    isVerified: false,
    token: null,
    widgetId: null,
    isInitialized: false,
    siteKey: '6LcVtmgsAAAAAIoCvMa0Ou4Y72WchB0mSdZsmBbs',
    
    /**
     * Inicializar reCAPTCHA v2
     */
    init(containerId = 'captchaWidget') {
        const container = document.getElementById(containerId);
        if (!container) {
            console.log('🛡️ Container CAPTCHA não encontrado');
            return;
        }
        
        // Renderizar widget reCAPTCHA
        this.render(container);
        
        // Carregar script do Google se não estiver carregado
        if (typeof grecaptcha === 'undefined') {
            this.loadRecaptchaScript();
        } else {
            this.renderRecaptchaWidget();
        }
        
        this.isInitialized = true;
        console.log('🛡️ reCAPTCHA v2 inicializado');
    },
    
    /**
     * Renderizar container do CAPTCHA
     */
    render(container) {
        container.innerHTML = `
            <div class="recaptcha-container">
                <div class="captcha-title">
                    <i class="fas fa-shield-alt"></i>
                    <span>Verificação de Segurança</span>
                </div>
                <div id="gRecaptchaWidget" class="g-recaptcha-placeholder">
                    <!-- reCAPTCHA será renderizado aqui -->
                </div>
                <div id="captchaStatus" class="captcha-status">
                    Clique em "Não sou um robô" para verificar
                </div>
                <div class="captcha-footer">
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i>
                        Protegido por Google reCAPTCHA
                    </small>
                </div>
            </div>
        `;
    },
    
    /**
     * Carregar script do Google reCAPTCHA
     */
    loadRecaptchaScript() {
        const script = document.createElement('script');
        script.src = 'https://www.google.com/recaptcha/api.js?render=explicit';
        script.async = true;
        script.defer = true;
        script.onload = () => {
            console.log('✅ Script reCAPTCHA carregado');
            this.renderRecaptchaWidget();
        };
        script.onerror = () => {
            console.error('❌ Falha ao carregar reCAPTCHA');
            document.getElementById('captchaStatus').innerHTML = 
                '<span class="text-danger">Erro ao carregar verificação de segurança</span>';
        };
        document.head.appendChild(script);
    },
    
    /**
     * Renderizar widget reCAPTCHA
     */
    renderRecaptchaWidget() {
        const container = document.getElementById('gRecaptchaWidget');
        if (!container || !this.siteKey) return;
        
        try {
            this.widgetId = grecaptcha.render(container, {
                'sitekey': this.siteKey,
                'theme': 'light',
                'size': 'normal',
                'callback': (response) => {
                    this.onRecaptchaSuccess(response);
                },
                'expired-callback': () => {
                    this.onRecaptchaExpired();
                },
                'error-callback': () => {
                    this.onRecaptchaError();
                }
            });
            
            console.log('✅ Widget reCAPTCHA renderizado');
        } catch (error) {
            console.error('❌ Erro ao renderizar reCAPTCHA:', error);
            container.innerHTML = '<div class="alert alert-danger">Erro ao carregar verificação</div>';
        }
    },
    
    /**
     * Callback quando reCAPTCHA é resolvido com sucesso
     */
    onRecaptchaSuccess(response) {
        console.log('✅ reCAPTCHA verificado com sucesso');
        this.isVerified = true;
        this.token = response;
        
        const statusEl = document.getElementById('captchaStatus');
        if (statusEl) {
            statusEl.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> Verificado! Processando...</span>';
        }
        
        // Disparar evento para SessionManager
        this.dispatchCaptchaVerified();
    },
    
    /**
     * Callback quando reCAPTCHA expira
     */
    onRecaptchaExpired() {
        console.log('⚠️ reCAPTCHA expirado');
        this.isVerified = false;
        this.token = null;
        
        const statusEl = document.getElementById('captchaStatus');
        if (statusEl) {
            statusEl.innerHTML = '<span class="text-warning">Verificação expirou. Por favor, verifique novamente.</span>';
        }
        
        // Recarregar widget após 2 segundos
        setTimeout(() => {
            if (typeof grecaptcha !== 'undefined' && this.widgetId !== null) {
                grecaptcha.reset(this.widgetId);
                if (statusEl) {
                    statusEl.textContent = 'Clique em "Não sou um robô" para verificar';
                }
            }
        }, 2000);
    },
    
    /**
     * Callback quando reCAPTCHA tem erro
     */
    onRecaptchaError() {
        console.error('❌ Erro no reCAPTCHA');
        this.isVerified = false;
        this.token = null;
        
        const statusEl = document.getElementById('captchaStatus');
        if (statusEl) {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Erro na verificação. Tente novamente.</span>';
        }
    },
    
    /**
     * Disparar evento quando CAPTCHA é verificado
     */
    dispatchCaptchaVerified() {
        const event = new CustomEvent('captchaVerified', {
            detail: {
                token: this.token,
                timestamp: Date.now()
            }
        });
        document.dispatchEvent(event);
    },
    
    /**
     * Obter token do CAPTCHA (para SessionManager)
     */
    getToken() {
        return this.token;
    },
    
    /**
     * Verificar se CAPTCHA está completo
     */
    isComplete() {
        return this.isVerified && this.token !== null;
    },
    
    /**
     * Verificar CAPTCHA (para compatibilidade com interface antiga)
     */
    verify() {
        if (typeof grecaptcha !== 'undefined' && this.widgetId !== null) {
            grecaptcha.execute(this.widgetId);
        } else {
            console.warn('⚠️ reCAPTCHA não está pronto para verificação');
        }
    },
    
    /**
     * Resetar CAPTCHA
     */
    reset() {
        this.isVerified = false;
        this.token = null;
        
        if (typeof grecaptcha !== 'undefined' && this.widgetId !== null) {
            grecaptcha.reset(this.widgetId);
        }
        
        const statusEl = document.getElementById('captchaStatus');
        if (statusEl) {
            statusEl.textContent = 'Clique em "Não sou um robô" para verificar';
        }
        
        console.log('🔄 reCAPTCHA resetado');
    },
    
    /**
     * Verificar se está inicializado
     */
    exists() {
        return this.isInitialized;
    }
};

// Exportar para escopo global
window.CaptchaManager = CaptchaManager;

console.log('🛡️ CaptchaManager v6.0 carregado (Google reCAPTCHA v2)');