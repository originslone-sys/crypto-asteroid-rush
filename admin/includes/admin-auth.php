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

    // Restaurar sessão
    $_SESSION['admin'] = true;
    $_SESSION['admin_name'] = $username;
    $_SESSION['admin_logged_in'] = true;

    return true;
}
