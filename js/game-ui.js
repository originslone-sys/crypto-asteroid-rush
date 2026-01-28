/* ============================================
   CRYPTO ASTEROID RUSH - UI Functions v2.1
   File: js/game-ui.js
   Native alerts and improved UX
   FIX: Vidas apagam corretamente ao perder
   FIX: Mostra new balance do servidor
   ============================================ */

let loadingScreen, connectModal, gameMenuModal, endGameModal, gameOverModal;
let transactionPopup, notification, preGameScreen;
let customAlert, customConfirm;
let confirmCallback = null;

function initUIElements() {
    loadingScreen = document.getElementById('loadingScreen');
    connectModal = document.getElementById('connectModal');
    gameMenuModal = document.getElementById('gameMenuModal');
    endGameModal = document.getElementById('endGameModal');
    gameOverModal = document.getElementById('gameOverModal');
    transactionPopup = document.getElementById('transactionPopup');
    notification = document.getElementById('notification');
    preGameScreen = document.getElementById('preGameScreen');
    customAlert = document.getElementById('customAlert');
    customConfirm = document.getElementById('customConfirm');
    
    // Setup custom alert/confirm handlers
    setupCustomDialogs();
}

function setupCustomDialogs() {
    // Alert OK button
    document.getElementById('alertOkBtn')?.addEventListener('click', () => {
        customAlert.classList.remove('active');
    });
    
    // Confirm buttons
    document.getElementById('confirmYesBtn')?.addEventListener('click', () => {
        customConfirm.classList.remove('active');
        if (confirmCallback) {
            confirmCallback(true);
            confirmCallback = null;
        }
    });
    
    document.getElementById('confirmNoBtn')?.addEventListener('click', () => {
        customConfirm.classList.remove('active');
        if (confirmCallback) {
            confirmCallback(false);
            confirmCallback = null;
        }
    });
}

// Show initial loading screen
function showLoading(show) {
    if (!loadingScreen) return;
    
    loadingScreen.style.opacity = show ? '1' : '0';
    setTimeout(() => {
        loadingScreen.style.display = show ? 'flex' : 'none';
    }, show ? 0 : 500);
}

// Show pre-game loading screen with ads
function showPreGameLoading(show) {
    if (!preGameScreen) return;
    
    if (show) {
        preGameScreen.classList.add('active');
        startLoadingAnimation();
    } else {
        preGameScreen.classList.remove('active');
    }
}

let loadingProgress = 0;
let loadingInterval = null;

function startLoadingAnimation() {
    loadingProgress = 0;
    const loadingBar = document.getElementById('loadingBar');
    const loadingPercent = document.getElementById('loadingPercent');
    const loadingStatus = document.getElementById('loadingStatus');
    const gameTip = document.getElementById('gameTip');
    
    if (!loadingBar || !loadingPercent) return;
    
    // Set random tip
    if (gameTip && typeof getRandomTip === 'function') {
        gameTip.textContent = getRandomTip();
    }
    
    const statuses = [
        'Loading assets...',
        'Initializing engines...',
        'Calibrating weapons...',
        'Scanning asteroid field...',
        'Preparing mission...',
        'Ready for launch!'
    ];
    
    if (loadingInterval) clearInterval(loadingInterval);
    
    loadingInterval = setInterval(() => {
        loadingProgress += Math.random() * 15 + 5;
        
        if (loadingProgress >= 100) {
            loadingProgress = 100;
            clearInterval(loadingInterval);
            
            setTimeout(() => {
                showPreGameLoading(false);
                if (typeof actualStartGame === 'function') {
                    actualStartGame();
                }
            }, 500);
        }
        
        loadingBar.style.width = loadingProgress + '%';
        loadingPercent.textContent = Math.floor(loadingProgress) + '%';
        
        // Update status
        const statusIndex = Math.min(
            Math.floor(loadingProgress / 20),
            statuses.length - 1
        );
        if (loadingStatus) {
            loadingStatus.textContent = statuses[statusIndex];
        }
        
        // Change tip occasionally
        if (loadingProgress > 30 && loadingProgress < 80 && Math.random() < 0.1) {
            if (gameTip && typeof getRandomTip === 'function') {
                gameTip.textContent = getRandomTip();
            }
        }
    }, 100);
}

// Show modal by ID
function showModal(modalId) {
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        if (!modal.classList.contains('custom-alert') && 
            !modal.classList.contains('custom-confirm')) {
            modal.classList.remove('active');
        }
    });
    
    if (modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
        }
    }
}

// Show transaction popup
function showTransactionPopup(show) {
    if (!transactionPopup) return;
    
    if (show) {
        document.getElementById('txStatus').textContent = 'Awaiting confirmation...';
        transactionPopup.classList.add('active');
        gameState.transactionInProgress = true;
    } else {
        transactionPopup.classList.remove('active');
        gameState.transactionInProgress = false;
    }
}

