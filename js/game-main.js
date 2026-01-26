/* ============================================
   CRYPTO ASTEROID RUSH - Main Entry Point v3.0
   File: js/game-main.js
   Improved controls for desktop and mobile
   ============================================ */

// Keyboard controls
function handleKeyDown(e) {
    if (!gameState.gameActive) return;
    
    switch(e.key) {
        case 'ArrowLeft':
        case 'a':
        case 'A':
            gameState.keys.left = true;
            break;
        case 'ArrowRight':
        case 'd':
        case 'D':
            gameState.keys.right = true;
            break;
        case ' ':
        case 'Spacebar':
            e.preventDefault();
            gameState.keys.fire = true;
            break;
    }
}

function handleKeyUp(e) {
    switch(e.key) {
        case 'ArrowLeft':
        case 'a':
        case 'A':
            gameState.keys.left = false;
            break;
        case 'ArrowRight':
        case 'd':
        case 'D':
            gameState.keys.right = false;
            break;
        case ' ':
        case 'Spacebar':
            gameState.keys.fire = false;
            break;
    }
}

// Touch controls - continuous movement
let touchIntervals = {
    left: null,
    right: null
};

function startTouchMove(direction) {
    if (!gameState.gameActive || !gameState.ship) return;
    
    const speed = CONFIG.SHIP_SPEED;
    const minX = 50;
    const maxX = canvas.width - 50;
    
    // Clear existing interval
    if (touchIntervals[direction]) {
        clearInterval(touchIntervals[direction]);
    }
    
    // Move immediately
    if (direction === 'left') {
        gameState.ship.x = Math.max(minX, gameState.ship.x - speed);
    } else if (direction === 'right') {
        gameState.ship.x = Math.min(maxX, gameState.ship.x + speed);
    }
    
    // Continue moving while held
    touchIntervals[direction] = setInterval(() => {
        if (!gameState.gameActive || !gameState.ship) {
            clearInterval(touchIntervals[direction]);
            return;
        }
        
        if (direction === 'left') {
            gameState.ship.x = Math.max(minX, gameState.ship.x - speed);
        } else if (direction === 'right') {
            gameState.ship.x = Math.min(maxX, gameState.ship.x + speed);
        }
    }, 50);
}

function stopTouchMove(direction) {
    if (touchIntervals[direction]) {
        clearInterval(touchIntervals[direction]);
        touchIntervals[direction] = null;
    }
}

// Setup mobile control buttons
function setupMobileControls() {
    const leftBtn = document.getElementById('leftBtn');
    const rightBtn = document.getElementById('rightBtn');
    const fireBtn = document.getElementById('fireBtn');
    
    if (leftBtn) {
        // Touch events
        leftBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            unlockAudio();
            startTouchMove('left');
        }, { passive: false });
        
        leftBtn.addEventListener('touchend', (e) => {
            e.preventDefault();
            stopTouchMove('left');
        }, { passive: false });
        
        leftBtn.addEventListener('touchcancel', () => stopTouchMove('left'));
        
        // Mouse events (for desktop testing)
        leftBtn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startTouchMove('left');
        });
        
        leftBtn.addEventListener('mouseup', () => stopTouchMove('left'));
        leftBtn.addEventListener('mouseleave', () => stopTouchMove('left'));
    }
    
    if (rightBtn) {
        rightBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            unlockAudio();
            startTouchMove('right');
        }, { passive: false });
        
        rightBtn.addEventListener('touchend', (e) => {
            e.preventDefault();
            stopTouchMove('right');
        }, { passive: false });
        
        rightBtn.addEventListener('touchcancel', () => stopTouchMove('right'));
        
        rightBtn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startTouchMove('right');
        });
        
        rightBtn.addEventListener('mouseup', () => stopTouchMove('right'));
        rightBtn.addEventListener('mouseleave', () => stopTouchMove('right'));
    }
    
    if (fireBtn) {
        let fireInterval = null;
        
        const startFiring = () => {
            unlockAudio();
            fireBullet();
            
            fireInterval = setInterval(() => {
                if (gameState.gameActive) {
                    fireBullet();
                } else {
                    clearInterval(fireInterval);
                }
            }, CONFIG.FIRE_RATE);
        };
        
        const stopFiring = () => {
            if (fireInterval) {
                clearInterval(fireInterval);
                fireInterval = null;
            }
        };
        
        fireBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            startFiring();
        }, { passive: false });
        
        fireBtn.addEventListener('touchend', (e) => {
            e.preventDefault();
            stopFiring();
        }, { passive: false });
        
        fireBtn.addEventListener('touchcancel', stopFiring);
        
        fireBtn.addEventListener('mousedown', (e) => {
            e.preventDefault();
            startFiring();
        });
        
        fireBtn.addEventListener('mouseup', stopFiring);
        fireBtn.addEventListener('mouseleave', stopFiring);
    }
}

