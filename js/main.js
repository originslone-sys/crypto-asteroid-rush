/* ============================================
   UNOBIX - Main JavaScript v4.0
   Dashboard, Carteira, Staking - Google Auth + BRL
   ============================================ */

// ============================================
// ESTADO GLOBAL
// ============================================

let userStats = {
    balance_brl: 0,
    total_earned_brl: 0,
    games_played: 0,
    staked_balance_brl: 0,
    total_withdrawn_brl: 0,
    pending_withdrawal_brl: 0
};

// ============================================
// UTILITÁRIOS
// ============================================

// Formatar valor em BRL
function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(value || 0);
}

// Formatar valor com mais casas decimais
function formatBRLPrecise(value) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4
    }).format(value || 0);
}

// Formatar data
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Formatar data curta
function formatDateShort(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit'
    });
}

// ============================================
// INICIALIZAÇÃO
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    createStars();
    captureReferralCode();
    setupEventListeners();
    
    // Aguardar autenticação
    document.addEventListener('authStateChanged', (e) => {
        if (e.detail.user) {
            onUserLoggedIn(e.detail.user);
        } else {
            onUserLoggedOut();
        }
    });
});

// Quando usuário faz login
function onUserLoggedIn(user) {
    hideConnectOverlay();
    updateUserUI(user);
    
    const page = document.body.dataset.page;
    
    switch (page) {
        case 'dashboard':
            loadDashboardData();
            break;
        case 'wallet':
            loadWalletData();
            break;
        case 'affiliates':
            loadAffiliateData();
            break;
    }
}

// Quando usuário faz logout
function onUserLoggedOut() {
    showConnectOverlay();
    resetUI();
}

// ============================================
// SISTEMA DE REFERRAL
// ============================================

