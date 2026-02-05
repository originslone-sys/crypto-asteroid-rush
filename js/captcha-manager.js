/* ============================================
   UNOBIX - CAPTCHA Manager v5.0
   File: js/captcha-manager.js
   CAPTCHA matemático simples (apenas soma)
   INTEGRADO: Reenvia ao servidor após verificar
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
    async verify() {
        const input = document.getElementById('captchaInput');
        if (!input) return false;
        
        const userAnswer = parseInt(input.value);
        
        if (isNaN(userAnswer)) {
            this.showStatus('Digite um número válido', 'error');
            return false;
        }
        
        if (userAnswer === this.currentAnswer) {
            this.isVerified = true;
            this.showStatus('✅ Verificação concluída! Processando...', 'success');
            
            // Desabilitar input após verificação
            input.disabled = true;
            
            console.log('✅ CAPTCHA verificado corretamente');
            
            // NOVO: Reenviar ao servidor automaticamente
            await this.processAfterVerification();
            
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
     * NOVO: Processar após verificação do CAPTCHA
     * Reenvia ao servidor para creditar ganhos
     */
    async processAfterVerification() {
        console.log('🔄 Processando crédito após CAPTCHA...');
        
        // Verificar se tem sessão pendente
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
                        
                        // Atualizar exibição do saldo
                        this.updateBalanceDisplay(result.new_balance);
                        
                        // Atualizar exibição de ganhos na tela de fim de jogo
                        this.updateEarningsDisplay(result.final_earnings, true);
                        
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
            // Sem pendência, apenas habilitar botão
            this.enableClaimButton();
        }
    },
    
    /**
     * Atualizar exibição do saldo
     */
    updateBalanceDisplay(newBalance) {
        if (newBalance === null || newBalance === undefined) return;
        
        // Tentar várias seletores comuns
        const selectors = [
            '#userBalance',
            '#balance-display',
            '.balance-value',
            '.user-balance',
            '[data-balance]'
        ];
        
        for (const selector of selectors) {
            const el = document.querySelector(selector);
            if (el) {
                el.textContent = `R$ ${parseFloat(newBalance).toFixed(2)}`;
                break;
            }
        }
        
        // Também atualizar via função global se existir
        if (typeof updateBalanceDisplay === 'function') {
            updateBalanceDisplay(newBalance);
        }
        
        // Salvar no localStorage
        localStorage.setItem('userBalance', newBalance.toString());
    },
    
    /**
     * Atualizar exibição de ganhos
     */
    updateEarningsDisplay(earnings, credited = false) {
        const selectors = [
            '#endGameEarnings',
            '.end-game-earnings',
            '.earnings-value',
            '.mission-earnings'
        ];
        
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
            claimBtn.innerHTML = '<i class="fas fa-calculator"></i> <span>RESOLVA O DESAFIO</span>';
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

console.log('🛡️ CaptchaManager v5.0 carregado (integrado com SessionManager)');
