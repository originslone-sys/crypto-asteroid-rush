<?php
// api/admin-ajax.php - Endpoint AJAX para o painel admin (COMPLETO)

require_once "../config.php";

header('Content-Type: application/json'); // Mudamos para JSON para melhor tratamento
header('Access-Control-Allow-Origin: *');

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// Verificar sessão admin
session_start();
if (!isset($_SESSION['admin'])) {
    echo json_encode(['error' => 'Acesso negado!']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    switch ($action) {
        case 'get_withdrawals':
            handleGetWithdrawals($pdo);
            break;
            
        case 'approve_withdrawal':
            handleApproveWithdrawal($pdo);
            break;
            
        case 'reject_withdrawal':
            handleRejectWithdrawal($pdo);
            break;
            
        default:
            echo json_encode(['error' => 'Ação não reconhecida']);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Erro no servidor: ' . $e->getMessage()]);
}

// ============================================
// FUNÇÕES DE MANIPULAÇÃO
// ============================================

function handleGetWithdrawals($pdo) {
    $status = $_POST['status'] ?? 'pending';
    
    // Construir query base
    $sql = "
        SELECT w.*, p.wallet_address, p.balance_usdt as player_balance
        FROM withdrawals w 
        LEFT JOIN players p ON w.wallet_address = p.wallet_address 
    ";
    
    $params = [];
    
    if ($status !== 'all') {
        $sql .= " WHERE w.status = ? ";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY w.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($withdrawals)) {
        echo json_encode([
            'html' => '<p style="color: #8a9ba8; text-align: center; padding: 40px;">
                        <i class="fas fa-check-circle" style="color: #05ffa1;"></i>
                        Nenhum saque encontrado.
                      </p>',
            'count' => 0
        ]);
        exit;
    }
    
    // Gerar tabela HTML
    $html = '<div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Carteira (Clique para copiar)</th>
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
        $statusText = '';
        $statusClass = '';
        
        switch ($w['status']) {
            case 'pending':
                $statusColor = '#ffd166';
                $statusText = 'Pendente';
                $statusClass = 'warning';
                break;
            case 'approved':
                $statusColor = '#05ffa1';
                $statusText = 'Aprovado';
                $statusClass = 'success';
                break;
            case 'rejected':
                $statusColor = '#ff2a6d';
                $statusText = 'Rejeitado';
                $statusClass = 'danger';
                break;
        }
        
        // Endereço completo para pagamento
        $fullWallet = htmlspecialchars($w['wallet_address']);
        $shortWallet = substr($w['wallet_address'], 0, 6) . '...' . substr($w['wallet_address'], -4);
        $amount = number_format($w['amount_usdt'], 6);
        $playerBalance = isset($w['player_balance']) ? number_format($w['player_balance'], 6) : 'N/A';
        $createdAt = date('d/m/Y H:i', strtotime($w['created_at']));
        $approvedAt = $w['approved_at'] ? date('d/m/Y H:i', strtotime($w['approved_at'])) : '-';
        
        $html .= '<tr>
                    <td>#' . $w['id'] . '</td>
                    <td>
                        <span class="wallet-addr" title="' . $fullWallet . '" 
                              onclick="copyToClipboard(\'' . addslashes($fullWallet) . '\')"
                              style="cursor: pointer;">
                            ' . $shortWallet . '
                            <i class="fas fa-copy" style="margin-left: 5px; font-size: 0.8em; color: #00f0ff;"></i>
                        </span>
                        <br>
                        <small style="color: #8a9ba8; font-size: 0.8em;">
                            Clique para copiar endereço completo
                        </small>
                    </td>
                    <td style="color: #05ffa1; font-weight: bold;">
                        $' . $amount . '
                    </td>
                    <td>
                        ' . ($playerBalance !== 'N/A' ? 
                            '$' . $playerBalance : 
                            '<span style="color: #8a9ba8;">N/A</span>') . '
                    </td>
                    <td>
                        <span class="badge badge-' . $statusClass . '" style="color: ' . $statusColor . '; font-weight: bold;">
                            ' . $statusText . '
                        </span>
                    </td>
                    <td>' . $createdAt . '</td>
                    <td>' . 
                        ($approvedAt !== '-' ? 
                            $approvedAt : 
                            '<span style="color: #8a9ba8;">-</span>') . '
                    </td>
                    <td>';
        
        // Mostrar ações apenas para saques pendentes
        if ($w['status'] === 'pending') {
            $html .= '<div style="display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; gap: 8px;">
                            <button onclick="processWithdrawal(' . $w['id'] . ', \'' . addslashes($fullWallet) . '\', ' . $w['amount_usdt'] . ', \'approve\')" 
                                    class="btn btn-success btn-sm" style="flex: 1;">
                                <i class="fas fa-check"></i> Aprovar & Pagar
                            </button>
                            <button onclick="processWithdrawal(' . $w['id'] . ', \'\', ' . $w['amount_usdt'] . ', \'reject\')" 
                                    class="btn btn-danger btn-sm" style="flex: 1;">
                                <i class="fas fa-times"></i> Rejeitar
                            </button>
                        </div>
                        <small style="color: #8a9ba8; font-size: 0.8em; text-align: center;">
                            "Aprovar & Pagar" abrirá o MetaMask para pagamento em USDT
                        </small>
                      </div>';
        } else {
            $html .= '<span style="color: #8a9ba8; font-size: 0.9rem;">
                        Processado
                      </span>';
        }
        
        $html .= '</td>
                </tr>';
    }
    
    $html .= '</tbody>
            </table>
          </div>';
    
    echo json_encode([
        'html' => $html,
        'count' => count($withdrawals),
        'status' => $status
    ]);
}

