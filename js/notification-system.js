/* ============================================
   UNOBIX - Notification System v7.0
   File: js/notification-system.js
   Toasts, modais e banners nativos
   ============================================ */

const NotificationSystem = {
    container: null,
    activeToasts: [],
    activeModal: null,
    maxToasts: 3,
    
    /**
     * Inicializar container de notificações
     */
    init() {
        if (this.container) return;
        
        // Container de toasts (canto superior direito)
        this.container = document.createElement('div');
        this.container.id = 'unobix-notifications';
        this.container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 380px;
            width: 100%;
            pointer-events: none;
        `;
        document.body.appendChild(this.container);
        
        // Injetar CSS
        if (!document.getElementById('unobix-notif-styles')) {
            const style = document.createElement('style');
            style.id = 'unobix-notif-styles';
            style.textContent = `
                .unobix-toast {
                    background: #1a1a2e;
                    border-radius: 12px;
                    padding: 16px 20px;
                    color: #fff;
                    font-family: 'Segoe UI', sans-serif;
                    font-size: 14px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
                    display: flex;
                    align-items: flex-start;
                    gap: 12px;
                    pointer-events: all;
                    animation: unobix-slideIn 0.3s ease-out;
                    border-left: 4px solid #666;
                    position: relative;
                    overflow: hidden;
                }
                .unobix-toast.success { border-left-color: #4CAF50; }
                .unobix-toast.error { border-left-color: #f44336; }
                .unobix-toast.warning { border-left-color: #FF9800; }
                .unobix-toast.info { border-left-color: #2196F3; }
                
                .unobix-toast-icon {
                    font-size: 20px;
                    flex-shrink: 0;
                    margin-top: 2px;
                }
                .unobix-toast.success .unobix-toast-icon { color: #4CAF50; }
                .unobix-toast.error .unobix-toast-icon { color: #f44336; }
                .unobix-toast.warning .unobix-toast-icon { color: #FF9800; }
                .unobix-toast.info .unobix-toast-icon { color: #2196F3; }
                
                .unobix-toast-content { flex: 1; }
                .unobix-toast-title {
                    font-weight: 600;
                    margin-bottom: 4px;
                    font-size: 14px;
                }
                .unobix-toast-message {
                    font-size: 13px;
                    color: #ccc;
                    line-height: 1.4;
                }
                
                .unobix-toast-close {
                    background: none;
                    border: none;
                    color: #888;
                    cursor: pointer;
                    font-size: 18px;
                    padding: 0;
                    line-height: 1;
                    flex-shrink: 0;
                }
                .unobix-toast-close:hover { color: #fff; }
                
                .unobix-toast-progress {
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    height: 3px;
                    background: rgba(255,255,255,0.3);
                    animation: unobix-progress linear forwards;
                }
                
                .unobix-toast.removing {
                    animation: unobix-slideOut 0.3s ease-in forwards;
                }
                
                @keyframes unobix-slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes unobix-slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
                @keyframes unobix-progress {
                    from { width: 100%; }
                    to { width: 0%; }
                }
                
                /* Modal Overlay */
                .unobix-modal-overlay {
                    position: fixed;
                    top: 0; left: 0; right: 0; bottom: 0;
                    background: rgba(0,0,0,0.7);
                    z-index: 100000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    animation: unobix-fadeIn 0.2s ease;
                }
                .unobix-modal {
                    background: #1a1a2e;
                    border-radius: 16px;
                    padding: 30px;
                    max-width: 420px;
                    width: 90%;
                    color: #fff;
                    text-align: center;
                    box-shadow: 0 16px 64px rgba(0,0,0,0.5);
                    animation: unobix-scaleIn 0.3s ease;
                }
                .unobix-modal-icon {
                    font-size: 48px;
                    margin-bottom: 16px;
                }
                .unobix-modal-title {
                    font-size: 20px;
                    font-weight: 700;
                    margin-bottom: 12px;
                }
                .unobix-modal-message {
                    font-size: 14px;
                    color: #ccc;
                    line-height: 1.6;
                    margin-bottom: 24px;
                }
                .unobix-modal-btn {
                    padding: 12px 32px;
                    border: none;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s;
                }
                .unobix-modal-btn.primary {
                    background: #6C63FF;
                    color: #fff;
                }
                .unobix-modal-btn.primary:hover { background: #5a52d5; }
                .unobix-modal-btn.danger {
                    background: #f44336;
                    color: #fff;
                }
                
                /* Banner (topo da tela) */
                .unobix-banner {
                    position: fixed;
                    top: 0; left: 0; right: 0;
                    z-index: 99998;
                    padding: 12px 20px;
                    text-align: center;
                    font-size: 14px;
                    font-weight: 600;
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                    animation: unobix-slideDown 0.3s ease;
                }
                .unobix-banner.warning { background: #E65100; }
                .unobix-banner.error { background: #B71C1C; }
                .unobix-banner.info { background: #0D47A1; }
                .unobix-banner .close-btn {
                    position: absolute;
                    right: 16px;
                    background: none;
                    border: none;
                    color: #fff;
                    font-size: 20px;
                    cursor: pointer;
                    opacity: 0.7;
                }
                .unobix-banner .close-btn:hover { opacity: 1; }
                
                @keyframes unobix-fadeIn { from { opacity: 0; } to { opacity: 1; } }
                @keyframes unobix-scaleIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
                @keyframes unobix-slideDown { from { transform: translateY(-100%); } to { transform: translateY(0); } }
                
                /* Rate limit countdown */
                .unobix-countdown {
                    font-size: 24px;
                    font-weight: 700;
                    color: #FF9800;
                    font-variant-numeric: tabular-nums;
                }
            `;
            document.head.appendChild(style);
        }
        
        console.log('🔔 NotificationSystem inicializado');
    },
    
    // ============================================
    // TOASTS
    // ============================================
    
    /**
     * Mostrar toast genérico
     */
    toast(title, message, type = 'info', duration = 5000) {
        this.init();
        
        // Limitar número de toasts
        while (this.activeToasts.length >= this.maxToasts) {
            this.removeToast(this.activeToasts[0]);
        }
        
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
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
        
        // Auto-remover
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
        }, 300);
    },
    
    // Atalhos
    success(title, message, duration = 4000) { return this.toast(title, message, 'success', duration); },
    error(title, message, duration = 6000) { return this.toast(title, message, 'error', duration); },
    warning(title, message, duration = 5000) { return this.toast(title, message, 'warning', duration); },
    info(title, message, duration = 4000) { return this.toast(title, message, 'info', duration); },
    
    // ============================================
    // MODAIS
    // ============================================
    
    /**
     * Mostrar modal
     */
    modal(title, message, options = {}) {
        this.init();
        this.closeModal(); // Fechar modal anterior
        
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
        
        // Fechar clicando fora (se permitido)
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
    
    /**
     * Mostrar banner no topo
     */
    banner(message, type = 'warning', duration = 0) {
        this.init();
        this.closeBanner(); // Remover banner anterior
        
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
    
    /**
     * Aviso de conta banida — modal não dispensável
     */
    banned(reason = '') {
        const message = reason || 'Sua conta foi suspensa por violação dos termos de uso. Entre em contato com o suporte se acredita que houve um erro.';
        
        this.modal('Conta Suspensa', message, {
            icon: '🚫',
            btnText: 'Entendi',
            btnClass: 'danger',
            dismissable: false,
            onClose: () => {
                // Redirecionar ou deslogar
                if (typeof window.authManager !== 'undefined') {
                    window.authManager.signOut().catch(() => {});
                }
            }
        });
    },
    
    /**
     * Aviso de rate limit com countdown
     */
    rateLimit(waitSeconds) {
        const minutes = Math.ceil(waitSeconds / 60);
        
        this.modal(
            'Aguarde um momento',
            `Você está jogando muito rápido! Aguarde antes de iniciar uma nova missão.`,
            {
                icon: '⏳',
                btnText: 'OK',
                btnClass: 'primary',
                onClose: () => {}
            }
        );
        
        // Adicionar countdown ao modal
        if (this.activeModal) {
            const modalContent = this.activeModal.querySelector('.unobix-modal-message');
            if (modalContent) {
                let remaining = waitSeconds;
                const countdownEl = document.createElement('div');
                countdownEl.className = 'unobix-countdown';
                countdownEl.style.marginTop = '12px';
                modalContent.appendChild(countdownEl);
                
                const updateCountdown = () => {
                    const min = Math.floor(remaining / 60);
                    const sec = remaining % 60;
                    countdownEl.textContent = `${min}:${sec.toString().padStart(2, '0')}`;
                    
                    if (remaining <= 0) {
                        clearInterval(intervalId);
                        countdownEl.textContent = 'Pronto!';
                        countdownEl.style.color = '#4CAF50';
                        
                        const btn = this.activeModal?.querySelector('.unobix-modal-btn');
                        if (btn) {
                            btn.textContent = 'Jogar Agora';
                            btn.classList.remove('primary');
                            btn.classList.add('primary');
                            btn.style.background = '#4CAF50';
                        }
                    }
                    remaining--;
                };
                
                updateCountdown();
                const intervalId = setInterval(updateCountdown, 1000);
            }
        }
    },
    
    /**
     * Aviso de sessão flagged
     */
    flagged() {
        this.warning(
            'Sessão em Revisão',
            'Esta sessão foi marcada para análise. Os ganhos serão creditados após revisão.'
        );
    },
    
    /**
     * Aviso de manutenção
     */
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

// Compatibilidade com função showNotification global (usada no SessionManager antigo)
window.showNotification = function(title, message, isSuccess = true) {
    if (isSuccess) {
        NotificationSystem.success(title, message);
    } else {
        NotificationSystem.error(title, message);
    }
};

console.log('🔔 NotificationSystem v7.0 carregado');
