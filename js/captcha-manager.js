/* ============================================
   UNOBIX - CAPTCHA Manager v2.0
   File: js/captcha-manager.js
   hCaptcha integration - versão robusta
   ============================================ */

const CaptchaManager = {
    widgetId: null,
    isVerified: false,
    token: null,
    siteKey: null,
    isInitialized: false,
    initAttempts: 0,
    maxAttempts: 10,
    
    // Initialize captcha
    init(containerId = 'captchaWidget') {
        if (this.isInitialized) return;
        
        const container = document.getElementById(containerId);
        if (!container) {
            console.log('🛡️ Container CAPTCHA não encontrado, aguardando...');
            return;
        }
        
        // Check if hCaptcha is loaded
        if (typeof hcaptcha === 'undefined') {
            this.initAttempts++;
            if (this.initAttempts < this.maxAttempts) {
                console.log('🛡️ hCaptcha ainda não carregado, tentativa', this.initAttempts);
                setTimeout(() => this.init(containerId), 500);
            }
            return;
        }
        
        // Site key - usar test key se não configurado
        this.siteKey = window.HCAPTCHA_SITE_KEY || '10000000-ffff-ffff-ffff-000000000001';
        
        try {
            // Limpar container
            container.innerHTML = '';
            
            this.widgetId = hcaptcha.render(containerId, {
                sitekey: this.siteKey,
                theme: 'dark',
                size: 'normal',
                callback: (token) => this.onSuccess(token),
                'expired-callback': () => this.onExpired(),
                'error-callback': (err) => this.onError(err)
            });
            
            this.isInitialized = true;
            console.log('🛡️ hCaptcha inicializado com sucesso');
            
        } catch (error) {
            console.error('❌ Erro ao inicializar hCaptcha:', error);
            // Se der erro, habilitar o botão mesmo assim (para testes)
            this.enableClaimButton();
        }
    },
    
    // Success callback
    onSuccess(token) {
        console.log('✅ CAPTCHA verificado');
        this.isVerified = true;
        this.token = token;
        this.enableClaimButton();
        this.showStatus('✅ Verificação concluída!', 'success');
    },
    
    // Enable claim button
    enableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = false;
            claimBtn.innerHTML = '<i class="fas fa-check"></i> <span>RESGATAR GANHOS</span>';
        }
    },
    
    // Expired callback
    onExpired() {
        console.warn('⚠️ CAPTCHA expirou');
        this.isVerified = false;
        this.token = null;
        
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = true;
        }
        
        this.showStatus('Verificação expirou. Complete novamente.', 'warning');
    },
    
    // Error callback
    onError(error) {
        console.error('❌ Erro no CAPTCHA:', error);
        this.isVerified = false;
        this.token = null;
        
        // Em caso de erro, habilitar botão para não bloquear o usuário
        this.enableClaimButton();
        this.showStatus('Erro na verificação. Tente clicar em Resgatar.', 'warning');
    },
    
    // Show status message
    showStatus(message, type = 'info') {
        const statusEl = document.getElementById('captchaStatus');
        if (statusEl) {
            statusEl.textContent = message;
            statusEl.className = 'captcha-status ' + type;
        }
    },
    
    // Get token
    getToken() {
        return this.token;
    },
    
    // Check if verified
    isComplete() {
        return this.isVerified && this.token !== null;
    },
    
    // Reset captcha
    reset() {
        if (this.widgetId !== null && typeof hcaptcha !== 'undefined') {
            try {
                hcaptcha.reset(this.widgetId);
            } catch (e) {
                console.warn('Erro ao resetar captcha:', e);
            }
        }
        
        this.isVerified = false;
        this.token = null;
        this.isInitialized = false;
        this.initAttempts = 0;
        
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = true;
            claimBtn.innerHTML = '<i class="fas fa-shield-alt"></i> <span>COMPLETE A VERIFICAÇÃO</span>';
        }
        
        this.showStatus('Complete a verificação para resgatar', 'info');
    }
};

// Inicializar quando DOM carregar
document.addEventListener('DOMContentLoaded', () => {
    console.log('🛡️ CaptchaManager inicializado');
});

// Auto-initialize when end game modal is shown
const observeEndGameModal = () => {
    const endGameModal = document.getElementById('endGameModal');
    
    if (endGameModal) {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.attributeName === 'class') {
                    if (endGameModal.classList.contains('active')) {
                        // Modal aberto, inicializar CAPTCHA
                        CaptchaManager.reset();
                        setTimeout(() => CaptchaManager.init(), 300);
                    }
                }
            });
        });
        
        observer.observe(endGameModal, { attributes: true });
    } else {
        // Tentar novamente em 500ms
        setTimeout(observeEndGameModal, 500);
    }
};

// Iniciar observação após DOM carregar
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', observeEndGameModal);
} else {
    observeEndGameModal();
}

window.CaptchaManager = CaptchaManager;