function captureReferralCode() {
    const urlParams = new URLSearchParams(window.location.search);
    const refCode = urlParams.get('ref');

    if (refCode && /^[A-Z0-9]{6}$/i.test(refCode)) {
        const code = refCode.toUpperCase();
        // Salvar em ambas as chaves para compatibilidade com auth-manager.js
        localStorage.setItem('unobix_referral', code);
        localStorage.setItem('unobix_referral_code', code);
        localStorage.setItem('unobix_referral_time', Date.now().toString());

        console.log('📋 Código de indicação capturado:', code);

        // Limpar apenas o param ?ref= da URL (preservar outros params)
        if (window.history.replaceState) {
            const cleanSearch = window.location.search
                .replace(/[?&]ref=[^&]+/, '')
                .replace(/^\?$/, '');
            const cleanUrl = window.location.pathname + cleanSearch + window.location.hash;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }
}

function getSavedReferralCode() {
    const code = localStorage.getItem('unobix_referral');
    const timestamp = localStorage.getItem('unobix_referral_time');
    
    if (!code || !timestamp) return null;
    
    // Expira em 7 dias
    const sevenDays = 7 * 24 * 60 * 60 * 1000;
    if (Date.now() - parseInt(timestamp) > sevenDays) {
        clearReferralCode();
        return null;
    }
    
    return code;
}

function clearReferralCode() {
    localStorage.removeItem('unobix_referral');
    localStorage.removeItem('unobix_referral_code');
    localStorage.removeItem('unobix_referral_time');
    localStorage.removeItem('referralCode');
    localStorage.removeItem('referralTimestamp');
}

// ============================================
// SPACE BACKGROUND AAA+ SYSTEM
// ============================================

const SpaceBGConfig = {
    stars: { count: 50, countMobile: 25 },
    meteors: { minInterval: 5000, maxInterval: 15000, enabled: true },
    particles: { count: 15, countMobile: 8, duration: 20000 },
    performance: { fpsThreshold: 30 }
};

const SpaceBGState = {
    isMobile: window.matchMedia('(max-width: 768px)').matches,
    isSmallScreen: window.matchMedia('(max-width: 480px)').matches,
    isLowPower: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    meteorContainer: null,
    meteorTimeout: null,
    fps: 60,
    frameCount: 0,
    lastFrameTime: performance.now()
};

function createStars() {
    // Não inicializa se preferir movimento reduzido
    if (SpaceBGState.isLowPower) {
        console.log('[SpaceBG] Reduced motion preference - skipping animations');
        return;
    }
    
    createAmbientGlow();
    createDistantGalaxies();
    createStarField();
    createParticleField();
    createMeteorContainer();
    
    // Primeiro meteoro após 3 segundos
    setTimeout(() => scheduleMeteor(), 3000);
    
    // Monitor de performance
    requestAnimationFrame(monitorSpacePerformance);
    
    // Pausa quando tab inativa
    document.addEventListener('visibilitychange', handleSpaceVisibility);
    
    console.log('[SpaceBG] ✨ Space background AAA+ initialized');
}

// Star Field - Estrelas animadas
function createStarField() {
    if (document.querySelector('.star-field')) return;
    
    const container = document.createElement('div');
    container.className = 'star-field';
    document.body.appendChild(container);

    const starCount = SpaceBGState.isMobile ? SpaceBGConfig.stars.countMobile : SpaceBGConfig.stars.count;
    const animations = ['twinkle1', 'twinkle2', 'twinkle3'];

    for (let i = 0; i < starCount; i++) {
        const star = document.createElement('div');
        
        // Tamanho aleatório com peso para pequenas
        const sizeRoll = Math.random();
        const size = sizeRoll < 0.6 ? 'small' : (sizeRoll < 0.9 ? 'medium' : 'large');
        
        // Cor aleatória (maioria branca)
        const colorRoll = Math.random();
        const color = colorRoll < 0.7 ? '' : (colorRoll < 0.85 ? 'cyan' : 'green');
        
        star.className = `star star--${size}${color ? ` star--${color}` : ''}`;
        star.style.left = `${Math.random() * 100}%`;
        star.style.top = `${Math.random() * 100}%`;
        
        // Animação aleatória
        const anim = animations[Math.floor(Math.random() * animations.length)];
        const duration = 2 + Math.random() * 4;
        const delay = Math.random() * 5;
        
        star.style.animation = `${anim} ${duration}s ease-in-out ${delay}s infinite`;
        container.appendChild(star);
    }
}

// Meteor Container
function createMeteorContainer() {
    if (document.querySelector('.meteor-container')) return;
    SpaceBGState.meteorContainer = document.createElement('div');
    SpaceBGState.meteorContainer.className = 'meteor-container';
    document.body.appendChild(SpaceBGState.meteorContainer);
}

// Spawn Meteor
function spawnMeteor() {
    if (!SpaceBGConfig.meteors.enabled || !SpaceBGState.meteorContainer) return;
    
    const meteor = document.createElement('div');
    meteor.className = 'meteor';
    
    // Posição inicial aleatória
    const startX = 50 + Math.random() * 50;
    const startY = Math.random() * 40;
    
    meteor.style.left = `${startX}%`;
    meteor.style.top = `${startY}%`;
    
    // Velocidade aleatória
    const speedClass = Math.random() < 0.3 ? 'meteor--fast' : (Math.random() < 0.6 ? '' : 'meteor--slow');
    if (speedClass) meteor.classList.add(speedClass);
    
    SpaceBGState.meteorContainer.appendChild(meteor);
    
    requestAnimationFrame(() => meteor.classList.add('meteor--active'));
    setTimeout(() => meteor.remove(), 2500);
    
    scheduleMeteor();
}

function scheduleMeteor() {
    if (SpaceBGState.meteorTimeout) clearTimeout(SpaceBGState.meteorTimeout);
    
    const delay = SpaceBGConfig.meteors.minInterval + 
        Math.random() * (SpaceBGConfig.meteors.maxInterval - SpaceBGConfig.meteors.minInterval);
    
    SpaceBGState.meteorTimeout = setTimeout(spawnMeteor, delay);
}

// Particle Field - Poeira espacial
function createParticleField() {
    if (SpaceBGState.isSmallScreen || document.querySelector('.particle-field')) return;
    
    const container = document.createElement('div');
    container.className = 'particle-field';
    document.body.appendChild(container);

    const particleCount = SpaceBGState.isMobile ? SpaceBGConfig.particles.countMobile : SpaceBGConfig.particles.count;

    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        particle.style.left = `${Math.random() * 100}%`;
        
        const size = 1 + Math.random() * 2;
        particle.style.width = `${size}px`;
        particle.style.height = `${size}px`;
        particle.style.opacity = 0.2 + Math.random() * 0.3;
        
        const duration = SpaceBGConfig.particles.duration + Math.random() * 10000;
        const delay = i * (SpaceBGConfig.particles.duration / particleCount);
        particle.style.animation = `floatParticle ${duration}ms linear ${delay}ms infinite`;
        
        container.appendChild(particle);
    }
}

