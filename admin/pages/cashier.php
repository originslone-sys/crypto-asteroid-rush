<?php
// ============================================
// UNOBIX - Recarregar Caixa (Admin)
// admin/pages/cashier.php
// Depositar valores via PIX no gateway ZettPay
// ============================================

$pageTitle = 'Recarregar Caixa';

require_once __DIR__ . '/../../api/zettpay-client.php';

$message = '';
$messageType = '';
$pixData = null;

// ── Processar ação POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_pix') {
        $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');

        if ($amount < 1) {
            $message = 'Valor mínimo: R$ 1,00';
            $messageType = 'danger';
        } elseif ($amount > 50000) {
            $message = 'Valor máximo: R$ 50.000,00';
            $messageType = 'danger';
        } else {
            try {
                $externalId = 'CAIXA-' . time() . '-' . bin2hex(random_bytes(4));

                $result = zettpayCreateDeposit(
                    $amount,
                    $externalId,
                    "Recarga de caixa UNOBIX - Admin",
                    [
                        'name' => 'Admin UNOBIX',
                        'email' => 'admin@unobix.com'
                    ],
                    [
                        'type' => 'cashier_reload'
                    ]
                );

                if ($result['success'] && !empty($result['data'])) {
                    $apiData = $result['data'];
                    $pixCode = $apiData['qr_code'] ?? $apiData['pix_copy_paste'] ?? '';

                    if (!empty($pixCode)) {
                        // Salvar na tabela zettpay_transactions
                        $pdo->prepare("
                            INSERT INTO zettpay_transactions (
                                user_id, external_id, zettpay_id, type, amount_brl,
                                status, qr_code, pix_copy_paste, expires_at, created_at
                            ) VALUES (0, ?, ?, 'deposit', ?, 'pending', ?, ?, ?, NOW())
                        ")->execute([
                            $externalId,
                            $apiData['id'] ?? null,
                            $amount,
                            $pixCode,
                            $pixCode,
                            $apiData['expires_at'] ?? null
                        ]);

                        $pixData = [
                            'external_id' => $externalId,
                            'amount' => $amount,
                            'pix_code' => $pixCode,
                            'expires_at' => $apiData['expires_at'] ?? null
                        ];

                        secureLog("ADMIN_CASHIER_PIX | amount: R\${$amount} | external_id: {$externalId}");
                        $message = 'PIX gerado com sucesso! Escaneie o QR Code ou copie o código.';
                        $messageType = 'success';
                    } else {
                        $message = 'PIX gerado mas sem QR Code na resposta.';
                        $messageType = 'danger';
                        secureLog("ADMIN_CASHIER_NO_QR | external_id: {$externalId} | response: " . json_encode($apiData));
                    }
                } else {
                    $message = 'Erro ao gerar PIX: ' . ($result['error'] ?? 'Resposta inválida');
                    $messageType = 'danger';
                }
            } catch (Exception $e) {
                $message = 'Erro: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// ── Buscar histórico de depósitos do caixa ───────────────────────────────
$deposits = [];
try {
    $stmt = $pdo->query("
        SELECT external_id, amount_brl, status, created_at, confirmed_at
        FROM zettpay_transactions
        WHERE external_id LIKE 'CAIXA-%' AND type = 'deposit'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Totais
$totalConfirmed = 0;
$totalPending = 0;
$countConfirmed = 0;
$countPending = 0;
foreach ($deposits as $d) {
    if ($d['status'] === 'confirmed') {
        $totalConfirmed += (float)$d['amount_brl'];
        $countConfirmed++;
    } elseif ($d['status'] === 'pending') {
        $totalPending += (float)$d['amount_brl'];
        $countPending++;
    }
}

function fmtBRL2(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}

$statusLabels = [
    'confirmed' => ['Confirmado', 'success'],
    'pending' => ['Pendente', 'warning'],
    'expired' => ['Expirado', 'secondary'],
    'failed' => ['Falhou', 'danger']
];
?>

<div class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-cash-register"></i> Recarregar Caixa</h1>
        <p class="page-subtitle">Depositar valores via PIX no gateway ZettPay</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom:16px;">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

        <!-- ══ Formulário de Depósito ══════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <h3 style="margin:0;font-size:1rem;"><i class="fas fa-qrcode"></i> Gerar PIX para Depósito</h3>
            </div>
            <div class="card-body" style="padding:20px;">
                <?php if ($pixData): ?>
                    <!-- QR Code e Copia-e-Cola -->
                    <div style="text-align:center;">
                        <div style="margin-bottom:12px;">
                            <span class="badge badge-warning" style="font-size:0.85rem;padding:6px 16px;">
                                <i class="fas fa-clock"></i> Aguardando Pagamento
                            </span>
                        </div>

                        <div style="font-family:'Orbitron',monospace;font-size:1.8rem;font-weight:900;color:#05ffa1;margin-bottom:16px;">
                            <?php echo fmtBRL2($pixData['amount']); ?>
                        </div>

                        <div style="margin:0 auto 16px;max-width:220px;">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?php echo urlencode($pixData['pix_code']); ?>"
                                 alt="QR Code PIX" style="width:100%;border-radius:12px;background:#fff;padding:8px;">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="font-size:0.75rem;color:var(--text-dim);display:block;margin-bottom:4px;">Copia e Cola:</label>
                            <div style="display:flex;gap:6px;">
                                <input type="text" id="pixCopyPaste" value="<?php echo htmlspecialchars($pixData['pix_code']); ?>"
                                       readonly style="flex:1;padding:8px 12px;border-radius:8px;border:1px solid var(--border-color);background:rgba(0,0,0,0.3);color:#fff;font-size:0.8rem;">
                                <button onclick="copyPixCode()" class="btn btn-primary" style="padding:8px 14px;white-space:nowrap;">
                                    <i class="fas fa-copy"></i> Copiar
                                </button>
                            </div>
                        </div>

                        <div style="font-size:0.72rem;color:var(--text-dim);margin-bottom:16px;">
                            ID: <code style="font-size:0.7rem;"><?php echo htmlspecialchars($pixData['external_id']); ?></code>
                        </div>

                        <div id="paymentStatus" style="padding:10px;border-radius:8px;background:rgba(255,170,0,0.08);border:1px solid rgba(255,170,0,0.15);font-size:0.82rem;">
                            <i class="fas fa-spinner fa-spin" style="color:#ffaa00;margin-right:6px;"></i>
                            Verificando pagamento automaticamente...
                        </div>

                        <div style="margin-top:12px;">
                            <a href="?page=cashier" class="btn" style="padding:8px 20px;font-size:0.82rem;background:rgba(255,255,255,0.06);">
                                <i class="fas fa-plus"></i> Novo Depósito
                            </a>
                        </div>
                    </div>

                    <script>
                    function copyPixCode() {
                        const input = document.getElementById('pixCopyPaste');
                        input.select();
                        document.execCommand('copy');
                        const btn = event.target.closest('button');
                        const origHTML = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
                        btn.style.background = '#05ffa1';
                        btn.style.color = '#000';
                        setTimeout(() => { btn.innerHTML = origHTML; btn.style.background = ''; btn.style.color = ''; }, 2000);
                    }

                    // Polling para verificar pagamento
                    let pollCount = 0;
                    const maxPolls = 180; // 15 minutos
                    const externalId = '<?php echo addslashes($pixData['external_id']); ?>';

                    const pollInterval = setInterval(async () => {
                        pollCount++;
                        if (pollCount > maxPolls) {
                            clearInterval(pollInterval);
                            document.getElementById('paymentStatus').innerHTML =
                                '<i class="fas fa-clock" style="color:#ff3366;margin-right:6px;"></i> Tempo esgotado. Recarregue a página para verificar.';
                            document.getElementById('paymentStatus').style.borderColor = 'rgba(255,51,102,0.2)';
                            document.getElementById('paymentStatus').style.background = 'rgba(255,51,102,0.08)';
                            return;
                        }

                        try {
                            const checkResp = await fetch('/api/deposit-status-admin.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ external_id: externalId })
                            });
                            const data = await checkResp.json();

                            if (data.is_confirmed) {
                                clearInterval(pollInterval);
                                document.getElementById('paymentStatus').innerHTML =
                                    '<i class="fas fa-check-circle" style="color:#05ffa1;font-size:1.1rem;margin-right:6px;"></i> <strong>Pagamento confirmado!</strong>';
                                document.getElementById('paymentStatus').style.borderColor = 'rgba(5,255,161,0.3)';
                                document.getElementById('paymentStatus').style.background = 'rgba(5,255,161,0.08)';
                            } else if (data.is_expired) {
                                clearInterval(pollInterval);
                                document.getElementById('paymentStatus').innerHTML =
                                    '<i class="fas fa-times-circle" style="color:#ff3366;margin-right:6px;"></i> PIX expirado. Gere um novo.';
                                document.getElementById('paymentStatus').style.borderColor = 'rgba(255,51,102,0.2)';
                                document.getElementById('paymentStatus').style.background = 'rgba(255,51,102,0.08)';
                            }
                        } catch (e) {}
                    }, 5000);
                    </script>

                <?php else: ?>
                    <form method="POST" style="max-width:360px;margin:0 auto;">
                        <input type="hidden" name="action" value="generate_pix">

                        <div style="margin-bottom:16px;">
                            <label style="font-size:0.82rem;color:var(--text-dim);display:block;margin-bottom:6px;">
                                <i class="fas fa-dollar-sign"></i> Valor do depósito (R$)
                            </label>
                            <input type="text" name="amount" id="amountInput" placeholder="0,00" required
                                   style="width:100%;padding:14px 16px;border-radius:10px;border:1px solid var(--border-color);
                                          background:rgba(0,0,0,0.3);color:#fff;font-size:1.3rem;font-weight:700;
                                          font-family:'Orbitron',monospace;text-align:center;">
                        </div>

                        <!-- Valores rápidos -->
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:20px;">
                            <?php foreach ([50, 100, 200, 500, 1000, 5000] as $v): ?>
                            <button type="button" onclick="document.getElementById('amountInput').value='<?php echo number_format($v, 2, ',', '.'); ?>'"
                                    style="padding:10px;border-radius:8px;border:1px solid var(--border-color);background:rgba(255,255,255,0.04);
                                           color:#fff;cursor:pointer;font-size:0.82rem;font-weight:600;transition:all 0.2s;"
                                    onmouseover="this.style.borderColor='var(--primary)';this.style.background='rgba(0,200,255,0.08)'"
                                    onmouseout="this.style.borderColor='var(--border-color)';this.style.background='rgba(255,255,255,0.04)'">
                                R$ <?php echo number_format($v, 0, ',', '.'); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;font-size:1rem;font-weight:700;border-radius:10px;">
                            <i class="fas fa-qrcode"></i> Gerar PIX
                        </button>

                        <div style="font-size:0.72rem;color:var(--text-dim);text-align:center;margin-top:10px;">
                            <i class="fas fa-shield-alt"></i> Pagamento processado via ZettPay
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══ Resumo ═════════════════════════════════════════════════════ -->
        <div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                <div class="card" style="border-top:3px solid #05ffa1;">
                    <div class="card-body" style="padding:16px;">
                        <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                            <i class="fas fa-check-circle" style="color:#05ffa1;margin-right:4px;"></i>Depositado
                        </div>
                        <div style="font-family:'Orbitron',monospace;font-size:1.4rem;font-weight:900;color:#05ffa1;">
                            <?php echo fmtBRL2($totalConfirmed); ?>
                        </div>
                        <div style="font-size:0.72rem;color:var(--text-dim);margin-top:4px;">
                            <?php echo $countConfirmed; ?> depósito(s) confirmado(s)
                        </div>
                    </div>
                </div>

                <div class="card" style="border-top:3px solid #ffaa00;">
                    <div class="card-body" style="padding:16px;">
                        <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">
                            <i class="fas fa-clock" style="color:#ffaa00;margin-right:4px;"></i>Pendente
                        </div>
                        <div style="font-family:'Orbitron',monospace;font-size:1.4rem;font-weight:900;color:#ffaa00;">
                            <?php echo fmtBRL2($totalPending); ?>
                        </div>
                        <div style="font-size:0.72rem;color:var(--text-dim);margin-top:4px;">
                            <?php echo $countPending; ?> depósito(s) pendente(s)
                        </div>
                    </div>
                </div>
            </div>

            <!-- Histórico -->
            <div class="card">
                <div class="card-header">
                    <h3 style="margin:0;font-size:1rem;"><i class="fas fa-history"></i> Histórico de Depósitos</h3>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th style="text-align:right;">Valor</th>
                                <th style="text-align:center;">Status</th>
                                <th>ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($deposits)): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center;padding:30px;color:var(--text-dim);">
                                        <i class="fas fa-inbox" style="font-size:1.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                                        Nenhum depósito realizado
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($deposits as $d):
                                    $sl = $statusLabels[$d['status']] ?? [$d['status'], 'secondary'];
                                ?>
                                <tr style="<?php echo $d['status'] === 'expired' ? 'opacity:0.5;' : ''; ?>">
                                    <td style="font-size:0.82rem;">
                                        <?php echo date('d/m/y H:i', strtotime($d['created_at'])); ?>
                                        <?php if ($d['confirmed_at']): ?>
                                            <div style="font-size:0.68rem;color:#05ffa1;">
                                                <i class="fas fa-check"></i> <?php echo date('d/m/y H:i', strtotime($d['confirmed_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;font-weight:700;font-family:'Orbitron',monospace;font-size:0.9rem;">
                                        <?php echo fmtBRL2((float)$d['amount_brl']); ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="badge badge-<?php echo $sl[1]; ?>"><?php echo $sl[0]; ?></span>
                                    </td>
                                    <td style="font-size:0.68rem;color:var(--text-dim);">
                                        <code><?php echo htmlspecialchars(substr($d['external_id'], 0, 24)); ?></code>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