// Update game UI
function updateUI() {
    const countdown = document.getElementById('countdown');
    const score = document.getElementById('score');
    const earned = document.getElementById('earned');
    
    if (countdown) {
        countdown.textContent = String(gameState.timeLeft).padStart(3, '0');
        
        if (gameState.timeLeft <= 10) {
            countdown.style.color = 'var(--danger)';
            countdown.style.textShadow = '0 0 20px var(--danger-glow)';
        } else {
            countdown.style.color = 'var(--primary)';
            countdown.style.textShadow = '0 0 20px var(--primary-glow)';
        }
    }
    
    if (score) {
        score.textContent = gameState.score;
    }
    
    if (earned) {
        earned.textContent = `$${gameState.earnings.toFixed(4)}`;
    }
    
    updateLivesDisplay();
}

// ============================================
// FIX: Update lives display - agora apaga corretamente
// ============================================
function updateLivesDisplay() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;
    
    const lifeElements = livesContainer.querySelectorAll('.life');
    lifeElements.forEach((life, index) => {
        if (index < gameState.lives) {
            // Vida ativa
            life.classList.add('active');
            life.classList.remove('lost');
        } else {
            // FIX: Vida perdida - adiciona classe 'lost' para estilização
            life.classList.remove('active');
            life.classList.add('lost');
        }
    });
}

// ============================================
// FIX: Animate life lost - animação + apaga permanentemente
// ============================================
function animateLifeLost() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;
    
    // Encontrar a última vida ativa (a que será perdida)
    const lifeElements = livesContainer.querySelectorAll('.life');
    let lostLifeIndex = -1;
    
    // Encontrar o índice da vida que acabou de perder
    lifeElements.forEach((life, index) => {
        if (index === gameState.lives) {
            lostLifeIndex = index;
        }
    });
    
    if (lostLifeIndex >= 0 && lostLifeIndex < lifeElements.length) {
        const lostLife = lifeElements[lostLifeIndex];
        
        // Adicionar animação de piscar
        lostLife.classList.add('losing');
        
        // Após animação, marcar como perdida permanentemente
        setTimeout(() => {
            lostLife.classList.remove('active', 'losing');
            lostLife.classList.add('lost');
        }, 300);
    }
}

// Show in-game notification
function showNotification(title, message, isSpecial = false) {
    if (!notification) return;
    
    const notifIcon = notification.querySelector('.notification-icon i');
    const notifTitle = document.getElementById('notificationTitle');
    const notifMessage = document.getElementById('notificationMessage');
    
    if (notifTitle) notifTitle.textContent = title;
    if (notifMessage) notifMessage.textContent = message;
    
    // Set icon based on type
    if (notifIcon) {
        if (title.includes('LEGENDARY')) {
            notifIcon.className = 'fas fa-star';
            notification.style.borderColor = '#FFD700';
        } else if (title.includes('EPIC')) {
            notifIcon.className = 'fas fa-gem';
            notification.style.borderColor = '#9932CC';
        } else if (title.includes('RARE')) {
            notifIcon.className = 'fas fa-diamond';
            notification.style.borderColor = '#1E90FF';
        } else if (title.includes('DAMAGE') || title.includes('HIT') || title.includes('COLLISION')) {
            notifIcon.className = 'fas fa-exclamation-triangle';
            notification.style.borderColor = 'var(--danger)';
        } else {
            notifIcon.className = 'fas fa-check-circle';
            notification.style.borderColor = 'var(--primary)';
        }
    }
    
    notification.classList.add('show');
    
    setTimeout(() => {
        notification.classList.remove('show');
    }, 2000);
}

// Update wallet UI
function updateWalletUI(walletAddress) {
    if (!walletAddress) return;
    
    const walletInfo = document.getElementById('connectedWalletInfo');
    const walletText = document.getElementById('connectedWalletText');
    
    if (walletInfo) {
        walletInfo.style.display = 'flex';
    }
    
    if (walletText) {
        walletText.textContent = `${walletAddress.substring(0, 6)}...${walletAddress.substring(walletAddress.length - 4)}`;
    }
}

// Custom Alert (replaces browser alert)
function gameAlert(message, type = 'info', title = 'Notice') {
    return new Promise((resolve) => {
        const alertIcon = document.getElementById('alertIcon');
        const alertTitle = document.getElementById('alertTitle');
        const alertMessage = document.getElementById('alertMessage');
        
        if (alertTitle) alertTitle.textContent = title;
        if (alertMessage) alertMessage.textContent = message;
        
        if (alertIcon) {
            alertIcon.className = 'alert-icon';
            
            switch (type) {
                case 'success':
                    alertIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
                    alertIcon.classList.add('success');
                    break;
                case 'warning':
                    alertIcon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                    alertIcon.classList.add('warning');
                    break;
                case 'error':
                    alertIcon.innerHTML = '<i class="fas fa-times-circle"></i>';
                    alertIcon.classList.add('error');
                    break;
                default:
                    alertIcon.innerHTML = '<i class="fas fa-info-circle"></i>';
            }
        }
        
        customAlert.classList.add('active');
        
        const okBtn = document.getElementById('alertOkBtn');
        const handler = () => {
            customAlert.classList.remove('active');
            okBtn.removeEventListener('click', handler);
            resolve();
        };
        okBtn.addEventListener('click', handler);
    });
}

