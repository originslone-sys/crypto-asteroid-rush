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
// FUNÇÕES DE SAQUES (BRL/PIX)
// ============================================

/**
 * Aprovar saque (marca como aprovado no sistema)
 * O pagamento real é feito manualmente via PIX/PayPal
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
// GERENCIAMENTO DE ALERTAS (NOVO v7.0)
// ============================================

/**
 * Excluir alerta individual
 */
async function deleteAlert(alertId) {
    if (!confirm(`Excluir alerta #${alertId}?`)) return false;
    
    try {
        const response = await adminAjax({ action: 'delete_alert', id: alertId });
        if (response.success) {
            showToast('Alerta excluído!', 'success');
            const row = document.querySelector(`tr[data-alert-id="${alertId}"]`);
            if (row) row.remove();
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
    }
    return false;
}

/**
 * Marcar alerta como revisado
 */
async function reviewAlert(alertId) {
    try {
        const response = await adminAjax({ action: 'review_alert', id: alertId });
        if (response.success) {
            showToast('Alerta marcado como revisado', 'success');
            const row = document.querySelector(`tr[data-alert-id="${alertId}"]`);
            if (row) {
                row.classList.add('reviewed');
                const btn = row.querySelector('.btn-review');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check-double"></i>';
                    btn.title = 'Revisado';
                    btn.disabled = true;
                    btn.classList.add('btn-success');
                }
            }
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
    }
    return false;
}

/**
 * Excluir alertas em massa (selecionados)
 */
async function bulkDeleteAlerts() {
    const checkboxes = document.querySelectorAll('.alert-checkbox:checked');
    if (checkboxes.length === 0) {
        showToast('Selecione pelo menos um alerta', 'warning');
        return false;
    }
    
    if (!confirm(`Excluir ${checkboxes.length} alerta(s) selecionado(s)?`)) return false;
    
    const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    try {
        const response = await adminAjax({ action: 'bulk_delete_alerts', ids: ids });
        if (response.success) {
            showToast(`${response.deleted} alertas excluídos`, 'success');
            setTimeout(() => location.reload(), 1000);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
    }
    return false;
}

/**
 * Revisar alertas em massa (selecionados)
 */
async function bulkReviewAlerts() {
    const checkboxes = document.querySelectorAll('.alert-checkbox:checked');
    if (checkboxes.length === 0) {
        showToast('Selecione pelo menos um alerta', 'warning');
        return false;
    }
    
    const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    try {
        const response = await adminAjax({ action: 'bulk_review_alerts', ids: ids });
        if (response.success) {
            showToast(`${response.reviewed} alertas revisados`, 'success');
            setTimeout(() => location.reload(), 1000);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
    }
    return false;
}

/**
 * Excluir alertas revisados antigos
 */
async function deleteReviewedAlerts(days = 30) {
    if (!confirm(`Excluir todos os alertas revisados com mais de ${days} dias?`)) return false;
    
    const dateTo = new Date();
    dateTo.setDate(dateTo.getDate() - days);
    const dateStr = dateTo.toISOString().split('T')[0];
    
    try {
        const response = await adminAjax({
            action: 'bulk_delete_alerts',
            date_to: dateStr,
            only_reviewed: true
        });
        if (response.success) {
            showToast(`${response.deleted} alertas antigos excluídos`, 'success');
            setTimeout(() => location.reload(), 1000);
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
    }
}

/**
 * Selecionar/deselecionar todos os checkboxes
 */
function toggleSelectAllAlerts(checkbox) {
    const checkboxes = document.querySelectorAll('.alert-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
}

// ============================================
// GERENCIAMENTO DE IP BLACKLIST (NOVO v7.0)
// ============================================

/**
 * Adicionar IP à blacklist
 */
async function blacklistIP(ip = '', reason = '', hours = null) {
    if (!ip) ip = prompt('Endereço IP para bloquear:');
    if (!ip) return false;
    
    if (!reason) reason = prompt('Motivo do bloqueio:') || 'Bloqueio manual';
    
    const hoursStr = prompt('Duração em horas (vazio = permanente):', '');
    hours = hoursStr ? parseInt(hoursStr) : null;
    
    try {
        const data = { action: 'blacklist_ip', ip, reason };
        if (hours) data.hours = hours;
        
        const response = await adminAjax(data);
        if (response.success) {
            showToast(`IP ${ip} bloqueado!`, 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
    }
    return false;
}

/**
 * Remover IP da blacklist
 */
async function unblacklistIP(ip) {
    if (!confirm(`Desbloquear IP ${ip}?`)) return false;
    
    try {
        const response = await adminAjax({ action: 'unblacklist_ip', ip });
        if (response.success) {
            showToast(`IP ${ip} desbloqueado!`, 'success');
            setTimeout(() => location.reload(), 1500);
            return true;
        } else {
            showToast('Erro: ' + (response.error || response.message), 'error');
        }
    } catch (error) {
        showToast('Erro: ' + error.message, 'error');
    }
    return false;
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
    return 'R$ ' + num.toFixed(6).replace('.', ',');
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
