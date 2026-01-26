/* ============================================
   CRYPTO ASTEROID RUSH - Admin JavaScript
   Arquivo: admin/js/admin.js
   ============================================ */

// ============================================
// CONFIGURAÇÃO USDT BSC
// ============================================
const USDT_CONFIG = {
    CONTRACT_ADDRESS: '0x55d398326f99059fF775485246999027B3197955',
    DECIMALS: 18,
    BSC_CHAIN_ID: '0x38'
};

// ============================================
// TOGGLE SIDEBAR (MOBILE)
// ============================================
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}

// ============================================
// NOTIFICAÇÕES TOAST
// ============================================
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'exclamation-triangle'}"></i> ${message}`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================
// COPIAR PARA CLIPBOARD
// ============================================
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Copiado para a área de transferência!');
    }).catch(() => {
        // Fallback
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('Copiado!');
    });
}

// ============================================
// CONFIRMAÇÃO DE AÇÕES
// ============================================
function confirmAction(message) {
    return confirm(message);
}

// ============================================
// FUNÇÕES DE PAGAMENTO METAMASK
// ============================================

// Verificar MetaMask
function checkMetaMask() {
    if (typeof window.ethereum === 'undefined') {
        showToast('MetaMask não detectada! Instale a extensão.', 'error');
        return false;
    }
    return true;
}

// Conectar carteira
async function connectWallet() {
    if (!checkMetaMask()) return null;
    
    try {
        const accounts = await window.ethereum.request({
            method: 'eth_requestAccounts'
        });
        return accounts[0] || null;
    } catch (error) {
        showToast('Erro ao conectar carteira: ' + error.message, 'error');
        return null;
    }
}

// Garantir rede BSC
async function ensureBSCNetwork() {
    try {
        const chainId = await window.ethereum.request({ method: 'eth_chainId' });
        
        if (chainId !== USDT_CONFIG.BSC_CHAIN_ID) {
            try {
                await window.ethereum.request({
                    method: 'wallet_switchEthereumChain',
                    params: [{ chainId: USDT_CONFIG.BSC_CHAIN_ID }]
                });
            } catch (switchError) {
                if (switchError.code === 4902) {
                    await window.ethereum.request({
                        method: 'wallet_addEthereumChain',
                        params: [{
                            chainId: USDT_CONFIG.BSC_CHAIN_ID,
                            chainName: 'BNB Smart Chain',
                            nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 },
                            rpcUrls: ['https://bsc-dataseed.binance.org/'],
                            blockExplorerUrls: ['https://bscscan.com/']
                        }]
                    });
                } else {
                    throw switchError;
                }
            }
        }
        return true;
    } catch (error) {
        showToast('Erro ao conectar à BSC: ' + error.message, 'error');
        return false;
    }
}

// Verificar saldo BNB
async function checkBNBBalance(address) {
    try {
        const balance = await window.ethereum.request({
            method: 'eth_getBalance',
            params: [address, 'latest']
        });
        return parseInt(balance) / 1e18;
    } catch (error) {
        return 0;
    }
}

// Verificar saldo USDT
async function checkUSDTBalance(address) {
    try {
        const data = '0x70a08231' + address.toLowerCase().replace('0x', '').padStart(64, '0');
        
        const result = await window.ethereum.request({
            method: 'eth_call',
            params: [{ to: USDT_CONFIG.CONTRACT_ADDRESS, data: data }, 'latest']
        });
        
        return Number(BigInt(result)) / Math.pow(10, 18);
    } catch (error) {
        console.error('Erro ao verificar saldo USDT:', error);
        return 0;
    }
}

// Enviar USDT
async function sendUSDT(fromAddress, toAddress, amountUSD) {
    // Converter para 18 decimais
    const amountStr = amountUSD.toFixed(18);
    const [intPart, decPart = ''] = amountStr.split('.');
    const paddedDec = decPart.padEnd(18, '0').slice(0, 18);
    const fullNumber = intPart + paddedDec;
    const amountWei = BigInt(fullNumber).toString(16);
    
    // Codificar transfer(address,uint256)
    const data = '0xa9059cbb' + 
                 toAddress.toLowerCase().replace('0x', '').padStart(64, '0') +
                 amountWei.padStart(64, '0');
    
    console.log('Enviando USDT:', { amount: amountUSD, to: toAddress, data });
    
    const txHash = await window.ethereum.request({
        method: 'eth_sendTransaction',
        params: [{
            from: fromAddress,
            to: USDT_CONFIG.CONTRACT_ADDRESS,
            value: '0x0',
            data: data,
            gas: '0x186A0'
        }]
    });
    
    return txHash;
}

