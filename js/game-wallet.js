/* ============================================
   CRYPTO ASTEROID RUSH - Wallet & Blockchain
   File: js/game-wallet.js
   ============================================ */

async function connectWallet() {
    if (typeof window.ethereum === 'undefined') {
        await gameAlert('MetaMask not found!\nPlease install the extension.', 'error', 'WALLET ERROR');
        window.open('https://metamask.io/download/', '_blank');
        return;
    }
    
    try {
        unlockAudio();
        showLoading(true);
        
        const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
        gameState.wallet = accounts[0].toLowerCase();
        localStorage.setItem('connectedWallet', gameState.wallet);
        gameState.isConnected = true;
        
        const chainId = await window.ethereum.request({ method: 'eth_chainId' });
        if (chainId !== CONFIG.BSC_CHAIN_ID) {
            try {
                await window.ethereum.request({
                    method: 'wallet_switchEthereumChain',
                    params: [{ chainId: CONFIG.BSC_CHAIN_ID }]
                });
            } catch (switchError) {
                if (switchError.code === 4902) {
                    await window.ethereum.request({
                        method: 'wallet_addEthereumChain',
                        params: [{
                            chainId: CONFIG.BSC_CHAIN_ID,
                            chainName: 'Binance Smart Chain',
                            nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 },
                            rpcUrls: ['https://bsc-dataseed.binance.org/'],
                            blockExplorerUrls: ['https://bscscan.com']
                        }]
                    });
                }
            }
        }
        
        try {
            await fetch('api/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ wallet: gameState.wallet })
            });
        } catch (e) {}
        
        updateWalletUI(gameState.wallet);
        
        setTimeout(() => {
            showLoading(false);
            showModal('gameMenuModal');
            showNotification('SUCCESS', 'Wallet connected!');
        }, 1000);
        
    } catch (error) {
        showLoading(false);
        if (error.code === 4001) {
            showNotification('CANCELLED', 'Connection cancelled.', true);
        } else {
            await gameAlert('Error connecting wallet.', 'error', 'ERROR');
        }
    }
}

async function ensureWalletConnection() {
    if (typeof window.ethereum === 'undefined') return false;
    
    try {
        const accounts = await window.ethereum.request({ method: 'eth_accounts' });
        if (accounts && accounts.length > 0) {
            gameState.wallet = accounts[0].toLowerCase();
            localStorage.setItem('connectedWallet', gameState.wallet);
            return true;
        }
        
        const newAccounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
        if (newAccounts && newAccounts.length > 0) {
            gameState.wallet = newAccounts[0].toLowerCase();
            localStorage.setItem('connectedWallet', gameState.wallet);
            updateWalletUI(gameState.wallet);
            return true;
        }
        return false;
    } catch (error) {
        if (error.code === 4001) {
            await gameAlert('Connection required to play!', 'warning', 'REQUIRED');
        }
        return false;
    }
}

async function switchToBSC() {
    try {
        await window.ethereum.request({
            method: 'wallet_switchEthereumChain',
            params: [{ chainId: '0x38' }]
        });
        return true;
    } catch (switchError) {
        if (switchError.code === 4902) {
            try {
                await window.ethereum.request({
                    method: 'wallet_addEthereumChain',
                    params: [{
                        chainId: '0x38',
                        chainName: 'Binance Smart Chain',
                        nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 },
                        rpcUrls: ['https://bsc-dataseed.binance.org/'],
                        blockExplorerUrls: ['https://bscscan.com']
                    }]
                });
                return true;
            } catch (e) { return false; }
        }
        return false;
    }
}

async function handleTransactionError(error) {
    let msg = '';
    let type = 'error';
    let title = 'ERROR';
    
    if (error.code === 4001 || error.message?.includes('rejected')) {
        msg = 'Transaction cancelled.';
        type = 'warning';
        title = 'CANCELLED';
    } else if (error.message?.includes('insufficient funds')) {
        msg = 'Insufficient BNB balance!';
    } else {
        msg = error.message || 'Unknown error';
    }
    
    await gameAlert(msg, type, title);
}

async function startGameSession() {
    const isConnected = await ensureWalletConnection();
    if (!isConnected) {
        await gameAlert('Could not connect wallet.', 'error', 'ERROR');
        return;
    }
    
    const startBtn = document.getElementById('startGameBtn');
    const originalText = startBtn.innerHTML;
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Processing...</span>';
    
    try {
        const accounts = await window.ethereum.request({ method: 'eth_accounts' });
        if (!accounts || accounts.length === 0) throw new Error('Disconnected');
        
        const currentAccount = accounts[0].toLowerCase();
        
        const chainId = await window.ethereum.request({ method: 'eth_chainId' });
        if (chainId !== '0x38') {
            startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Switching to BSC...</span>';
            const switched = await switchToBSC();
            if (!switched) throw new Error('Could not switch to BSC');
            await new Promise(r => setTimeout(r, 2000));
        }
        
        const balance = await window.ethereum.request({
            method: 'eth_getBalance',
            params: [currentAccount, 'latest']
        });
        
        const balanceBNB = parseInt(balance) / 1e18;
        if (balanceBNB < 0.00002) {
            throw new Error(`Low balance! Have: ${balanceBNB.toFixed(8)} BNB`);
        }
        
        startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Opening MetaMask...</span>';
        
        await gameAlert(
            'MetaMask will open for confirmation.\n\nVerify:\n• Network: BSC\n• Amount: 0.00001 BNB',
            'warning',
            'ATTENTION'
        );
        
        const valueHex = '0x' + BigInt('10000000000000').toString(16);
        
        const txHash = await window.ethereum.request({
            method: 'eth_sendTransaction',
            params: [{
                from: currentAccount,
                to: CONFIG.PROJECT_WALLET,
                value: valueHex
            }]
        });
        
        console.log('✅ TX Hash:', txHash);
        
        startBtn.disabled = false;
        startBtn.innerHTML = originalText;
        
        // Redirect to loading page with ads
        console.log('🎬 Redirecting to loading screen...');
        window.location.href = 'loading.html?paid=true';
        
    } catch (error) {
        console.error('❌ Error:', error);
        startBtn.disabled = false;
        startBtn.innerHTML = originalText;
        await handleTransactionError(error);
    }
}

window.connectWallet = connectWallet;
window.ensureWalletConnection = ensureWalletConnection;
window.switchToBSC = switchToBSC;
window.handleTransactionError = handleTransactionError;
window.startGameSession = startGameSession;
