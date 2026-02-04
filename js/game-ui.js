/* ============================================
   UNOBIX - UI Functions v3.0
   File: js/game-ui.js
   Corrigido: Formato BRL, removido wallet MetaMask
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
    
    setupCustomDialogs();
}

function setupCustomDialogs() {
    document.getElementById('alertOkBtn')?.addEventListener('click', () => {
        customAlert.classList.remove('active');
    });
    
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

// Tela de carregamento inicial
function showLoading(show) {
    if (!loadingScreen) return;
    
    loadingScreen.style.opacity = show ? '1' : '0';
    setTimeout(() => {
        loadingScreen.style.display = show ? 'flex' : 'none';
    }, show ? 0 : 500);
}

// Tela pré-jogo (com anúncios)
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
    
    if (gameTip && typeof getRandomTip === 'function') {
        gameTip.textContent = getRandomTip();
    }
    
    const statuses = [
        'Carregando recursos...',
        'Inicializando motores...',
        'Calibrando armas...',
        'Escaneando campo de asteroides...',
        'Preparando missão...',
        'Pronto para lançamento!'
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
        
        const statusIndex = Math.min(
            Math.floor(loadingProgress / 20),
            statuses.length - 1
        );
        if (loadingStatus) {
            loadingStatus.textContent = statuses[statusIndex];
        }
        
        if (loadingProgress > 30 && loadingProgress < 80 && Math.random() < 0.1) {
            if (gameTip && typeof getRandomTip === 'function') {
                gameTip.textContent = getRandomTip();
            }
        }
    }, 100);
}

// Mostrar modal por ID
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

// Popup de transação
function showTransactionPopup(show) {
    if (!transactionPopup) return;
    
    if (show) {
        document.getElementById('txStatus').textContent = 'Aguardando confirmação...';
        transactionPopup.classList.add('active');
        gameState.transactionInProgress = true;
    } else {
        transactionPopup.classList.remove('active');
        gameState.transactionInProgress = false;
    }
}

// ============================================
// ATUALIZAR UI DO JOGO - FORMATO BRL
// ============================================
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
    
    // CORRIGIDO: Formato BRL
    if (earned) {
        earned.textContent = formatEarningsBRL(gameState.earnings);
    }
    
    updateLivesDisplay();
}

/**
 * Formatar ganhos em BRL (4 casas decimais)
 */
function formatEarningsBRL(value) {
    return 'R$ ' + (value || 0).toFixed(4).replace('.', ',');
}

/**
 * Formatar valor em BRL (2 casas decimais)
 */
function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(value || 0);
}

// ============================================
// DISPLAY DE VIDAS
// ============================================
function updateLivesDisplay() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;
    
    const lifeElements = livesContainer.querySelectorAll('.life');
    lifeElements.forEach((life, index) => {
        if (index < gameState.lives) {
            life.classList.add('active');
            life.classList.remove('lost');
        } else {
            life.classList.remove('active');
            life.classList.add('lost');
        }
    });
}

function animateLifeLost() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;
    
    const lifeElements = livesContainer.querySelectorAll('.life');
    let lostLifeIndex = -1;
    
    lifeElements.forEach((life, index) => {
        if (index === gameState.lives) {
            lostLifeIndex = index;
        }
    });
    
    if (lostLifeIndex >= 0 && lostLifeIndex < lifeElements.length) {
        const lostLife = lifeElements[lostLifeIndex];
        lostLife.classList.add('losing');
        
        setTimeout(() => {
            lostLife.classList.remove('active', 'losing');
            lostLife.classList.add('lost');
        }, 300);
    }
}