// Main initialization
document.addEventListener('DOMContentLoaded', () => {
    console.log('🎮 Crypto Asteroid Rush v3.0 - Initializing...');
    
    // Initialize UI elements
    initUIElements();
    initCanvas();
    
    // Load audio preference
    const savedAudio = localStorage.getItem('audioEnabled');
    if (savedAudio !== null) {
        gameState.audioEnabled = savedAudio === 'true';
        const audioIcon = document.getElementById('audioIcon');
        if (audioIcon) {
            audioIcon.className = gameState.audioEnabled ? 'fas fa-volume-up' : 'fas fa-volume-mute';
        }
    }
    
    // Main button event listeners
    document.getElementById('connectBtn')?.addEventListener('click', connectWallet);
    document.getElementById('audioToggle')?.addEventListener('click', toggleAudio);
    
    // Add ship selection UI
    addShipSelectionUI();
    
    // Start game button
    document.getElementById('startGameBtn')?.addEventListener('click', async function() {
        console.log('🎯 "Start Mission" button clicked');
        unlockAudio();
        
        if (typeof window.ethereum === 'undefined') {
            await gameAlert('MetaMask not found! Please install the app.', 'error', 'ERROR');
            return;
        }
        
        // Check if ship selected
        if (!gameState.selectedShipDesign) {
            const useRandom = await gameConfirm(
                'You haven\'t selected a ship for this mission.\nWould you like to use a random ship?',
                'NO SHIP SELECTED'
            );
            
            if (!useRandom) {
                showNotification('NOTICE', 'Please select a ship first!', true);
                return;
            }
        }
        
        await startGameSession();
    });
    
    // Play again button
    document.getElementById('playAgainBtn')?.addEventListener('click', () => {
        unlockAudio();
        showModal('gameMenuModal');
        addShipSelectionUI();
    });
    
    // Retry button (game over)
    document.getElementById('retryBtn')?.addEventListener('click', () => {
        unlockAudio();
        showModal('gameMenuModal');
        addShipSelectionUI();
    });
    
    // Exit button (game over)
    document.getElementById('exitBtn')?.addEventListener('click', () => {
        window.location.href = 'index.html';
    });
    
    // Wallet button
    document.getElementById('walletBtn')?.addEventListener('click', () => {
        window.location.href = 'wallet.html';
    });
    
    // Keyboard controls
    window.addEventListener('keydown', (e) => {
        unlockAudio();
        handleKeyDown(e);
    });
    
    window.addEventListener('keyup', handleKeyUp);
    
    // Canvas click to fire
    canvas?.addEventListener('click', () => {
        unlockAudio();
        if (gameState.gameActive) fireBullet();
    });
    
    // Setup mobile controls
    setupMobileControls();
    
    // Check for saved wallet
    if (gameState.wallet) {
        gameState.isConnected = true;
        updateWalletUI(gameState.wallet);
        
        // Check if coming from loading page (auto-start game)
        const params = new URLSearchParams(window.location.search);
        const shouldStart = params.get('start') === 'true';
        const loadingComplete = sessionStorage.getItem('loadingComplete') === 'true';
        
        if (shouldStart && loadingComplete) {
            // Clear the flag
            sessionStorage.removeItem('loadingComplete');
            // Remove URL params
            window.history.replaceState({}, '', 'game.html');
            // Start game directly
            console.log('🎮 Auto-starting game after loading screen');
            setTimeout(() => {
                startGameWithLoading();
            }, 500);
        } else {
            showModal('gameMenuModal');
        }
    }
    
    // MetaMask listeners
    if (window.ethereum) {
        window.ethereum.on('accountsChanged', (accounts) => {
            if (accounts.length === 0) {
                gameState.isConnected = false;
                gameState.wallet = null;
                localStorage.removeItem('connectedWallet');
                showNotification('DISCONNECTED', 'Wallet disconnected', true);
                showModal('connectModal');
            } else {
                gameState.wallet = accounts[0].toLowerCase();
                localStorage.setItem('connectedWallet', gameState.wallet);
                updateWalletUI(gameState.wallet);
                showNotification('CONNECTED', 'Wallet changed successfully');
            }
        });
        
        window.ethereum.on('chainChanged', () => {
            window.location.reload();
        });
    }
    
    // Unlock audio on any interaction
    const unlockOnInteraction = () => {
        if (!isAudioUnlocked) unlockAudio();
    };
    
    document.addEventListener('click', unlockOnInteraction);
    document.addEventListener('touchstart', unlockOnInteraction);
    document.addEventListener('keydown', unlockOnInteraction);
    
    // Remove loading screen
    setTimeout(() => {
        showLoading(false);
        console.log('✅ Game initialized successfully');
    }, 1500);
});

// Prevent context menu on game
document.addEventListener('contextmenu', (e) => {
    if (gameState.gameActive) {
        e.preventDefault();
    }
});

// Handle visibility change (pause when tab hidden)
document.addEventListener('visibilitychange', () => {
    if (document.hidden && gameState.gameActive) {
        // Could pause game here if needed
        gameState.keys = { left: false, right: false, fire: false };
        stopTouchMove('left');
        stopTouchMove('right');
    }
});