// Ambient Glow
function createAmbientGlow() {
    if (document.querySelector('.ambient-glow')) return;
    const glow = document.createElement('div');
    glow.className = 'ambient-glow';
    document.body.appendChild(glow);
}

// Distant Galaxies
function createDistantGalaxies() {
    if (SpaceBGState.isMobile) return;
    
    for (let i = 1; i <= 2; i++) {
        if (document.querySelector(`.distant-galaxy--${i}`)) continue;
        const galaxy = document.createElement('div');
        galaxy.className = `distant-galaxy distant-galaxy--${i}`;
        document.body.appendChild(galaxy);
    }
}

// Performance Monitor
function monitorSpacePerformance() {
    const now = performance.now();
    SpaceBGState.frameCount++;
    
    if (now - SpaceBGState.lastFrameTime >= 1000) {
        SpaceBGState.fps = SpaceBGState.frameCount;
        SpaceBGState.frameCount = 0;
        SpaceBGState.lastFrameTime = now;
        
        // Desabilita meteoros se FPS muito baixo
        if (SpaceBGState.fps < SpaceBGConfig.performance.fpsThreshold) {
            SpaceBGConfig.meteors.enabled = false;
            console.log('[SpaceBG] Low FPS detected, disabling meteors');
        }
    }
    
    requestAnimationFrame(monitorSpacePerformance);
}

// Visibility handler - pausa quando tab inativa
function handleSpaceVisibility() {
    if (document.hidden) {
        if (SpaceBGState.meteorTimeout) {
            clearTimeout(SpaceBGState.meteorTimeout);
            SpaceBGState.meteorTimeout = null;
        }
    } else {
        if (SpaceBGConfig.meteors.enabled && !SpaceBGState.meteorTimeout) {
            scheduleMeteor();
        }
    }
}

// API Global para controle manual
window.SpaceBG = {
    config: SpaceBGConfig,
    state: SpaceBGState,
    spawnMeteor: () => { if (SpaceBGState.meteorContainer) spawnMeteor(); },
    toggleMeteors: (enabled) => { SpaceBGConfig.meteors.enabled = enabled; }
};

// ============================================
// UI - OVERLAY E HEADER
// ============================================

function showConnectOverlay() {
    const overlay = document.getElementById('connectOverlay');
    if (overlay) {
        overlay.classList.remove('hidden');
        overlay.classList.add('active');
    }
}

function hideConnectOverlay() {
    const overlay = document.getElementById('connectOverlay');
    if (overlay) {
        overlay.classList.add('hidden');
        overlay.classList.remove('active');
    }
}

function updateUserUI(user) {
    const walletBtn = document.getElementById('walletBtn');
    const userDisplayName = document.getElementById('userDisplayName');
    
    if (walletBtn) {
        walletBtn.classList.add('connected');
        
        // Adicionar foto se tiver
        if (user.photoURL) {
            walletBtn.innerHTML = `
                <img src="${user.photoURL}" alt="${user.displayName}">
                <span>${user.displayName?.split(' ')[0] || 'Usuário'}</span>
            `;
        } else {
            walletBtn.innerHTML = `
                <i class="fas fa-user-circle"></i>
                <span>${user.displayName?.split(' ')[0] || 'Usuário'}</span>
            `;
        }
    }
    
    if (userDisplayName) {
        userDisplayName.textContent = user.displayName?.split(' ')[0] || 'Usuário';
    }
}

function resetUI() {
    const walletBtn = document.getElementById('walletBtn');
    
    if (walletBtn) {
        walletBtn.classList.remove('connected');
        walletBtn.innerHTML = `
            <i class="fas fa-user-circle"></i>
            <span>Entrar</span>
        `;
    }
    
    // Resetar valores
    userStats = {
        balance_brl: 0,
        total_earned_brl: 0,
        games_played: 0,
        total_withdrawn_brl: 0,
        pending_withdrawal_brl: 0
    };
}