// Processar pagamento de saque
async function processWithdrawalPayment(id, walletAddress, amount) {
    if (!checkMetaMask()) return false;
    
    try {
        // 1. Conectar
        const adminAddress = await connectWallet();
        if (!adminAddress) return false;
        
        // 2. Verificar rede
        if (!await ensureBSCNetwork()) return false;
        
        // 3. Verificar saldos
        const bnbBalance = await checkBNBBalance(adminAddress);
        const usdtBalance = await checkUSDTBalance(adminAddress);
        
        if (bnbBalance < 0.0005) {
            showToast(`Saldo BNB insuficiente: ${bnbBalance.toFixed(6)} BNB`, 'error');
            return false;
        }
        
        if (usdtBalance < amount) {
            showToast(`Saldo USDT insuficiente: $${usdtBalance.toFixed(6)}`, 'error');
            return false;
        }
        
        // 4. Confirmar
        if (!confirm(`💸 CONFIRMAR PAGAMENTO\n\nValor: $${amount.toFixed(6)} USDT\nPara: ${walletAddress}\n\nSaldo BNB: ${bnbBalance.toFixed(6)}\nSaldo USDT: $${usdtBalance.toFixed(6)}\n\nContinuar?`)) {
            return false;
        }
        
        // 5. Enviar
        showToast('Processando pagamento...', 'warning');
        const txHash = await sendUSDT(adminAddress, walletAddress, amount);
        
        // 6. Registrar no sistema
        const formData = new FormData();
        formData.append('action', 'approve_withdrawal');
        formData.append('id', id);
        formData.append('tx_hash', txHash);
        
        const response = await fetch('../api/admin-ajax.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            showToast('Pagamento enviado com sucesso!', 'success');
            setTimeout(() => location.reload(), 2000);
            return true;
        } else {
            showToast('Pagamento enviado, mas erro ao registrar: ' + result.message, 'warning');
            return true;
        }
        
    } catch (error) {
        console.error('Erro:', error);
        if (error.code === 4001) {
            showToast('Transação cancelada', 'warning');
        } else {
            showToast('Erro: ' + error.message, 'error');
        }
        return false;
    }
}

// Rejeitar saque
async function rejectWithdrawal(id) {
    if (!confirm('Rejeitar este saque?\n\nO saldo será devolvido ao jogador.')) {
        return false;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'reject_withdrawal');
        formData.append('id', id);
        
        const response = await fetch('../api/admin-ajax.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            showToast('Saque rejeitado!', 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + result.message, 'error');
            return false;
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
        return false;
    }
}

// ============================================
// FUNÇÕES DE SEGURANÇA
// ============================================

// Banir wallet
async function banWallet(wallet, reason = '') {
    if (!reason) {
        reason = prompt('Motivo do banimento:');
        if (!reason) return false;
    }
    
    if (!confirm(`Banir wallet ${wallet.substring(0, 10)}...?\n\nMotivo: ${reason}`)) {
        return false;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'ban_wallet');
        formData.append('wallet', wallet);
        formData.append('reason', reason);
        
        const response = await fetch('../api/admin-ajax.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            showToast('Wallet banida!', 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + result.message, 'error');
            return false;
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
        return false;
    }
}

// Desbanir wallet
async function unbanWallet(wallet) {
    if (!confirm(`Desbanir wallet ${wallet.substring(0, 10)}...?`)) {
        return false;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'unban_wallet');
        formData.append('wallet', wallet);
        
        const response = await fetch('../api/admin-ajax.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            showToast('Wallet desbanida!', 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + result.message, 'error');
            return false;
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
        return false;
    }
}

// Blacklist IP
async function blacklistIP(ip, hours = null) {
    const reason = prompt('Motivo do bloqueio:');
    if (!reason) return false;
    
    if (!confirm(`Bloquear IP ${ip}?\n\nMotivo: ${reason}\nDuração: ${hours ? hours + ' horas' : 'Permanente'}`)) {
        return false;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'blacklist_ip');
        formData.append('ip', ip);
        formData.append('reason', reason);
        if (hours) formData.append('hours', hours);
        
        const response = await fetch('../api/admin-ajax.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            showToast('IP bloqueado!', 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + result.message, 'error');
            return false;
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
        return false;
    }
}

// ============================================
// FUNÇÕES UTILITÁRIAS
// ============================================

// Formatar data
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR');
}

// Formatar moeda
function formatCurrency(value, decimals = 6) {
    return '$' + parseFloat(value).toFixed(decimals);
}

// Truncar wallet
function truncateWallet(wallet) {
    return wallet.substring(0, 6) + '...' + wallet.substring(wallet.length - 4);
}

// ============================================
// INICIALIZAÇÃO
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh para páginas específicas
    const autoRefreshPages = ['withdrawals', 'security'];
    const currentPage = new URLSearchParams(window.location.search).get('page');
    
    if (autoRefreshPages.includes(currentPage)) {
        setInterval(() => {
            // Recarregar apenas se não houver interação recente
            if (!document.querySelector(':focus')) {
                // location.reload();
            }
        }, 60000); // 1 minuto
    }
    
    console.log('🚀 Admin Panel initialized');
});
