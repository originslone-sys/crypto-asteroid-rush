/* ============================================
   UNOBIX - Admin JavaScript
   Arquivo: admin/js/admin.js
   v6.0 - BRL, Google UID, sem MetaMask
   ============================================ */

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
// FUNÇÕES DE SAQUES (PIX)
// ============================================

/**
 * Aprovar saque (marca como aprovado no sistema)
 */
async function approveWithdrawal(id, amount) {
    const amountFormatted = formatBRL(amount);
    
    if (!confirm(`✅ APROVAR SAQUE #${id}\n\nValor: ${amountFormatted}\n\nVocê confirma que já realizou o pagamento?`)) {
        return false;
    }
    
    try {
        showToast('Processando...', 'warning');
        
        const response = await adminAjax({
            action: 'approve_withdrawal',
            id: id
        });
        
        if (response.success) {
            showToast('Saque aprovado com sucesso!', 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
            return false;
        }
    } catch (error) {
        showToast('Erro de conexão: ' + error.message, 'error');
        return false;
    }
}

/**
 * Aprovar saque PIX via ZettPay (processamento automático)
 * Admin aprova → sistema envia para ZettPay → webhook confirma
 */
async function approveWithdrawalZettpay(id, amount) {
    const amountFormatted = formatBRL(amount);

    if (!confirm(`⚡ APROVAR SAQUE PIX #${id} VIA ZETTPAY\n\nValor: ${amountFormatted}\n\nO pagamento será processado automaticamente via PIX.\nDeseja continuar?`)) {
        return false;
    }

    try {
        showToast('Enviando para ZettPay...', 'warning');

        const response = await adminAjax({
            action: 'approve_withdrawal_zettpay',
            id: id
        });

        if (response.success) {
            showToast(response.message || 'Saque enviado para processamento PIX!', 'success');
            setTimeout(() => location.reload(), 2000);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
            return false;
        }
    } catch (error) {
        showToast('Erro de conexão: ' + error.message, 'error');
        return false;
    }
}

/**
 * Rejeitar saque (devolve saldo ao jogador)
 */
async function rejectWithdrawal(id) {
    const reason = prompt('Motivo da rejeição:');
    if (!reason) return false;
    
    if (!confirm(`❌ Rejeitar saque #${id}?\n\nMotivo: ${reason}\nO saldo será devolvido ao jogador.`)) {
        return false;
    }
    
    try {
        const response = await adminAjax({
            action: 'reject_withdrawal',
            id: id,
            reason: reason
        });
        
        if (response.success) {
            showToast('Saque rejeitado e saldo devolvido!', 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
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

/**
 * Banir jogador por Google UID
 */
async function banPlayer(googleUid, reason = '') {
    if (!reason) {
        reason = prompt('Motivo do banimento:');
        if (!reason) return false;
    }
    
    if (!confirm(`Banir jogador?\n\nGoogle UID: ${googleUid.substring(0, 15)}...\nMotivo: ${reason}`)) {
        return false;
    }
    
    try {
        const response = await adminAjax({
            action: 'ban_player',
            google_uid: googleUid,
            reason: reason
        });
        
        if (response.success) {
            showToast('Jogador banido!', 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
            return false;
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
        return false;
    }
}

/**
 * Desbanir jogador por Google UID
 */
async function unbanPlayer(googleUid) {
    if (!confirm(`Desbanir jogador?\n\nGoogle UID: ${googleUid.substring(0, 15)}...`)) {
        return false;
    }
    
    try {
        const response = await adminAjax({
            action: 'unban_player',
            google_uid: googleUid
        });
        
        if (response.success) {
            showToast('Jogador desbanido!', 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
            return false;
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
        return false;
    }
}

// ============================================
// AJAX HELPER (chamadas ao admin-ajax.php)
// ============================================

/**
 * Função centralizada para chamadas AJAX ao admin
 * Usa JSON e inclui tratamento de erros
 */
async function adminAjax(data) {
    const response = await fetch('../api/admin-ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
        credentials: 'same-origin'  // Envia cookies de sessão
    });
    
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    
    return await response.json();
}

// ============================================
// FUNÇÕES UTILITÁRIAS
// ============================================

/**
 * Formatar data no padrão brasileiro
 */
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('pt-BR') + ' ' + date.toLocaleTimeString('pt-BR');
}

/**
 * Formatar valor em BRL (6 casas decimais)
 */
function formatBRL(value) {
    const num = parseFloat(value) || 0;
    return 'R$ ' + num.toFixed(2).replace('.', ',');
}

/**
 * Formatar valor em BRL resumido (2 casas)
 */
function formatBRLShort(value) {
    const num = parseFloat(value) || 0;
    return 'R$ ' + num.toFixed(2).replace('.', ',');
}

/**
 * Truncar Google UID para exibição
 */
function truncateUid(uid) {
    if (!uid || uid.length <= 12) return uid;
    return uid.substring(0, 8) + '...' + uid.substring(uid.length - 4);
}

// ============================================
// INICIALIZAÇÃO
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh para páginas específicas (desabilitado por padrão)
    const autoRefreshPages = ['withdrawals', 'security'];
    const currentPage = new URLSearchParams(window.location.search).get('page');
    
    if (autoRefreshPages.includes(currentPage)) {
        setInterval(() => {
            // Recarregar apenas se não houver interação recente
            if (!document.querySelector(':focus') && !document.querySelector('.modal-overlay.active')) {
                // location.reload();
            }
        }, 60000); // 1 minuto
    }
    
    console.log('🚀 UNOBIX Admin Panel v6.0 initialized');
});
