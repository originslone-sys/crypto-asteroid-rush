/* ============================================
   UNOBIX - Captcha Manager
   Gerenciador de hCaptcha para validação anti-bot
   ============================================ */

class CaptchaManager {
    constructor() {
        // Site Key do hCaptcha (substituir pelo valor real)
        this.siteKey = 'YOUR_HCAPTCHA_SITE_KEY';
        
        this.widgetId = null;
        this.isVerified = false;
        this.token = null;
        this.sessionId = null;
        
        this.onSuccessCallback = null;
        this.onErrorCallback = null;
    }

    // Renderizar widget do hCaptcha
    render(containerId, options = {}) {
        const container = document.getElementById(containerId);
        if (!container) {
            console.error('Container do CAPTCHA não encontrado:', containerId);
            return false;
        }

        // Verificar se hcaptcha está carregado
        if (typeof hcaptcha === 'undefined') {
            console.error('hCaptcha SDK não carregado');
            this.showStatus('Erro ao carregar verificação. Recarregue a página.', 'error');
            return false;
        }

        try {
            // Limpar container
            container.innerHTML = '';
            
            // Renderizar widget
            this.widgetId = hcaptcha.render(containerId, {
                sitekey: this.siteKey,
                theme: 'dark',
                size: options.size || 'normal',
                callback: (token) => this.onSuccess(token),
                'expired-callback': () => this.onExpired(),
                'error-callback': (error) => this.onError(error)
            });

            this.isVerified = false;
            this.token = null;
            
            return true;
        } catch (error) {
            console.error('Erro ao renderizar hCaptcha:', error);
            this.showStatus('Erro ao carregar verificação.', 'error');
            return false;
        }
    }

    // Callback de sucesso
    onSuccess(token) {
        this.isVerified = true;
        this.token = token;
        
        this.showStatus('✓ Verificação concluída!', 'success');
        
        if (this.onSuccessCallback) {
            this.onSuccessCallback(token);
        }
    }

    // Callback de expiração
    onExpired() {
        this.isVerified = false;
        this.token = null;
        
        this.showStatus('Verificação expirou. Complete novamente.', 'error');
        
        // Resetar widget
        this.reset();
    }

    // Callback de erro
    onError(error) {
        this.isVerified = false;
        this.token = null;
        
        console.error('Erro no hCaptcha:', error);
        this.showStatus('Erro na verificação. Tente novamente.', 'error');
        
        if (this.onErrorCallback) {
            this.onErrorCallback(error);
        }
    }

    // Resetar widget
    reset() {
        if (this.widgetId !== null && typeof hcaptcha !== 'undefined') {
            try {
                hcaptcha.reset(this.widgetId);
                this.isVerified = false;
                this.token = null;
            } catch (error) {
                console.error('Erro ao resetar hCaptcha:', error);
            }
        }
    }

    // Verificar se está verificado
    isValid() {
        return this.isVerified && this.token !== null;
    }

    // Obter token
    getToken() {
        return this.token;
    }

    // Definir session ID para vincular com a sessão do jogo
    setSessionId(sessionId) {
        this.sessionId = sessionId;
    }

    // Validar token no backend
    async verify() {
        if (!this.token) {
            return { success: false, error: 'Complete a verificação primeiro.' };
        }

        try {
            const payload = {
                captcha_token: this.token
            };

            // Adicionar session_id se disponível
            if (this.sessionId) {
                payload.session_id = this.sessionId;
            }

            // Adicionar google_uid se disponível
            if (window.authManager?.currentUser) {
                payload.google_uid = window.authManager.currentUser.uid;
            }

            const response = await fetch('/api/verify-captcha.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (data.success) {
                console.log('✅ CAPTCHA verificado no servidor');
                return { success: true };
            } else {
                console.error('❌ Falha na verificação:', data.error);
                this.reset();
                return { success: false, error: data.error || 'Verificação falhou.' };
            }
        } catch (error) {
            console.error('Erro ao verificar CAPTCHA:', error);
            return { success: false, error: 'Erro de conexão.' };
        }
    }

    // Mostrar status
    showStatus(message, type = 'info') {
        const statusEl = document.getElementById('captchaStatus');
        if (statusEl) {
            statusEl.textContent = message;
            statusEl.className = 'captcha-status ' + type;
        }
    }

    // Definir callbacks
    setCallbacks(onSuccess, onError) {
        this.onSuccessCallback = onSuccess;
        this.onErrorCallback = onError;
    }

    // Destruir widget
    destroy() {
        if (this.widgetId !== null && typeof hcaptcha !== 'undefined') {
            try {
                hcaptcha.remove(this.widgetId);
            } catch (error) {
                // Ignorar erros de remoção
            }
        }
        this.widgetId = null;
        this.isVerified = false;
        this.token = null;
    }
}

// Criar instância global
window.captchaManager = new CaptchaManager();

console.log('🛡️ CaptchaManager inicializado');
