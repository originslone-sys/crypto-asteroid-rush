/* ============================================
   CRYPTO ASTEROID RUSH - Main JavaScript v3.3
   Dashboard, Wallet, Staking functionality
   UPDATED: Transaction history with asteroid details
   ============================================ */

// ============================================
// GLOBAL STATE
// ============================================

let currentWallet = null;
let userStats = {
    balance: 0,
    totalEarned: 0,
    gamesPlayed: 0,
    staked: 0,
    totalWithdrawn: 0,
    pendingWithdrawal: 0
};

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    createStars();
    captureReferralCode();
    initWalletState();
    setupEventListeners();
    
    const page = document.body.dataset.page;
    if (page === 'dashboard') {
        loadDashboardData();
    } else if (page === 'wallet') {
        loadWalletData();
    } else if (page === 'staking') {
        loadStakingData();
    } else if (page === 'affiliates') {
        loadAffiliateData();
    }
});

// ============================================
// REFERRAL SYSTEM
// ============================================

function captureReferralCode() {
    const urlParams = new URLSearchParams(window.location.search);
    const refCode = urlParams.get('ref');
    
    if (refCode && /^[A-Z0-9]{6}$/i.test(refCode)) {
        localStorage.setItem('referralCode', refCode.toUpperCase());
        localStorage.setItem('referralTimestamp', Date.now().toString());
        
        console.log('Referral code captured:', refCode.toUpperCase());
        
        if (window.history.replaceState) {
            const cleanUrl = window.location.protocol + '//' + window.location.host + window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    }
}

function getSavedReferralCode() {
    const code = localStorage.getItem('referralCode');
    const timestamp = localStorage.getItem('referralTimestamp');
    
    if (!code || !timestamp) return null;
    
    const sevenDays = 7 * 24 * 60 * 60 * 1000;
    if (Date.now() - parseInt(timestamp) > sevenDays) {
        localStorage.removeItem('referralCode');
        localStorage.removeItem('referralTimestamp');
        return null;
    }
    
    return code;
}

function clearReferralCode() {
    localStorage.removeItem('referralCode');
    localStorage.removeItem('referralTimestamp');
}

// ============================================
// STARS BACKGROUND
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
// WALLET - PERSISTENCE
// ============================================

function initWalletState() {
    const savedWallet = localStorage.getItem('connectedWallet');
    
    if (savedWallet) {
        currentWallet = savedWallet;
        updateWalletUI();
        hideConnectOverlay();
        verifyWalletAsync();
    } else {
        showConnectOverlay();
    }
}

async function verifyWalletAsync() {
    if (typeof window.ethereum === 'undefined') return;
    
    try {
        const accounts = await window.ethereum.request({ method: 'eth_accounts' });
        if (accounts.length > 0 && accounts[0].toLowerCase() !== currentWallet) {
            currentWallet = accounts[0].toLowerCase();
            localStorage.setItem('connectedWallet', currentWallet);
            updateWalletUI();
        }
    } catch (error) {
        console.log('Wallet verification:', error);
    }
    
    if (window.ethereum) {
        window.ethereum.on('accountsChanged', handleAccountsChanged);
    }
}

function handleAccountsChanged(accounts) {
    if (accounts.length === 0) {
        console.log('MetaMask disconnected, keeping session');
    } else {
        currentWallet = accounts[0].toLowerCase();
        localStorage.setItem('connectedWallet', currentWallet);
        location.reload();
    }
}

async function connectWallet() {
    if (typeof window.ethereum === 'undefined') {
        showNotification('MetaMask not detected! Please install the extension.', 'error');
        window.open('https://metamask.io/download/', '_blank');
        return;
    }
    
    try {
        const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
        currentWallet = accounts[0].toLowerCase();
        
        const chainId = await window.ethereum.request({ method: 'eth_chainId' });
        if (chainId !== '0x38') {
            try {
                await window.ethereum.request({
                    method: 'wallet_switchEthereumChain',
                    params: [{ chainId: '0x38' }]
                });
            } catch (switchError) {
                if (switchError.code === 4902) {
                    await window.ethereum.request({
                        method: 'wallet_addEthereumChain',
                        params: [{
                            chainId: '0x38',
                            chainName: 'BNB Smart Chain',
                            nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 },
                            rpcUrls: ['https://bsc-dataseed.binance.org/'],
                            blockExplorerUrls: ['https://bscscan.com/']
                        }]
                    });
                }
            }
        }
        
        localStorage.setItem('connectedWallet', currentWallet);
        
        // Login with referral code
        try {
            const referralCode = getSavedReferralCode();
            const loginData = { wallet: currentWallet };
            
            if (referralCode) {
                loginData.referral_code = referralCode;
            }
            
            const response = await fetch('api/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(loginData)
            });
            
            const result = await response.json();
            
            if (result.referral_registered) {
                clearReferralCode();
                showNotification(`Welcome! You were referred by ${result.referrer}`, 'success');
            }
            
        } catch (e) {
            console.log('Login error:', e);
        }
        
        hideConnectOverlay();
        updateWalletUI();
        
        const page = document.body.dataset.page;
        if (page === 'dashboard') loadDashboardData();
        else if (page === 'wallet') loadWalletData();
        else if (page === 'staking') loadStakingData();
        else if (page === 'affiliates') loadAffiliateData();
        
        showNotification('Wallet connected successfully!', 'success');
        
    } catch (error) {
        if (error.code !== 4001) {
            showNotification('Connection error. Try again.', 'error');
        }
    }
}