function handleApproveWithdrawal($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    $txHash = $_POST['tx_hash'] ?? '';
    
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        return;
    }
    
    try {
        // Iniciar transação
        $pdo->beginTransaction();
        
        // 1. Buscar informações do saque
        $stmt = $pdo->prepare("
            SELECT w.*, p.id as player_id 
            FROM withdrawals w 
            LEFT JOIN players p ON w.wallet_address = p.wallet_address 
            WHERE w.id = ? AND w.status = 'pending'
            FOR UPDATE
        ");
        $stmt->execute([$id]);
        $withdrawal = $stmt->fetch();
        
        if (!$withdrawal) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Saque não encontrado ou já processado']);
            return;
        }
        
        // 2. Atualizar status do saque
        $stmt = $pdo->prepare("
            UPDATE withdrawals 
            SET status = 'approved', 
                approved_at = NOW(),
                tx_hash = ?
            WHERE id = ?
        ");
        $stmt->execute([$txHash, $id]);
        
        // 3. Registrar transação de pagamento
        $description = $txHash ? 
            "Pagamento de saque via MetaMask - TX: $txHash" : 
            "Pagamento de saque manual";
            
        $stmt = $pdo->prepare("
            INSERT INTO transactions 
            (wallet_address, type, amount, fee_bnb, fee_type, description, status, created_at)
            VALUES (?, 'withdrawal_paid', ?, 0, 'withdrawal', ?, 'completed', NOW())
        ");
        $stmt->execute([
            $withdrawal['wallet_address'],
            $withdrawal['amount_usdt'],
            $description
        ]);
        
        // 4. Registrar como sessão de pagamento (opcional)
        $stmt = $pdo->prepare("
            INSERT INTO admin_payments 
            (withdrawal_id, admin_user, amount_usdt, tx_hash, status, created_at)
            VALUES (?, ?, ?, ?, 'completed', NOW())
        ");
        $stmt->execute([
            $id,
            $_SESSION['admin_name'],
            $withdrawal['amount_usdt'],
            $txHash
        ]);
        
        // 5. Commit da transação
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => "✅ Saque #$id aprovado com sucesso!",
            'withdrawal_id' => $id,
            'amount' => $withdrawal['amount_usdt'],
            'wallet' => $withdrawal['wallet_address']
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erro ao aprovar saque: ' . $e->getMessage()]);
    }
}

function handleRejectWithdrawal($pdo) {
    $id = (int)($_POST['id'] ?? 0);
    
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        return;
    }
    
    try {
        // Iniciar transação
        $pdo->beginTransaction();
        
        // 1. Buscar informações do saque
        $stmt = $pdo->prepare("
            SELECT w.*, p.id as player_id, p.balance_usdt as player_balance
            FROM withdrawals w 
            LEFT JOIN players p ON w.wallet_address = p.wallet_address 
            WHERE w.id = ? AND w.status = 'pending'
            FOR UPDATE
        ");
        $stmt->execute([$id]);
        $withdrawal = $stmt->fetch();
        
        if (!$withdrawal) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Saque não encontrado ou já processado']);
            return;
        }
        
        // 2. Devolver saldo ao jogador (se existir)
        if ($withdrawal['player_id']) {
            $newBalance = $withdrawal['player_balance'] + $withdrawal['amount_usdt'];
            $stmt = $pdo->prepare("
                UPDATE players 
                SET balance_usdt = ?
                WHERE id = ?
            ");
            $stmt->execute([$newBalance, $withdrawal['player_id']]);
        }
        
        // 3. Atualizar status do saque
        $stmt = $pdo->prepare("
            UPDATE withdrawals 
            SET status = 'rejected', 
                approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        
        // 4. Registrar transação de rejeição
        $stmt = $pdo->prepare("
            INSERT INTO transactions 
            (wallet_address, type, amount, description, status, created_at)
            VALUES (?, 'withdrawal_rejected', ?, 'Saque rejeitado - Saldo devolvido', 'completed', NOW())
        ");
        $stmt->execute([
            $withdrawal['wallet_address'],
            $withdrawal['amount_usdt']
        ]);
        
        // 5. Commit da transação
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => "❌ Saque #$id rejeitado! Saldo devolvido.",
            'withdrawal_id' => $id,
            'amount' => $withdrawal['amount_usdt'],
            'wallet' => $withdrawal['wallet_address']
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Erro ao rejeitar saque: ' . $e->getMessage()]);
    }
}

// Verificar se tabela admin_payments existe
function checkAdminPaymentsTable($pdo) {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'admin_payments'")->fetch();
    
    if (!$tableCheck) {
        $pdo->exec("
            CREATE TABLE admin_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                withdrawal_id INT NOT NULL,
                admin_user VARCHAR(50) NOT NULL,
                amount_usdt DECIMAL(20,8) NOT NULL,
                tx_hash VARCHAR(66),
                status VARCHAR(20) DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_withdrawal (withdrawal_id),
                INDEX idx_admin (admin_user),
                INDEX idx_created (created_at)
            )
        ");
    }
}

// Chamar a verificação no início
checkAdminPaymentsTable($pdo);
?>