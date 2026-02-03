<?php
// ================================================================
// UNOBIX - ADMIN WITHDRAWALS
// v4.0 - Suporte a BRL, PIX, PayPal, USDT
// ================================================================

date_default_timezone_set('America/Sao_Paulo');

if (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
} elseif (file_exists(__DIR__ . "/config.php")) {
    require_once __DIR__ . "/config.php";
} else {
    die('<p style="color: var(--danger);">Configuração não encontrada!</p>');
}

header('Content-Type: text/html; charset=utf-8');

// ================================================================
// Sessão administrativa
// ================================================================
session_start();
if (!isset($_SESSION['admin'])) {
    die('<p style="color: var(--danger);">Acesso negado!</p>');
}

// ================================================================
// Parâmetros de filtro
// ================================================================
$status = $_GET['status'] ?? 'pending';
$paymentMethod = $_GET['payment_method'] ?? 'all';

// ================================================================
// Conexão PDO
// ================================================================
try {
    $pdo = getDatabaseConnection();
    if (!$pdo) throw new Exception("Falha na conexão");

    // ============================================================
    // Query dinâmica com suporte a BRL e novos campos
    // ============================================================
    $sql = "
        SELECT 
            w.id,
            w.google_uid,
            w.wallet_address,
            COALESCE(w.amount_brl, w.amount_usdt) AS amount,
            w.amount_brl,
            w.amount_usdt,
            w.payment_method,
            w.payment_details,
            w.status,
            w.notes,
            w.created_at,
            w.approved_at,
            w.tx_hash,
            p.email,
            p.display_name,
            p.balance_brl AS player_balance_brl,
            p.balance_usdt AS player_balance_usdt
        FROM withdrawals w
        LEFT JOIN players p ON (
            (w.google_uid IS NOT NULL AND p.google_uid = w.google_uid) OR
            (w.google_uid IS NULL AND p.wallet_address = w.wallet_address)
        )
        WHERE 1=1
    ";

    $params = [];
    
    if ($status !== 'all') {
        $sql .= " AND w.status = ?";
        $params[] = $status;
    }
    
    if ($paymentMethod !== 'all') {
        $sql .= " AND w.payment_method = ?";
        $params[] = $paymentMethod;
    }

    $sql .= " ORDER BY w.created_at DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $withdrawals = $stmt->fetchAll();

    if (empty($withdrawals)) {
        echo '<p style="color: var(--text-dim); text-align: center; padding: 40px;">
                <i class="fas fa-check-circle" style="color: var(--success);"></i>
                Nenhum saque encontrado com os filtros selecionados.
              </p>';
        return;
    }

    // ============================================================
    // Renderização HTML
    // ============================================================
    echo '<div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuário</th>
                        <th>Valor</th>
                        <th>Método</th>
                        <th>Detalhes</th>
                        <th>Saldo Atual</th>
                        <th>Status</th>
                        <th>Solicitado em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>';

    foreach ($withdrawals as $w) {
        // Status colors
        $statusColor = match($w['status']) {
            'pending' => 'var(--warning)',
            'approved' => 'var(--success)',
            'rejected' => 'var(--danger)',
            default => 'var(--text-dim)'
        };
        
        $statusText = match($w['status']) {
            'pending' => 'Pendente',
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
            default => htmlspecialchars($w['status'])
        };

        // Payment method display
        $methodIcon = match($w['payment_method']) {
            'pix' => '🇧🇷 PIX',
            'paypal' => '💳 PayPal',
            'usdt_bep20' => '💰 USDT',
            default => '❓ ' . ($w['payment_method'] ?? 'N/A')
        };

        // Payment details
        $paymentDetails = '';
        if ($w['payment_details']) {
            $details = json_decode($w['payment_details'], true);
            if ($details) {
                if ($w['payment_method'] === 'pix' && isset($details['pix_key'])) {
                    $paymentDetails = 'Chave: ' . htmlspecialchars(substr($details['pix_key'], 0, 20)) . '...';
                } elseif ($w['payment_method'] === 'paypal' && isset($details['paypal_email'])) {
                    $paymentDetails = htmlspecialchars($details['paypal_email']);
                } elseif ($w['payment_method'] === 'usdt_bep20' && isset($details['wallet'])) {
                    $paymentDetails = substr($details['wallet'], 0, 10) . '...' . substr($details['wallet'], -6);
                }
            }
        }

        // User identifier
        $userDisplay = '';
        if ($w['display_name']) {
            $userDisplay = htmlspecialchars($w['display_name']);
            if ($w['email']) {
                $userDisplay .= '<br><small style="color: var(--text-dim);">' . htmlspecialchars($w['email']) . '</small>';
            }
        } elseif ($w['email']) {
            $userDisplay = htmlspecialchars($w['email']);
        } elseif ($w['wallet_address']) {
            $userDisplay = '<span class="wallet-addr" title="' . htmlspecialchars($w['wallet_address']) . '">'
                         . substr($w['wallet_address'], 0, 6) . '...' . substr($w['wallet_address'], -4)
                         . '</span>';
        } else {
            $userDisplay = '<span style="color: var(--text-dim);">N/A</span>';
        }

        // Amount display (preferir BRL)
        $amountDisplay = $w['amount_brl'] 
            ? 'R$ ' . number_format($w['amount_brl'], 2, ',', '.')
            : '$ ' . number_format($w['amount_usdt'] ?? 0, 6);

        // Player balance
        $balanceDisplay = $w['player_balance_brl'] 
            ? 'R$ ' . number_format($w['player_balance_brl'], 2, ',', '.')
            : ($w['player_balance_usdt'] ? '$ ' . number_format($w['player_balance_usdt'], 6) : 'N/A');

        echo '<tr>
                <td>#' . htmlspecialchars($w['id']) . '</td>
                <td>' . $userDisplay . '</td>
                <td style="color: var(--success); font-weight: bold;">' . $amountDisplay . '</td>
                <td>' . $methodIcon . '</td>
                <td><small>' . ($paymentDetails ?: '<span style="color: var(--text-dim);">-</span>') . '</small></td>
                <td>' . $balanceDisplay . '</td>
                <td><span style="color: ' . $statusColor . '; font-weight: bold;">' . $statusText . '</span></td>
                <td>' . ($w['created_at'] ? date('d/m/Y H:i', strtotime($w['created_at'])) : '-') . '</td>
                <td>';

        if ($w['status'] === 'pending') {
            echo '<div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-success btn-sm" 
                            onclick="processWithdrawal(' . $w['id'] . ', \'approve\')">
                        <i class="fas fa-check"></i> Aprovar
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" 
                            onclick="processWithdrawal(' . $w['id'] . ', \'reject\')">
                        <i class="fas fa-times"></i> Rejeitar
                    </button>
                  </div>';
        } else {
            $processedInfo = $w['approved_at'] ? date('d/m/Y H:i', strtotime($w['approved_at'])) : 'Processado';
            echo '<span style="color: var(--text-dim); font-size: 0.85rem;">' . $processedInfo . '</span>';
            
            if ($w['tx_hash']) {
                echo '<br><small><a href="https://bscscan.com/tx/' . htmlspecialchars($w['tx_hash']) . '" 
                         target="_blank" style="color: var(--primary);">Ver TX</a></small>';
            }
        }

        echo '</td></tr>';
    }

    echo '</tbody></table></div>';

    // Script para processamento
    echo '<script>
    function processWithdrawal(id, action) {
        const actionText = action === "approve" ? "APROVAR" : "REJEITAR";
        if (!confirm(`Tem certeza que deseja ${actionText} o saque #${id}?`)) return;
        
        fetch("admin-ajax.php", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({
                action: action === "approve" ? "approve_withdrawal" : "reject_withdrawal",
                id: id
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(data.message || "Operação realizada com sucesso!");
                location.reload();
            } else {
                alert("Erro: " + (data.error || data.message || "Falha na operação"));
            }
        })
        .catch(e => alert("Erro de conexão: " + e.message));
    }
    </script>';

} catch (Exception $e) {
    error_log("[ADMIN-WITHDRAWALS] " . $e->getMessage());
    echo '<p style="color: var(--danger); text-align: center;">
            Erro ao carregar saques: ' . htmlspecialchars($e->getMessage()) . '
          </p>';
}
?>