// ============================================
// LOGIN COM GOOGLE
// ============================================

async function connectWithGoogle() {
    try {
        // Verificar se authManager está disponível
        if (!window.authManager) {
            console.error('❌ authManager não está disponível. Recarregando página...');
            showNotification('Sistema de login não carregado. Recarregando...', 'warning');
            setTimeout(() => location.reload(), 2000);
            return;
        }
        
        console.log('🔐 Iniciando login com Google...');
        await window.authManager.signIn();
        // O signIn vai redirecionar, então não precisa de retorno
    } catch (error) {
        console.error('❌ Erro no login:', error);
        showNotification('Erro ao fazer login: ' + error.message, 'error');
    }
}

async function logout() {
    const result = await window.authManager.logout();
    
    if (result.success) {
        showNotification('Você saiu da conta.', 'info');
    } else {
        showNotification(result.error, 'error');
    }
}

// ============================================
// DASHBOARD
// ============================================

async function loadDashboardData() {
    const user = window.authManager?.currentUser;
    if (!user) return;

    try {
        const response = await fetch('/api/balance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: user.uid })
        });
        
        const data = await response.json();
        
        if (data.success) {
            userStats = {
                balance_brl: parseFloat(data.balance_brl) || 0,
                total_earned_brl: parseFloat(data.total_earned_brl) || 0,
                games_played: parseInt(data.total_played) || 0,
                staked_balance_brl: parseFloat(data.staked_balance_brl) || 0
            };
            
            // Atualizar UI
            const el = (id) => document.getElementById(id);
            
            if (el('statBalance')) el('statBalance').textContent = formatBRL(userStats.balance_brl);
            if (el('statEarned')) el('statEarned').textContent = formatBRL(userStats.total_earned_brl);
            if (el('statGames')) el('statGames').textContent = userStats.games_played;
        }
        
        // Carregar atividade recente
        loadRecentActivity();
        
    } catch (error) {
        console.error('Erro ao carregar dashboard:', error);
    }
}

async function loadRecentActivity() {
    const user = window.authManager?.currentUser;
    if (!user) return;

    const container = document.getElementById('activityList');
    if (!container) return;

    try {
        const response = await fetch('/api/transactions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                google_uid: user.uid,
                limit: 5
            })
        });
        
        const data = await response.json();
        
        if (data.success && data.transactions?.length > 0) {
            container.innerHTML = data.transactions.map(tx => {
                const isPositive = !tx.type.includes('withdrawal') && !tx.type.includes('stake');
                const iconClass = getActivityIconClass(tx.type);
                
                return `
                    <div class="activity-item">
                        <div class="activity-info">
                            <div class="activity-icon ${iconClass}">
                                <i class="fas fa-${getActivityIcon(tx.type)}"></i>
                            </div>
                            <div>
                                <div class="activity-title">${getActivityTitle(tx.type)}</div>
                                <div class="activity-date">${formatDateShort(tx.created_at)}</div>
                            </div>
                        </div>
                        <div class="activity-amount ${isPositive ? 'positive' : ''}">
                            ${isPositive ? '+' : ''}${formatBRL(tx.amount_brl)}
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Nenhuma atividade ainda</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Erro ao carregar atividade:', error);
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Erro ao carregar</p>
            </div>
        `;
    }
}

function getActivityIcon(type) {
    const icons = {
        'game_earning': 'gamepad',
        'stake_reward': 'chart-line',
        'referral_bonus': 'users',
        'withdrawal': 'arrow-up',
        'withdrawal_approved': 'check-circle',
        'withdrawal_rejected': 'times-circle',
        'stake': 'lock',
        'unstake': 'unlock'
    };
    return icons[type] || 'circle';
}

function getActivityIconClass(type) {
    if (type.includes('game') || type.includes('reward') || type.includes('bonus')) {
        return 'reward';
    } else if (type.includes('withdrawal')) {
        return 'withdraw';
    } else if (type.includes('stake')) {
        return 'game';
    }
    return '';
}

function getActivityTitle(type) {
    const titles = {
        'game_earning': 'Ganhos da Missão',
        'stake_reward': 'Rendimento Staking',
        'referral_bonus': 'Bônus de Indicação',
        'withdrawal': 'Saque Solicitado',
        'withdrawal_approved': 'Saque Aprovado',
        'withdrawal_rejected': 'Saque Rejeitado',
        'stake': 'Stake Realizado',
        'unstake': 'Unstake Realizado'
    };
    return titles[type] || type;
}

// ============================================
// CARTEIRA
// ============================================

async function loadWalletData() {
    const user = window.authManager?.currentUser;
    if (!user) return;

    try {
        const response = await fetch('/api/balance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: user.uid })
        });
        
        const data = await response.json();
        
        if (data.success) {
            const el = (id) => document.getElementById(id);
            
            if (el('walletBalance')) el('walletBalance').textContent = formatBRL(data.balance_brl);
            if (el('walletEarned')) el('walletEarned').textContent = formatBRL(data.total_earned_brl);
            if (el('walletWithdrawn')) el('walletWithdrawn').textContent = formatBRL(data.total_withdrawn_brl);
            if (el('walletPending')) el('walletPending').textContent = formatBRL(data.pending_withdrawal_brl || 0);
        }
        
        // Carregar histórico
        loadTransactionHistory();
        
    } catch (error) {
        console.error('Erro ao carregar carteira:', error);
    }
}

