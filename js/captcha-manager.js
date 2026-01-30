/* ============================================
   UNOBIX - CAPTCHA Manager v3.0
   File: js/captcha-manager.js
   CAPTCHA matemático simples (sem hCaptcha)
   ============================================ */

const CaptchaManager = {
    isVerified: false,
    currentAnswer: null,
    currentQuestion: null,
    isInitialized: false,
    
    // Initialize captcha
    init(containerId = 'captchaWidget') {
        const container = document.getElementById(containerId);
        if (!container) {
            console.log('🛡️ Container CAPTCHA não encontrado');
            return;
        }
        
        // Gerar novo desafio
        this.generateChallenge();
        
        // Renderizar interface
        this.render(container);
        
        this.isInitialized = true;
        console.log('🛡️ CAPTCHA matemático inicializado');
    },
    
    // Gerar desafio matemático
    generateChallenge() {
        const operations = ['+', '-', '×'];
        const operation = operations[Math.floor(Math.random() * operations.length)];
        
        let num1, num2, answer;
        
        switch (operation) {
            case '+':
                num1 = Math.floor(Math.random() * 20) + 1;
                num2 = Math.floor(Math.random() * 20) + 1;
                answer = num1 + num2;
                break;
            case '-':
                num1 = Math.floor(Math.random() * 20) + 10;
                num2 = Math.floor(Math.random() * 10) + 1;
                answer = num1 - num2;
                break;
            case '×':
                num1 = Math.floor(Math.random() * 10) + 1;
                num2 = Math.floor(Math.random() * 10) + 1;
                answer = num1 * num2;
                break;
        }
        
        this.currentQuestion = `${num1} ${operation} ${num2} = ?`;
        this.currentAnswer = answer;
        this.isVerified = false;
    },
    
    // Renderizar interface do CAPTCHA
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
            
            // Auto-verificar quando digitar
            input.addEventListener('input', () => {
                const value = parseInt(input.value);
                if (!isNaN(value) && input.value.length >= String(this.currentAnswer).length) {
                    this.verify();
                }
            });
        }
        
        if (verifyBtn) {
            verifyBtn.addEventListener('click', () => this.verify());
        }
        
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.refresh());
        }
    },
    
    // Verificar resposta
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
    
    // Atualizar desafio
    refresh() {
        this.generateChallenge();
        
        const container = document.getElementById('captchaWidget');
        if (container) {
            this.render(container);
        }
        
        this.isVerified = false;
        this.disableClaimButton();
    },
    
    // Enable claim button
    enableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = false;
            claimBtn.innerHTML = '<i class="fas fa-check"></i> <span>RESGATAR GANHOS</span>';
        }
    },
    
    // Disable claim button
    disableClaimButton() {
        const claimBtn = document.getElementById('claimRewardBtn');
        if (claimBtn) {
            claimBtn.disabled = true;
            claimBtn.innerHTML = '<i class="fas fa-calculator"></i> <span>RESOLVA O DESAFIO</span>';
        }
    },
    
    // Show status message
    showStatus(message, type = 'info') {
        const statusEl = document.getElementById('captchaStatus');
        if (statusEl) {
            statusEl.textContent = message;
            statusEl.className = 'captcha-status ' + type;
        }
    },
    
    // Get verification status
    isComplete() {
        return this.isVerified;
    },
    
    // Get token (para compatibilidade com backend)
    getToken() {
        if (this.isVerified) {
            // Gerar token simples baseado na resposta correta
            return btoa(`math_${this.currentAnswer}_${Date.now()}`);
        }
        return null;
    },
    
    // Reset captcha
    reset() {
        this.isVerified = false;
        this.currentAnswer = null;
        this.currentQuestion = null;
        this.isInitialized = false;
        
        this.disableClaimButton();
        this.showStatus('Complete a verificação para resgatar', 'info');
    }
};

// Inicializar quando DOM carregar
document.addEventListener('DOMContentLoaded', () => {
    console.log('🛡️ CaptchaManager pronto');
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
        setTimeout(observeEndGameModal, 500);
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', observeEndGameModal);
} else {
    observeEndGameModal();
}

window.CaptchaManager = CaptchaManager;