// Custom Confirm (replaces browser confirm)
function gameConfirm(message, title = 'Confirm') {
    return new Promise((resolve) => {
        const confirmTitle = document.getElementById('confirmTitle');
        const confirmMessage = document.getElementById('confirmMessage');
        
        if (confirmTitle) confirmTitle.textContent = title;
        if (confirmMessage) confirmMessage.textContent = message;
        
        confirmCallback = resolve;
        customConfirm.classList.add('active');
    });
}

// Show game over screen
function showGameOver(lostEarnings) {
    const lostEarningsEl = document.getElementById('lostEarnings');
    if (lostEarningsEl) {
        lostEarningsEl.textContent = `$${lostEarnings.toFixed(4)}`;
    }
    
    showModal('gameOverModal');
}

// ============================================
// FIX: Show end game results - aceita valores do servidor
// ============================================
function showEndGameResults(stats, serverEarnings = null, serverBalance = null) {
    console.log('📊 showEndGameResults called with:', { 
        displayEarnings: serverEarnings || gameState.earnings,
        serverBalance: serverBalance,
        serverEarnings: serverEarnings,
        stats: stats 
    });
    
    const finalScore = document.getElementById('finalScore');
    const finalReward = document.getElementById('finalReward');
    const breakdownContainer = document.getElementById('asteroidsBreakdown');
    
    // FIX: Usar earnings do servidor se disponível
    const displayEarnings = (serverEarnings !== null && !isNaN(serverEarnings)) 
        ? serverEarnings 
        : gameState.earnings;
    
    if (finalScore) finalScore.textContent = gameState.score;
    if (finalReward) finalReward.textContent = `$${parseFloat(displayEarnings).toFixed(4)}`;
    
    // Generate breakdown HTML
    let breakdownHTML = `
        <div class="breakdown-title">ASTEROIDS BREAKDOWN</div>
        <div class="breakdown-grid">
            <div class="breakdown-item">
                <span class="breakdown-type common">
                    <span class="dot"></span>
                    Common
                </span>
                <span class="breakdown-count">${stats.common}</span>
            </div>
            <div class="breakdown-item">
                <span class="breakdown-type rare">
                    <span class="dot"></span>
                    Rare
                </span>
                <span class="breakdown-count">${stats.rare} (+$${(stats.rare * CONFIG.REWARDS.RARE).toFixed(4)})</span>
            </div>
            <div class="breakdown-item">
                <span class="breakdown-type epic">
                    <span class="dot"></span>
                    Epic
                </span>
                <span class="breakdown-count">${stats.epic} (+$${(stats.epic * CONFIG.REWARDS.EPIC).toFixed(4)})</span>
            </div>
            <div class="breakdown-item">
                <span class="breakdown-type legendary">
                    <span class="dot"></span>
                    Legendary
                </span>
                <span class="breakdown-count">${stats.legendary} (+$${(stats.legendary * CONFIG.REWARDS.LEGENDARY).toFixed(4)})</span>
            </div>
        </div>
    `;
    
    // FIX: Adicionar NEW BALANCE se disponível
    if (serverBalance !== null && !isNaN(serverBalance)) {
        breakdownHTML += `
            <div class="balance-update">
                <div class="balance-icon"><i class="fas fa-wallet"></i></div>
                <div class="balance-info">
                    <span class="balance-label">NEW BALANCE</span>
                    <span class="balance-value">$${parseFloat(serverBalance).toFixed(4)}</span>
                </div>
            </div>
        `;
    }
    
    if (breakdownContainer) {
        breakdownContainer.innerHTML = breakdownHTML;
    }
    
    showModal('endGameModal');
}

// Update selected ship info
function updateSelectedShipInfo(shipDesign) {
    const infoEl = document.getElementById('selectedShipInfo');
    if (!infoEl) return;
    
    const nameEl = infoEl.querySelector('.ship-name');
    if (nameEl) {
        if (shipDesign) {
            nameEl.textContent = shipDesign.name;
            nameEl.style.color = shipDesign.primary;
        } else {
            nameEl.textContent = 'Random ship will be assigned';
            nameEl.style.color = '';
        }
    }
}

// Legacy compatibility
function showMissionInfo(rareCount, hasEpic) {
    console.log('Mission info (legacy):', { rareCount, hasEpic });
}

// Export functions
window.initUIElements = initUIElements;
window.showLoading = showLoading;
window.showPreGameLoading = showPreGameLoading;
window.showModal = showModal;
window.showTransactionPopup = showTransactionPopup;
window.updateUI = updateUI;
window.updateLivesDisplay = updateLivesDisplay;
window.animateLifeLost = animateLifeLost;
window.showNotification = showNotification;
window.updateWalletUI = updateWalletUI;
window.showMissionInfo = showMissionInfo;
window.gameAlert = gameAlert;
window.gameConfirm = gameConfirm;
window.showGameOver = showGameOver;
window.showEndGameResults = showEndGameResults;
window.updateSelectedShipInfo = updateSelectedShipInfo;
