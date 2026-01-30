/* ============================================
   UNOBIX - Ads Manager
   Gerenciador de anúncios (AdSense e outros)
   ============================================ */

class AdsManager {
    constructor() {
        // Configurações
        this.config = {
            preGameDuration: 5,      // Segundos do anúncio pré-jogo
            endGameSlots: 3,         // Slots de anúncio no fim do jogo
            enabled: true,           // Anúncios habilitados
            adsenseClientId: ''      // ID do AdSense (será carregado do servidor)
        };

        this.currentAd = null;
        this.preGameTimer = null;
        this.isPreGameComplete = false;
        
        this.init();
    }

    async init() {
        // Carregar configurações do servidor
        await this.loadConfig();
    }

    // Carregar configurações
    async loadConfig() {
        try {
            const response = await fetch('/api/get-config.php?key=ads_config');
            const data = await response.json();
            
            if (data.success && data.value) {
                const serverConfig = JSON.parse(data.value);
                this.config.enabled = serverConfig.pregame_enabled !== false;
                this.config.preGameDuration = serverConfig.pregame_duration || 5;
                this.config.endGameSlots = serverConfig.endgame_slots || 3;
            }
        } catch (error) {
            console.log('Usando configuração padrão de anúncios');
        }
    }

    // Mostrar anúncio pré-jogo
    async showPreGameAd() {
        return new Promise((resolve) => {
            if (!this.config.enabled) {
                resolve(true);
                return;
            }

            this.isPreGameComplete = false;
            const adContainer = document.getElementById('adContainer');
            const preGameScreen = document.getElementById('preGameScreen');
            const timerEl = document.getElementById('adTimer');
            const loadingBar = document.getElementById('loadingBar');
            const loadingPercent = document.getElementById('loadingPercent');
            const loadingStatus = document.getElementById('loadingStatus');

            // Mostrar tela pré-jogo
            preGameScreen.classList.add('active');

            // Renderizar anúncio (placeholder ou AdSense real)
            this.renderPreGameAd(adContainer);

            // Timer do anúncio
            let timeLeft = this.config.preGameDuration;
            let progress = 0;
            const totalTime = this.config.preGameDuration;

            const updateTimer = () => {
                if (timerEl) {
                    timerEl.textContent = `${timeLeft}s`;
                }
                
                progress = ((totalTime - timeLeft) / totalTime) * 100;
                if (loadingBar) loadingBar.style.width = `${progress}%`;
                if (loadingPercent) loadingPercent.textContent = `${Math.round(progress)}%`;
                
                // Status messages
                if (loadingStatus) {
                    if (progress < 30) {
                        loadingStatus.textContent = 'Preparando missão...';
                    } else if (progress < 60) {
                        loadingStatus.textContent = 'Carregando asteroides...';
                    } else if (progress < 90) {
                        loadingStatus.textContent = 'Calibrando armas...';
                    } else {
                        loadingStatus.textContent = 'Pronto para lançamento!';
                    }
                }
            };

            updateTimer();

            this.preGameTimer = setInterval(() => {
                timeLeft--;
                updateTimer();

                if (timeLeft <= 0) {
                    clearInterval(this.preGameTimer);
                    this.preGameTimer = null;
                    
                    // Finalizar
                    if (loadingBar) loadingBar.style.width = '100%';
                    if (loadingPercent) loadingPercent.textContent = '100%';
                    if (loadingStatus) loadingStatus.textContent = 'Iniciando...';
                    
                    setTimeout(() => {
                        this.isPreGameComplete = true;
                        preGameScreen.classList.remove('active');
                        resolve(true);
                    }, 500);
                }
            }, 1000);
        });
    }

    // Renderizar anúncio pré-jogo
    renderPreGameAd(container) {
        if (!container) return;

        // Placeholder (substituir por código real do AdSense)
        container.innerHTML = `
            <div class="ad-placeholder">
                <i class="fas fa-ad"></i>
                <span>Espaço Publicitário</span>
                <small style="color: var(--text-muted); font-size: 0.7rem; margin-top: 5px;">
                    Anúncios ajudam a manter o jogo gratuito
                </small>
            </div>
        `;

        // TODO: Implementar AdSense real
        // if (this.config.adsenseClientId) {
        //     container.innerHTML = `
        //         <ins class="adsbygoogle"
        //              style="display:block"
        //              data-ad-client="${this.config.adsenseClientId}"
        //              data-ad-slot="YOUR_AD_SLOT"
        //              data-ad-format="auto"></ins>
        //     `;
        //     (adsbygoogle = window.adsbygoogle || []).push({});
        // }
    }

    // Mostrar anúncios pós-jogo
    renderEndGameAds(container) {
        if (!container || !this.config.enabled) return;

        // Criar slots de anúncio
        let html = '<div class="endgame-ads">';
        
        for (let i = 0; i < this.config.endGameSlots; i++) {
            html += `
                <div class="ad-slot" id="endgameAd${i}">
                    <div class="ad-placeholder small">
                        <i class="fas fa-ad"></i>
                    </div>
                </div>
            `;
        }
        
        html += '</div>';
        container.innerHTML = html;

        // TODO: Carregar anúncios reais
    }

    // Cancelar timer pré-jogo
    cancelPreGame() {
        if (this.preGameTimer) {
            clearInterval(this.preGameTimer);
            this.preGameTimer = null;
        }
        
        const preGameScreen = document.getElementById('preGameScreen');
        if (preGameScreen) {
            preGameScreen.classList.remove('active');
        }
    }

    // Pular anúncio (se permitido)
    skipAd() {
        // Por enquanto, não permite pular
        // Pode ser implementado com botão após X segundos
        return false;
    }

    // Verificar se anúncios estão habilitados
    isEnabled() {
        return this.config.enabled;
    }

    // Mostrar dica aleatória
    showRandomTip() {
        const tips = [
            'DICA: Asteroides LENDÁRIOS são dourados e valem mais!',
            'DICA: Asteroides ÉPICOS são roxos e valem R$ 0,005!',
            'DICA: Asteroides RAROS são azuis e valem R$ 0,001!',
            'DICA: Sobreviva até o fim para receber seus ganhos!',
            'DICA: Use as setas ou WASD para mover sua nave!',
            'DICA: Faça stake dos seus ganhos para render 5% ao ano!',
            'DICA: Convide amigos e ganhe R$ 1,00 por indicação!',
            'DICA: Saques são processados entre os dias 20-25!',
            'DICA: Missões difíceis têm asteroides mais rápidos!',
            'DICA: Mantenha 6 vidas para completar a missão!'
        ];

        const tipEl = document.getElementById('gameTip');
        if (tipEl) {
            const randomTip = tips[Math.floor(Math.random() * tips.length)];
            tipEl.textContent = randomTip;
        }
    }
}

// Criar instância global
window.adsManager = new AdsManager();

console.log('📺 AdsManager inicializado');
