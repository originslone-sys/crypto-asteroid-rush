<?php
// ============================================
// CRYPTO ASTEROID RUSH - Admin Withdrawals
// Compatível com Railway / PHP 8 / MySQL 8
// ============================================

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../config.php';

header('Content-Type: text/html; charset=utf-8');

// --- Sessão admin existente ---
session_start();
if (!isset($_SESSION['admin'])) {
    die('<p style="color: var(--danger);">Acesso negado!</p>');
}

$status = $_GET['status'] ?? 'pending';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query dinâmica (detecta colunas diferentes)
    $sql = "
        SELECT 
            w.id,
            w.wallet_address,
            COALESCE(w.amount_usdt, w.amount, 0) AS amount_usdt,
            w.status,
            COALESCE(w.created_at, w.date) AS created_at,
            w.approved_at,
            p.balance_usdt AS player_balance
        FROM withdrawals w
        LEFT JOIN players p ON w.wallet_address = p.wallet_address
    ";

    $params = [];
    if ($status !== 'all') {
        $sql .= " WHERE w.status = ? ";
        $params[] = $status;
    }

    $sql .= " ORDER BY COALESCE(w.created_at, w.date) DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $withdrawals = $stmt->fetchAll();

    if (empty($withdrawals)) {
        echo '<p style="color: var(--text-dim); text-align: center; padding: 40px;">
                <i class="fas fa-check-circle" style="color: var(--success);"></i>
                Nenhum saque encontrado.
              </p>';
        return;
    }

    echo '<div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Carteira</th>
                        <th>Valor (USDT)</th>
                        <th>Saldo Atual</th>
                        <th>Status</th>
                        <th>Solicitado em</th>
                        <th>Aprovado em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>';

    foreach ($withdrawals as $w) {
        $statusColor = [
            'pending' => 'var(--warning)',
            'approved' => 'var(--success)',
            'rejected' => 'var(--danger)',
        ][$w['status']] ?? 'var(--text-dim)';

        $statusText = [
            'pending' => 'Pendente',
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
        ][$w['status']] ?? 'Desconhecido';

        echo '<tr>
                <td>#' . $w['id'] . '</td>
                <td><span class="wallet-addr" title="' . htmlspecialchars($w['wallet_address']) . '">' .
                substr($w['wallet_address'], 0, 6) . '...' . substr($w['wallet_address'], -4) . '</span></td>
                <td style="color: var(--success); font-weight: bold;">$' . number_format($w['amount_usdt'], 6) . '</td>
                <td>' . (isset($w['player_balance'])
                    ? '$' . number_format($w['player_balance'], 6)
                    : '<span style="color: var(--text-dim);">N/A</span>') . '</td>
                <td><span style="color: ' . $statusColor . '; font-weight: bold;">' . $statusText . '</span></td>
                <td>' . date('d/m/Y H:i', strtotime($w['created_at'])) . '</td>
                <td>' . ($w['approved_at']
                    ? date('d/m/Y H:i', strtotime($w['approved_at']))
                    : '<span style="color: var(--text-dim);">-</span>') . '</td>
                <td>';

        if ($w['status'] === 'pending') {
            echo '<div style="display: flex; gap: 8px;">
                    <form method="POST" style="display:inline;" onsubmit="return confirmAction(this)">
                        <input type="hidden" name="action" value="approve_withdrawal">
                        <input type="hidden" name="id" value="' . $w['id'] . '">
                        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Aprovar</button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirmAction(this)">
                        <input type="hidden" name="action" value="reject_withdrawal">
                        <input type="hidden" name="id" value="' . $w['id'] . '">
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-times"></i> Rejeitar</button>
                    </form>
                  </div>';
        } else {
            echo '<span style="color: var(--text-dim); font-size: 0.9rem;">Processado</span>';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table></div>';

} catch (Exception $e) {
    error_log("[ADMIN-WITHDRAWALS] " . $e->getMessage());
    echo '<p style="color: var(--danger); text-align: center;">
            Erro ao carregar saques: ' . htmlspecialchars($e->getMessage()) . '
          </p>';
}
?>
