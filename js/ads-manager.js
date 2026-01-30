/* ============================================
   UNOBIX - Ads Manager v2.0
   Gerenciador de anúncios totalmente configurável
   Carrega configurações do servidor
   ============================================ */

class AdsManager {
    constructor() {
        // Configuração padrão (será sobrescrita pelo servidor)
        this.config = {
            enabled: true,
            debug: false,
            tracking: true,
            
            // Pré-jogo
            pregame: {
                enabled: true,
                totalDuration: 10,
                minDuration: 5,
                skipEnabled: false,
                skipAfter: 5,
                rotationInterval: 5,
                maxSlots: 3
            },
            
            // Pós-jogo
            endgame: {
                enabled: true,
                displayMode: 'grid',
                maxSlots: 4,
                autoRotate: true,
                rotationInterval: 8,
                showOnGameover: true
            }
        };
        
        // Slots carregados do servidor
        this.slots = {
            pregame: [],
            endgame: [],
            interstitial: [],
            banner: []
        };
        
        // Estado
        this.isLoaded = false;
        this.currentPregameIndex = 0;
        this.currentEndgameIndex = 0;
        this.pregameTimer = null;
        this.rotationTimer = null;
        this.endgameRotationTimer = null;
        this.isPreGameComplete = false;
        this.sessionId = null;
        this.pregameTimeLeft = 0;
        
        // Callbacks
        this.onPregameComplete = null;
        this.onAdClick = null;
        
        this.init();
    }

    async init() {
        await this.loadConfig();
        this.log('AdsManager inicializado', this.config);
    }

    // Log de debug
    log(...args) {
        if (this.config.debug) {
            console.log('[AdsManager]', ...args);
        }
    }

    // Carregar configuração do servidor
    async loadConfig() {
        try {
            const response = await fetch('/api/admin-ads.php?action=get_public_config');
            const data = await response.json();
            
            if (data.success) {
                // Mapear configuração
                const cfg = data.config;
                
                this.config = {
                    enabled: cfg.enabled !== false,
                    debug: cfg.debug_mode === true,
                    tracking: cfg.tracking_enabled !== false,
                    
                    pregame: {
                        enabled: cfg.pregame_enabled !== false,
                        totalDuration: parseInt(cfg.pregame_total_duration) || 10,
                        minDuration: parseInt(cfg.pregame_min_duration) || 5,
                        skipEnabled: cfg.pregame_skip_enabled === true,
                        skipAfter: parseInt(cfg.pregame_skip_after) || 5,
                        rotationInterval: parseInt(cfg.pregame_rotation_interval) || 5,
                        maxSlots: parseInt(cfg.pregame_max_slots) || 3
                    },
                    
                    endgame: {
                        enabled: cfg.endgame_enabled !== false,
                        displayMode: cfg.endgame_display_mode || 'grid',
                        maxSlots: parseInt(cfg.endgame_max_slots) || 4,
                        autoRotate: cfg.endgame_auto_rotate !== false,
                        rotationInterval: parseInt(cfg.endgame_rotation_interval) || 8,
                        showOnGameover: cfg.endgame_show_on_gameover !== false
                    }
                };
                
                // Carregar slots
                if (data.slots) {
                    this.slots = data.slots;
                }
                
                this.isLoaded = true;
                this.log('Configuração carregada', { config: this.config, slots: this.slots });
            }
        } catch (error) {
            console.error('Erro ao carregar config de ads:', error);
            this.isLoaded = true;
        }
    }

    // Definir session ID para tracking
    setSessionId(sessionId) {
        this.sessionId = sessionId;
    }

    // ============================================
    // PRÉ-JOGO (Tela de Carregamento)
    // ============================================

