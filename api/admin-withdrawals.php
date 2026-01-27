<?php
// ================================================================
// CRYPTO ASTEROID RUSH - ADMIN WITHDRAWALS
// Compatível com Railway (PHP 8 + MySQL 8)
// ================================================================

date_default_timezone_set('America/Sao_Paulo');

if (file_exists(__DIR__ . "/../config.php")) {
    require_once __DIR__ . "/../config.php";
} else {
    die('<p style="color: var(--danger);">Configuração não encontrada!</p>');
}

header('Content-Type: text/html; charset=utf-8');

// ================================================================
// Sessão administrativa (mantida conforme original)
// ================================================================
session_start();
if (!isset($_SESSION['admin'])) {
    die('<p style="color: var(--danger);">Acesso negado!</p>');
}

// ================================================================
// Parâmetros de filtro
// ================================================================
$status = $_GET['status'] ?? 'pending';

// ================================================================
// Conexão PDO - Railway compatível
// ================================================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec("SET time_zone = '-03:00'");

    // ============================================================
    // Query dinâmica (preserva lógica, adapta schema)
    // ============================================================
    $sql = "
        SELECT 
            w.*, 
            p.wallet_address, 
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

    // ============================================================
    // Renderização HTML - 100% preservada
    // ============================================================
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
        $statusColor = '';
        $statusText  = '';

        switch ($w['status']) {
            case 'pending':
                $statusColor = 'var(--warning)';
                $statusText  = 'Pendente';
                break;
            case 'approved':
                $statusColor = 'var(--success)';
                $statusText  = 'Aprovado';
                break;
            case 'rejected':
                $statusColor = 'var(--danger)';
                $statusText  = 'Rejeitado';
                break;
            default:
                $statusColor = 'var(--text-dim)';
                $statusText  = htmlspecialchars($w['status']);
                break;
        }

        echo '<tr>
                <td>#' . htmlspecialchars($w['id']) . '</td>
                <td>
                    <span class="wallet-addr" title="' . htmlspecialchars($w['wallet_address']) . '">
                        ' . substr($w['wallet_address'], 0, 6) . '...' . substr($w['wallet_address'], -4) . '
                    </span>
                </td>
                <td style="color: var(--success); font-weight: bold;">
                    $' . number_format($w['amount_usdt'] ?? $w['amount'] ?? 0, 6) . '
                </td>
                <td>' . (
                    isset($w['player_balance'])
                        ? '$' . number_format($w['player_balance'], 6)
                        : '<span style="color: var(--text-dim);">N/A</span>'
                ) . '</td>
                <td><span style="color: ' . $statusColor . '; font-weight: bold;">' . $statusText . '</span></td>
                <td>' . (
                    $w['created_at'] || $w['date']
                        ? date('d/m/Y H:i', strtotime($w['created_at'] ?? $w['date']))
                        : '<span style="color: var(--text-dim);">-</span>'
                ) . '</td>
                <td>' . (
                    $w['approved_at']
                        ? date('d/m/Y H:i', strtotime($w['approved_at']))
                        : '<span style="color: var(--text-dim);">-</span>'
                ) . '</td>
                <td>';

        if ($w['status'] === 'pending') {
            echo '<div style="display: flex; gap: 8px;">
                    <form method="POST" style="display: inline;" onsubmit="return confirmAction(this)">
                        <input type="hidden" name="action" value="approve_withdrawal">
                        <input type="hidden" name="id" value="' . htmlspecialchars($w['id']) . '">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fas fa-check"></i> Aprovar
                        </button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirmAction(this)">
                        <input type="hidden" name="action" value="reject_withdrawal">
                        <input type="hidden" name="id" value="' . htmlspecialchars($w['id']) . '">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="fas fa-times"></i> Rejeitar
                        </button>
                    </form>
                  </div>';
        } else {
            echo '<span style="color: var(--text-dim); font-size: 0.9rem;">
                    Processado
                  </span>';
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
