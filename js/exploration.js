/* ============================================
   UNOBIX - Exploration System v1.0
   js/exploration.js
   Aluguel de naves para coleta passiva de créditos
   ============================================ */

(function() {
    'use strict';

    const API_URL = 'api/exploration.php';
    let shipsData = [];
    let rentalsData = [];
    let countdownTimers = [];

    // Cores das naves (mesmo do game-ships.js)
    const SHIP_COLORS = {
        PHOENIX:  { primary: '#cc2233', secondary: '#ffcc00', accent: '#ff6644', cockpit: '#44aaff', engine: '#ff4400' },
        GUARDIAN: { primary: '#2d5a27', secondary: '#8b6914', accent: '#4a7c3f', cockpit: '#66ffaa', engine: '#88cc44' },
        THUNDER:  { primary: '#1a4a8a', secondary: '#00d4ff', accent: '#4488ff', cockpit: '#00ffff', engine: '#00ccff' },
        INFERNO:  { primary: '#cc4400', secondary: '#ff2200', accent: '#ffaa00', cockpit: '#ffcc44', engine: '#ff6600' },
        NEBULA:   { primary: '#4a1a6b', secondary: '#8844cc', accent: '#cc44ff', cockpit: '#ff44ff', engine: '#aa22ff' },
        VIPER:    { primary: '#1a4a2a', secondary: '#00ff66', accent: '#88ff44', cockpit: '#00ff88', engine: '#44ff00' },
        WOLF:     { primary: '#3a3a4a', secondary: '#6a6a7a', accent: '#8a8a9a', cockpit: '#88aaff', engine: '#6688ff' }
    };

    function getShipSVG(key, size) {
        const c = SHIP_COLORS[key] || SHIP_COLORS.PHOENIX;
        const uid = 'sv_' + key + '_' + Math.random().toString(36).substr(2, 5);
        return `<svg width="${size}" height="${size}" viewBox="-55 -45 110 100">
            <defs>
                <linearGradient id="${uid}_body" x1="0" y1="-38" x2="0" y2="28" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stop-color="${c.secondary}" stop-opacity="0.6"/>
                    <stop offset="30%" stop-color="${c.primary}"/>
                    <stop offset="100%" stop-color="${c.primary}" stop-opacity="0.7"/>
                </linearGradient>
                <linearGradient id="${uid}_wing" x1="0" y1="0" x2="48" y2="15" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stop-color="${c.primary}"/>
                    <stop offset="100%" stop-color="${c.primary}" stop-opacity="0.5"/>
                </linearGradient>
                <linearGradient id="${uid}_flame" x1="0" y1="32" x2="0" y2="55" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stop-color="#ffffff"/>
                    <stop offset="20%" stop-color="${c.engine}"/>
                    <stop offset="60%" stop-color="#ff4400" stop-opacity="0.6"/>
                    <stop offset="100%" stop-color="#ff2200" stop-opacity="0"/>
                </linearGradient>
                <radialGradient id="${uid}_glow" cx="50%" cy="0%">
                    <stop offset="0%" stop-color="${c.engine}" stop-opacity="0.4"/>
                    <stop offset="100%" stop-color="${c.engine}" stop-opacity="0"/>
                </radialGradient>
                <linearGradient id="${uid}_glass" x1="-8" y1="-28" x2="8" y2="-10" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stop-color="#ffffff" stop-opacity="0.6"/>
                    <stop offset="30%" stop-color="${c.cockpit}" stop-opacity="0.7"/>
                    <stop offset="100%" stop-color="${c.cockpit}" stop-opacity="0.4"/>
                </linearGradient>
            </defs>
            <!-- Engine glow -->
            <ellipse cx="-10" cy="40" rx="8" ry="16" fill="url(#${uid}_glow)"/>
            <ellipse cx="10" cy="40" rx="8" ry="16" fill="url(#${uid}_glow)"/>
            <!-- Engine flames -->
            <path d="M-12,32 Q-14,42 -10,52 Q-8,42 -8,32 Z" fill="url(#${uid}_flame)"/>
            <path d="M8,32 Q8,42 10,52 Q14,42 12,32 Z" fill="url(#${uid}_flame)"/>
            <!-- Engine housings -->
            <rect x="-15" y="20" width="10" height="12" rx="1" fill="#1a1a24" stroke="#5a5a6a" stroke-width="0.8"/>
            <rect x="5" y="20" width="10" height="12" rx="1" fill="#1a1a24" stroke="#5a5a6a" stroke-width="0.8"/>
            <!-- Wings -->
            <polygon points="8,5 48,-5 42,20 8,25" fill="url(#${uid}_wing)" stroke="${c.secondary}" stroke-width="0.6" opacity="0.9"/>
            <polygon points="-8,5 -48,-5 -42,20 -8,25" fill="url(#${uid}_wing)" stroke="${c.secondary}" stroke-width="0.6" opacity="0.9"/>
            <!-- Wing detail lines -->
            <line x1="15" y1="6" x2="40" y2="0" stroke="${c.secondary}" stroke-width="0.4" opacity="0.4"/>
            <line x1="-15" y1="6" x2="-40" y2="0" stroke="${c.secondary}" stroke-width="0.4" opacity="0.4"/>
            <!-- Wing tip thrusters -->
            <ellipse cx="46" cy="0" rx="3" ry="6" fill="${c.secondary}" opacity="0.7"/>
            <ellipse cx="-46" cy="0" rx="3" ry="6" fill="${c.secondary}" opacity="0.7"/>
            <circle cx="38" cy="18" r="3" fill="${c.engine}" opacity="0.5"/>
            <circle cx="-38" cy="18" r="3" fill="${c.engine}" opacity="0.5"/>
            <!-- Fuselage (curved body) -->
            <path d="M0,-38 C8,-32 14,-20 15,-5 C16,10 14,22 8,28 L-8,28 C-14,22 -16,10 -15,-5 C-14,-20 -8,-32 0,-38 Z" fill="url(#${uid}_body)"/>
            <!-- Fuselage highlight -->
            <path d="M-6,-35 Q0,-38 6,-35" stroke="${c.secondary}" stroke-width="1" fill="none" opacity="0.5"/>
            <!-- Center ridge -->
            <path d="M0,-32 L3,-25 L3,15 L0,22 L-3,15 L-3,-25 Z" fill="${c.secondary}" opacity="0.5"/>
            <!-- Hull panel lines -->
            <line x1="-12" y1="-20" x2="12" y2="-20" stroke="${c.primary}" stroke-width="0.4" opacity="0.3"/>
            <line x1="-12" y1="-10" x2="12" y2="-10" stroke="${c.primary}" stroke-width="0.4" opacity="0.3"/>
            <line x1="-12" y1="5" x2="12" y2="5" stroke="${c.primary}" stroke-width="0.4" opacity="0.3"/>
            <line x1="-12" y1="15" x2="12" y2="15" stroke="${c.primary}" stroke-width="0.4" opacity="0.3"/>
            <!-- Hull vents -->
            <rect x="-11" y="-15" width="3" height="8" fill="#1a1a24" opacity="0.6"/>
            <rect x="8" y="-15" width="3" height="8" fill="#1a1a24" opacity="0.6"/>
            <!-- Cockpit frame -->
            <polygon points="0,-30 10,-18 8,-8 -8,-8 -10,-18" fill="#3a3a4a"/>
            <!-- Cockpit glass -->
            <polygon points="0,-28 8,-17 6,-10 -6,-10 -8,-17" fill="url(#${uid}_glass)"/>
            <!-- Cockpit reflection -->
            <polygon points="-3,-26 3,-26 5,-20 -2,-20" fill="white" opacity="0.2"/>
            <!-- Cockpit HUD dots -->
            <rect x="-4" y="-18" width="2" height="1" fill="#00ff88" opacity="0.4"/>
            <rect x="1" y="-16" width="3" height="1" fill="#00ff88" opacity="0.3"/>
            <!-- Weapon pods -->
            <rect x="-19" y="-22" width="6" height="14" rx="2" fill="#2a2a35" stroke="#4a4a5a" stroke-width="0.5"/>
            <rect x="13" y="-22" width="6" height="14" rx="2" fill="#2a2a35" stroke="#4a4a5a" stroke-width="0.5"/>
            <!-- Weapon barrels -->
            <rect x="-18" y="-34" width="4" height="14" rx="1" fill="#3a3a4a"/>
            <rect x="14" y="-34" width="4" height="14" rx="1" fill="#3a3a4a"/>
            <!-- Barrel tips -->
            <circle cx="-16" cy="-34" r="2" fill="#1a1a22"/>
            <circle cx="16" cy="-34" r="2" fill="#1a1a22"/>
            <circle cx="-16" cy="-34" r="1" fill="${c.accent}" opacity="0.8"/>
            <circle cx="16" cy="-34" r="1" fill="${c.accent}" opacity="0.8"/>
            <!-- Nav lights -->
            <circle cx="42" cy="-2" r="1.5" fill="#00ff00" opacity="0.7"/>
            <circle cx="-42" cy="-2" r="1.5" fill="#ff0000" opacity="0.7"/>
            <circle cx="0" cy="-36" r="1.2" fill="white" opacity="0.8"/>
            <circle cx="0" cy="26" r="1.2" fill="#ffaa00" opacity="0.7"/>
        </svg>`;
    }

    function getGoogleUid() {
        return localStorage.getItem('googleUid') || '';
    }

    async function apiCall(action, data = {}) {
        data.action = action;
        data.google_uid = getGoogleUid();
        const resp = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return resp.json();
    }

    function showToast(msg, type) {
        const t = document.getElementById('toast');
        if (!t) return;
        t.textContent = msg;
        t.style.display = 'block';
        t.style.background = type === 'error' ? 'rgba(255,71,87,0.95)' : 'rgba(0,255,136,0.95)';
        t.style.color = type === 'error' ? '#fff' : '#000';
        setTimeout(() => { t.style.display = 'none'; }, 3500);
    }

    function formatBRL(val) {
        return 'R$ ' + parseFloat(val).toFixed(2).replace('.', ',');
    }

    function formatDuration(hours) {
        if (hours < 24) return hours + 'h';
        const days = Math.floor(hours / 24);
        const rem = hours % 24;
        return rem > 0 ? days + 'd ' + rem + 'h' : days + 'd';
    }

    function formatCountdown(seconds) {
        if (seconds <= 0) return 'Expirado';
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        if (d > 0) return d + 'd ' + h + 'h ' + m + 'm';
        if (h > 0) return h + 'h ' + m + 'm';
        return m + 'm';
    }

    // ============================================
    // RENDERIZAR NAVES DISPONÍVEIS
    // ============================================
    function renderShips(ships, activeRentals, maxRentals, balance) {
        const grid = document.getElementById('shipsGrid');
        if (!ships.length) {
            grid.innerHTML = '<div class="empty-rentals" style="grid-column:1/-1;"><i class="fas fa-rocket"></i><p>Nenhuma nave disponível no momento</p></div>';
            return;
        }

        grid.innerHTML = ships.map(ship => {
            const isRented = ship.is_rented;
            const canRent = !isRented && activeRentals < maxRentals;
            const reason = isRented ? 'Já alugada' :
                           activeRentals >= maxRentals ? 'Limite atingido' : '';

            return `
                <div class="ship-card ${isRented ? 'rented' : ''}">
                    ${isRented ? '<span class="rented-badge"><i class="fas fa-check"></i> ATIVA</span>' : ''}
                    <div class="ship-visual">${getShipSVG(ship.ship_key, 120)}</div>
                    <div class="ship-name">${escapeHtml(ship.name)}</div>
                    <div class="ship-desc">${escapeHtml(ship.description || '')}</div>
                    <div class="ship-stats">
                        <div class="stat"><div class="stat-val">${ship.credits_per_day}</div><div class="stat-lbl">créditos/dia</div></div>
                        <div class="stat"><div class="stat-val">${formatDuration(ship.rental_duration_hours)}</div><div class="stat-lbl">duração</div></div>
                        <div class="stat"><div class="stat-val">${formatBRL(ship.rental_price_brl)}</div><div class="stat-lbl">preço</div></div>
                    </div>
                    ${isRented ? '<button class="rent-btn disabled" disabled>Em exploração</button>' :
                      canRent ? `<button class="rent-btn available" onclick="window._rentShip(${ship.id})"><i class="fas fa-qrcode"></i> Pagar PIX ${formatBRL(ship.rental_price_brl)}</button>` :
                      `<button class="rent-btn disabled" disabled>${reason}</button>`}
                </div>`;
        }).join('');
    }

    // ============================================
    // RENDERIZAR ALUGUÉIS ATIVOS
    // ============================================
    function renderRentals(rentals) {
        const section = document.getElementById('rentalsSection');
        const list = document.getElementById('rentalsList');

        // Limpar timers anteriores
        countdownTimers.forEach(t => clearInterval(t));
        countdownTimers = [];

        const activeRentals = rentals.filter(r => r.status === 'active' || r.unclaimed > 0);

        if (!activeRentals.length) {
            section.style.display = 'none';
            return;
        }

        section.style.display = 'block';
        list.innerHTML = activeRentals.map(r => {
            const isExpired = r.is_expired;
            return `
                <div class="rental-card ${isExpired ? 'expired' : 'active'}">
                    <div class="rental-ship">${getShipSVG(r.ship_key, 60)}</div>
                    <div class="rental-info">
                        <div class="rental-name">${escapeHtml(r.ship_name)}</div>
                        <div class="rental-timer ${isExpired ? 'expired-text' : ''}" id="timer_${r.id}">
                            ${isExpired ? '<i class="fas fa-flag-checkered"></i> Expirado' : '<i class="fas fa-clock"></i> Calculando...'}
                        </div>
                        <div style="font-size:0.7rem;color:#667788;margin-top:2px;">${r.credits_per_day} créditos/dia</div>
                    </div>
                    <div class="rental-credits">
                        <div class="credits-value" id="credits_${r.id}">${r.unclaimed}</div>
                        <div class="credits-label">créditos disponíveis</div>
                    </div>
                    <button class="claim-btn" id="claim_${r.id}" onclick="window._claimCredits(${r.id})" ${r.unclaimed <= 0 ? 'disabled' : ''}>
                        <i class="fas fa-coins"></i> Resgatar
                    </button>
                </div>`;
        }).join('');

        // Iniciar countdowns
        activeRentals.forEach(r => {
            if (!r.is_expired) {
                const expiresAt = new Date(r.expires_at.replace(' ', 'T')).getTime();
                const startedAt = new Date(r.started_at.replace(' ', 'T')).getTime();
                const creditsPerDay = r.credits_per_day;
                const claimed = r.credits_claimed;

                function updateTimer() {
                    const now = Date.now();
                    const remaining = Math.floor((expiresAt - now) / 1000);
                    const timerEl = document.getElementById('timer_' + r.id);
                    const creditsEl = document.getElementById('credits_' + r.id);
                    const claimBtn = document.getElementById('claim_' + r.id);

                    if (timerEl) {
                        if (remaining <= 0) {
                            timerEl.innerHTML = '<i class="fas fa-flag-checkered"></i> Expirado';
                            timerEl.className = 'rental-timer expired-text';
                        } else {
                            timerEl.innerHTML = '<i class="fas fa-clock"></i> ' + formatCountdown(remaining);
                        }
                    }

                    // Atualizar créditos em tempo real
                    if (creditsEl) {
                        const effectiveEnd = Math.min(now, expiresAt);
                        const elapsed = Math.max(0, effectiveEnd - startedAt) / 1000;
                        const total = Math.floor((elapsed / 86400) * creditsPerDay);
                        const unclaimed = Math.max(0, total - claimed);
                        creditsEl.textContent = unclaimed;
                        if (claimBtn) claimBtn.disabled = unclaimed <= 0;
                    }
                }

                updateTimer();
                const timer = setInterval(updateTimer, 30000); // Atualizar a cada 30s
                countdownTimers.push(timer);
            }
        });
    }

    // ============================================
    // CARREGAR DADOS
    // ============================================
    async function loadExploration() {
        try {
            const [shipsResp, rentalsResp] = await Promise.all([
                apiCall('list_ships'),
                apiCall('get_rentals')
            ]);

            if (shipsResp.success) {
                shipsData = shipsResp.ships;
                document.getElementById('statBalance').textContent = formatBRL(shipsResp.balance_brl);
                document.getElementById('statCredits').textContent = shipsResp.credits;
                document.getElementById('statActiveRentals').textContent = shipsResp.active_rentals + '/' + shipsResp.max_rentals;

                renderShips(shipsData, shipsResp.active_rentals, shipsResp.max_rentals, shipsResp.balance_brl);
            }

            if (rentalsResp.success) {
                rentalsData = rentalsResp.rentals;
                renderRentals(rentalsData);
            }
        } catch (e) {
            console.error('Erro ao carregar exploração:', e);
            document.getElementById('shipsGrid').innerHTML = '<div class="empty-rentals" style="grid-column:1/-1;"><i class="fas fa-exclamation-triangle"></i><p>Erro ao carregar dados</p></div>';
        }
    }

    // ============================================
    // ALUGAR NAVE (PIX)
    // ============================================
    let pixCheckInterval = null;

    window._rentShip = async function(shipId) {
        const ship = shipsData.find(s => s.id === shipId);
        if (!ship) return;

        if (!confirm(`Alugar ${ship.name} por ${formatBRL(ship.rental_price_brl)} via PIX?\n\nDuração: ${formatDuration(ship.rental_duration_hours)}\nColeta: ${ship.credits_per_day} créditos/dia`)) {
            return;
        }

        try {
            const result = await apiCall('rent_ship', { ship_id: shipId });
            if (result.success && result.payment_required) {
                showPixModal(result);
            } else if (result.success) {
                showToast('Nave alugada com sucesso!', 'success');
                loadExploration();
            } else {
                showToast(result.error || 'Erro ao alugar', 'error');
            }
        } catch (e) {
            showToast('Erro de conexão', 'error');
        }
    };

    function showPixModal(data) {
        const modal = document.getElementById('pixModal');
        if (!modal) return;

        document.getElementById('pixShipName').textContent = data.ship_name;
        document.getElementById('pixAmount').textContent = formatBRL(data.amount_brl);
        document.getElementById('pixDuration').textContent = formatDuration(data.duration_hours);
        document.getElementById('pixCreditsDay').textContent = data.credits_per_day + ' créditos/dia';
        document.getElementById('pixCode').value = data.pix_copy_paste || data.qr_code || '';

        // Gerar imagem QR Code
        const qrContainer = document.getElementById('pixQrImage');
        const qrData = data.pix_copy_paste || data.qr_code;
        if (qrData && qrContainer) {
            const qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(qrData);
            qrContainer.innerHTML = '<img src="' + qrImageUrl + '" alt="QR Code PIX" style="width:100%;max-width:220px;border-radius:10px;background:#fff;padding:8px;">';
        } else if (qrContainer) {
            qrContainer.innerHTML = '<p style="color:#667788;font-size:0.8rem;">QR Code indisponível. Use o código abaixo.</p>';
        }

        // Reset status area
        const statusArea = document.getElementById('pixStatusArea');
        if (statusArea) {
            statusArea.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#00ff88;margin-right:6px;"></i>' +
                '<span style="color:#00ff88;font-size:0.8rem;font-weight:600;">Aguardando pagamento...</span>' +
                '<div style="color:#667788;font-size:0.65rem;margin-top:4px;">A nave será ativada automaticamente após a confirmação</div>';
            statusArea.style.borderColor = 'rgba(0,255,136,0.15)';
            statusArea.style.background = 'rgba(0,255,136,0.05)';
        }

        modal.style.display = 'flex';

        // Iniciar polling para verificar pagamento
        if (pixCheckInterval) clearInterval(pixCheckInterval);
        const externalId = data.external_id;
        let checks = 0;

        pixCheckInterval = setInterval(async () => {
            checks++;
            if (checks > 180) { // 15 min max (5s * 180)
                clearInterval(pixCheckInterval);
                pixCheckInterval = null;
                if (statusArea) {
                    statusArea.innerHTML = '<i class="fas fa-clock" style="color:#ffaa00;margin-right:6px;"></i>' +
                        '<span style="color:#ffaa00;font-size:0.8rem;font-weight:600;">PIX expirado</span>' +
                        '<div style="color:#667788;font-size:0.65rem;margin-top:4px;">Gere um novo pagamento para alugar.</div>';
                    statusArea.style.borderColor = 'rgba(255,170,0,0.2)';
                    statusArea.style.background = 'rgba(255,170,0,0.05)';
                }
                return;
            }
            try {
                const status = await apiCall('check_payment', { external_id: externalId });
                if (status.success && status.paid) {
                    clearInterval(pixCheckInterval);
                    pixCheckInterval = null;
                    if (statusArea) {
                        statusArea.innerHTML = '<i class="fas fa-check-circle" style="color:#00ff88;font-size:1.2rem;margin-right:6px;"></i>' +
                            '<span style="color:#00ff88;font-size:0.9rem;font-weight:700;">Pagamento confirmado!</span>' +
                            '<div style="color:#00ff88;font-size:0.75rem;margin-top:4px;">Nave ativada com sucesso!</div>';
                    }
                    showToast('Pagamento confirmado! Nave ativada!', 'success');
                    setTimeout(() => {
                        modal.style.display = 'none';
                        loadExploration();
                    }, 2000);
                }
            } catch (e) { /* retry */ }
        }, 5000);
    }

    window._closePixModal = function() {
        const modal = document.getElementById('pixModal');
        if (modal) modal.style.display = 'none';
        if (pixCheckInterval) {
            clearInterval(pixCheckInterval);
            pixCheckInterval = null;
        }
        loadExploration();
    };

    window._copyPixCode = function() {
        const input = document.getElementById('pixCode');
        if (!input) return;
        input.select();
        try {
            navigator.clipboard.writeText(input.value).then(() => {
                showToast('Código PIX copiado!', 'success');
            }).catch(() => {
                document.execCommand('copy');
                showToast('Código PIX copiado!', 'success');
            });
        } catch (e) {
            document.execCommand('copy');
            showToast('Código PIX copiado!', 'success');
        }
    };

    // ============================================
    // RESGATAR CRÉDITOS
    // ============================================
    window._claimCredits = async function(rentalId) {
        const btn = document.getElementById('claim_' + rentalId);
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

        try {
            const result = await apiCall('claim_credits', { rental_id: rentalId });
            if (result.success) {
                showToast(result.message, 'success');
                loadExploration();
            } else {
                showToast(result.error || 'Erro ao resgatar', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-coins"></i> Resgatar'; }
            }
        } catch (e) {
            showToast('Erro de conexão', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-coins"></i> Resgatar'; }
        }
    };

    // ============================================
    // UTILITÁRIOS
    // ============================================
    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    // ============================================
    // INICIALIZAÇÃO
    // ============================================
    // Mobile menu
    var menuBtn = document.getElementById('mobileMenuBtn');
    var nav = document.getElementById('nav');
    if (menuBtn && nav) {
        menuBtn.addEventListener('click', function() { nav.classList.toggle('active'); });
    }

    // Auth
    document.addEventListener('authStateChanged', function(e) {
        if (e.detail && e.detail.user) {
            document.getElementById('connectOverlay').classList.remove('active');
            var name = e.detail.user.displayName || e.detail.user.email || '';
            document.getElementById('userDisplayName').textContent = name.split(' ')[0];
            loadExploration();
        } else {
            document.getElementById('connectOverlay').classList.add('active');
        }
    });

    console.log('🚀 Exploration v1.0 carregado');
})();
