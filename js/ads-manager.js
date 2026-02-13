/* ============================================
   UNOBIX - Ads Manager v4.0
   File: js/ads-manager.js
   Gerenciamento de anúncios com API backend
   Alinhado com admin-ads.php e ads-config.php
   ============================================ */

const AdsManager = {
    isInitialized: false,
    adTimer: null,
    config: null,
    slots: null,          // slots agrupados por tipo: { pregame: [], endgame: [], ... }
    currentAd: null,
    adIndex: {},          // índice de rotação por tipo
    skipCallback: null,
    
    // Configurações padrão (sobrescritas pela API)
    defaultConfig: {
        enabled: false,
        pregame_enabled: false,
        pregame_total_duration: 10,
        pregame_min_duration: 5,
        pregame_skip_enabled: false,
        pregame_skip_after: 5,
        pregame_rotation_interval: 5,
        pregame_max_slots: 3,
        endgame_enabled: false,
        endgame_display_mode: 'grid',
        endgame_max_slots: 4,
        endgame_auto_rotate: true,
        endgame_rotation_interval: 8,
        endgame_show_on_gameover: true,
        interstitial_enabled: false,
        interstitial_frequency: 3,
        interstitial_duration: 5,
        interstitial_skip_after: 3,
        banner_enabled: false,
        banner_position: 'bottom',
        tracking_enabled: true,
        debug_mode: false
    },
    
    defaultSlots: {
        pregame: [],
        endgame: [],
        interstitial: [],
        banner: []
    },
    
    // ============================================
    // INICIALIZAÇÃO
    // ============================================
    
    async init() {
        if (this.isInitialized) return;
        
        this.log('📺 AdsManager v4.0 inicializando...');
        
        await this.loadConfig();
        
        this.isInitialized = true;
        this.log('📺 AdsManager inicializado', this.config?.enabled ? '(ativo)' : '(desativado)');
    },
    
    // Carregar configurações e slots da API
    async loadConfig() {
        try {
            const response = await fetch('api/ads-config.php', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            });
            
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('Resposta não é JSON válido');
            }
            
            if (data.success) {
                this.config = { ...this.defaultConfig, ...(data.config || {}) };
                this.slots = { ...this.defaultSlots, ...(data.slots || {}) };
                this.log('📺 Config carregada da API:', Object.keys(this.config).length, 'settings');
                this.log('📺 Slots:', Object.entries(this.slots).map(([k,v]) => `${k}:${v.length}`).join(', '));
            } else {
                this.log('📺 API retornou erro, usando defaults');
                this.config = { ...this.defaultConfig };
                this.slots = { ...this.defaultSlots };
            }
        } catch (error) {
            this.log('📺 Erro ao carregar config, usando defaults:', error.message);
            this.config = { ...this.defaultConfig };
            this.slots = { ...this.defaultSlots };
        }
    },
    
    async reloadConfig() {
        await this.loadConfig();
        return this.config;
    },
    
    // ============================================
    // PRE-GAME ADS (tela de carregamento)
    // ============================================
    
    async showPreGameAd(containerId = 'adContainer') {
        const container = document.getElementById(containerId);
        if (!container) return Promise.resolve();
        
        if (!this.config?.enabled || !this.config?.pregame_enabled) {
            this.log('📺 Pregame ads desabilitados');
            return Promise.resolve();
        }
        
        const pregameSlots = this.slots?.pregame || [];
        if (pregameSlots.length === 0) {
            this.log('📺 Nenhum slot pregame configurado');
            return Promise.resolve();
        }
        
        return new Promise((resolve) => {
            this.log('📺 Mostrando anúncio pré-jogo...');
            this.skipCallback = resolve;
            
            const slot = this.getNextSlot('pregame');
            
            if (slot) {
                this.renderSlot(container, slot, 'pregame');
                this.trackImpression(slot.id);
            } else {
                this.showDefaultPlaceholder(container);
            }
            
            this.startAdTimer(container, resolve);
        });
    },
    
    // ============================================
    // END-GAME ADS (tela de resultado)
    // ============================================
    
    async showEndGameAd(containerId = 'endgameAdContainer') {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        if (!this.config?.enabled || !this.config?.endgame_enabled) {
            this.log('📺 Endgame ads desabilitados');
            return;
        }
        
        const endgameSlots = this.slots?.endgame || [];
        if (endgameSlots.length === 0) return;
        
        const mode = this.config.endgame_display_mode || 'grid';
        const maxSlots = this.config.endgame_max_slots || 4;
        const activeSlots = endgameSlots.slice(0, maxSlots);
        
        if (mode === 'grid') {
            // Mostrar múltiplos slots em grid
            container.innerHTML = '<div class="ad-endgame-grid">' +
                activeSlots.map(slot => {
                    this.trackImpression(slot.id);
                    return `<div class="ad-endgame-slot" data-slot-id="${slot.id}">
                        ${this.getSlotHTML(slot)}
                    </div>`;
                }).join('') +
            '</div>';
        } else {
            // Mostrar um slot por vez com rotação
            const slot = this.getNextSlot('endgame');
            if (slot) {
                this.renderSlot(container, slot, 'endgame');
                this.trackImpression(slot.id);
                
                // Auto-rotação
                if (this.config.endgame_auto_rotate) {
                    const interval = (this.config.endgame_rotation_interval || 8) * 1000;
                    setInterval(() => {
                        const nextSlot = this.getNextSlot('endgame');
                        if (nextSlot) {
                            this.renderSlot(container, nextSlot, 'endgame');
                            this.trackImpression(nextSlot.id);
                        }
                    }, interval);
                }
            }
        }
    },
    
    // ============================================
    // INTERSTITIAL ADS
    // ============================================
    
    async showInterstitial() {
        if (!this.config?.enabled || !this.config?.interstitial_enabled) {
            return Promise.resolve();
        }
        
        const slot = this.getNextSlot('interstitial');
        if (!slot) return Promise.resolve();
        
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.id = 'adInterstitial';
            overlay.className = 'ad-interstitial-overlay';
            
            const duration = this.config.interstitial_duration || 5;
            const skipAfter = this.config.interstitial_skip_after || 3;
            
            overlay.innerHTML = `
                <div class="ad-interstitial-container">
                    <div class="ad-interstitial-content">${this.getSlotHTML(slot)}</div>
                    <button class="ad-interstitial-close" id="interstitialClose" style="display:none;">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                    <div class="ad-interstitial-timer" id="interstitialTimer">${duration}s</div>
                </div>
            `;
            
            document.body.appendChild(overlay);
            this.trackImpression(slot.id);
            
            let timeLeft = duration;
            const timer = setInterval(() => {
                timeLeft--;
                const timerEl = document.getElementById('interstitialTimer');
                if (timerEl) timerEl.textContent = `${timeLeft}s`;
                
                if (timeLeft <= (duration - skipAfter)) {
                    const closeBtn = document.getElementById('interstitialClose');
                    if (closeBtn) {
                        closeBtn.style.display = 'block';
                        closeBtn.onclick = () => { 
                            clearInterval(timer);
                            overlay.remove(); 
                            resolve(); 
                        };
                    }
                }
                
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    overlay.remove();
                    resolve();
                }
            }, 1000);
        });
    },
    
    shouldShowInterstitial() {
        if (!this.config?.interstitial_enabled) return false;
        const frequency = this.config.interstitial_frequency || 3;
        const missionCount = parseInt(localStorage.getItem('totalMissions') || '0');
        return missionCount > 0 && missionCount % frequency === 0;
    },
    
    // ============================================
    // BANNER ADS
    // ============================================
    
    showBanner(position = null) {
        if (!this.config?.enabled || !this.config?.banner_enabled) return;
        
        position = position || this.config.banner_position || 'bottom';
        
        const slot = this.getNextSlot('banner');
        if (!slot) return;
        
        let banner = document.getElementById('adBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'adBanner';
            banner.className = `ad-banner ad-banner-${position}`;
            document.body.appendChild(banner);
        }
        
        banner.innerHTML = `
            <div class="ad-banner-content">${this.getSlotHTML(slot)}</div>
            <button class="ad-banner-close" onclick="AdsManager.hideBanner()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        banner.style.display = 'block';
        this.trackImpression(slot.id);
    },
    
    hideBanner() {
        const banner = document.getElementById('adBanner');
        if (banner) banner.style.display = 'none';
    },
    
    // ============================================
    // CORE: Slot Management
    // ============================================
    
    // Obter próximo slot por tipo (rotação)
    getNextSlot(type) {
        const typeSlots = this.slots?.[type] || [];
        if (typeSlots.length === 0) return null;
        
        if (!this.adIndex[type]) this.adIndex[type] = 0;
        
        const slot = typeSlots[this.adIndex[type]];
        this.adIndex[type] = (this.adIndex[type] + 1) % typeSlots.length;
        
        return slot;
    },
    
    // Gerar HTML do slot (usa script_code do banco)
    getSlotHTML(slot) {
        if (!slot) return '';
        
        let html = '';
        
        // CSS personalizado
        if (slot.custom_css) {
            html += `<style>${slot.custom_css}</style>`;
        }
        
        // Container com dimensões
        const style = [];
        if (slot.width) style.push(`width:${slot.width}${isNaN(slot.width) ? '' : 'px'}`);
        if (slot.height) style.push(`height:${slot.height}${isNaN(slot.height) ? '' : 'px'}`);
        
        html += `<div class="ad-slot-content" data-slot-id="${slot.id}" 
                      style="${style.join(';')}">${slot.script_code}</div>`;
        
        return html;
    },
    
    // Renderizar slot no container
    renderSlot(container, slot, type) {
        this.currentAd = slot;
        
        const duration = this.config?.[`${type}_total_duration`] || this.config?.pregame_total_duration || 10;
        const skipEnabled = this.config?.[`${type}_skip_enabled`] || false;
        const skipAfter = this.config?.[`${type}_skip_after`] || 5;
        
        container.innerHTML = `
            <div class="ad-wrapper" data-slot-id="${slot.id}">
                ${this.getSlotHTML(slot)}
                <div class="ad-timer" id="adTimer">${duration}s</div>
                ${skipEnabled ? `<button class="ad-skip-btn" id="adSkipBtn" style="display:none;" onclick="AdsManager.skip()">
                    <i class="fas fa-forward"></i> Pular
                </button>` : ''}
                <div class="ad-label">Publicidade</div>
            </div>
        `;
        
        // Executar scripts inline do ad
        this.executeScripts(container);
        
        // Mostrar botão de pular após delay
        if (skipEnabled) {
            setTimeout(() => {
                const skipBtn = document.getElementById('adSkipBtn');
                if (skipBtn) skipBtn.style.display = 'block';
            }, skipAfter * 1000);
        }
    },
    
    // Executar scripts do slot (necessário para ads de terceiros)
    executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            if (oldScript.src) {
                newScript.src = oldScript.src;
            } else {
                // IIFE evita conflito de let/const ao re-executar na rotação
                newScript.textContent = `(function(){${oldScript.textContent}})();`;
            }
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    },
    
    // ============================================
    // TIMER
    // ============================================
    
    startAdTimer(container, callback) {
        let timeLeft = this.config?.pregame_total_duration || 10;
        const timerEl = document.getElementById('adTimer');
        
        if (this.adTimer) clearInterval(this.adTimer);
        
        this.adTimer = setInterval(() => {
            timeLeft--;
            if (timerEl) timerEl.textContent = `${timeLeft}s`;
            
            if (timeLeft <= 0) {
                this.clearTimer();
                callback();
            }
        }, 1000);
    },
    
    clearTimer() {
        if (this.adTimer) {
            clearInterval(this.adTimer);
            this.adTimer = null;
        }
    },
    
    skip() {
        if (!this.config?.pregame_skip_enabled) return;
        
        this.log('📺 Anúncio pulado pelo usuário');
        this.clearTimer();
        
        if (this.skipCallback) {
            this.skipCallback();
            this.skipCallback = null;
        }
        
        document.dispatchEvent(new CustomEvent('adSkipped'));
    },
    
    // ============================================
    // TRACKING (impressões e cliques)
    // ============================================
    
    async trackImpression(slotId) {
        if (!this.config?.tracking_enabled || !slotId) return;
        
        try {
            const googleUid = window.authManager?.getUserId?.() || localStorage.getItem('googleUid');
            
            await fetch('api/admin-ads.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'log_impression',
                    slot_id: slotId,
                    google_uid: googleUid,
                    page: window.location.pathname,
                    session_id: this.getSessionId()
                })
            });
        } catch (e) { /* silencioso */ }
    },
    
    async trackClick(slotId) {
        if (!this.config?.tracking_enabled || !slotId) return;
        
        try {
            const googleUid = window.authManager?.getUserId?.() || localStorage.getItem('googleUid');
            
            await fetch('api/admin-ads.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'log_click',
                    slot_id: slotId,
                    google_uid: googleUid,
                    session_id: this.getSessionId()
                })
            });
        } catch (e) { /* silencioso */ }
    },
    
    getSessionId() {
        let sid = sessionStorage.getItem('ads_session_id');
        if (!sid) {
            sid = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('ads_session_id', sid);
        }
        return sid;
    },
    
    // ============================================
    // PLACEHOLDER (quando não há anúncios)
    // ============================================
    
    showDefaultPlaceholder(container) {
        const duration = this.config?.pregame_total_duration || 10;
        container.innerHTML = `
            <div class="ad-wrapper ad-placeholder">
                <div style="text-align:center; padding: 40px;">
                    <div style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;">📺</div>
                    <div style="color: rgba(255,255,255,0.5); font-size: 0.9rem;">Carregando...</div>
                </div>
                <div class="ad-timer" id="adTimer">${duration}s</div>
            </div>
        `;
    },
    
    // ============================================
    // UTILITÁRIOS
    // ============================================
    
    log(...args) {
        if (this.config?.debug_mode) {
            console.log(...args);
        }
    },
    
    getConfig() { return this.config; },
    getSlots() { return this.slots; },
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
