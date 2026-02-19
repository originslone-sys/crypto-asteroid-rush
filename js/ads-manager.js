/* ============================================
   UNOBIX - Ads Manager v5.0
   File: js/ads-manager.js
   Engine de ads com isolamento correto de scripts
   - Scripts globais (Monetag, push, pop-under): injetados no <head> UMA VEZ, sequencialmente
   - Display ads (Adsterra, banners, iframes): renderizados em iframe isolado, rotação livre
   - Separação total: rotação só afeta conteúdo visual, nunca re-injeta scripts globais
   ============================================ */

const AdsManager = {
    isInitialized: false,
    adTimer: null,
    config: null,
    slots: null,          // slots agrupados por tipo: { pregame: [], endgame: [], ... }
    currentAd: null,
    adIndex: {},           // índice de rotação por tipo (sobre slots visuais)
    skipCallback: null,

    // Tracking de deduplicação
    _injectedSlotIds: new Set(),   // slot IDs que já tiveram globals injetados
    _injectedFingerprints: new Set(), // fingerprints de scripts já injetados (src URL ou hash do inline)

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
        endgame_total_duration: 10,
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
        if (this._initPromise) return this._initPromise;

        this._initPromise = (async () => {
            this.log('📺 AdsManager v5.0 inicializando...');
            await this.loadConfig();
            this.isInitialized = true;
            this.log('📺 AdsManager inicializado', this.config?.enabled ? '(ativo)' : '(desativado)');
        })();

        return this._initPromise;
    },

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
                this.log('📺 Config carregada:', Object.keys(this.config).length, 'settings');
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
    // CLASSIFICAÇÃO DE SCRIPTS
    // ============================================

    // Detectar se um código contém APENAS <script> tags (sem HTML visual).
    // Scripts puros (Monetag, push, pop-under) precisam rodar no <head>,
    // não dentro de iframe. Display ads (Adsterra, a-ads) sempre têm
    // elementos visuais (<div>, <ins>, <iframe>, <img>) junto dos scripts.
    _isGlobalOnlyScript(code) {
        if (!code) return false;
        const hasVisual = /<(?:div|ins|iframe|img|a |span|p |section)\b/i.test(code);
        return !hasVisual && /<script\b/i.test(code);
    },

    // Verificar se um slot tem conteúdo visual (que precisa de espaço no container)
    _hasVisualContent(slot) {
        if (!slot) return false;
        if (!slot.script_code) return false;
        // Se custom_js existe, script_code é sempre visual (display ad)
        if (slot.custom_js) return true;
        // Se position=head (legado) ou auto-detectado como global-only, NÃO é visual
        if (slot.position === 'head') return false;
        if (this._isGlobalOnlyScript(slot.script_code)) return false;
        return true;
    },

    // Extrair código global de um slot (custom_js, ou script_code se for global-only)
    _getGlobalCode(slot) {
        if (!slot) return null;
        // custom_js é sempre global
        if (slot.custom_js) return slot.custom_js;
        // script_code pode ser global se for position=head ou auto-detectado
        if (slot.position === 'head' || this._isGlobalOnlyScript(slot.script_code)) {
            return slot.script_code;
        }
        return null;
    },

    // Retornar apenas slots visuais de um tipo (para rotação)
    _getVisualSlots(type) {
        const typeSlots = this.slots?.[type] || [];
        return typeSlots.filter(slot => this._hasVisualContent(slot));
    },

    // Gerar fingerprint de um script para deduplicação por conteúdo
    // (evita injetar o mesmo tag.min.js de Monetag duas vezes mesmo de slots diferentes)
    _scriptFingerprint(scriptEl) {
        if (scriptEl.src) {
            // Para scripts externos: usar URL + data-zone (redes ads usam zone para diferenciar)
            const zone = scriptEl.getAttribute('data-zone') || '';
            return 'src:' + scriptEl.src + (zone ? ':zone:' + zone : '');
        }
        // Para scripts inline: usar hash do conteúdo
        const content = scriptEl.textContent.trim();
        return 'inline:' + content.substring(0, 500);
    },

    // ============================================
    // INJEÇÃO DE SCRIPTS GLOBAIS (roda UMA VEZ por tipo)
    // ============================================

    // Injetar TODOS os scripts globais de um tipo no <head>, sequencialmente.
    // Cada script aguarda o anterior carregar antes de prosseguir.
    // Deduplicação dupla: por slot ID e por fingerprint do script.
    async injectGlobalScripts(type) {
        const typeSlots = this.slots?.[type] || [];

        for (const slot of typeSlots) {
            const globalCode = this._getGlobalCode(slot);
            if (!globalCode) continue;

            const slotKey = type + '_' + slot.id;
            if (this._injectedSlotIds.has(slotKey)) continue;
            this._injectedSlotIds.add(slotKey);

            this.log('📺 Processando scripts globais do slot', slot.id, slot.slot_name || '');

            // Extrair scripts do código
            const temp = document.createElement('div');
            temp.innerHTML = globalCode;
            const scripts = temp.querySelectorAll('script');

            for (const oldScript of scripts) {
                const fingerprint = this._scriptFingerprint(oldScript);

                if (this._injectedFingerprints.has(fingerprint)) {
                    this.log('📺 Skip duplicado:', fingerprint.substring(0, 80));
                    continue;
                }
                this._injectedFingerprints.add(fingerprint);

                // Injetar e aguardar carregamento
                await this._injectScript(oldScript);

                // Delay entre scripts para evitar race conditions entre redes ads
                await new Promise(r => setTimeout(r, 150));
            }
        }
    },

    // Injetar um único <script> no <head> e aguardar carregamento
    _injectScript(sourceScript) {
        return new Promise((resolve) => {
            const newScript = document.createElement('script');

            // Copiar todos os atributos (src, data-zone, data-cfasync, async, etc.)
            Array.from(sourceScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });

            if (sourceScript.src) {
                // Script externo: aguardar load
                newScript.onload = () => {
                    this.log('📺 Script carregado:', sourceScript.src.substring(0, 60));
                    resolve();
                };
                newScript.onerror = () => {
                    this.log('📺 Erro ao carregar script:', sourceScript.src.substring(0, 60));
                    resolve(); // Não bloquear por erro
                };
            } else {
                // Script inline: copiar conteúdo
                newScript.textContent = sourceScript.textContent;
            }

            document.head.appendChild(newScript);

            // Inline scripts executam sincronamente, resolver imediatamente
            if (!sourceScript.src) resolve();

            // Timeout fallback para scripts que não disparam onload
            setTimeout(resolve, 5000);
        });
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

        const pregameSlots = this._getVisualSlots('pregame');
        if (pregameSlots.length === 0) {
            this.log('📺 Nenhum slot pregame visual configurado');
            return Promise.resolve();
        }

        // Injetar scripts globais ANTES de renderizar
        await this.injectGlobalScripts('pregame');

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

        const endgameSlots = this._getVisualSlots('endgame');
        if (endgameSlots.length === 0) return;

        // Injetar scripts globais ANTES de renderizar
        await this.injectGlobalScripts('endgame');

        const mode = this.config.endgame_display_mode || 'grid';
        const maxSlots = this.config.endgame_max_slots || 4;
        const activeSlots = endgameSlots.slice(0, maxSlots);

        if (mode === 'grid') {
            container.innerHTML = '<div class="ad-endgame-grid">' +
                activeSlots.map(slot => {
                    this.trackImpression(slot.id);
                    return `<div class="ad-endgame-slot" data-slot-id="${slot.id}">
                        ${this.getSlotHTML(slot)}
                    </div>`;
                }).join('') +
            '</div>';
            this.executeScripts(container);
        } else {
            const slot = this.getNextSlot('endgame');
            if (slot) {
                this.renderSlot(container, slot, 'endgame');
                this.trackImpression(slot.id);

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

        // Injetar globais de interstitial
        await this.injectGlobalScripts('interstitial');

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
            this.executeScripts(overlay);
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

        // Injetar globais de banner
        this.injectGlobalScripts('banner');

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
        this.executeScripts(banner);
        this.trackImpression(slot.id);
    },

    hideBanner() {
        const banner = document.getElementById('adBanner');
        if (banner) banner.style.display = 'none';
    },

    // ============================================
    // CORE: Slot Management
    // ============================================

    // Obter próximo slot VISUAL por tipo (rodízio sequencial persistente).
    // Usa localStorage para lembrar qual slot foi exibido por último,
    // garantindo que cada sessão mostre o próximo slot da fila.
    // Slots global-only não participam da rotação visual.
    getNextSlot(type) {
        const visualSlots = this._getVisualSlots(type);
        if (visualSlots.length === 0) return null;

        const storageKey = 'ads_rotation_' + type;
        let idx = parseInt(localStorage.getItem(storageKey) || '0');
        if (isNaN(idx) || idx < 0) idx = 0;

        const slot = visualSlots[idx % visualSlots.length];

        // Salvar próximo índice para a próxima sessão
        localStorage.setItem(storageKey, String((idx + 1) % visualSlots.length));

        return slot;
    },

    // Gerar HTML do slot — APENAS conteúdo visual de display.
    // Scripts globais (custom_js / global-only script_code) NÃO são incluídos aqui.
    // Eles são tratados exclusivamente por injectGlobalScripts().
    getSlotHTML(slot) {
        if (!slot) return '';
        if (!this._hasVisualContent(slot)) return '';

        let html = '';

        // CSS personalizado
        if (slot.custom_css) {
            html += `<style>${slot.custom_css}</style>`;
        }

        // Conteúdo visual (script_code com elementos HTML)
        const style = [];
        if (slot.width) style.push(`width:${slot.width}${isNaN(slot.width) ? '' : 'px'}`);
        if (slot.height) style.push(`height:${slot.height}${isNaN(slot.height) ? '' : 'px'}`);

        html += `<div class="ad-slot-content" data-slot-id="${slot.id}" data-position="center"
                      style="${style.join(';')}">${slot.script_code}</div>`;

        return html;
    },

    // Renderizar slot no container
    renderSlot(container, slot, type) {
        if (!slot || !this._hasVisualContent(slot)) return;
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

        // Ativar scripts de display (iframe)
        this.executeScripts(container);

        if (skipEnabled) {
            setTimeout(() => {
                const skipBtn = document.getElementById('adSkipBtn');
                if (skipBtn) skipBtn.style.display = 'block';
            }, skipAfter * 1000);
        }
    },

    // Ativar scripts de DISPLAY ads em iframes isolados.
    // Processa APENAS divs com data-position="center".
    // Scripts globais (head) já foram tratados por injectGlobalScripts().
    executeScripts(container) {
        const slotDivs = container.querySelectorAll('.ad-slot-content[data-position="center"]');
        slotDivs.forEach(slotDiv => {
            const adHTML = slotDiv.innerHTML;
            if (!adHTML.trim()) return;

            // Se não tem <script> tags, não precisa de iframe — HTML puro (a-ads, iframes nativos)
            if (!slotDiv.querySelector('script')) return;

            const h = slotDiv.style.height || '250px';
            const iframe = document.createElement('iframe');
            iframe.style.cssText = 'border:0;width:100%;height:' + h + ';overflow:hidden;display:block;';
            iframe.setAttribute('scrolling', 'no');
            iframe.setAttribute('frameborder', '0');

            slotDiv.innerHTML = '';
            slotDiv.appendChild(iframe);

            const doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open();
            doc.write('<!DOCTYPE html><html><head><meta charset="UTF-8">'
                + '<style>body{margin:0;padding:0;overflow:hidden;display:flex;align-items:center;justify-content:center;min-height:100vh;}</style>'
                + '</head><body>' + adHTML + '</body></html>');
            doc.close();
        });
    },

    // Somar duration_seconds dos slots VISUAIS de um tipo.
    // Slots global-only (sem conteúdo visual) não contribuem para a duração da tela.
    getTotalSlotsDuration(type) {
        const visualSlots = this._getVisualSlots(type);
        if (visualSlots.length === 0) return 0;

        let total = 0;
        visualSlots.forEach(slot => {
            total += parseInt(slot.duration_seconds) || 5;
        });
        return total;
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