function disconnectWallet() {
    currentWallet = null;
    localStorage.removeItem('connectedWallet');
    showConnectOverlay();
    updateWalletUI();
}

function updateWalletUI() {
    const walletBtn = document.getElementById('walletBtn');
    if (!walletBtn) return;
    
    if (currentWallet) {
        const short = currentWallet.substring(0, 6) + '...' + currentWallet.substring(38);
        walletBtn.innerHTML = `<i class="fas fa-check-circle"></i> ${short}`;
        walletBtn.classList.add('connected');
    } else {
        walletBtn.innerHTML = `<i class="fas fa-wallet"></i> Connect`;
        walletBtn.classList.remove('connected');
    }
}

function showConnectOverlay() {
    const overlay = document.getElementById('connectOverlay');
    if (overlay) overlay.classList.remove('hidden');
}

function hideConnectOverlay() {
    const overlay = document.getElementById('connectOverlay');
    if (overlay) overlay.classList.add('hidden');
}

// ============================================
// DASHBOARD DATA
// ============================================

async function loadDashboardData() {
    if (!currentWallet) return;
    
    try {
        const walletRes = await fetch(`api/wallet-info.php?wallet=${encodeURIComponent(currentWallet)}`);
        const walletData = await walletRes.json();
        
        if (walletData.success) {
            userStats.balance = parseFloat(walletData.balance) || 0;
            userStats.totalEarned = parseFloat(walletData.total_earned) || 0;
            userStats.gamesPlayed = parseInt(walletData.total_played) || 0;
            userStats.totalWithdrawn = parseFloat(walletData.total_withdrawn) || 0;
            userStats.pendingWithdrawal = parseFloat(walletData.pending_withdrawal) || 0;
        }
        
        const stakingRes = await fetch('api/get-stakes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ wallet: currentWallet })
        });
        const stakingData = await stakingRes.json();
        
        if (stakingData.success) {
            userStats.staked = parseFloat(stakingData.total_staked) || 0;
        }
        
        updateDashboardUI();
        loadRecentActivity();
        
    } catch (error) {
        console.error('Error loading dashboard:', error);
    }
}

function updateDashboardUI() {
    const el = (id) => document.getElementById(id);
    
    if (el('statBalance')) el('statBalance').textContent = `$${userStats.balance.toFixed(4)}`;
    if (el('statEarned')) el('statEarned').textContent = `$${userStats.totalEarned.toFixed(4)}`;
    if (el('statGames')) el('statGames').textContent = userStats.gamesPlayed;
    if (el('statStaked')) el('statStaked').textContent = `$${userStats.staked.toFixed(4)}`;
}

