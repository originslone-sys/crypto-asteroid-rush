<?php
// ============================================
// UNOBIX - Admin Auth com Cookie Persistente
// Resolve perda de sessão em Cloud Run (stateless)
// ============================================

define('ADMIN_AUTH_COOKIE', 'unobix_admin_token');
define('ADMIN_AUTH_SECRET', getenv('ADMIN_AUTH_SECRET') ?: 'unobix_adm_v2_k9x7m2p4w8!hmac2026');
define('ADMIN_AUTH_EXPIRY', 86400 * 1); // 1 dia (reduzido de 7 para segurança)

/**
 * Gera token HMAC assinado para o cookie admin
 */
function adminGenerateToken(string $username, int $expiry): string {
    $payload = $username . '|' . $expiry;
    $signature = hash_hmac('sha256', $payload, ADMIN_AUTH_SECRET);
    return base64_encode($payload . '|' . $signature);
}

/**
 * Valida token do cookie admin
 * Retorna username se válido, false caso contrário
 */
function adminValidateToken(string $token): string|false {
    $decoded = base64_decode($token, true);
    if ($decoded === false) return false;

    $parts = explode('|', $decoded);
    if (count($parts) !== 3) return false;

    [$username, $expiry, $signature] = $parts;

    // Verificar expiração
    if ((int)$expiry < time()) return false;

    // Verificar assinatura HMAC
    $expectedSig = hash_hmac('sha256', $username . '|' . $expiry, ADMIN_AUTH_SECRET);
    if (!hash_equals($expectedSig, $signature)) return false;

    return $username;
}

/**
 * Define cookie de autenticação admin
 */
function adminSetAuthCookie(string $username): void {
    $expiry = time() + ADMIN_AUTH_EXPIRY;
    $token = adminGenerateToken($username, $expiry);

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    setcookie(ADMIN_AUTH_COOKIE, $token, [
        'expires'  => $expiry,
        'path'     => '/',
        'secure'   => $secure,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Remove cookie de autenticação admin
 */
function adminClearAuthCookie(): void {
    setcookie(ADMIN_AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Restaura sessão admin a partir do cookie se a sessão PHP foi perdida.
 * Deve ser chamado APÓS session_start().
 */
function adminRestoreSession(): bool {
    if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
        return true; // Sessão já ativa
    }

    if (empty($_COOKIE[ADMIN_AUTH_COOKIE])) {
        return false;
    }

    $username = adminValidateToken($_COOKIE[ADMIN_AUTH_COOKIE]);
    if ($username === false) {
        adminClearAuthCookie();
        return false;
    }

    // Verificar se sessão foi revogada
    try {
        $sid = session_id();
        if ($sid) {
            require_once __DIR__ . '/../../api/config.php';
            $pdoAuth = getDatabaseConnection();
            $chk = $pdoAuth->prepare("SELECT revoked FROM admin_active_sessions WHERE session_id = ? LIMIT 1");
            $chk->execute([$sid]);
            $row = $chk->fetch();
            if ($row && $row['revoked']) {
                adminClearAuthCookie();
                return false;
            }
        }
    } catch (Exception $e) { /* tabela pode não existir ainda */ }

    // Restaurar sessão
    $_SESSION['admin'] = true;
    $_SESSION['admin_name'] = $username;
    $_SESSION['admin_logged_in'] = true;

    return true;
}

/**
 * Registra tentativa de login no banco (sucesso ou falha)
 * Busca localização do IP via API gratuita
 */
function adminLogLogin(string $username, bool $success, string $ip): void {
    try {
        // Buscar geolocalização do IP (API gratuita, sem chave)
        $city = null;
        $region = null;
        $country = null;

        $geoContext = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $geoResponse = @file_get_contents("http://ip-api.com/json/{$ip}?fields=city,regionName,country&lang=pt-BR", false, $geoContext);
        if ($geoResponse) {
            $geo = json_decode($geoResponse, true);
            if (!empty($geo['city'])) {
                $city = $geo['city'];
                $region = $geo['regionName'] ?? null;
                $country = $geo['country'] ?? null;
            }
        }

        require_once __DIR__ . '/../../api/config.php';
        $pdo = getDatabaseConnection();

        // Criar tabela se não existir (fallback para primeiro uso)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_login_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                city VARCHAR(100) DEFAULT NULL,
                region VARCHAR(100) DEFAULT NULL,
                country VARCHAR(50) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created (created_at),
                INDEX idx_ip (ip_address),
                INDEX idx_success (success)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $pdo->prepare("
            INSERT INTO admin_login_log (username, success, ip_address, user_agent, city, region, country)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            substr($username, 0, 50),
            $success ? 1 : 0,
            $ip,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            $city,
            $region,
            $country
        ]);
    } catch (Exception $e) {
        error_log("adminLogLogin error: " . $e->getMessage());
    }
}