    async showPreGame(containerId = 'preGameScreen') {
        return new Promise((resolve) => {
            if (!this.config.enabled || !this.config.pregame.enabled) {
                this.log('Pré-jogo desabilitado');
                resolve(true);
                return;
            }

            const slots = this.slots.pregame?.slice(0, this.config.pregame.maxSlots) || [];
            
            if (slots.length === 0) {
                this.log('Nenhum slot pré-jogo configurado');
                this.showLoadingOnly(containerId, resolve);
                return;
            }

            this.isPreGameComplete = false;
            this.currentPregameIndex = 0;
            
            const container = document.getElementById(containerId);
            if (!container) {
                resolve(true);
                return;
            }

            // Mostrar container
            container.classList.add('active');
            container.style.display = 'flex';

            // Renderizar primeiro ad
            this.renderPregameAd(slots[0]);

            // Timer principal
            this.pregameTimeLeft = this.config.pregame.totalDuration;
            let canSkip = false;
            
            this.updatePregameUI(this.pregameTimeLeft, canSkip);

            // Rotação de ads
            if (slots.length > 1) {
                this.rotationTimer = setInterval(() => {
                    this.currentPregameIndex = (this.currentPregameIndex + 1) % slots.length;
                    this.renderPregameAd(slots[this.currentPregameIndex]);
                    
                    // Log impressão do novo ad
                    if (this.config.tracking) {
                        this.logImpression(slots[this.currentPregameIndex].id, 'pregame');
                    }
                }, this.config.pregame.rotationInterval * 1000);
            }

            // Countdown
            this.pregameTimer = setInterval(() => {
                this.pregameTimeLeft--;
                
                // Verificar se pode pular
                const elapsed = this.config.pregame.totalDuration - this.pregameTimeLeft;
                canSkip = this.config.pregame.skipEnabled && elapsed >= this.config.pregame.skipAfter;
                
                this.updatePregameUI(this.pregameTimeLeft, canSkip);

                if (this.pregameTimeLeft <= 0) {
                    this.completePregame(container, resolve);
                }
            }, 1000);

            // Botão de pular
            this.setupSkipButton(container, resolve);

            // Log impressão inicial
            if (slots[0] && this.config.tracking) {
                this.logImpression(slots[0].id, 'pregame');
            }
            
            // Mostrar dica aleatória
            this.showRandomTip();
        });
    }

    // Renderizar anúncio do pré-jogo
    renderPregameAd(slot) {
        const adContainer = document.getElementById('adContainer');
        if (!adContainer || !slot) return;

        // Limpar container
        adContainer.innerHTML = '';

        // Criar wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'ad-slot-wrapper';
        wrapper.setAttribute('data-slot-id', slot.id);
        
        // Aplicar dimensões
        if (slot.width) {
            wrapper.style.width = isNaN(slot.width) ? slot.width : slot.width + 'px';
        }
        if (slot.height) {
            wrapper.style.height = isNaN(slot.height) ? slot.height : slot.height + 'px';
        }
        
        // Aplicar CSS customizado
        if (slot.custom_css) {
            const style = document.createElement('style');
            style.textContent = slot.custom_css;
            wrapper.appendChild(style);
        }
        
        // Inserir código do anúncio
        wrapper.innerHTML += slot.script_code;
        
        adContainer.appendChild(wrapper);
        
        // Executar scripts inline
        this.executeScripts(wrapper);
        
        // Adicionar listener de clique para tracking
        wrapper.addEventListener('click', () => {
            if (this.config.tracking) {
                this.logClick(slot.id, 'pregame');
            }
            if (this.onAdClick) {
                this.onAdClick(slot);
            }
        });
        
        this.log('Ad renderizado:', slot.slot_name);
    }

