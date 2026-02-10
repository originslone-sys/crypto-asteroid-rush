/* ============================================
   UNOBIX - Notification System v8.0
   File: js/notification-system.js
   
   v8.0 CHANGES:
   - Notificações in-game MINIMAL (compactas, não atrapalham gameplay)
   - Toasts finos no canto superior, auto-dismiss rápido
   - Modais e banners mantidos para fora do jogo
   - Game notifications são inline, sem overlay
   ============================================ */

const NotificationSystem = {
    container: null,
    activeToasts: [],
    activeModal: null,
    maxToasts: 3,
    
    init() {
        if (this.container) return;
        
        this.container = document.createElement('div');
        this.container.id = 'unobix-notifications';
        this.container.style.cssText = `
            position: fixed;
            top: 12px;
            right: 12px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-width: 260px;
            width: auto;
            pointer-events: none;
        `;
        document.body.appendChild(this.container);
        
        if (!document.getElementById('unobix-notif-styles')) {
            const style = document.createElement('style');
            style.id = 'unobix-notif-styles';
            style.textContent = `
                /* ==============================
                   TOAST — Compact minimal style
                   ============================== */
                .unobix-toast {
                    background: rgba(12, 12, 20, 0.88);
                    backdrop-filter: blur(8px);
                    -webkit-backdrop-filter: blur(8px);
                    border-radius: 8px;
                    padding: 8px 12px;
                    color: #eee;
                    font-family: 'Segoe UI', system-ui, sans-serif;
                    font-size: 12px;
                    box-shadow: 0 2px 12px rgba(0,0,0,0.4), inset 0 0 0 1px rgba(255,255,255,0.06);
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    pointer-events: all;
                    animation: ux-slideIn 0.25s cubic-bezier(0.22,1,0.36,1);
                    position: relative;
                    overflow: hidden;
                    max-width: 260px;
                    border-left: 3px solid #555;
                }
                .unobix-toast.success { border-left-color: #34d399; }
                .unobix-toast.error   { border-left-color: #f87171; }
                .unobix-toast.warning { border-left-color: #fbbf24; }
                .unobix-toast.info    { border-left-color: #60a5fa; }
                
                .unobix-toast-icon {
                    font-size: 14px;
                    flex-shrink: 0;
                    line-height: 1;
                }
                .unobix-toast.success .unobix-toast-icon { color: #34d399; }
                .unobix-toast.error   .unobix-toast-icon { color: #f87171; }
                .unobix-toast.warning .unobix-toast-icon { color: #fbbf24; }
                .unobix-toast.info    .unobix-toast-icon { color: #60a5fa; }
                
                .unobix-toast-content {
                    flex: 1;
                    min-width: 0;
                }
                .unobix-toast-title {
                    font-weight: 600;
                    font-size: 11px;
                    letter-spacing: 0.03em;
                    text-transform: uppercase;
                    opacity: 0.85;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .unobix-toast-message {
                    font-size: 12px;
                    color: #ccc;
                    line-height: 1.3;
                    margin-top: 1px;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                
                .unobix-toast-close {
                    background: none;
                    border: none;
                    color: #666;
                    cursor: pointer;
                    font-size: 14px;
                    padding: 0;
                    line-height: 1;
                    flex-shrink: 0;
                    opacity: 0;
                    transition: opacity 0.15s;
                }
                .unobix-toast:hover .unobix-toast-close { opacity: 1; }
                .unobix-toast-close:hover { color: #fff; }
                
                .unobix-toast-progress {
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    height: 2px;
                    background: rgba(255,255,255,0.15);
                    animation: ux-progress linear forwards;
                }
                
                .unobix-toast.removing {
                    animation: ux-slideOut 0.2s cubic-bezier(0.55,0,1,0.45) forwards;
                }
                
                @keyframes ux-slideIn {
                    from { transform: translateX(40px); opacity: 0; }
                    to   { transform: translateX(0); opacity: 1; }
                }
                @keyframes ux-slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to   { transform: translateX(40px); opacity: 0; }
                }
                @keyframes ux-progress {
                    from { width: 100%; }
                    to   { width: 0%; }
                }
                
                /* ==============================
                   MODAL — Clean, minimal
                   ============================== */
                .unobix-modal-overlay {
                    position: fixed;
                    top: 0; left: 0; right: 0; bottom: 0;
                    background: rgba(0,0,0,0.65);
                    z-index: 100000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    animation: ux-fadeIn 0.2s ease;
                }
                .unobix-modal {
                    background: #141420;
                    border: 1px solid rgba(255,255,255,0.08);
                    border-radius: 12px;
                    padding: 24px;
                    max-width: 380px;
                    width: 90%;
                    color: #fff;
                    text-align: center;
                    box-shadow: 0 12px 48px rgba(0,0,0,0.5);
                    animation: ux-scaleIn 0.25s cubic-bezier(0.22,1,0.36,1);
                }
                .unobix-modal-icon {
                    font-size: 36px;
                    margin-bottom: 12px;
                }
                .unobix-modal-title {
                    font-size: 16px;
                    font-weight: 700;
                    margin-bottom: 8px;
                    letter-spacing: 0.02em;
                }
                .unobix-modal-message {
                    font-size: 13px;
                    color: #aaa;
                    line-height: 1.5;
                    margin-bottom: 20px;
                }
                .unobix-modal-btn {
                    padding: 10px 28px;
                    border: none;
                    border-radius: 6px;
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.15s;
                }
                .unobix-modal-btn.primary {
                    background: #6C63FF;
                    color: #fff;
                }
                .unobix-modal-btn.primary:hover { background: #5a52d5; }
                .unobix-modal-btn.danger {
                    background: #ef4444;
                    color: #fff;
                }
                
                /* ==============================
                   BANNER — Slim top bar
                   ============================== */
                .unobix-banner {
                    position: fixed;
                    top: 0; left: 0; right: 0;
                    z-index: 99998;
                    padding: 8px 16px;
                    text-align: center;
                    font-size: 12px;
                    font-weight: 600;
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    animation: ux-slideDown 0.3s ease;
                }
                .unobix-banner.warning { background: #b45309; }
                .unobix-banner.error   { background: #991b1b; }
                .unobix-banner.info    { background: #1e40af; }
                .unobix-banner .close-btn {
                    position: absolute;
                    right: 12px;
                    background: none;
                    border: none;
                    color: #fff;
                    font-size: 16px;
                    cursor: pointer;
                    opacity: 0.6;
                }
                .unobix-banner .close-btn:hover { opacity: 1; }
                
                @keyframes ux-fadeIn { from { opacity: 0; } to { opacity: 1; } }
                @keyframes ux-scaleIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
                @keyframes ux-slideDown { from { transform: translateY(-100%); } to { transform: translateY(0); } }
                
                .unobix-countdown {
                    font-size: 20px;
                    font-weight: 700;
                    color: #fbbf24;
                    font-variant-numeric: tabular-nums;
                }
                
                /* Mobile: even more compact */
                @media (max-width: 480px) {
                    #unobix-notifications {
                        max-width: 200px !important;
                        top: 8px !important;
                        right: 8px !important;
                        gap: 4px !important;
                    }
                    .unobix-toast {
                        padding: 6px 10px;
                        font-size: 11px;
                        max-width: 200px;
                    }
                    .unobix-toast-icon { font-size: 12px; }
                    .unobix-toast-title { font-size: 10px; }
                    .unobix-toast-message { font-size: 11px; }
                }
            `;
            document.head.appendChild(style);
        }
        
        console.log('🔔 NotificationSystem v8.0 inicializado (minimal)');
    },
    
    // ============================================
    // TOASTS — Compact, fast dismiss
    // ============================================
    toast(title, message, type = 'info', duration = 3000) {
        this.init();
        
        while (this.activeToasts.length >= this.maxToasts) {
            this.removeToast(this.activeToasts[0]);
        }
        
        const icons = {
            success: '✓',
            error: '✗',
            warning: '!',
            info: 'i'
        };
        
        const toast = document.createElement('div');
        toast.className = `unobix-toast ${type}`;
        toast.innerHTML = `
            <span class="unobix-toast-icon">${icons[type] || icons.info}</span>
            <div class="unobix-toast-content">
                <div class="unobix-toast-title">${this._escape(title)}</div>
                ${message ? `<div class="unobix-toast-message">${this._escape(message)}</div>` : ''}
            </div>
            <button class="unobix-toast-close" onclick="NotificationSystem.removeToast(this.parentElement)">&times;</button>
            <div class="unobix-toast-progress" style="animation-duration: ${duration}ms;"></div>
        `;
        
        this.container.appendChild(toast);
        this.activeToasts.push(toast);
        
        const timeoutId = setTimeout(() => this.removeToast(toast), duration);
        toast._timeoutId = timeoutId;
        
        return toast;
    },
    
    removeToast(toast) {
        if (!toast || !toast.parentElement) return;
        if (toast._timeoutId) clearTimeout(toast._timeoutId);
        
        toast.classList.add('removing');
        setTimeout(() => {
            if (toast.parentElement) toast.parentElement.removeChild(toast);
            this.activeToasts = this.activeToasts.filter(t => t !== toast);
        }, 200);
    },
    
    // Atalhos — durations curtas para gameplay
    success(title, message, duration = 2500) { return this.toast(title, message, 'success', duration); },
    error(title, message, duration = 4000) { return this.toast(title, message, 'error', duration); },
    warning(title, message, duration = 3000) { return this.toast(title, message, 'warning', duration); },
    info(title, message, duration = 2500) { return this.toast(title, message, 'info', duration); },
    
    // ============================================
    // MODAIS
    // ============================================
    modal(title, message, options = {}) {
        this.init();
        this.closeModal();
        
        const icon = options.icon || '⚠️';
        const btnText = options.btnText || 'Entendi';
        const btnClass = options.btnClass || 'primary';
        const onClose = options.onClose || null;
        
        const overlay = document.createElement('div');
        overlay.className = 'unobix-modal-overlay';
        overlay.innerHTML = `
            <div class="unobix-modal">
                <div class="unobix-modal-icon">${icon}</div>
                <div class="unobix-modal-title">${this._escape(title)}</div>
                <div class="unobix-modal-message">${this._escape(message)}</div>
                <button class="unobix-modal-btn ${btnClass}" id="unobixModalBtn">${this._escape(btnText)}</button>
            </div>
        `;
        
        document.body.appendChild(overlay);
        this.activeModal = overlay;
        
        const btn = overlay.querySelector('#unobixModalBtn');
        btn.addEventListener('click', () => {
            this.closeModal();
            if (onClose) onClose();
        });
        
        if (options.dismissable !== false) {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    this.closeModal();
                    if (onClose) onClose();
                }
            });
        }
        
        return overlay;
    },
    
    closeModal() {
        if (this.activeModal && this.activeModal.parentElement) {
            this.activeModal.parentElement.removeChild(this.activeModal);
        }
        this.activeModal = null;
    },
    
    // ============================================
    // BANNERS
    // ============================================
    banner(message, type = 'warning', duration = 0) {
        this.init();
        this.closeBanner();
        
        const banner = document.createElement('div');
        banner.className = `unobix-banner ${type}`;
        banner.id = 'unobix-active-banner';
        banner.innerHTML = `
            <span>${this._escape(message)}</span>
            <button class="close-btn" onclick="NotificationSystem.closeBanner()">&times;</button>
        `;
        
        document.body.appendChild(banner);
        
        if (duration > 0) {
            setTimeout(() => this.closeBanner(), duration);
        }
        
        return banner;
    },
    
    closeBanner() {
        const banner = document.getElementById('unobix-active-banner');
        if (banner) banner.remove();
    },
    
    // ============================================
    // AVISOS ESPECÍFICOS DO JOGO
    // ============================================
    banned(reason = '') {
        const message = reason || 'Sua conta foi suspensa por violação dos termos de uso. Entre em contato com o suporte se acredita que houve um erro.';
        this.modal('Conta Suspensa', message, {
            icon: '🚫',
            btnText: 'Entendi',
            btnClass: 'danger',
            dismissable: false,
            onClose: () => {
                if (typeof window.authManager !== 'undefined') {
                    window.authManager.signOut().catch(() => {});
                }
            }
        });
    },
    
    rateLimit(waitSeconds) {
        this.modal(
            'Aguarde um momento',
            'Você está jogando muito rápido! Aguarde antes de iniciar uma nova missão.',
            {
                icon: '⏳',
                btnText: 'OK',
                btnClass: 'primary',
                onClose: () => {}
            }
        );
        
        if (this.activeModal) {
            const modalContent = this.activeModal.querySelector('.unobix-modal-message');
            if (modalContent) {
                let remaining = waitSeconds;
                const countdownEl = document.createElement('div');
                countdownEl.className = 'unobix-countdown';
                countdownEl.style.marginTop = '8px';
                modalContent.appendChild(countdownEl);
                
                const updateCountdown = () => {
                    const min = Math.floor(remaining / 60);
                    const sec = remaining % 60;
                    countdownEl.textContent = `${min}:${sec.toString().padStart(2, '0')}`;
                    
                    if (remaining <= 0) {
                        clearInterval(intervalId);
                        countdownEl.textContent = 'Pronto!';
                        countdownEl.style.color = '#34d399';
                        
                        const btn = this.activeModal?.querySelector('.unobix-modal-btn');
                        if (btn) {
                            btn.textContent = 'Jogar Agora';
                            btn.style.background = '#34d399';
                        }
                    }
                    remaining--;
                };
                
                updateCountdown();
                const intervalId = setInterval(updateCountdown, 1000);
            }
        }
    },
    
    flagged() {
        this.warning('Sessão em Revisão', 'Os ganhos serão creditados após análise.');
    },
    
    maintenance(message = '') {
        this.banner(
            message || '🔧 Sistema em manutenção. Algumas funcionalidades podem estar indisponíveis.',
            'info'
        );
    },
    
    // ============================================
    // HELPERS
    // ============================================
    _escape(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};

// Auto-inicializar
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => NotificationSystem.init());
} else {
    NotificationSystem.init();
}

window.NotificationSystem = NotificationSystem;

// Compatibilidade com showNotification global
window.showNotification = function(title, message, isSuccess = true) {
    if (isSuccess) {
        NotificationSystem.success(title, message);
    } else {
        NotificationSystem.error(title, message);
    }
};

console.log('🔔 NotificationSystem v8.0 carregado (minimal)');
