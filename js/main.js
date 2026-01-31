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
        case 'staking':
            loadStakingData();
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
    
    if (refCode && /^[A-Z0-9]{6,8}$/i.test(refCode)) {
        localStorage.setItem('unobix_referral', refCode.toUpperCase());
        localStorage.setItem('unobix_referral_time', Date.now().toString());
        
        console.log('📋 Código de indicação capturado:', refCode.toUpperCase());
        
        // Limpar URL
        if (window.history.replaceState) {
            const cleanUrl = window.location.protocol + '//' + window.location.host + window.location.pathname;
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
    localStorage.removeItem('unobix_referral_time');
}

// ============================================
// BACKGROUND DE ESTRELAS
// ============================================

function createStars() {
    const bg = document.querySelector('.cosmic-bg');
    if (!bg) return;
    
    const starCount = window.innerWidth < 768 ? 40 : 70;
    
    for (let i = 0; i < starCount; i++) {
        const star = document.createElement('div');
        star.classList.add('star');
        
        const rand = Math.random();
        if (rand < 0.6) star.classList.add('small');
        else if (rand < 0.9) star.classList.add('medium');
        else star.classList.add('large');
        
        star.style.left = `${Math.random() * 100}%`;
        star.style.top = `${Math.random() * 100}%`;
        star.style.animationDuration = `${2 + Math.random() * 3}s`;
        star.style.animationDelay = `${Math.random() * 2}s`;
        
        bg.appendChild(star);
    }
}

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
        staked_balance_brl: 0,
        total_withdrawn_brl: 0,
        pending_withdrawal_brl: 0
    };
}

// ============================================
// LOGIN COM GOOGLE
// ============================================

async function connectWithGoogle() {
    try {
        console.log('🔐 Iniciando login com Google...');
        
        // Verificar se authManager existe
        if (!window.authManager) {
            console.error('❌ authManager não definido! Tentando inicializar...');
            
            // Tentar carregar auth-manager.js dinamicamente
            await loadAuthManager();
            
            if (!window.authManager) {
                throw new Error('Não foi possível carregar o sistema de login');
            }
        }
        
        await window.authManager.signIn();
        // O signIn vai redirecionar, então não precisa de retorno
    } catch (error) {
        console.error('❌ Erro no login:', error);
        showNotification('Erro ao fazer login: ' + error.message, 'error');
    }
}

// Carregar auth-manager dinamicamente se necessário
async function loadAuthManager() {
    return new Promise((resolve, reject) => {
        // Verificar se já está carregado
        if (typeof AuthManager !== 'undefined' && typeof firebase !== 'undefined') {
            console.log('✅ AuthManager já carregado, criando instância...');
            window.authManager = new AuthManager();
            window.authManager.init().then(resolve).catch(reject);
            return;
        }
        
        // Carregar Firebase se necessário
        if (typeof firebase === 'undefined') {
            console.log('🔥 Carregando Firebase...');
            const firebaseScript = document.createElement('script');
            firebaseScript.src = 'https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js';
            firebaseScript.onload = () => {
                const authScript = document.createElement('script');
                authScript.src = 'https://www.gstatic.com/firebasejs/10.7.1/firebase-auth-compat.js';
                authScript.onload = loadAuthManagerScript;
                document.head.appendChild(authScript);
            };
            firebaseScript.onerror = () => reject(new Error('Falha ao carregar Firebase SDK'));
            document.head.appendChild(firebaseScript);
        } else {
            loadAuthManagerScript();
        }
        
        function loadAuthManagerScript() {
            console.log('📦 Carregando auth-manager.js...');
            const script = document.createElement('script');
            script.src = 'js/auth-manager.js';
            script.onload = () => {
                setTimeout(() => {
                    if (typeof AuthManager !== 'undefined') {
                        window.authManager = new AuthManager();
                        window.authManager.init().then(resolve).catch(reject);
                    } else {
                        reject(new Error('AuthManager não carregado após script'));
                    }
                }, 1000);
            };
            script.onerror = reject;
            document.head.appendChild(script);
        }
    });
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
            if (el('statStaked')) el('statStaked').textContent = formatBRL(userStats.staked_balance_brl);
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
// STAKING
// ============================================

const STAKING_APY = 0.05; // 5% ao ano
let stakingBalance = 0;
let stakedAmount = 0;

async function loadStakingData() {
    const user = window.authManager?.currentUser;
    if (!user) return;

    try {
        // Carregar saldo
        const balanceRes = await fetch('/api/balance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: user.uid })
        });
        const balanceData = await balanceRes.json();
        
        if (balanceData.success) {
            stakingBalance = parseFloat(balanceData.balance_brl) || 0;
            stakedAmount = parseFloat(balanceData.staked_balance_brl) || 0;
            
            const el = (id) => document.getElementById(id);
            
            if (el('stakingBalance')) el('stakingBalance').textContent = formatBRL(stakingBalance);
            if (el('totalStaked')) el('totalStaked').textContent = formatBRL(stakedAmount);
            
            // Habilitar/desabilitar botão unstake
            const unstakeBtn = document.getElementById('unstakeBtn');
            if (unstakeBtn) unstakeBtn.disabled = stakedAmount <= 0;
        }

        // Carregar dados de stake
        const stakeRes = await fetch('/api/get-stakes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: user.uid })
        });
        const stakeData = await stakeRes.json();
        
        if (stakeData.success) {
            const el = (id) => document.getElementById(id);
            
            const totalEarned = parseFloat(stakeData.total_earned_brl) || 0;
            const pendingReward = parseFloat(stakeData.pending_reward_brl) || 0;
            
            if (el('stakingEarned')) el('stakingEarned').textContent = formatBRL(totalEarned);
            if (el('pendingReward')) el('pendingReward').textContent = '+' + formatBRL(pendingReward);
            if (el('todayEarnings')) el('todayEarnings').textContent = '+' + formatBRL(pendingReward);
            
            // Seção de rendimento pendente
            const pendingSection = document.getElementById('pendingRewardSection');
            if (pendingSection) {
                if (pendingReward > 0.001) {
                    pendingSection.style.display = 'flex';
                    const pendingValue = document.getElementById('pendingRewardValue');
                    if (pendingValue) pendingValue.textContent = '+' + formatBRL(pendingReward);
                } else {
                    pendingSection.style.display = 'none';
                }
            }
        }
    } catch (error) {
        console.error('Erro ao carregar staking:', error);
    }
}