    // Executar scripts dentro de um elemento
    executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            
            // Copiar atributos
            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });
            
            // Copiar conteúdo
            newScript.textContent = oldScript.textContent;
            
            // Substituir
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    // Atualizar UI do pré-jogo
    updatePregameUI(timeLeft, canSkip) {
        const timerEl = document.getElementById('adTimer');
        const loadingBar = document.getElementById('loadingBar');
        const loadingPercent = document.getElementById('loadingPercent');
        const loadingStatus = document.getElementById('loadingStatus');
        const skipBtn = document.getElementById('skipAdBtn');
        
        const totalTime = this.config.pregame.totalDuration;
        const progress = ((totalTime - timeLeft) / totalTime) * 100;
        
        if (timerEl) {
            timerEl.textContent = `${timeLeft}s`;
        }
        
        if (loadingBar) {
            loadingBar.style.width = `${progress}%`;
        }
        
        if (loadingPercent) {
            loadingPercent.textContent = `${Math.round(progress)}%`;
        }
        
        if (loadingStatus) {
            if (progress < 25) {
                loadingStatus.textContent = 'Preparando missão...';
            } else if (progress < 50) {
                loadingStatus.textContent = 'Carregando asteroides...';
            } else if (progress < 75) {
                loadingStatus.textContent = 'Calibrando armas...';
            } else if (progress < 100) {
                loadingStatus.textContent = 'Pronto para lançamento!';
            } else {
                loadingStatus.textContent = 'Iniciando...';
            }
        }
        
        // Mostrar/ocultar botão de pular
        if (skipBtn) {
            if (this.config.pregame.skipEnabled && canSkip) {
                skipBtn.textContent = 'PULAR';
                skipBtn.style.display = 'block';
                skipBtn.disabled = false;
                skipBtn.classList.add('can-skip');
            } else if (this.config.pregame.skipEnabled) {
                const elapsed = totalTime - timeLeft;
                const remaining = this.config.pregame.skipAfter - elapsed;
                if (remaining > 0) {
                    skipBtn.textContent = `Pular em ${remaining}s`;
                    skipBtn.style.display = 'block';
                    skipBtn.disabled = true;
                    skipBtn.classList.remove('can-skip');
                }
            } else {
                skipBtn.style.display = 'none';
            }
        }
    }

    // Configurar botão de pular
    setupSkipButton(container, resolve) {
        const skipBtn = document.getElementById('skipAdBtn');
        if (skipBtn) {
            skipBtn.onclick = () => {
                const elapsed = this.config.pregame.totalDuration - this.pregameTimeLeft;
                if (this.config.pregame.skipEnabled && elapsed >= this.config.pregame.skipAfter) {
                    this.log('Anúncio pulado pelo usuário');
                    this.completePregame(container, resolve);
                }
            };
        }
    }

    // Completar pré-jogo
    completePregame(container, resolve) {
        // Limpar timers
        if (this.pregameTimer) {
            clearInterval(this.pregameTimer);
            this.pregameTimer = null;
        }
        if (this.rotationTimer) {
            clearInterval(this.rotationTimer);
            this.rotationTimer = null;
        }
        
        this.isPreGameComplete = true;
        
        // Atualizar UI para 100%
        const loadingBar = document.getElementById('loadingBar');
        const loadingPercent = document.getElementById('loadingPercent');
        const loadingStatus = document.getElementById('loadingStatus');
        
        if (loadingBar) loadingBar.style.width = '100%';
        if (loadingPercent) loadingPercent.textContent = '100%';
        if (loadingStatus) loadingStatus.textContent = 'Iniciando...';
        
        // Delay para mostrar 100%
        setTimeout(() => {
            container.classList.remove('active');
            container.style.display = 'none';
            
            if (this.onPregameComplete) {
                this.onPregameComplete();
            }
            
            resolve(true);
        }, 500);
    }

    // Mostrar apenas loading (sem ads)
    showLoadingOnly(containerId, resolve) {
        const container = document.getElementById(containerId);
        if (!container) {
            resolve(true);
            return;
        }

        container.classList.add('active');
        container.style.display = 'flex';

        // Placeholder no container de ad
        const adContainer = document.getElementById('adContainer');
        if (adContainer) {
            adContainer.innerHTML = `
                <div class="ad-placeholder">
                    <i class="fas fa-rocket"></i>
                    <span>Preparando Missão</span>
                </div>
            `;
        }

        this.pregameTimeLeft = this.config.pregame.minDuration;
        this.updatePregameUI(this.pregameTimeLeft, false);
        
        this.showRandomTip();

        this.pregameTimer = setInterval(() => {
            this.pregameTimeLeft--;
            const totalTime = this.config.pregame.minDuration;
            const progress = ((totalTime - this.pregameTimeLeft) / totalTime) * 100;
            
            const loadingBar = document.getElementById('loadingBar');
            const loadingPercent = document.getElementById('loadingPercent');
            
            if (loadingBar) loadingBar.style.width = `${progress}%`;
            if (loadingPercent) loadingPercent.textContent = `${Math.round(progress)}%`;

            if (this.pregameTimeLeft <= 0) {
                this.completePregame(container, resolve);
            }
        }, 1000);
    }

    // Cancelar pré-jogo
    cancelPreGame() {
        if (this.pregameTimer) {
            clearInterval(this.pregameTimer);
            this.pregameTimer = null;
        }
        if (this.rotationTimer) {
            clearInterval(this.rotationTimer);
            this.rotationTimer = null;
        }
        
        const container = document.getElementById('preGameScreen');
        if (container) {
            container.classList.remove('active');
            container.style.display = 'none';
        }
    }

    // ============================================
    // PÓS-JOGO (Tela Final)
    // ============================================

    renderEndGameAds(containerId = 'endgameAdsContainer', isGameOver = false) {
        if (!this.config.enabled || !this.config.endgame.enabled) {
            this.log('Pós-jogo desabilitado');
            return;
        }

        // Não mostrar no game over se configurado
        if (isGameOver && !this.config.endgame.showOnGameover) {
            return;
        }

        const slots = this.slots.endgame?.slice(0, this.config.endgame.maxSlots) || [];
        
        if (slots.length === 0) {
            this.log('Nenhum slot pós-jogo configurado');
            return;
        }

        const container = document.getElementById(containerId);
        if (!container) {
            this.log('Container de endgame não encontrado:', containerId);
            return;
        }

        // Limpar container e timer anterior
        this.clearEndGameAds();
        container.innerHTML = '';
        
        // Aplicar modo de exibição
        container.className = `endgame-ads-container mode-${this.config.endgame.displayMode}`;

        switch (this.config.endgame.displayMode) {
            case 'grid':
                this.renderGridAds(container, slots);
                break;
            case 'carousel':
                this.renderCarouselAds(container, slots);
                break;
            case 'stacked':
                this.renderStackedAds(container, slots);
                break;
            case 'single':
                this.renderSingleAd(container, slots);
                break;
            default:
                this.renderGridAds(container, slots);
        }
        
        // Log impressões
        if (this.config.tracking) {
            slots.forEach(slot => {
                this.logImpression(slot.id, 'endgame');
            });
        }
    }

    // Modo Grid
    renderGridAds(container, slots) {
        const grid = document.createElement('div');
        grid.className = 'ads-grid';
        grid.style.cssText = `
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            width: 100%;
        `;

        slots.forEach(slot => {
            const wrapper = this.createAdWrapper(slot);
            grid.appendChild(wrapper);
        });

        container.appendChild(grid);
    }

    // Modo Carousel
    renderCarouselAds(container, slots) {
        this.currentEndgameIndex = 0;

        const carousel = document.createElement('div');
        carousel.className = 'ads-carousel';
        carousel.style.cssText = `
            position: relative;
            width: 100%;
            overflow: hidden;
        `;

        const track = document.createElement('div');
        track.className = 'carousel-track';
        track.id = 'carouselTrack';
        track.style.cssText = `
            display: flex;
            transition: transform 0.5s ease;
        `;

        slots.forEach((slot) => {
            const wrapper = this.createAdWrapper(slot);
            wrapper.style.cssText += `
                min-width: 100%;
                flex-shrink: 0;
            `;
            track.appendChild(wrapper);
        });

        carousel.appendChild(track);

        // Indicadores
        if (slots.length > 1) {
            const indicators = document.createElement('div');
            indicators.className = 'carousel-indicators';
            indicators.style.cssText = `
                display: flex;
                justify-content: center;
                gap: 8px;
                margin-top: 15px;
            `;

            slots.forEach((_, index) => {
                const dot = document.createElement('button');
                dot.className = `carousel-dot ${index === 0 ? 'active' : ''}`;
                dot.style.cssText = `
                    width: 10px;
                    height: 10px;
                    border-radius: 50%;
                    border: none;
                    background: ${index === 0 ? 'var(--primary, #00E5CC)' : 'rgba(255,255,255,0.3)'};
                    cursor: pointer;
                    transition: background 0.3s;
                `;
                dot.onclick = () => this.goToSlide(index);
                indicators.appendChild(dot);
            });

            carousel.appendChild(indicators);

            // Auto-rotação
            if (this.config.endgame.autoRotate) {
                this.endgameRotationTimer = setInterval(() => {
                    this.currentEndgameIndex = (this.currentEndgameIndex + 1) % slots.length;
                    this.goToSlide(this.currentEndgameIndex);
                }, this.config.endgame.rotationInterval * 1000);
            }
        }

        container.appendChild(carousel);
    }

    goToSlide(index) {
        const track = document.getElementById('carouselTrack');
        if (track) {
            track.style.transform = `translateX(-${index * 100}%)`;
        }

        // Atualizar indicadores
        const dots = document.querySelectorAll('.carousel-dot');
        dots.forEach((dot, i) => {
            dot.style.background = i === index ? 'var(--primary, #00E5CC)' : 'rgba(255,255,255,0.3)';
            dot.classList.toggle('active', i === index);
        });

        this.currentEndgameIndex = index;
    }

    // Modo Stacked
    renderStackedAds(container, slots) {
        const stack = document.createElement('div');
        stack.className = 'ads-stack';
        stack.style.cssText = `
            display: flex;
            flex-direction: column;
            gap: 15px;
            width: 100%;
        `;

        slots.forEach(slot => {
            const wrapper = this.createAdWrapper(slot);
            stack.appendChild(wrapper);
        });

        container.appendChild(stack);
    }

    // Modo Single
    renderSingleAd(container, slots) {
        if (slots.length === 0) return;

        this.currentEndgameIndex = 0;
        const wrapper = this.createAdWrapper(slots[0]);
        wrapper.id = 'singleAdWrapper';
        container.appendChild(wrapper);

        // Rotação se houver mais de um
        if (slots.length > 1 && this.config.endgame.autoRotate) {
            this.endgameRotationTimer = setInterval(() => {
                this.currentEndgameIndex = (this.currentEndgameIndex + 1) % slots.length;
                const newWrapper = this.createAdWrapper(slots[this.currentEndgameIndex]);
                newWrapper.id = 'singleAdWrapper';
                
                const oldWrapper = document.getElementById('singleAdWrapper');
                if (oldWrapper) {
                    oldWrapper.replaceWith(newWrapper);
                }
                
                // Log impressão
                if (this.config.tracking) {
                    this.logImpression(slots[this.currentEndgameIndex].id, 'endgame');
                }
            }, this.config.endgame.rotationInterval * 1000);
        }
    }

    // Criar wrapper de ad
    createAdWrapper(slot) {
        const wrapper = document.createElement('div');
        wrapper.className = 'ad-slot-wrapper';
        wrapper.setAttribute('data-slot-id', slot.id);
        
        // Aplicar dimensões
        if (slot.width) {
            wrapper.style.width = isNaN(slot.width) ? slot.width : slot.width + 'px';
        }
        if (slot.height) {
            wrapper.style.height = isNaN(slot.height) ? slot.height : slot.height + 'px';
        }
        
        // CSS customizado
        if (slot.custom_css) {
            const style = document.createElement('style');
            style.textContent = slot.custom_css;
            wrapper.appendChild(style);
        }
        
        // Código do anúncio
        wrapper.innerHTML += slot.script_code;
        
        // Executar scripts
        setTimeout(() => this.executeScripts(wrapper), 100);
        
        // Listener de clique
        wrapper.addEventListener('click', () => {
            if (this.config.tracking) {
                this.logClick(slot.id, 'endgame');
            }
        });
        
        return wrapper;
    }

    // Limpar anúncios do pós-jogo
    clearEndGameAds() {
        if (this.endgameRotationTimer) {
            clearInterval(this.endgameRotationTimer);
            this.endgameRotationTimer = null;
        }
    }

    // ============================================
    // TRACKING
    // ============================================

    async logImpression(slotId, page) {
        if (!this.config.tracking || !slotId) return;

        try {
            await fetch('/api/admin-ads.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'log_impression',
                    slot_id: slotId,
                    session_id: this.sessionId,
                    google_uid: window.authManager?.getGoogleUid(),
                    page: page
                })
            });
            this.log('Impressão registrada:', slotId);
        } catch (error) {
            // Silenciar erros de tracking
        }
    }

    async logClick(slotId, page) {
        if (!this.config.tracking || !slotId) return;

        try {
            await fetch('/api/admin-ads.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'log_click',
                    slot_id: slotId,
                    session_id: this.sessionId,
                    google_uid: window.authManager?.getGoogleUid()
                })
            });
            this.log('Clique registrado:', slotId);
        } catch (error) {
            // Silenciar erros de tracking
        }
    }

    // ============================================
    // UTILITÁRIOS
    // ============================================

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
            tipEl.textContent = tips[Math.floor(Math.random() * tips.length)];
        }
    }

    // Verificar se está habilitado
    isEnabled() {
        return this.config.enabled;
    }

    // Obter configuração atual
    getConfig() {
        return { ...this.config };
    }

    // Recarregar configuração
    async reload() {
        await this.loadConfig();
    }
}

// Criar instância global
window.adsManager = new AdsManager();

console.log('📺 AdsManager v2.0 inicializado');
