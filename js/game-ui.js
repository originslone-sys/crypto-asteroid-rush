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
        startPreGameLoadingWithAds();
    } else {
        preGameScreen.classList.remove('active');
    }
}

let loadingProgress = 0;
let loadingInterval = null;

// Dicas do jogo para rotação
const gameTips = [
    'DICA: Destrua asteroides raros para ganhar mais R$!',
    'DICA: Asteroides épicos valem muito mais que os comuns!',
    'DICA: Lendários são raros mas valem uma fortuna!',
    'DICA: Faça staking dos seus ganhos para rendimento automático!',
    'DICA: Indique amigos e ganhe comissão por indicação!',
    'DICA: Quanto mais missões completar, maiores as recompensas!',
    'DICA: Evite colisões para manter suas vidas intactas!',
    'DICA: Use a carteira para acompanhar seus ganhos!',
];

const postGameTips = [
    'Faça staking dos seus ganhos para rendimento automático!',
    'Indique amigos e ganhe comissão quando eles jogarem!',
    'Quanto mais missões, melhores suas recompensas!',
    'Saque seus ganhos direto para Pix quando quiser!',
    'Mude sua nave na tela inicial para variar o gameplay!',
    'Jogue diariamente para maximizar seus ganhos!',
];

function getRandomTipFromList(list) {
    return list[Math.floor(Math.random() * list.length)];
}

/**
 * PRÉ-JOGO: Loading com barra de progresso + dicas + anúncios rodando juntos
 */
function startPreGameLoadingWithAds() {
    loadingProgress = 0;
    const loadingBar = document.getElementById('loadingBar');
    const loadingPercent = document.getElementById('loadingPercent');
    const loadingStatus = document.getElementById('loadingStatus');
    const gameTip = document.getElementById('gameTip');
    const adContainer = document.getElementById('adContainer');
    
    if (!loadingBar || !loadingPercent) return;
    
    // Definir dica inicial
    if (gameTip) gameTip.textContent = typeof getRandomTip === 'function' ? getRandomTip() : getRandomTipFromList(gameTips);
    
    // Iniciar anúncio no container (não bloqueia, roda em paralelo)
    if (typeof AdsManager !== 'undefined' && AdsManager.isEnabled?.() && adContainer) {
        try {
            const slot = AdsManager.getNextSlot?.('pregame');
            if (slot) {
                adContainer.innerHTML = AdsManager.getSlotHTML?.(slot) || '';
                AdsManager.executeScripts?.(adContainer);
                AdsManager.trackImpression?.(slot.id);
            }
        } catch (e) {
            console.warn('📺 Erro ao renderizar ad pregame:', e.message);
        }
    }
    
    // Determinar duração total baseada na config de ads
    const adDuration = (typeof AdsManager !== 'undefined' && AdsManager.isEnabled?.()) 
        ? (AdsManager.getConfig?.()?.pregame_total_duration || 10) 
        : 3; // sem ads = loading rápido de 3s
    
    const totalSteps = adDuration * 10; // 10 updates por segundo
    let step = 0;
    
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
        step++;
        loadingProgress = Math.min(100, (step / totalSteps) * 100);
        
        loadingBar.style.width = loadingProgress + '%';
        loadingPercent.textContent = Math.floor(loadingProgress) + '%';
        
        // Atualizar status
        const statusIndex = Math.min(Math.floor(loadingProgress / 20), statuses.length - 1);
        if (loadingStatus) loadingStatus.textContent = statuses[statusIndex];
        
        // Rotacionar dicas
        if (step % 30 === 0 && gameTip) {
            gameTip.textContent = typeof getRandomTip === 'function' ? getRandomTip() : getRandomTipFromList(gameTips);
        }
        
        // Concluído
        if (loadingProgress >= 100) {
            clearInterval(loadingInterval);
            loadingInterval = null;
            
            setTimeout(() => {
                showPreGameLoading(false);
                if (typeof actualStartGame === 'function') {
                    actualStartGame();
                }
            }, 400);
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
        lostEarningsEl.textContent = formatEarningsBRL(lostEarnings);
    }
    
    // Renderizar anúncio no game over (direto na tela)
    const goAdContainer = document.getElementById('gameoverAdContainer');
    if (goAdContainer && typeof AdsManager !== 'undefined' && AdsManager.isEnabled?.()) {
        try {
            const slot = AdsManager.getNextSlot?.('endgame');
            if (slot) {
                goAdContainer.innerHTML = AdsManager.getSlotHTML?.(slot) || '';
                AdsManager.executeScripts?.(goAdContainer);
                AdsManager.trackImpression?.(slot.id);
            } else {
                goAdContainer.innerHTML = '';
            }
        } catch (e) {
            console.warn('📺 Erro ad gameover:', e.message);
            goAdContainer.innerHTML = '';
        }
    }
    
    showModal('gameOverModal');
}