async function loadRecentActivity() {
    if (!currentWallet) return;
    
    const activityList = document.getElementById('activityList');
    if (!activityList) return;
    
    try {
        const res = await fetch(`api/transactions.php?wallet=${encodeURIComponent(currentWallet)}&limit=5`);
        const data = await res.json();
        
        if (data.success && data.transactions && data.transactions.length > 0) {
            let html = '';
            data.transactions.forEach(tx => {
                const icon = getActivityIcon(tx.type);
                const isNegative = tx.amount < 0 || tx.type.includes('withdraw') || tx.type === 'stake';
                const color = isNegative ? 'var(--danger)' : 'var(--success)';
                const sign = isNegative ? '' : '+';
                const amount = Math.abs(parseFloat(tx.amount));
                const date = new Date(tx.created_at).toLocaleDateString();
                
                html += `
                    <div class="activity-item">
                        <div class="activity-icon" style="color: ${color}">${icon}</div>
                        <div class="activity-info">
                            <div class="activity-title">${formatActivityType(tx.type)}</div>
                            <div class="activity-desc">${tx.description || ''}</div>
                        </div>
                        <div class="activity-amount" style="color: ${color}">${sign}$${amount.toFixed(4)}</div>
                        <div class="activity-date">${date}</div>
                    </div>
                `;
            });
            activityList.innerHTML = html;
        } else {
            activityList.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No activity yet</p>
                </div>
            `;
        }
    } catch (error) {
        activityList.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <p>Error loading activity</p>
            </div>
        `;
    }
}

function getActivityIcon(type) {
    const icons = {
        'game_win': '<i class="fas fa-gamepad"></i>',
        'mission': '<i class="fas fa-rocket"></i>',
        'withdrawal': '<i class="fas fa-arrow-up"></i>',
        'withdrawal_request': '<i class="fas fa-clock"></i>',
        'stake': '<i class="fas fa-lock"></i>',
        'unstake': '<i class="fas fa-unlock"></i>',
        'staking_reward': '<i class="fas fa-gift"></i>',
        'referral_commission': '<i class="fas fa-users"></i>',
        'referral': '<i class="fas fa-users"></i>',
        'deposit': '<i class="fas fa-arrow-down"></i>'
    };
    return icons[type] || '<i class="fas fa-circle"></i>';
}

function formatActivityType(type) {
    const names = {
        'game_win': 'Mission Reward',
        'mission': 'Mission Reward',
        'withdrawal': 'Withdrawal',
        'withdrawal_request': 'Withdrawal Request',
        'stake': 'Staked',
        'unstake': 'Unstaked',
        'staking_reward': 'Staking Reward',
        'referral_commission': 'Referral Commission',
        'referral': 'Referral Commission',
        'deposit': 'Deposit'
    };
    return names[type] || type;
}

// ============================================
// WALLET DATA
// ============================================

async function loadWalletData() {
    if (!currentWallet) return;
    
    try {
        const res = await fetch(`api/wallet-info.php?wallet=${encodeURIComponent(currentWallet)}`);
        const data = await res.json();
        
        if (data.success) {
            const el = (id) => document.getElementById(id);
            
            if (el('walletBalance')) el('walletBalance').textContent = `$${parseFloat(data.balance).toFixed(6)}`;
            if (el('walletEarned')) el('walletEarned').textContent = `$${parseFloat(data.total_earned).toFixed(6)}`;
            if (el('walletWithdrawn')) el('walletWithdrawn').textContent = `$${parseFloat(data.total_withdrawn).toFixed(6)}`;
            if (el('walletPending')) el('walletPending').textContent = `$${parseFloat(data.pending_withdrawal).toFixed(6)}`;
            if (el('walletAddress')) el('walletAddress').textContent = currentWallet;
        }
        
        loadTransactionHistory();
        
    } catch (error) {
        console.error('Error loading wallet:', error);
    }
}

// ============================================
// TRANSACTION HISTORY - IMPROVED v2.0
// Shows asteroid breakdown for missions
// ============================================

async function loadTransactionHistory() {
    if (!currentWallet) return;
    
    const historyList = document.getElementById('transactionHistory');
    if (!historyList) return;
    
    try {
        const res = await fetch(`api/transactions.php?wallet=${encodeURIComponent(currentWallet)}&limit=30`);
        const data = await res.json();
        
        if (data.success && data.transactions && data.transactions.length > 0) {
            let html = '';
            data.transactions.forEach(tx => {
                const icon = getActivityIcon(tx.type);
                const isNegative = tx.amount < 0 || tx.type.includes('withdraw') || tx.type === 'stake';
                const iconClass = isNegative ? 'negative' : 'positive';
                const amountColor = isNegative ? 'var(--danger)' : 'var(--success)';
                const sign = isNegative ? '' : '+';
                const amount = Math.abs(parseFloat(tx.amount));
                const date = new Date(tx.created_at);
                const dateStr = date.toLocaleDateString();
                const timeStr = date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                
                // Description
                let description = tx.description || '';
                let asteroidDetails = '';
                
                // If mission, show asteroid breakdown
                if (tx.type === 'mission' && tx.details) {
                    const d = tx.details;
                    if (d.legendary_asteroids > 0) {
                        asteroidDetails += `<span class="asteroid-badge legendary">★ ${d.legendary_asteroids}</span>`;
                    }
                    if (d.epic_asteroids > 0) {
                        asteroidDetails += `<span class="asteroid-badge epic">◆ ${d.epic_asteroids}</span>`;
                    }
                    if (d.rare_asteroids > 0) {
                        asteroidDetails += `<span class="asteroid-badge rare">● ${d.rare_asteroids}</span>`;
                    }
                    if (d.common_asteroids > 0) {
                        asteroidDetails += `<span class="asteroid-badge common">○ ${d.common_asteroids}</span>`;
                    }
                }
                
                // Status class
                let statusClass = tx.status || 'completed';
                if (statusClass === 'approved') statusClass = 'completed';
                
                html += `
                    <div class="transaction-item">
                        <div class="tx-icon ${iconClass}">${icon}</div>
                        <div class="tx-info">
                            <div class="tx-type">${formatActivityType(tx.type)}</div>
                            <div class="tx-desc">${description}</div>
                            ${asteroidDetails ? `<div class="asteroid-details">${asteroidDetails}</div>` : ''}
                        </div>
                        <div class="tx-amount" style="color: ${amountColor}">${sign}$${amount.toFixed(6)}</div>
                        <div class="tx-meta">
                            <div class="tx-date">${dateStr} ${timeStr}</div>
                            <span class="tx-status ${statusClass}">${tx.status}</span>
                        </div>
                    </div>
                `;
            });
            historyList.innerHTML = html;
        } else {
            historyList.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No transactions yet</p>
                    <p style="font-size: 0.85rem; margin-top: 10px;">Play missions to start earning!</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading transactions:', error);
        historyList.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <p>Error loading transactions</p>
                <p style="font-size: 0.85rem; margin-top: 10px;">Please try again later</p>
            </div>
        `;
    }
}

// ============================================
// WITHDRAWAL
// ============================================

async function requestWithdraw() {
    const amountInput = document.getElementById('withdrawAmount');
    const addressInput = document.getElementById('withdrawAddress');
    
    const amount = parseFloat(amountInput?.value);
    const address = addressInput?.value?.trim() || currentWallet;
    
    if (!amount || amount < 0.01) {
        showNotification('Minimum withdrawal: $0.01', 'warning');
        return;
    }
    
    const btn = document.querySelector('.withdraw-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    try {
        const res = await fetch('api/withdraw.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                wallet: currentWallet,
                amount: amount,
                withdraw_address: address
            })
        });
        const data = await res.json();
        
        if (data.success) {
            showNotification('Withdrawal requested successfully!', 'success');
            amountInput.value = '';
            setTimeout(loadWalletData, 1000);
        } else {
            showNotification(data.error || 'Error requesting withdrawal', 'error');
        }
    } catch (error) {
        showNotification('Connection error', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Request Withdrawal';
        }
    }
}

// ============================================
// STAKING DATA
// ============================================

let availableBalance = 0;

async function loadStakingData() {
    if (!currentWallet) return;
    
    try {
        const balanceRes = await fetch(`api/wallet-info.php?wallet=${encodeURIComponent(currentWallet)}`);
        const balanceData = await balanceRes.json();
        
        if (balanceData.success) {
            availableBalance = parseFloat(balanceData.balance) || 0;
            const el = document.getElementById('stakingBalance');
            if (el) el.textContent = `$${availableBalance.toFixed(6)}`;
        }
        
        const stakingRes = await fetch('api/get-stakes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ wallet: currentWallet })
        });
        const stakingData = await stakingRes.json();
        
        if (stakingData.success) {
            const totalStaked = parseFloat(stakingData.total_staked) || 0;
            const totalEarned = parseFloat(stakingData.total_earned) || 0;
            const todayEarnings = parseFloat(stakingData.today_earnings) || 0;
            
            const el = (id) => document.getElementById(id);
            if (el('totalStaked')) el('totalStaked').textContent = `$${totalStaked.toFixed(6)}`;
            if (el('stakingEarned')) el('stakingEarned').textContent = `$${totalEarned.toFixed(6)}`;
            if (el('todayEarnings')) el('todayEarnings').textContent = `+$${todayEarnings.toFixed(6)}`;
            
            const unstakeBtn = document.getElementById('unstakeBtn');
            if (unstakeBtn) unstakeBtn.disabled = totalStaked === 0;
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

async function stakeFunds() {
    const amountInput = document.getElementById('stakeAmount');
    const amount = parseFloat(amountInput?.value);
    
    if (!amount || amount < 0.0001) {
        showNotification('Minimum amount: $0.0001', 'warning');
        return;
    }
    
    if (amount > availableBalance) {
        showNotification('Insufficient balance', 'warning');
        return;
    }
    
    const btn = document.getElementById('stakeBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    try {
        const res = await fetch('api/stake.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ wallet: currentWallet, amount })
        });
        const data = await res.json();
        
        if (data.success) {
            showNotification('Stake created successfully!', 'success');
            amountInput.value = '';
            setTimeout(loadStakingData, 1000);
        } else {
            showNotification(data.error || 'Error', 'error');
        }
    } catch (error) {
        showNotification('Error', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rocket"></i> STAKE NOW';
        }
    }
}

async function unstakeFunds() {
    if (!confirm('Are you sure you want to unstake all funds?')) return;
    
    const btn = document.getElementById('unstakeBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    try {
        const res = await fetch('api/unstake.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ wallet: currentWallet })
        });
        const data = await res.json();
        
        if (data.success) {
            showNotification('Unstake requested!', 'success');
            setTimeout(loadStakingData, 1000);
        } else {
            showNotification(data.error || 'Error', 'error');
        }
    } catch (error) {
        showNotification('Error', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wallet"></i> UNSTAKE ALL';
        }
    }
}

function setStakeAmount(amount) {
    const input = document.getElementById('stakeAmount');
    if (input) input.value = amount;
    updateProjection();
}

function setMaxAmount() {
    const input = document.getElementById('stakeAmount');
    if (input && availableBalance > 0) {
        input.value = availableBalance.toFixed(6);
        updateProjection();
    }
}

// ============================================
// PROJECTION CALCULATOR
// ============================================

const APY = 0.12;

function updateProjection() {
    const amount = parseFloat(document.getElementById('projectionAmount')?.value) || 100;
    const hourlyRate = APY / (365 * 24);
    
    const calcEarnings = (hours) => (amount * Math.exp(hourlyRate * hours) - amount).toFixed(6);
    
    const el = (id) => document.getElementById(id);
    if (el('projHour')) el('projHour').textContent = `$${calcEarnings(1)}`;
    if (el('projDay')) el('projDay').textContent = `$${calcEarnings(24)}`;
    if (el('projWeek')) el('projWeek').textContent = `$${calcEarnings(24 * 7)}`;
    if (el('projMonth')) el('projMonth').textContent = `$${calcEarnings(24 * 30)}`;
    if (el('projYear')) el('projYear').textContent = `$${calcEarnings(24 * 365)}`;
}

// ============================================
// AFFILIATE SYSTEM
// ============================================

let affiliateData = null;

async function loadAffiliateData() {
    if (!currentWallet) return;
    
    try {
        const res = await fetch(`api/referral-info.php?wallet=${encodeURIComponent(currentWallet)}`);
        const data = await res.json();
        
        if (data.success) {
            affiliateData = data;
            updateAffiliateUI(data);
        } else {
            console.error('Error loading affiliate data:', data.error);
        }
    } catch (error) {
        console.error('Error loading affiliate data:', error);
    }
}

function updateAffiliateUI(data) {
    const baseUrl = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
    const fullLink = baseUrl + '?ref=' + data.referral_code;
    
    const linkInput = document.getElementById('referralLink');
    const codeSpan = document.getElementById('referralCode');
    
    if (linkInput) linkInput.value = fullLink;
    if (codeSpan) codeSpan.textContent = data.referral_code;
    
    const stats = data.stats;
    const el = (id) => document.getElementById(id);
    
    if (el('statTotalReferred')) el('statTotalReferred').textContent = stats.total_referred;
    if (el('statPending')) el('statPending').textContent = stats.pending;
    if (el('statCompleted')) el('statCompleted').textContent = parseInt(stats.completed) + parseInt(stats.claimed || 0);
    if (el('statTotalEarned')) el('statTotalEarned').textContent = '$' + stats.total_earned;
    
    const availableCommission = parseFloat(stats.available_commission);
    const claimSection = document.getElementById('claimSection');
    const claimAmount = document.getElementById('claimAmount');
    
    if (claimSection) {
        if (availableCommission > 0) {
            claimSection.style.display = 'flex';
            if (claimAmount) claimAmount.textContent = '$' + stats.available_commission;
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
                        <h3>No referrals yet</h3>
                        <p>Share your link to start earning!</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    let html = '';
    referrals.forEach(ref => {
        const statusClass = 'status-' + ref.status;
        const statusText = ref.status.charAt(0).toUpperCase() + ref.status.slice(1);
        const progressPercent = ref.progress_percent;
        const progressText = ref.missions_completed + '/' + ref.missions_required;
        const date = new Date(ref.created_at).toLocaleDateString();
        
        html += `
            <tr>
                <td class="wallet-cell">${ref.wallet_short}</td>
                <td>
                    <div>${progressText} missions</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${progressPercent}%"></div>
                    </div>
                </td>
                <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                <td style="color: var(--success);">$${ref.commission}</td>
                <td style="color: var(--text-dim);">${date}</td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

function copyReferralLink() {
    const linkInput = document.getElementById('referralLink');
    const copyBtn = document.getElementById('copyBtn');
    
    if (!linkInput) return;
    
    linkInput.select();
    linkInput.setSelectionRange(0, 99999);
    
    navigator.clipboard.writeText(linkInput.value).then(() => {
        if (copyBtn) {
            copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            copyBtn.classList.add('copied');
            
            setTimeout(() => {
                copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                copyBtn.classList.remove('copied');
            }, 2000);
        }
        
        showNotification('Link copied to clipboard!', 'success');
    }).catch(() => {
        document.execCommand('copy');
        showNotification('Link copied!', 'success');
    });
}

async function claimCommissions() {
    if (!currentWallet) {
        showNotification('Please connect your wallet', 'warning');
        return;
    }
    
    const claimBtn = document.getElementById('claimBtn');
    if (claimBtn) {
        claimBtn.disabled = true;
        claimBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    try {
        const res = await fetch('api/referral-claim.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ wallet: currentWallet })
        });
        const data = await res.json();
        
        if (data.success) {
            showNotification(`Successfully claimed $${data.amount_claimed}!`, 'success');
            setTimeout(loadAffiliateData, 1000);
        } else {
            showNotification(data.error || 'Error claiming commissions', 'error');
        }
    } catch (error) {
        console.error('Error claiming:', error);
        showNotification('Connection error', 'error');
    } finally {
        if (claimBtn) {
            claimBtn.disabled = false;
            claimBtn.innerHTML = '<i class="fas fa-download"></i> CLAIM TO BALANCE';
        }
    }
}

// ============================================
// EVENT LISTENERS
// ============================================

function setupEventListeners() {
    // Connect button
    document.getElementById('connectBtn')?.addEventListener('click', connectWallet);
    
    // Wallet button in header
    document.getElementById('walletBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        if (currentWallet) {
            window.location.href = 'wallet.html';
        } else {
            connectWallet();
        }
    });
    
    // Mobile menu
    document.getElementById('mobileMenuBtn')?.addEventListener('click', () => {
        document.getElementById('nav')?.classList.toggle('open');
    });
    
    // Staking buttons
    document.getElementById('stakeBtn')?.addEventListener('click', stakeFunds);
    document.getElementById('unstakeBtn')?.addEventListener('click', unstakeFunds);
    
    // Projection calculator
    document.getElementById('projectionAmount')?.addEventListener('input', updateProjection);
    
    // FAQ toggles
    document.querySelectorAll('.faq-question').forEach(q => {
        q.addEventListener('click', () => {
            document.querySelectorAll('.faq-item.open').forEach(item => {
                if (item !== q.parentElement) {
                    item.classList.remove('open');
                }
            });
            q.parentElement.classList.toggle('open');
        });
    });
}

// ============================================
// NOTIFICATIONS
// ============================================

function showNotification(message, type = 'info') {
    document.querySelector('.notification')?.remove();
    
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideIn 0.3s ease reverse';
        setTimeout(() => notification.remove(), 300);
    }, 4000);
}

// ============================================
// GLOBAL EXPORTS
// ============================================

window.connectWallet = connectWallet;
window.disconnectWallet = disconnectWallet;
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