function updateProjection() {
    const amount = parseFloat(document.getElementById('projectionAmount')?.value) || 0;
    const dailyRate = STAKING_APY / 365;
    
    // Juros compostos
    const projections = {
        projDay: amount * (Math.pow(1 + dailyRate, 1) - 1),
        projWeek: amount * (Math.pow(1 + dailyRate, 7) - 1),
        projMonth: amount * (Math.pow(1 + dailyRate, 30) - 1),
        proj6Month: amount * (Math.pow(1 + dailyRate, 180) - 1),
        projYear: amount * (Math.pow(1 + dailyRate, 365) - 1)
    };
    
    Object.entries(projections).forEach(([id, value]) => {
        const el = document.getElementById(id);
        if (el) el.textContent = formatBRL(value);
    });
}

function setStakeAmount(amount) {
    const input = document.getElementById('stakeAmount');
    if (input) input.value = amount.toFixed(2);
}

function setMaxAmount() {
    const input = document.getElementById('stakeAmount');
    if (input) input.value = stakingBalance.toFixed(2);
}

async function stakeFunds() {
    const user = window.authManager?.currentUser;
    if (!user) {
        showNotification('Faça login primeiro!', 'warning');
        return;
    }

    const amount = parseFloat(document.getElementById('stakeAmount')?.value);
    
    if (!amount || amount < 0.01) {
        showNotification('Valor mínimo: R$ 0,01', 'warning');
        return;
    }
    
    if (amount > stakingBalance) {
        showNotification('Saldo insuficiente!', 'warning');
        return;
    }

    const btn = document.getElementById('stakeBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    }

    try {
        const response = await fetch('/api/stake.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                google_uid: user.uid,
                amount_brl: amount
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification('✅ Stake realizado! Rendimento: 5% ao ano', 'success');
            document.getElementById('stakeAmount').value = '';
            loadStakingData();
        } else {
            showNotification(data.error || 'Erro ao fazer stake', 'error');
        }
    } catch (error) {
        console.error('Erro:', error);
        showNotification('Erro de conexão', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rocket"></i> FAZER STAKE';
        }
    }
}

async function unstakeFunds() {
    const user = window.authManager?.currentUser;
    if (!user) {
        showNotification('Faça login primeiro!', 'warning');
        return;
    }

    if (stakedAmount <= 0) {
        showNotification('Você não tem nada em stake!', 'warning');
        return;
    }

    if (!confirm('Deseja resgatar todo o seu stake?\n\nO rendimento acumulado será creditado junto.')) {
        return;
    }

    const btn = document.getElementById('unstakeBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    }

    try {
        const response = await fetch('/api/unstake.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ google_uid: user.uid })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(`✅ Resgatado! Rendimento: ${formatBRL(data.reward_brl)}`, 'success');
            loadStakingData();
        } else {
            showNotification(data.error || 'Erro ao fazer unstake', 'error');
        }
    } catch (error) {
        console.error('Erro:', error);
        showNotification('Erro de conexão', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wallet"></i> RESGATAR TUDO';
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
        const statusTexts = { 'pending': 'Em Progresso', 'completed': 'Completado', 'claimed': 'Resgatado' };
        const userDisplay = ref.display_name || ref.email?.split('@')[0] || 'Usuário';
        
        return `
            <tr>
                <td class="user-cell">${userDisplay}</td>
                <td>
                    <div>${ref.missions_completed || 0}/100 missões</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${ref.progress_percent || 0}%"></div>
                    </div>
                </td>
                <td><span class="status-badge status-${ref.status}">${statusTexts[ref.status] || ref.status}</span></td>
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
    document.getElementById('mobileMenuBtn')?.addEventListener('click', () => {
        document.getElementById('nav')?.classList.toggle('open');
    });
    
    // Staking
    document.getElementById('stakeBtn')?.addEventListener('click', stakeFunds);
    document.getElementById('unstakeBtn')?.addEventListener('click', unstakeFunds);
    document.getElementById('projectionAmount')?.addEventListener('input', updateProjection);
    
    // FAQ
    document.querySelectorAll('.faq-question').forEach(q => {
        q.addEventListener('click', () => {
            document.querySelectorAll('.faq-item.open').forEach(item => {
                if (item !== q.parentElement) item.classList.remove('open');
            });
            q.parentElement.classList.toggle('open');
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
window.stakeFunds = stakeFunds;
window.unstakeFunds = unstakeFunds;
window.setStakeAmount = setStakeAmount;
window.setMaxAmount = setMaxAmount;
window.updateProjection = updateProjection;
window.showNotification = showNotification;
window.copyReferralLink = copyReferralLink;
window.claimCommissions = claimCommissions;
window.loadAffiliateData = loadAffiliateData;
window.formatBRL = formatBRL;

console.log('🚀 Unobix Main.js carregado');