// ============================================
// TELA DE FIM DE JOGO (vitória)
// Fluxo: loading pós-jogo com ads → resultados
// ============================================

// Armazena dados para exibir após o loading pós-jogo
let pendingEndGameData = null;

function showEndGameResults(stats, serverEarnings = null, serverBalance = null) {
    console.log('📊 showEndGameResults:', { 
        displayEarnings: serverEarnings || gameState.earnings,
        serverBalance: serverBalance,
        stats: stats 
    });
    
    // Salvar dados para exibir depois
    pendingEndGameData = { stats, serverEarnings, serverBalance };
    
    // Se ads estão habilitadas, mostrar tela de loading pós-jogo com anúncios
    if (typeof AdsManager !== 'undefined' && AdsManager.isEnabled?.()) {
        showPostGameLoading();
    } else {
        // Sem ads → ir direto para resultados
        displayEndGameResultsFinal();
    }
}

/**
 * PÓS-JOGO: Tela de loading com anúncios antes dos resultados
 */
function showPostGameLoading() {
    const postGameScreen = document.getElementById('postGameScreen');
    if (!postGameScreen) {
        // Se o elemento não existe, ir direto pros resultados
        displayEndGameResultsFinal();
        return;
    }
    
    postGameScreen.classList.add('active');
    
    const loadingBar = document.getElementById('postGameLoadingBar');
    const loadingPercent = document.getElementById('postGameLoadingPercent');
    const loadingStatus = document.getElementById('postGameLoadingStatus');
    const tipEl = document.getElementById('postGameTip');
    const adContainer = document.getElementById('postGameAdContainer');
    
    // Renderizar anúncio endgame
    if (adContainer && typeof AdsManager !== 'undefined') {
        try {
            const slot = AdsManager.getNextSlot?.('endgame');
            if (slot) {
                adContainer.innerHTML = AdsManager.getSlotHTML?.(slot) || '';
                AdsManager.executeScripts?.(adContainer);
                AdsManager.trackImpression?.(slot.id);
            }
        } catch (e) {
            console.warn('📺 Erro ad postgame:', e.message);
        }
    }
    
    // Dica inicial
    if (tipEl) tipEl.textContent = getRandomTipFromList(postGameTips);
    
    // Duração da tela pós-jogo (baseada na config de endgame)
    const duration = AdsManager.getConfig?.()?.endgame_rotation_interval || 8;
    const totalSteps = duration * 10;
    let step = 0;
    let progress = 0;
    
    const postGameStatuses = [
        'Calculando resultados...',
        'Verificando asteroides destruídos...',
        'Processando recompensas...',
        'Atualizando ranking...',
        'Finalizando...',
        'Resultados prontos!'
    ];
    
    const postGameInterval = setInterval(() => {
        step++;
        progress = Math.min(100, (step / totalSteps) * 100);
        
        if (loadingBar) loadingBar.style.width = progress + '%';
        if (loadingPercent) loadingPercent.textContent = Math.floor(progress) + '%';
        
        const statusIndex = Math.min(Math.floor(progress / 20), postGameStatuses.length - 1);
        if (loadingStatus) loadingStatus.textContent = postGameStatuses[statusIndex];
        
        // Rotacionar dicas
        if (step % 30 === 0 && tipEl) {
            tipEl.textContent = getRandomTipFromList(postGameTips);
        }
        
        if (progress >= 100) {
            clearInterval(postGameInterval);
            
            setTimeout(() => {
                postGameScreen.classList.remove('active');
                displayEndGameResultsFinal();
            }, 400);
        }
    }, 100);
}

/**
 * Exibir resultados finais (após loading pós-jogo)
 */
function displayEndGameResultsFinal() {
    if (!pendingEndGameData) return;
    
    const { stats, serverEarnings, serverBalance } = pendingEndGameData;
    pendingEndGameData = null;
    
    const finalScore = document.getElementById('finalScore');
    const finalReward = document.getElementById('finalReward');
    const breakdownContainer = document.getElementById('asteroidsBreakdown');
    
    const displayEarnings = (serverEarnings !== null && !isNaN(serverEarnings)) 
        ? serverEarnings 
        : gameState.earnings;
    
    if (finalScore) finalScore.textContent = gameState.score;
    if (finalReward) finalReward.textContent = formatEarningsBRL(displayEarnings);
    
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
