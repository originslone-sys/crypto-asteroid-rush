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
        NEBULA:   { primary: '#6633aa', secondary: '#aa44ff', accent: '#cc66ff', cockpit: '#ff88ff', engine: '#9944ff' },
        VIPER:    { primary: '#228833', secondary: '#44ff66', accent: '#66ff44', cockpit: '#aaffcc', engine: '#00ff44' },
        WOLF:     { primary: '#556677', secondary: '#8899aa', accent: '#99aabb', cockpit: '#aaddff', engine: '#4488cc' }
    };

    function getShipSVG(key, size) {
        const c = SHIP_COLORS[key] || SHIP_COLORS.PHOENIX;
        return `<svg width="${size}" height="${size}" viewBox="-50 -50 100 100">
            <defs>
                <radialGradient id="eng_${key}" cx="50%" cy="50%"><stop offset="0%" stop-color="${c.engine}" stop-opacity="0.8"/><stop offset="100%" stop-color="${c.engine}" stop-opacity="0"/></radialGradient>
            </defs>
            <ellipse cx="0" cy="35" rx="10" ry="18" fill="url(#eng_${key})"/>
            <polygon points="0,-40 18,28 -18,28" fill="${c.primary}" stroke="${c.secondary}" stroke-width="1.5"/>
            <polygon points="-10,5 -42,-8 -36,20" fill="${c.primary}" stroke="${c.secondary}" stroke-width="0.8" opacity="0.9"/>
            <polygon points="10,5 42,-8 36,20" fill="${c.primary}" stroke="${c.secondary}" stroke-width="0.8" opacity="0.9"/>
            <ellipse cx="0" cy="-18" rx="9" ry="7" fill="${c.cockpit}" opacity="0.7"/>
            <line x1="0" y1="-38" x2="0" y2="26" stroke="${c.accent}" stroke-width="1" opacity="0.4"/>
            <circle cx="0" cy="30" r="5" fill="${c.engine}" opacity="0.9"/>
            <circle cx="-6" cy="28" r="2.5" fill="${c.engine}" opacity="0.6"/>
            <circle cx="6" cy="28" r="2.5" fill="${c.engine}" opacity="0.6"/>
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
            const canRent = !isRented && activeRentals < maxRentals && balance >= ship.rental_price_brl;
            const reason = isRented ? 'Já alugada' :
                           activeRentals >= maxRentals ? 'Limite atingido' :
                           balance < ship.rental_price_brl ? 'Saldo insuficiente' : '';

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
                      canRent ? `<button class="rent-btn available" onclick="window._rentShip(${ship.id})"><i class="fas fa-rocket"></i> Alugar por ${formatBRL(ship.rental_price_brl)}</button>` :
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
    // ALUGAR NAVE
    // ============================================
    window._rentShip = async function(shipId) {
        const ship = shipsData.find(s => s.id === shipId);
        if (!ship) return;

        if (!confirm(`Alugar ${ship.name} por ${formatBRL(ship.rental_price_brl)}?\n\nDuração: ${formatDuration(ship.rental_duration_hours)}\nColeta: ${ship.credits_per_day} créditos/dia`)) {
            return;
        }

        try {
            const result = await apiCall('rent_ship', { ship_id: shipId });
            if (result.success) {
                showToast(result.message, 'success');
                loadExploration();
            } else {
                showToast(result.error || 'Erro ao alugar', 'error');
            }
        } catch (e) {
            showToast('Erro de conexão', 'error');
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
