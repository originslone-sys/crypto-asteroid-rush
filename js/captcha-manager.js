/* ============================================
   UNOBIX - CAPTCHA Manager v4.0
   File: js/captcha-manager.js
   CAPTCHA matemático simples (apenas soma)
   ============================================ */

const CaptchaManager = {
    isVerified: false,
    currentAnswer: null,
    currentQuestion: null,
    num1: 0,
    num2: 0,
    isInitialized: false,
    
    /**
     * Inicializar CAPTCHA
     */
    init(containerId = 'captchaWidget') {
        const container = document.getElementById(containerId);
        if (!container) {
            console.log('🛡️ Container CAPTCHA não encontrado');
            return;
        }
        
        this.generateChallenge();
        this.render(container);
        
        this.isInitialized = true;
        console.log('🛡️ CAPTCHA matemático inicializado');
    },
    
    /**
     * Gerar desafio de soma simples
     */
    generateChallenge() {
        // Apenas soma para simplicidade
        this.num1 = Math.floor(Math.random() * 20) + 1;
        this.num2 = Math.floor(Math.random() * 20) + 1;
        this.currentAnswer = this.num1 + this.num2;
        this.currentQuestion = `${this.num1} + ${this.num2} = ?`;
        this.isVerified = false;
    },
    
    /**
     * Renderizar interface do CAPTCHA
     */
    render(container) {
        container.innerHTML = `
            <div class="math-captcha">
                <div class="captcha-question">
                    <i class="fas fa-calculator"></i>
                    <span>Resolva: <strong>${this.currentQuestion}</strong></span>
                </div>
                <div class="captcha-input-wrapper">
                    <input type="number" 
                           id="captchaInput" 
                           class="captcha-input" 
                           placeholder="Sua resposta"
                           autocomplete="off"
                           inputmode="numeric">
                    <button type="button" id="captchaVerifyBtn" class="captcha-verify-btn">
                        <i class="fas fa-check"></i>
                    </button>
                </div>
                <div id="captchaStatus" class="captcha-status">Digite a resposta acima</div>
                <button type="button" id="captchaRefreshBtn" class="captcha-refresh-btn">
                    <i class="fas fa-sync-alt"></i> Novo desafio
                </button>
            </div>
        `;
        
        // Event listeners
        const input = document.getElementById('captchaInput');
        const verifyBtn = document.getElementById('captchaVerifyBtn');
        const refreshBtn = document.getElementById('captchaRefreshBtn');
        
        if (input) {
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.verify();
                }
            });
            
            // Auto-verificar quando digitar resposta completa
            input.addEventListener('input', () => {
                const value = parseInt(input.value);
                if (!isNaN(value) && input.value.length >= String(this.currentAnswer).length) {
                    this.verify();
                }
            });
            
            // Focar no input
            setTimeout(() => input.focus(), 100);
        }
        
        if (verifyBtn) {
            verifyBtn.addEventListener('click', () => this.verify());
        }
        
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.refresh());
        }
    },
    
    /**
     * Verificar resposta
     */
    verify() {
        const input = document.getElementById('captchaInput');
        if (!input) return false;
        
        const userAnswer = parseInt(input.value);
        
        if (isNaN(userAnswer)) {
            this.showStatus('Digite um número válido', 'error');
            return false;
        }
        
        if (userAnswer === this.currentAnswer) {
            this.isVerified = true;
            this.showStatus('✅ Verificação concluída!', 'success');
            this.enableClaimButton();
            
            // Desabilitar input após verificação
            input.disabled = true;
            
            console.log('✅ CAPTCHA verificado corretamente');
            return true;
        } else {
            this.showStatus('❌ Resposta incorreta. Tente novamente.', 'error');
            input.value = '';
            input.focus();
            
            // Gerar novo desafio após erro
            setTimeout(() => this.refresh(), 1500);
            return false;
        }
    },
    
    /**
     * Atualizar desafio
     */
    refresh() {
        this.generateChallenge();
        
        const container = document.getElementById('captchaWidget');
        if (container) {
            this.render(container);
        }
        
        this.isVerified = false;
        this.disableClaimButton();
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
            claimBtn.innerHTML = '<i class="fas fa-calculator"></i> <span>RESOLVA O DESAFIO</span>';
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
     * Obter token para enviar ao backend
     * Formato: base64 de "math_{resposta}_{timestamp}"
     */
    getToken() {
        if (this.isVerified) {
            const tokenData = `math_${this.currentAnswer}_${Date.now()}`;
            return btoa(tokenData);
        }
        return null;
    },
    
    /**
     * Resetar CAPTCHA
     */
    reset() {
        this.isVerified = false;
        this.currentAnswer = null;
        this.currentQuestion = null;
        this.isInitialized = false;
        
        this.disableClaimButton();
        this.showStatus('Complete a verificação para resgatar', 'info');
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
                        // Modal aberto, inicializar CAPTCHA
                        CaptchaManager.reset();
                        setTimeout(() => CaptchaManager.init(), 300);
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

console.log('🛡️ CaptchaManager v4.0 carregado (soma simples)');
