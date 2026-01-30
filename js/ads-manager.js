/* ============================================
   UNOBIX - Ads Manager v3.0
   File: js/ads-manager.js
   Gerenciamento de anúncios com API backend
   Configurações dinâmicas do painel admin
   ============================================ */

const AdsManager = {
    isInitialized: false,
    adTimer: null,
    config: null,
    currentAd: null,
    adIndex: 0,
    skipCallback: null,
    
    // Configurações padrão (sobrescritas pela API)
    defaultConfig: {
        enabled: true,
        preGameAdEnabled: true,
        preGameAdDuration: 5,           // segundos
        bannerEnabled: false,
        bannerPosition: 'bottom',       // top, bottom
        bannerRefreshInterval: 30,      // segundos
        interstitialEnabled: false,
        interstitialFrequency: 3,       // a cada X missões
        rewardedAdEnabled: false,
        rewardedAdBonus: 0.001,         // BRL bonus
        adNetworks: [],                 // Configurações de redes de anúncios
        customAds: [],                  // Anúncios personalizados do admin
        fallbackImageUrl: '',           // Imagem fallback
        fallbackLinkUrl: '',            // Link fallback
        skipButtonDelay: 3,             // Segundos até mostrar botão pular
        allowSkip: false                // Permitir pular anúncio
    },
    
    // Initialize ads system
    async init() {
        if (this.isInitialized) return;
        
        console.log('📺 AdsManager v3.0 inicializando...');
        
        // Carregar configurações da API
        await this.loadConfig();
        
        this.isInitialized = true;
        console.log('📺 AdsManager inicializado', this.config?.enabled ? '(ativo)' : '(desativado)');
    },
    
    // Carregar configurações da API
    async loadConfig() {
        try {
            const response = await fetch('api/ads-config.php', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const text = await response.text();
            
            // Verificar se é JSON válido
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('Resposta não é JSON válido');
            }
            
            if (data.success && data.config) {
                this.config = { ...this.defaultConfig, ...data.config };
                console.log('📺 Configurações de ads carregadas da API');
            } else {
                console.warn('📺 Usando configurações padrão de ads');
                this.config = { ...this.defaultConfig };
            }
        } catch (error) {
            console.warn('📺 Erro ao carregar config de ads, usando padrão:', error.message);
            this.config = { ...this.defaultConfig };
        }
    },
    
    // Recarregar configurações (para atualização dinâmica)
    async reloadConfig() {
        await this.loadConfig();
        return this.config;
    },
    
    // Show pre-game ad
    async showPreGameAd(containerId = 'adContainer') {
        const container = document.getElementById(containerId);
        if (!container) return Promise.resolve();
        
        // Verificar se ads estão habilitados
        if (!this.config?.enabled || !this.config?.preGameAdEnabled) {
            console.log('📺 Ads desabilitados, pulando...');
            return Promise.resolve();
        }
        
        return new Promise((resolve) => {
            console.log('📺 Mostrando anúncio pré-jogo...');
            
            this.skipCallback = resolve;
            
            // Buscar anúncio para exibir
            const ad = this.getNextAd('pregame');
            
            if (ad) {
                this.renderAd(container, ad);
            } else {
                this.showPlaceholder(container);
            }
            
            // Registrar impressão
            this.logImpression('pregame', ad?.id);
            
            // Iniciar countdown
            this.startAdTimer(container, resolve);
        });
    },
    
    // Obter próximo anúncio
    getNextAd(placement = 'pregame') {
        if (!this.config?.customAds || this.config.customAds.length === 0) {
            return null;
        }
        
        // Filtrar anúncios por placement
        const availableAds = this.config.customAds.filter(ad => 
            ad.enabled && 
            (!ad.placement || ad.placement === placement || ad.placement === 'all')
        );
        
        if (availableAds.length === 0) return null;
        
        // Rotação de anúncios
        this.adIndex = (this.adIndex + 1) % availableAds.length;
        return availableAds[this.adIndex];
    },
    
    // Renderizar anúncio
    renderAd(container, ad) {
        this.currentAd = ad;
        
        const duration = this.config.preGameAdDuration || 5;
        const allowSkip = this.config.allowSkip;
        const skipDelay = this.config.skipButtonDelay || 3;
        
        let adContent = '';
        
        // Determinar tipo de anúncio
        if (ad.type === 'image' || ad.imageUrl) {
            adContent = `
                <a href="${ad.linkUrl || '#'}" target="_blank" rel="noopener" class="ad-link" data-ad-id="${ad.id}">
                    <img src="${ad.imageUrl}" alt="${ad.title || 'Anúncio'}" class="ad-image" 
                         onerror="AdsManager.onAdImageError(this)">
                </a>
            `;
        } else if (ad.type === 'html' && ad.htmlContent) {
            adContent = `<div class="ad-html-content">${ad.htmlContent}</div>`;
        } else if (ad.type === 'video' && ad.videoUrl) {
            adContent = `
                <video class="ad-video" autoplay muted playsinline>
                    <source src="${ad.videoUrl}" type="video/mp4">
                </video>
            `;
        } else if (ad.type === 'adsense' && ad.adsenseCode) {
            adContent = `<div class="ad-adsense">${ad.adsenseCode}</div>`;
        } else {
            this.showPlaceholder(container);
            return;
        }
        
        container.innerHTML = `
            <div class="ad-wrapper" data-ad-id="${ad.id}">
                ${adContent}
                ${ad.title ? `<div class="ad-title">${ad.title}</div>` : ''}
                <div class="ad-timer" id="adTimer">${duration}s</div>
                ${allowSkip ? `<button class="ad-skip-btn" id="adSkipBtn" style="display:none;" onclick="AdsManager.skip()">
                    <i class="fas fa-forward"></i> Pular
                </button>` : ''}
                <div class="ad-label">Publicidade</div>
            </div>
        `;
        
        // Mostrar botão de pular após delay
        if (allowSkip) {
            setTimeout(() => {
                const skipBtn = document.getElementById('adSkipBtn');
                if (skipBtn) skipBtn.style.display = 'block';
            }, skipDelay * 1000);
        }
        
        // Registrar clique
        const adLink = container.querySelector('.ad-link');
        if (adLink) {
            adLink.addEventListener('click', () => {
                this.logClick(ad.id);
            });
        }
    },
    
    // Mostrar placeholder quando não há anúncios
    showPlaceholder(container) {
        const duration = this.config?.preGameAdDuration || 5;
        
        // Verificar se há imagem/link fallback
        if (this.config?.fallbackImageUrl) {
            container.innerHTML = `
                <div class="ad-wrapper">
                    <a href="${this.config.fallbackLinkUrl || '#'}" target="_blank" rel="noopener" class="ad-link">
                        <img src="${this.config.fallbackImageUrl}" alt="Anúncio" class="ad-image"
                             onerror="AdsManager.showDefaultPlaceholder(this.parentElement.parentElement)">
                    </a>
                    <div class="ad-timer" id="adTimer">${duration}s</div>
                    <div class="ad-label">Publicidade</div>
                </div>
            `;
        } else {
            this.showDefaultPlaceholder(container);
        }
    },
    
    // Placeholder padrão (sem anúncio configurado)
    showDefaultPlaceholder(container) {
        const duration = this.config?.preGameAdDuration || 5;
        
        container.innerHTML = `
            <div class="ad-placeholder">
                <div class="ad-placeholder-content">
                    <i class="fas fa-rocket" style="font-size: 3rem; color: var(--primary); margin-bottom: 15px;"></i>
                    <div style="font-size: 1.2rem; color: var(--text-primary);">Preparando sua missão...</div>
                    <div style="font-size: 0.9rem; color: var(--text-dim); margin-top: 10px;">Sistemas sendo inicializados</div>
                </div>
            </div>
            <div class="ad-timer" id="adTimer">${duration}s</div>
        `;
    },
    
    // Erro ao carregar imagem do anúncio
    onAdImageError(img) {
        console.warn('📺 Erro ao carregar imagem do anúncio');
        const wrapper = img.closest('.ad-wrapper');
        if (wrapper) {
            this.showDefaultPlaceholder(wrapper.parentElement);
        }
    },
    
    // Iniciar timer do anúncio
    startAdTimer(container, callback) {
        let timeLeft = this.config?.preGameAdDuration || 5;
        const timerEl = document.getElementById('adTimer');
        
        if (this.adTimer) {
            clearInterval(this.adTimer);
        }
        
        this.adTimer = setInterval(() => {
            timeLeft--;
            
            if (timerEl) {
                timerEl.textContent = `${timeLeft}s`;
            }
            
            if (timeLeft <= 0) {
                this.clearTimer();
                callback();
            }
        }, 1000);
    },
    
    // Limpar timer
    clearTimer() {
        if (this.adTimer) {
            clearInterval(this.adTimer);
            this.adTimer = null;
        }
    },
    
    // Pular anúncio
    skip() {
        if (!this.config?.allowSkip) return;
        
        console.log('📺 Anúncio pulado pelo usuário');
        this.logEvent('skip', this.currentAd?.id);
        this.clearTimer();
        
        // Chamar callback se existir
        if (this.skipCallback) {
            this.skipCallback();
            this.skipCallback = null;
        }
        
        // Disparar evento
        document.dispatchEvent(new CustomEvent('adSkipped'));
    },
    
    // ============================================
    // BANNER ADS
    // ============================================
    
    showBanner(position = null) {
        if (!this.config?.enabled || !this.config?.bannerEnabled) return;
        
        position = position || this.config.bannerPosition || 'bottom';
        
        const ad = this.getNextAd('banner');
        if (!ad) return;
        
        let banner = document.getElementById('adBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'adBanner';
            banner.className = `ad-banner ad-banner-${position}`;
            document.body.appendChild(banner);
        }
        
        banner.innerHTML = `
            <a href="${ad.linkUrl || '#'}" target="_blank" rel="noopener" class="ad-banner-link" data-ad-id="${ad.id}">
                <img src="${ad.imageUrl}" alt="${ad.title || 'Anúncio'}" class="ad-banner-image">
            </a>
            <button class="ad-banner-close" onclick="AdsManager.hideBanner()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        banner.style.display = 'block';
        this.logImpression('banner', ad.id);
        
        if (this.config.bannerRefreshInterval > 0) {
            setTimeout(() => {
                if (document.getElementById('adBanner')?.style.display !== 'none') {
                    this.showBanner(position);
                }
            }, this.config.bannerRefreshInterval * 1000);
        }
    },
    
    hideBanner() {
        const banner = document.getElementById('adBanner');
        if (banner) banner.style.display = 'none';
    },
    
    // ============================================
    // INTERSTITIAL ADS
    // ============================================
    
    async showInterstitial() {
        if (!this.config?.enabled || !this.config?.interstitialEnabled) {
            return Promise.resolve();
        }
        
        return new Promise((resolve) => {
            const ad = this.getNextAd('interstitial');
            if (!ad) { resolve(); return; }
            
            const overlay = document.createElement('div');
            overlay.id = 'adInterstitial';
            overlay.className = 'ad-interstitial-overlay';
            
            overlay.innerHTML = `
                <div class="ad-interstitial-container">
                    <a href="${ad.linkUrl || '#'}" target="_blank" rel="noopener">
                        <img src="${ad.imageUrl}" alt="${ad.title || 'Anúncio'}" class="ad-interstitial-image">
                    </a>
                    <button class="ad-interstitial-close" id="interstitialClose" style="display:none;">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                    <div class="ad-interstitial-timer" id="interstitialTimer">5s</div>
                </div>
            `;
            
            document.body.appendChild(overlay);
            this.logImpression('interstitial', ad.id);
            
            let timeLeft = 5;
            const timer = setInterval(() => {
                timeLeft--;
                const timerEl = document.getElementById('interstitialTimer');
                if (timerEl) timerEl.textContent = `${timeLeft}s`;
                
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    const closeBtn = document.getElementById('interstitialClose');
                    if (closeBtn) {
                        closeBtn.style.display = 'block';
                        closeBtn.onclick = () => { overlay.remove(); resolve(); };
                    }
                }
            }, 1000);
        });
    },
    
    // ============================================
    // REWARDED ADS
    // ============================================
    
    async showRewardedAd() {
        if (!this.config?.enabled || !this.config?.rewardedAdEnabled) {
            return { success: false, reason: 'disabled' };
        }
        
        const ad = this.getNextAd('rewarded');
        if (!ad) return { success: false, reason: 'no_ad' };
        
        // Implementar lógica de rewarded ad
        return new Promise((resolve) => {
            setTimeout(() => {
                this.logEvent('rewarded_complete', ad.id);
                resolve({ success: true, reward: this.config.rewardedAdBonus || 0.001 });
            }, 5000);
        });
    },
    
    // ============================================
    // LOGGING / ANALYTICS
    // ============================================
    
    async logImpression(placement, adId) {
        this.logEvent('impression', adId, { placement });
    },
    
    async logClick(adId) {
        this.logEvent('click', adId);
    },
    
    async logEvent(eventType, adId, extraData = {}) {
        try {
            const googleUid = window.authManager?.getUserId() || localStorage.getItem('googleUid');
            
            await fetch('api/ads-log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    event_type: eventType,
                    ad_id: adId,
                    google_uid: googleUid,
                    page: window.location.pathname,
                    ...extraData
                })
            });
        } catch (error) {
            // Silencioso
        }
    },
    
    // ============================================
    // UTILITÁRIOS
    // ============================================
    
    shouldShowInterstitial() {
        if (!this.config?.interstitialEnabled) return false;
        const frequency = this.config.interstitialFrequency || 3;
        const missionCount = parseInt(localStorage.getItem('totalMissions') || '0');
        return missionCount > 0 && missionCount % frequency === 0;
    },
    
    getConfig() { return this.config; },
    isEnabled() { return this.config?.enabled === true; },
    
    destroy() {
        this.clearTimer();
        this.hideBanner();
        const interstitial = document.getElementById('adInterstitial');
        if (interstitial) interstitial.remove();
        this.currentAd = null;
        this.isInitialized = false;
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    AdsManager.init();
});

window.AdsManager = AdsManager;