// ============================================
// NOTIFICAÇÕES IN-GAME
// ============================================
function showNotification(title, message, isSpecial = false) {
    if (!notification) return;
    
    const notifIcon = notification.querySelector('.notification-icon i');
    const notifTitle = document.getElementById('notificationTitle');
    const notifMessage = document.getElementById('notificationMessage');
    
    if (notifTitle) notifTitle.textContent = title;
    if (notifMessage) notifMessage.textContent = message;
    
    if (notifIcon) {
        if (title.includes('LENDÁRIO') || title.includes('LEGENDARY')) {
            notifIcon.className = 'fas fa-star';
            notification.style.borderColor = '#FFD700';
        } else if (title.includes('ÉPICO') || title.includes('EPIC')) {
            notifIcon.className = 'fas fa-gem';
            notification.style.borderColor = '#9932CC';
        } else if (title.includes('RARO') || title.includes('RARE')) {
            notifIcon.className = 'fas fa-diamond';
            notification.style.borderColor = '#1E90FF';
        } else if (title.includes('DANO') || title.includes('DAMAGE') || title.includes('HIT')) {
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

// ============================================
// ALERTAS E CONFIRMAÇÕES CUSTOMIZADOS
// ============================================
function gameAlert(message, type = 'info', title = 'Aviso') {
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

function gameConfirm(message, title = 'Confirmar') {
    return new Promise((resolve) => {
        const confirmTitle = document.getElementById('confirmTitle');
        const confirmMessage = document.getElementById('confirmMessage');
        
        if (confirmTitle) confirmTitle.textContent = title;
        if (confirmMessage) confirmMessage.textContent = message;
        
        confirmCallback = resolve;
        customConfirm.classList.add('active');
    });
}

// ============================================
// TELA DE GAME OVER (perdeu todas as vidas)
// ============================================
function showGameOver(lostEarnings) {
    const lostEarningsEl = document.getElementById('lostEarnings');
    if (lostEarningsEl) {
        // CORRIGIDO: Formato BRL
        lostEarningsEl.textContent = formatEarningsBRL(lostEarnings);
    }
    
    showModal('gameOverModal');
}

// ============================================
// TELA DE FIM DE JOGO (vitória)
// ============================================
function showEndGameResults(stats, serverEarnings = null, serverBalance = null) {
    console.log('📊 showEndGameResults:', { 
        displayEarnings: serverEarnings || gameState.earnings,
        serverBalance: serverBalance,
        stats: stats 
    });
    
    const finalScore = document.getElementById('finalScore');
    const finalReward = document.getElementById('finalReward');
    const breakdownContainer = document.getElementById('asteroidsBreakdown');
    
    // Usar earnings do servidor se disponível
    const displayEarnings = (serverEarnings !== null && !isNaN(serverEarnings)) 
        ? serverEarnings 
        : gameState.earnings;
    
    if (finalScore) finalScore.textContent = gameState.score;
    
    // CORRIGIDO: Formato BRL
    if (finalReward) finalReward.textContent = formatEarningsBRL(displayEarnings);
    
    // Gerar breakdown HTML
    let breakdownHTML = `
        <div class="breakdown-title">ASTEROIDES DESTRUÍDOS</div>
        <div class="breakdown-grid">
            <div class="breakdown-item">
                <span class="breakdown-type common">
                    <span class="dot"></span>
                    Comum
                </span>
                <span class="breakdown-count">${stats.common}</span>
            </div>
            <div class="breakdown-item">
                <span class="breakdown-type rare">
                    <span class="dot"></span>
                    Raro
                </span>
                <span class="breakdown-count">${stats.rare} (+${formatEarningsBRL(stats.rare * CONFIG.REWARDS.RARE)})</span>
            </div>
            <div class="breakdown-item">
                <span class="breakdown-type epic">
                    <span class="dot"></span>
                    Épico
                </span>
                <span class="breakdown-count">${stats.epic} (+${formatEarningsBRL(stats.epic * CONFIG.REWARDS.EPIC)})</span>
            </div>
            <div class="breakdown-item">
                <span class="breakdown-type legendary">
                    <span class="dot"></span>
                    Lendário
                </span>
                <span class="breakdown-count">${stats.legendary} (+${formatEarningsBRL(stats.legendary * CONFIG.REWARDS.LEGENDARY)})</span>
            </div>
        </div>
    `;
    
    // Mostrar novo saldo se disponível
    if (serverBalance !== null && !isNaN(serverBalance)) {
        breakdownHTML += `
            <div class="balance-update">
                <div class="balance-icon"><i class="fas fa-wallet"></i></div>
                <div class="balance-info">
                    <span class="balance-label">NOVO SALDO</span>
                    <span class="balance-value">${formatBRL(serverBalance)}</span>
                </div>
            </div>
        `;
        
        // Mostrar também no elemento dedicado
        const balanceUpdate = document.getElementById('balanceUpdate');
        const newBalanceEl = document.getElementById('newBalance');
        if (balanceUpdate && newBalanceEl) {
            newBalanceEl.textContent = formatBRL(serverBalance);
            balanceUpdate.style.display = 'flex';
        }
    }
    
    if (breakdownContainer) {
        breakdownContainer.innerHTML = breakdownHTML;
    }
    
    showModal('endGameModal');
}

// Atualizar info da nave selecionada
function updateSelectedShipInfo(shipDesign) {
    const infoEl = document.getElementById('selectedShipInfo');
    if (!infoEl) return;
    
    const nameEl = infoEl.querySelector('.ship-name');
    if (nameEl) {
        if (shipDesign) {
            nameEl.textContent = shipDesign.name;
            nameEl.style.color = shipDesign.primary;
        } else {
            nameEl.textContent = 'Nave aleatória será atribuída';
            nameEl.style.color = '';
        }
    }
}

// Legacy compatibility
function showMissionInfo(rareCount, hasEpic) {
    console.log('Mission info (legacy):', { rareCount, hasEpic });
}

// ============================================
// EXPORTAR FUNÇÕES
// ============================================
window.initUIElements = initUIElements;
window.showLoading = showLoading;
window.showPreGameLoading = showPreGameLoading;
window.showModal = showModal;
window.showTransactionPopup = showTransactionPopup;
window.updateUI = updateUI;
window.updateLivesDisplay = updateLivesDisplay;
window.animateLifeLost = animateLifeLost;
window.showNotification = showNotification;
window.showMissionInfo = showMissionInfo;
window.gameAlert = gameAlert;
window.gameConfirm = gameConfirm;
window.showGameOver = showGameOver;
window.showEndGameResults = showEndGameResults;
window.updateSelectedShipInfo = updateSelectedShipInfo;
window.formatEarningsBRL = formatEarningsBRL;
window.formatBRL = formatBRL;

console.log('📦 game-ui.js v3.0 carregado (BRL)');