async function loadTransactionHistory() {
    const user = window.authManager?.currentUser;
    if (!user) return;

    const container = document.getElementById('transactionHistory');
    if (!container) return;

    try {
        const response = await fetch('/api/transactions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                google_uid: user.uid,
                limit: 20
            })
        });
        
        const data = await response.json();
        
        if (data.success && data.transactions?.length > 0) {
            container.innerHTML = data.transactions.map(tx => {
                const isPositive = !tx.type.includes('withdrawal') && !tx.type.includes('stake');
                
                return `
                    <div class="transaction-item">
                        <div class="tx-icon ${isPositive ? 'positive' : 'negative'}">
                            <i class="fas fa-${getActivityIcon(tx.type)}"></i>
                        </div>
                        <div class="tx-info">
                            <div class="tx-type">${getActivityTitle(tx.type)}</div>
                            <div class="tx-desc">${tx.description || ''}</div>
                        </div>
                        <div class="tx-amount ${isPositive ? 'positive' : ''}">
                            ${isPositive ? '+' : ''}${formatBRL(tx.amount_brl)}
                        </div>
                        <div class="tx-meta">
                            <div class="tx-date">${formatDate(tx.created_at)}</div>
                            ${tx.status ? `<span class="tx-status ${tx.status}">${getStatusText(tx.status)}</span>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Nenhuma transação ainda</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Erro ao carregar transações:', error);
    }
}

function getStatusText(status) {
    const texts = {
        'completed': 'Concluído',
        'pending': 'Pendente',
        'approved': 'Aprovado',
        'rejected': 'Rejeitado',
        'failed': 'Falhou'
    };
    return texts[status] || status;
}

async function requestWithdraw() {
    const user = window.authManager?.currentUser;
    if (!user) {
        showNotification('Faça login primeiro!', 'warning');
        return;
    }

    const amount = parseFloat(document.getElementById('withdrawAmount')?.value);
    const paymentMethod = document.querySelector('.payment-method.selected')?.dataset?.method || 'pix';
    const paymentDetails = document.getElementById('paymentDetails')?.value?.trim();
    
    if (!amount || amount < 1) {
        showNotification('Valor mínimo: R$ 1,00', 'warning');
        return;
    }
    
    if (!paymentDetails) {
        showNotification('Preencha os dados de pagamento', 'warning');
        return;
    }

    const btn = document.getElementById('withdrawBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    }

    try {
        const response = await fetch('/api/withdraw.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                google_uid: user.uid,
                amount_brl: amount,
                payment_method: paymentMethod,
                payment_details: paymentDetails
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification('✅ Saque solicitado! Processamento: dias 20-25', 'success');
            document.getElementById('withdrawAmount').value = '';
            document.getElementById('paymentDetails').value = '';
            loadWalletData();
        } else {
            showNotification(data.error || 'Erro ao solicitar saque', 'error');
        }
    } catch (error) {
        console.error('Erro:', error);
        showNotification('Erro de conexão', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Solicitar Saque';
        }
    }
}

// ============================================
// AFILIADOS
// ============================================

async function loadAffiliateData() {
    const user = window.authManager?.currentUser;
    if (!user) return;

    try {
        const res = await fetch('/api/referral-info.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: user.uid })
        });
        const data = await res.json();
        
        if (data.success) {
            updateAffiliateUI(data);
        }
    } catch (error) {
        console.error('Erro ao carregar afiliados:', error);
    }
}

function updateAffiliateUI(data) {
    const baseUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
    const fullLink = baseUrl + '?ref=' + data.referral_code;
    
    const el = (id) => document.getElementById(id);
    
    if (el('referralLink')) el('referralLink').value = fullLink;
    if (el('referralCode')) el('referralCode').textContent = data.referral_code;
    
    const stats = data.stats;
    
    if (el('statTotalReferred')) el('statTotalReferred').textContent = stats.total_referred || 0;
    if (el('statPending')) el('statPending').textContent = stats.pending || 0;
    if (el('statCompleted')) el('statCompleted').textContent = (parseInt(stats.completed) || 0) + (parseInt(stats.claimed) || 0);
    if (el('statTotalEarned')) el('statTotalEarned').textContent = formatBRL(stats.total_earned_brl);
    
    const availableCommission = parseFloat(stats.available_commission_brl) || 0;
    const claimSection = document.getElementById('claimSection');
    
    if (claimSection) {
        if (availableCommission > 0) {
            claimSection.style.display = 'flex';
            if (el('claimAmount')) el('claimAmount').textContent = formatBRL(availableCommission);
        } else {
            claimSection.style.display = 'none';
        }
    }
    
    updateReferralsTable(data.referrals);
}

function updateReferralsTable(referrals) {
    const tbody = document.getElementById('referralsTableBody');
    if (!tbody) return;
    
    if (!referrals || referrals.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5">
                    <div class="empty-state">
                        <i class="fas fa-user-friends"></i>
                        <h3>Nenhum indicado ainda</h3>
                        <p>Compartilhe seu link para começar a ganhar!</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = referrals.map(ref => {
        const statusTexts = { 'pending': 'Em Progresso', 'qualified': 'Completado', 'claimed': 'Resgatado' };
        const userDisplay = ref.display_name || ref.email?.split('@')[0] || ref.referred_short || 'Usuário';
        const req = ref.missions_required || 100;

        return `
            <tr>
                <td class="user-cell">${userDisplay}</td>
                <td>
                    <div>${ref.missions_completed || 0}/${req} missões</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${ref.progress_percent || 0}%"></div>
                    </div>
                </td>
                <td><span class="status-badge status-${ref.status === 'qualified' ? 'completed' : ref.status}">${statusTexts[ref.status] || ref.status}</span></td>
                <td style="color: var(--success);">${formatBRL(ref.commission_brl)}</td>
                <td style="color: var(--text-dim);">${formatDateShort(ref.created_at)}</td>
            </tr>
        `;
    }).join('');
}

function copyReferralLink() {
    const linkInput = document.getElementById('referralLink');
    const copyBtn = document.getElementById('copyBtn');
    
    if (!linkInput) return;
    
    linkInput.select();
    linkInput.setSelectionRange(0, 99999);
    
    navigator.clipboard.writeText(linkInput.value).then(() => {
        if (copyBtn) {
            copyBtn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
            copyBtn.classList.add('copied');
            
            setTimeout(() => {
                copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copiar';
                copyBtn.classList.remove('copied');
            }, 2000);
        }
        
        showNotification('Link copiado!', 'success');
    }).catch(() => {
        document.execCommand('copy');
        showNotification('Link copiado!', 'success');
    });
}

async function claimCommissions() {
    const user = window.authManager?.currentUser;
    if (!user) {
        showNotification('Faça login primeiro!', 'warning');
        return;
    }
    
    const claimBtn = document.getElementById('claimBtn');
    if (claimBtn) {
        claimBtn.disabled = true;
        claimBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    }
    
    try {
        const res = await fetch('/api/referral-claim.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: user.uid })
        });
        const data = await res.json();
        
        if (data.success) {
            showNotification(`✅ Resgatado: ${formatBRL(data.amount_claimed_brl)}`, 'success');
            setTimeout(loadAffiliateData, 1000);
        } else {
            showNotification(data.error || 'Erro ao resgatar', 'error');
        }
    } catch (error) {
        console.error('Erro:', error);
        showNotification('Erro de conexão', 'error');
    } finally {
        if (claimBtn) {
            claimBtn.disabled = false;
            claimBtn.innerHTML = '<i class="fas fa-download"></i> RESGATAR';
        }
    }
}

// ============================================
// EVENT LISTENERS
// ============================================

function setupEventListeners() {
    // Botão de conectar (Google)
    document.getElementById('connectBtn')?.addEventListener('click', connectWithGoogle);
    
    // Botão do header
    document.getElementById('walletBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        
        // Verificar se authManager existe
        if (!window.authManager) {
            console.warn('⚠️ authManager não disponível, tentando login...');
            connectWithGoogle();
            return;
        }
        
        if (window.authManager.isLoggedIn && window.authManager.isLoggedIn()) {
            window.location.href = 'wallet.html';
        } else {
            connectWithGoogle();
        }
    });
    
    // Menu mobile
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const nav = document.getElementById('nav');

    mobileMenuBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        nav?.classList.toggle('active');
    });

    // Fechar menu ao clicar em um link
    nav?.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            nav.classList.remove('active');
        });
    });

    // Fechar menu ao clicar fora
    document.addEventListener('click', (e) => {
        if (nav?.classList.contains('active') &&
            !nav.contains(e.target) &&
            !mobileMenuBtn?.contains(e.target)) {
            nav.classList.remove('active');
        }
    });
    
    // FAQ
    document.querySelectorAll('.faq-question').forEach(q => {
        q.addEventListener('click', () => {
            document.querySelectorAll('.faq-item.active').forEach(item => {
                if (item !== q.parentElement) item.classList.remove('active');
            });
            q.parentElement.classList.toggle('active');
        });
    });
}

