<?php
// ============================================
// UNOBIX - Recarregar Caixa (Admin)
// admin/pages/cashier.php
// Depositar e retirar valores via PIX no gateway ZettPay
// ============================================

$pageTitle = 'Recarregar Caixa';

require_once __DIR__ . '/../../api/zettpay-client.php';

$message = '';
$messageType = '';
$pixData = null;
$cashoutResult = null;

// ── Processar ação POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cashier_withdraw') {
        // ════ Retirada de saldo do caixa via PIX ════
        $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['cw_amount'] ?? '0');
        $pixKey = trim($_POST['cw_pix_key'] ?? '');
        $pixKeyType = strtolower(trim($_POST['cw_pix_key_type'] ?? 'cpf'));
        $confirmText = trim($_POST['cw_confirm'] ?? '');

        $allowedKeyTypes = ['cpf', 'cnpj', 'email', 'phone', 'evp'];

        // Mapeamento igual ao auto-withdraw.php - ZettPay espera 'document' para cpf/cnpj
        $keyTypeMap = [
            'cpf' => 'document', 'cnpj' => 'document', 'document' => 'document',
            'email' => 'email', 'phone' => 'phone', 'celular' => 'phone',
            'aleatoria' => 'evp', 'evp' => 'evp'
        ];

        if ($confirmText !== 'CONFIRMAR') {
            $message = 'Para confirmar a retirada digite CONFIRMAR no campo de confirmação.';
            $messageType = 'danger';
        } elseif ($amount < 1) {
            $message = 'Valor mínimo de retirada: R$ 1,00';
            $messageType = 'danger';
        } elseif ($amount > 50000) {
            $message = 'Valor máximo de retirada: R$ 50.000,00';
            $messageType = 'danger';
        } elseif (!in_array($pixKeyType, $allowedKeyTypes)) {
            $message = 'Tipo de chave PIX inválido.';
            $messageType = 'danger';
        } elseif (empty($pixKey)) {
            $message = 'Chave PIX é obrigatória.';
            $messageType = 'danger';
        } else {
            // Normalizar chave conforme tipo
            if ($pixKeyType === 'cpf' || $pixKeyType === 'cnpj') {
                $pixKey = preg_replace('/\D/', '', $pixKey);
            } elseif ($pixKeyType === 'phone') {
                // Remover tudo que não é dígito e garantir formato +55
                $digits = preg_replace('/\D/', '', $pixKey);
                if (strlen($digits) >= 10 && substr($digits, 0, 2) !== '55') {
                    $digits = '55' . $digits;
                }
                $pixKey = '+' . $digits;
            }

            // Converter para o tipo que o ZettPay espera
            $zettpayKeyType = $keyTypeMap[$pixKeyType] ?? 'document';

            try {
                $externalId = 'CAIXA-OUT-' . time() . '-' . bin2hex(random_bytes(4));

                $apiResult = zettpayCreateCashout(
                    $amount,
                    $pixKey,
                    $zettpayKeyType,
                    $externalId,
                    ['type' => 'cashier_withdraw']
                );

                if ($apiResult['success']) {
                    $apiData = $apiResult['data']['data'] ?? $apiResult['data'] ?? [];

                    // Registrar na tabela zettpay_transactions (user_id=0 indica caixa/admin)
                    $pdo->prepare("
                        INSERT INTO zettpay_transactions (
                            user_id, external_id, zettpay_id, type, amount_brl, fee_brl,
                            status, pix_key, pix_key_type, created_at
                        ) VALUES (0, ?, ?, 'cashout', ?, 0, 'processing', ?, ?, NOW())
                    ")->execute([
                        $externalId,
                        $apiData['provider_transaction_id'] ?? $apiData['id'] ?? null,
                        $amount,
                        $pixKey,
                        $zettpayKeyType
                    ]);

                    secureLog("ADMIN_CASHIER_WITHDRAW | amount: R\${$amount} | external_id: {$externalId} | key_type: {$zettpayKeyType} | pix_key: " . substr($pixKey, 0, 6) . "***");

                    $cashoutResult = [
                        'external_id' => $externalId,
                        'amount' => $amount,
                        'pix_key' => $pixKey,
                        'pix_key_type' => $zettpayKeyType
                    ];
                    $message = 'Retirada de R$ ' . number_format($amount, 2, ',', '.') . ' enviada ao gateway. Acompanhe o status no histórico abaixo.';
                    $messageType = 'success';
                } else {
                    // Extrair mensagem de erro detalhada igual ao auto-withdraw
                    $errorMsg = $apiResult['error'] ?? 'Resposta inválida do gateway';
                    $errorData = $apiResult['data'] ?? [];
                    $errorCode = '';
                    if (is_array($errorData)) {
                        $errorCode = $errorData['error']['code'] ?? $errorData['code'] ?? '';
                    }
                    $fullErr = $errorMsg . ($errorCode ? " ({$errorCode})" : '');

                    $message = 'Erro ao processar retirada: ' . $fullErr;
                    $messageType = 'danger';
                    secureLog("ADMIN_CASHIER_WITHDRAW_FAIL | external_id: {$externalId} | http: " . ($apiResult['http_code'] ?? 0) . " | error: " . $fullErr);
                }
            } catch (Exception $e) {
                $message = 'Erro: ' . $e->getMessage();
                $messageType = 'danger';
                secureLog("ADMIN_CASHIER_WITHDRAW_EXCEPTION | " . $e->getMessage());
            }
        }
    } elseif ($action === 'generate_pix') {
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

// ── Consultar saldo disponível no gateway ZettPay ────────────────────────
$gatewayBalance = zettpayGetBalance();

// ── Buscar histórico de depósitos do caixa ───────────────────────────────
$deposits = [];
try {
    $stmt = $pdo->query("
        SELECT external_id, amount_brl, status, created_at, confirmed_at
        FROM zettpay_transactions
        WHERE external_id LIKE 'CAIXA-%' AND external_id NOT LIKE 'CAIXA-OUT-%' AND type = 'deposit'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Buscar histórico de retiradas do caixa ───────────────────────────────
$cashierWithdrawals = [];
try {
    $stmt = $pdo->query("
        SELECT external_id, amount_brl, status, pix_key, pix_key_type, created_at, confirmed_at
        FROM zettpay_transactions
        WHERE external_id LIKE 'CAIXA-OUT-%' AND type = 'cashout'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $cashierWithdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Totais de depósitos
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

// Totais de retiradas do caixa
$totalCashierWithdrawn = 0;
$totalCashierWithdrawPending = 0;
foreach ($cashierWithdrawals as $cw) {
    if ($cw['status'] === 'confirmed') {
        $totalCashierWithdrawn += (float)$cw['amount_brl'];
    } elseif ($cw['status'] === 'processing' || $cw['status'] === 'pending') {
        $totalCashierWithdrawPending += (float)$cw['amount_brl'];
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
        <p class="page-subtitle">Depositar e retirar valores via PIX no gateway ZettPay</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom:16px;">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- ══ Saldo do Gateway (ZettPay) ══════════════════════════════════════ -->
    <div class="card" style="margin-bottom:20px;border-top:3px solid #00c8ff;">
        <div class="card-body" style="padding:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div style="display:flex;align-items:center;gap:18px;">
                <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#00c8ff,#0084ff);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:#fff;box-shadow:0 6px 18px rgba(0,200,255,0.25);">
                    <i class="fas fa-wallet"></i>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:4px;">
                        Saldo Disponível no Gateway
                    </div>
                    <?php if ($gatewayBalance['success']): ?>
                        <div style="font-family:'Orbitron',monospace;font-size:1.8rem;font-weight:900;color:#00c8ff;line-height:1;">
                            <?php echo fmtBRL2($gatewayBalance['available']); ?>
                        </div>
                        <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:0.72rem;">
                            <?php if ($gatewayBalance['blocked'] !== null && $gatewayBalance['blocked'] > 0): ?>
                                <span style="color:#ffaa00;">
                                    <i class="fas fa-lock"></i> Bloqueado: <strong><?php echo fmtBRL2($gatewayBalance['blocked']); ?></strong>
                                </span>
                            <?php endif; ?>
                            <?php if ($gatewayBalance['current'] !== null): ?>
                                <span style="color:var(--text-dim);">
                                    <i class="fas fa-coins"></i> Total: <strong><?php echo fmtBRL2($gatewayBalance['current']); ?></strong>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($gatewayBalance['last_updated_at'])):
                                $lastUpd = strtotime($gatewayBalance['last_updated_at']);
                            ?>
                                <span style="color:var(--text-dim);">
                                    <i class="fas fa-clock"></i> Atualizado: <?php echo $lastUpd ? date('d/m/y H:i', $lastUpd) : htmlspecialchars($gatewayBalance['last_updated_at']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-family:'Orbitron',monospace;font-size:1.2rem;font-weight:700;color:#ff6666;line-height:1;">
                            Indisponível
                        </div>
                        <div style="font-size:0.7rem;color:var(--text-dim);margin-top:4px;">
                            <?php echo htmlspecialchars($gatewayBalance['error'] ?? 'Não foi possível consultar o saldo'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="?page=cashier" class="btn" style="padding:10px 16px;font-size:0.85rem;background:rgba(255,255,255,0.06);">
                    <i class="fas fa-sync"></i> Atualizar
                </a>
                <?php if ($gatewayBalance['success'] && $gatewayBalance['available'] > 0): ?>
                <button type="button" onclick="document.getElementById('cashierWithdrawCard').scrollIntoView({behavior:'smooth'});document.getElementById('cwAmount')?.focus();"
                        class="btn btn-primary" style="padding:10px 18px;font-size:0.85rem;background:linear-gradient(135deg,#ff6600,#ff8800);border:none;">
                    <i class="fas fa-money-bill-transfer"></i> Retirar via PIX
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

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

    <!-- ══ Retirada de Caixa via PIX ══════════════════════════════════════ -->
    <div class="card" id="cashierWithdrawCard" style="margin-bottom:20px;border-top:3px solid #ff6600;">
        <div class="card-header">
            <h3 style="margin:0;font-size:1rem;"><i class="fas fa-money-bill-transfer" style="color:#ff6600;"></i> Retirar Saldo do Caixa via PIX</h3>
        </div>
        <div class="card-body" style="padding:20px;">
            <div style="background:rgba(255,102,0,0.08);border:1px solid rgba(255,102,0,0.2);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:0.82rem;color:#ffaa66;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Atenção:</strong> Esta operação retira saldo real do gateway ZettPay para a chave PIX informada. Confira os dados antes de confirmar.
            </div>

            <form method="POST" onsubmit="return confirmCashierWithdraw(event)" style="max-width:520px;margin:0 auto;">
                <input type="hidden" name="action" value="cashier_withdraw">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:6px;">
                            <i class="fas fa-dollar-sign"></i> Valor (R$)
                        </label>
                        <input type="text" name="cw_amount" id="cwAmount" placeholder="0,00" required
                               style="width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--border-color);
                                      background:rgba(0,0,0,0.3);color:#fff;font-size:1.15rem;font-weight:700;
                                      font-family:'Orbitron',monospace;text-align:center;">
                    </div>
                    <div>
                        <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:6px;">
                            <i class="fas fa-key"></i> Tipo de Chave PIX
                        </label>
                        <select name="cw_pix_key_type" id="cwPixKeyType"
                                style="width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--border-color);
                                       background:rgba(0,0,0,0.3);color:#fff;font-size:0.95rem;">
                            <option value="cpf">CPF</option>
                            <option value="cnpj">CNPJ</option>
                            <option value="email">E-mail</option>
                            <option value="phone">Telefone</option>
                            <option value="evp">Chave Aleatória</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:6px;">
                        <i class="fas fa-fingerprint"></i> Chave PIX do Destinatário
                    </label>
                    <input type="text" name="cw_pix_key" id="cwPixKey" placeholder="Digite a chave PIX" required
                           style="width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--border-color);
                                  background:rgba(0,0,0,0.3);color:#fff;font-size:0.95rem;">
                </div>

                <div style="margin-bottom:18px;">
                    <label style="font-size:0.8rem;color:var(--text-dim);display:block;margin-bottom:6px;">
                        <i class="fas fa-shield-alt"></i> Digite <code style="color:#ff6600;font-weight:700;">CONFIRMAR</code> para autorizar a retirada
                    </label>
                    <input type="text" name="cw_confirm" placeholder="CONFIRMAR" required
                           style="width:100%;padding:12px 14px;border-radius:10px;border:1px solid var(--border-color);
                                  background:rgba(0,0,0,0.3);color:#fff;font-size:0.95rem;text-transform:uppercase;letter-spacing:1.5px;">
                </div>

                <button type="submit" class="btn" style="width:100%;padding:14px;font-size:1rem;font-weight:700;border-radius:10px;
                        background:linear-gradient(135deg,#ff6600,#ff8800);color:#fff;border:none;cursor:pointer;">
                    <i class="fas fa-paper-plane"></i> Retirar Saldo via PIX
                </button>

                <div style="font-size:0.72rem;color:var(--text-dim);text-align:center;margin-top:10px;">
                    <i class="fas fa-shield-alt"></i> Saída processada via gateway ZettPay
                </div>
            </form>

            <script>
            function confirmCashierWithdraw(e) {
                const amt = document.getElementById('cwAmount').value || '0';
                const key = document.getElementById('cwPixKey').value || '';
                if (!confirm('Confirmar retirada de R$ ' + amt + ' para a chave PIX:\n' + key + '\n\nEsta ação NÃO pode ser desfeita.')) {
                    e.preventDefault();
                    return false;
                }
                return true;
            }

            // Máscara do valor (formato brasileiro)
            (function() {
                const inp = document.getElementById('cwAmount');
                if (!inp) return;
                inp.addEventListener('input', () => {
                    let v = inp.value.replace(/\D/g, '');
                    if (!v) { inp.value = ''; return; }
                    v = (parseInt(v, 10) / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    inp.value = v;
                });
            })();
            </script>
        </div>
    </div>

    <!-- ══ Histórico de Retiradas de Caixa ════════════════════════════════ -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <h3 style="margin:0;font-size:1rem;"><i class="fas fa-history"></i> Histórico de Retiradas do Caixa</h3>
            <div style="display:flex;gap:14px;font-size:0.78rem;">
                <div>
                    <span style="color:var(--text-dim);">Retirado: </span>
                    <strong style="color:#05ffa1;font-family:'Orbitron',monospace;"><?php echo fmtBRL2($totalCashierWithdrawn); ?></strong>
                </div>
                <?php if ($totalCashierWithdrawPending > 0): ?>
                <div>
                    <span style="color:var(--text-dim);">Em processamento: </span>
                    <strong style="color:#ffaa00;font-family:'Orbitron',monospace;"><?php echo fmtBRL2($totalCashierWithdrawPending); ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th style="text-align:right;">Valor</th>
                        <th>Chave PIX</th>
                        <th style="text-align:center;">Tipo</th>
                        <th style="text-align:center;">Status</th>
                        <th>ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cashierWithdrawals)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:30px;color:var(--text-dim);">
                                <i class="fas fa-inbox" style="font-size:1.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                                Nenhuma retirada de caixa realizada
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cashierWithdrawals as $cw):
                            $cwsl = $statusLabels[$cw['status']] ?? [$cw['status'], 'secondary'];
                            if ($cw['status'] === 'processing') $cwsl = ['Processando', 'warning'];
                            $maskedKey = $cw['pix_key'];
                            if (in_array($cw['pix_key_type'], ['cpf', 'cnpj']) && strlen($maskedKey) > 6) {
                                $maskedKey = substr($maskedKey, 0, 3) . '***' . substr($maskedKey, -2);
                            } elseif ($cw['pix_key_type'] === 'email' && strpos($maskedKey, '@') !== false) {
                                $parts = explode('@', $maskedKey);
                                $maskedKey = substr($parts[0], 0, 2) . '***@' . $parts[1];
                            } elseif (strlen($maskedKey) > 6) {
                                $maskedKey = substr($maskedKey, 0, 3) . '***' . substr($maskedKey, -3);
                            }
                        ?>
                        <tr>
                            <td style="font-size:0.82rem;">
                                <?php echo date('d/m/y H:i', strtotime($cw['created_at'])); ?>
                                <?php if ($cw['confirmed_at']): ?>
                                    <div style="font-size:0.68rem;color:#05ffa1;">
                                        <i class="fas fa-check"></i> <?php echo date('d/m/y H:i', strtotime($cw['confirmed_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;font-weight:700;font-family:'Orbitron',monospace;font-size:0.9rem;color:#ff8866;">
                                -<?php echo fmtBRL2((float)$cw['amount_brl']); ?>
                            </td>
                            <td style="font-size:0.78rem;font-family:monospace;color:var(--text-dim);">
                                <?php echo htmlspecialchars($maskedKey); ?>
                            </td>
                            <td style="text-align:center;font-size:0.72rem;text-transform:uppercase;color:var(--text-dim);">
                                <?php echo htmlspecialchars($cw['pix_key_type']); ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge badge-<?php echo $cwsl[1]; ?>"><?php echo $cwsl[0]; ?></span>
                            </td>
                            <td style="font-size:0.68rem;color:var(--text-dim);">
                                <code><?php echo htmlspecialchars(substr($cw['external_id'], 0, 26)); ?></code>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
