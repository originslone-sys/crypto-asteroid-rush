<?php
// ============================================
// UNOBIX - Detecção de VPN/Proxy/TOR
// Arquivo: api/proxy-check.php
// v7.0 - proxycheck.io com cache em banco
// ============================================

// config.php já deve estar carregado pelo arquivo que inclui proxy-check.php
// require_once __DIR__ . "/config.php";

/**
 * Verificar se IP é VPN/Proxy/TOR
 * 
 * @param PDO $pdo Conexão com banco
 * @param string $ip Endereço IP
 * @return array [
 *   'allowed' => bool,
 *   'is_vpn' => bool,
 *   'is_proxy' => bool,
 *   'type' => string (VPN, Proxy, TOR, Residential, etc),
 *   'provider' => string,
 *   'country' => string,
 *   'risk' => int (0-100),
 *   'cached' => bool,
 *   'error' => string|null
 * ]
 */
function checkProxyVPN($pdo, $ip = null) {
    $ip = $ip ?: getClientIP();
    
    // Se desabilitado, permitir tudo
    if (!PROXY_CHECK_ENABLED) {
        return ['allowed' => true, 'is_vpn' => false, 'is_proxy' => false, 'cached' => false];
    }
    
    // IPs locais sempre permitidos
    if (isLocalIP($ip)) {
        return ['allowed' => true, 'is_vpn' => false, 'is_proxy' => false, 'cached' => false, 'local' => true];
    }
    
    // ============================================
    // 1. VERIFICAR CACHE
    // ============================================
    $cached = getProxyCacheResult($pdo, $ip);
    if ($cached !== null) {
        return $cached;
    }
    
    // ============================================
    // 2. CONSULTAR proxycheck.io
    // ============================================
    $result = queryProxyCheck($ip);
    
    // ============================================
    // 3. SALVAR CACHE
    // ============================================
    if ($result && !isset($result['error'])) {
        saveProxyCacheResult($pdo, $ip, $result);
    }
    
    // ============================================
    // 4. DECIDIR SE BLOQUEIA
    // ============================================
    $result['allowed'] = shouldAllowIP($result);
    
    // ============================================
    // 5. LOGAR SE BLOQUEADO
    // ============================================
    if (!$result['allowed']) {
        $type = $result['type'] ?? 'unknown';
        $provider = $result['provider'] ?? '';
        secureLog("PROXY_BLOCKED | IP: {$ip} | Type: {$type} | Provider: {$provider} | Risk: " . ($result['risk'] ?? 0));
        
        // Registrar como atividade suspeita
        try {
            $stmtSusp = $pdo->prepare("
                INSERT INTO suspicious_activity 
                (user_id, ip_address, activity_type, details, severity, created_at)
                VALUES (NULL, ?, 'VPN_PROXY_DETECTED', ?, 'high', NOW())
            ");
            $stmtSusp->execute([
                $ip,
                json_encode([
                    'type' => $type,
                    'provider' => $provider,
                    'country' => $result['country'] ?? '',
                    'risk' => $result['risk'] ?? 0,
                    'blocked' => !$result['allowed']
                ])
            ]);
        } catch (Exception $e) {}
    } elseif (!empty($result['is_vpn']) || !empty($result['is_proxy'])) {
        // Permitido mas é VPN/Proxy — logar para monitoramento
        if (!PROXY_CHECK_LOG_ONLY) {
            secureLog("PROXY_ALLOWED | IP: {$ip} | Type: " . ($result['type'] ?? 'unknown'));
        }
    }
    
    return $result;
}

/**
 * Consultar API proxycheck.io
 */
function queryProxyCheck($ip) {
    $apiKey = PROXY_CHECK_API_KEY;
    
    // Montar URL
    $url = "https://proxycheck.io/v2/{$ip}";
    $params = [
        'vpn' => 3,          // Detecção VPN avançada
        'asn' => 1,          // Info de ASN
        'risk' => 1,         // Score de risco
        'port' => 1,         // Porta usada
        'seen' => 1,         // Última vez visto
        'days' => 7,         // Dados dos últimos 7 dias
        'tag' => 'unobix'    // Tag para identificação
    ];
    
    if ($apiKey) {
        $params['key'] = $apiKey;
    }
    
    $url .= '?' . http_build_query($params);
    
    $options = [
        'http' => [
            'method' => 'GET',
            'timeout' => 5,         // Timeout curto para não travar o jogo
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n"
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        secureLog("PROXYCHECK_ERROR | Falha ao contatar API | IP: {$ip}");
        // Fail-open: não bloquear se API falhar
        return [
            'is_vpn' => false,
            'is_proxy' => false,
            'type' => 'unknown',
            'risk' => 0,
            'error' => 'API timeout'
        ];
    }
    
    $data = json_decode($response, true);
    
    if (!$data || ($data['status'] ?? '') !== 'ok') {
        $errorMsg = $data['message'] ?? 'Resposta inválida';
        secureLog("PROXYCHECK_ERROR | {$errorMsg} | IP: {$ip}");
        return [
            'is_vpn' => false,
            'is_proxy' => false,
            'type' => 'unknown',
            'risk' => 0,
            'error' => $errorMsg
        ];
    }
    
    // Extrair dados do IP
    $ipData = $data[$ip] ?? [];
    
    $isProxy = strtolower($ipData['proxy'] ?? 'no') === 'yes';
    $proxyType = strtolower($ipData['type'] ?? '');
    $isVPN = $isProxy && in_array($proxyType, ['vpn', 'tor']);
    $isTOR = $proxyType === 'tor';
    $isResidential = $proxyType === 'residential';
    
    return [
        'is_vpn' => $isVPN,
        'is_proxy' => $isProxy,
        'is_tor' => $isTOR,
        'is_residential' => $isResidential,
        'type' => $ipData['type'] ?? 'unknown',
        'provider' => $ipData['provider'] ?? '',
        'country' => $ipData['country'] ?? '',
        'isocode' => $ipData['isocode'] ?? '',
        'asn' => $ipData['asn'] ?? '',
        'risk' => (int)($ipData['risk'] ?? 0),
        'port' => $ipData['port'] ?? null,
        'last_seen' => $ipData['last seen human'] ?? null,
        'cached' => false
    ];
}

/**
 * Verificar se deve permitir o IP baseado nos resultados e config
 */
function shouldAllowIP($result) {
    // Se modo log-only, sempre permitir
    if (PROXY_CHECK_LOG_ONLY) {
        return true;
    }
    
    // Se teve erro na API, permitir (fail-open)
    if (isset($result['error'])) {
        return true;
    }
    
    // Não é proxy/vpn
    if (empty($result['is_proxy']) && empty($result['is_vpn'])) {
        return true;
    }
    
    // TOR: bloquear se configurado
    if (!empty($result['is_tor']) && PROXY_CHECK_BLOCK_TOR) {
        return false;
    }
    
    // VPN residencial: permitir se configurado
    if (!empty($result['is_residential']) && PROXY_CHECK_ALLOW_RESIDENTIAL) {
        return true;
    }
    
    // VPN: bloquear se configurado
    if (!empty($result['is_vpn']) && PROXY_CHECK_BLOCK_VPN) {
        return false;
    }
    
    // Proxy genérico: bloquear se configurado
    if (!empty($result['is_proxy']) && PROXY_CHECK_BLOCK_PROXY) {
        return false;
    }
    
    return true;
}

/**
 * Verificar se é IP local/privado
 */
function isLocalIP($ip) {
    return in_array($ip, ['127.0.0.1', '::1', 'localhost']) ||
           filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

// ============================================
// CACHE — Evitar consultas repetidas ao mesmo IP
// ============================================

/**
 * Buscar resultado em cache
 */
function getProxyCacheResult($pdo, $ip) {
    try {
        // Garantir tabela existe
        ensureProxyCacheTable($pdo);
        
        $cacheHours = PROXY_CHECK_CACHE_HOURS;
        $stmt = $pdo->prepare("
            SELECT result_json, created_at FROM proxy_cache 
            WHERE ip_address = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            LIMIT 1
        ");
        $stmt->execute([$ip, $cacheHours]);
        $row = $stmt->fetch();
        
        if ($row) {
            $result = json_decode($row['result_json'], true);
            if ($result) {
                $result['cached'] = true;
                $result['cached_at'] = $row['created_at'];
                $result['allowed'] = shouldAllowIP($result);
                return $result;
            }
        }
    } catch (Exception $e) {
        // Cache falhou — consultar API normalmente
    }
    
    return null;
}

/**
 * Salvar resultado no cache
 */
function saveProxyCacheResult($pdo, $ip, $result) {
    try {
        ensureProxyCacheTable($pdo);
        
        $stmt = $pdo->prepare("
            INSERT INTO proxy_cache (ip_address, result_json, is_proxy, is_vpn, risk_score)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                result_json = VALUES(result_json),
                is_proxy = VALUES(is_proxy),
                is_vpn = VALUES(is_vpn),
                risk_score = VALUES(risk_score),
                created_at = NOW()
        ");
        $stmt->execute([
            $ip,
            json_encode($result),
            !empty($result['is_proxy']) ? 1 : 0,
            !empty($result['is_vpn']) ? 1 : 0,
            (int)($result['risk'] ?? 0)
        ]);
    } catch (Exception $e) {
        error_log("proxy_cache save error: " . $e->getMessage());
    }
}

/**
 * Garantir que tabela proxy_cache existe
 */
function ensureProxyCacheTable($pdo) {
    static $checked = false;
    if ($checked) return;
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS proxy_cache (
                ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
                result_json TEXT NOT NULL,
                is_proxy TINYINT(1) DEFAULT 0,
                is_vpn TINYINT(1) DEFAULT 0,
                risk_score INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created (created_at),
                INDEX idx_proxy (is_proxy),
                INDEX idx_vpn (is_vpn)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $checked = true;
    } catch (Exception $e) {}
}

/**
 * Limpar cache expirado (chamar periodicamente ou via admin cleanup)
 */
function cleanProxyCache($pdo) {
    try {
        $hours = PROXY_CHECK_CACHE_HOURS;
        $stmt = $pdo->exec("DELETE FROM proxy_cache WHERE created_at < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)");
        return $stmt;
    } catch (Exception $e) {
        return 0;
    }
}