// ============================================
// NOTIFICAÇÕES
// ============================================

function showNotification(message, type = 'info') {
    // Remover notificação existente
    document.querySelector('.notification-toast')?.remove();
    
    const notification = document.createElement('div');
    notification.className = `notification-toast ${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
        <span>${message}</span>
    `;
    
    // Estilos inline
    Object.assign(notification.style, {
        position: 'fixed',
        top: '20px',
        right: '20px',
        padding: '15px 25px',
        borderRadius: '10px',
        background: type === 'success' ? 'rgba(0, 255, 136, 0.9)' : 
                    type === 'error' ? 'rgba(255, 71, 87, 0.9)' : 
                    type === 'warning' ? 'rgba(255, 209, 102, 0.9)' : 
                    'rgba(0, 229, 204, 0.9)',
        color: type === 'warning' ? '#333' : '#fff',
        fontWeight: '600',
        display: 'flex',
        alignItems: 'center',
        gap: '10px',
        zIndex: '9999',
        animation: 'slideIn 0.3s ease',
        boxShadow: '0 5px 20px rgba(0, 0, 0, 0.3)'
    });
    
    document.body.appendChild(notification);
    
    // Adicionar keyframes se não existir
    if (!document.getElementById('notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            @keyframes slideIn {
                from { opacity: 0; transform: translateX(100px); }
                to { opacity: 1; transform: translateX(0); }
            }
        `;
        document.head.appendChild(style);
    }
    
    setTimeout(() => {
        notification.style.animation = 'slideIn 0.3s ease reverse';
        setTimeout(() => notification.remove(), 300);
    }, 4000);
}

// ============================================
// EXPORTS GLOBAIS
// ============================================

window.connectWithGoogle = connectWithGoogle;
window.logout = logout;
window.requestWithdraw = requestWithdraw;
window.showNotification = showNotification;
window.copyReferralLink = copyReferralLink;
window.claimCommissions = claimCommissions;
window.loadAffiliateData = loadAffiliateData;
window.formatBRL = formatBRL;

console.log('🚀 Unobix Main.js carregado');
