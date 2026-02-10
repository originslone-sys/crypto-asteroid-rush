<?php
// ============================================
// UNOBIX - Rate Limiter
// Arquivo: api/rate-limiter.php
// v3.0 - Fix: ALTER TABLE para colunas faltantes
// ============================================

// Configurações de Rate Limit
if (!defined('RATE_LIMIT_GAME_INTERVAL')) {
    define('RATE_LIMIT_GAME_INTERVAL', 180);     // 3 minutos entre jogos
    define('RATE_LIMIT_REQUESTS_PER_MINUTE', 100);
    define('RATE_LIMIT_REQUESTS_PER_HOUR', 500);
    define('RATE_LIMIT_EVENTS_PER_SECOND', 50);
}

class RateLimiter {
    private $pdo;
    private $ip;
    private $wallet;
    private $googleUid;
    
    public function __construct($pdo, $wallet = null, $googleUid = null) {
        $this->pdo = $pdo;
        $this->ip = $this->getClientIP();
        $this->wallet = $wallet ? strtolower(trim($wallet)) : null;
        $this->googleUid = $googleUid ? trim($googleUid) : null;
        
        $this->ensureTableExists();
    }
    
    private function getClientIP() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        // Fallback: aceitar qualquer IP válido incluindo privado
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    private function ensureTableExists() {
        static $checked = false;
        if ($checked) return;
        
        try {
            // Criar tabela se não existir
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL,
                    google_uid VARCHAR(128) DEFAULT NULL,
                    wallet_address VARCHAR(42) DEFAULT NULL,
                    action_type VARCHAR(30) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ip (ip_address),
                    INDEX idx_google_uid (google_uid),
                    INDEX idx_wallet (wallet_address),
                    INDEX idx_action (action_type),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // ── FIX: Adicionar colunas que podem faltar em tabelas antigas ──
            // Se a tabela já existia sem google_uid, o CREATE acima não adicionou
            $columns = [];
            $result = $this->pdo->query("SHOW COLUMNS FROM rate_limits");
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }
            
            if (!in_array('google_uid', $columns)) {
                $this->pdo->exec("ALTER TABLE rate_limits ADD COLUMN google_uid VARCHAR(128) DEFAULT NULL AFTER ip_address");
                $this->pdo->exec("ALTER TABLE rate_limits ADD INDEX idx_google_uid (google_uid)");
                error_log("[UNOBIX] rate_limits: coluna google_uid adicionada via ALTER TABLE");
            }
            
            if (!in_array('wallet_address', $columns)) {
                $this->pdo->exec("ALTER TABLE rate_limits ADD COLUMN wallet_address VARCHAR(42) DEFAULT NULL AFTER google_uid");
                $this->pdo->exec("ALTER TABLE rate_limits ADD INDEX idx_wallet (wallet_address)");
                error_log("[UNOBIX] rate_limits: coluna wallet_address adicionada via ALTER TABLE");
            }
            
            $checked = true;
        } catch (Exception $e) {
            error_log("[UNOBIX] ensureTableExists error: " . $e->getMessage());
        }
    }
    
    /**
     * Obtém o identificador principal do usuário
     */
    private function getUserIdentifier() {
        if ($this->googleUid) {
            return ['column' => 'google_uid', 'value' => $this->googleUid];
        }
        if ($this->wallet) {
            return ['column' => 'wallet_address', 'value' => $this->wallet];
        }
        return null;
    }
    
    /**
     * Registrar ação
     */
    public function logAction($actionType) {
        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limits (ip_address, google_uid, wallet_address, action_type)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$this->ip, $this->googleUid, $this->wallet, $actionType]);
    }
    
    /**
     * Limpar registros antigos
     */
    public function cleanup() {
        $this->pdo->exec("
            DELETE FROM rate_limits 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
    }
    
    // ============================================
    // VERIFICAÇÕES
    // ============================================
    
    public function checkRequestsPerMinute() {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM rate_limits
            WHERE ip_address = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$this->ip]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] >= RATE_LIMIT_REQUESTS_PER_MINUTE) {
            return [
                'allowed' => false,
                'error' => 'Muitas requisições. Aguarde 1 minuto.',
                'retry_after' => 60
            ];
        }
        
        return ['allowed' => true];
    }
    
    public function checkRequestsPerHour() {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM rate_limits
            WHERE ip_address = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$this->ip]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] >= RATE_LIMIT_REQUESTS_PER_HOUR) {
            return [
                'allowed' => false,
                'error' => 'Limite de requisições atingido. Tente novamente mais tarde.',
                'retry_after' => 3600
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Verificar intervalo entre jogos (por google_uid ou wallet)
     */
    public function checkGameInterval() {
        $user = $this->getUserIdentifier();
        if (!$user) {
            return ['allowed' => true];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT created_at FROM rate_limits
            WHERE {$user['column']} = ?
            AND action_type = 'game_start'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user['value']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $lastGame = strtotime($result['created_at']);
            $elapsed = time() - $lastGame;
            
            if ($elapsed < RATE_LIMIT_GAME_INTERVAL) {
                $waitTime = RATE_LIMIT_GAME_INTERVAL - $elapsed;
                return [
                    'allowed' => false,
                    'error' => "Aguarde {$waitTime} segundos antes de jogar novamente.",
                    'retry_after' => $waitTime,
                    'wait_seconds' => $waitTime
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Verificar se usuário está banido
     */
    public function checkUserBanned() {
        $user = $this->getUserIdentifier();
        if (!$user) {
            return ['allowed' => true];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT is_banned, ban_reason FROM users
            WHERE google_uid = ?
            LIMIT 1
        ");
        $stmt->execute([$this->googleUid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['is_banned']) {
            return [
                'allowed' => false,
                'error' => 'Conta suspensa: ' . ($result['ban_reason'] ?? 'Violação dos termos de uso'),
                'banned' => true
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Verificar se IP está na blacklist
     */
    public function checkIPBlacklist() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ip_blacklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL UNIQUE,
                reason VARCHAR(255) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME DEFAULT NULL,
                INDEX idx_ip (ip_address),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $stmt = $this->pdo->prepare("
            SELECT reason FROM ip_blacklist
            WHERE ip_address = ?
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$this->ip]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return [
                'allowed' => false,
                'error' => 'Acesso bloqueado',
                'blocked' => true
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Verificar eventos por segundo
     */
    public function checkEventsPerSecond($sessionId) {
        $user = $this->getUserIdentifier();
        if (!$user) {
            return ['allowed' => true];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM rate_limits
            WHERE {$user['column']} = ?
            AND action_type = 'game_event'
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)
        ");
        $stmt->execute([$user['value']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] >= RATE_LIMIT_EVENTS_PER_SECOND) {
            return [
                'allowed' => false,
                'error' => 'Muitos eventos',
                'throttled' => true
            ];
        }
        
        return ['allowed' => true];
    }
    
    // ============================================
    // VERIFICAÇÕES COMPLETAS
    // ============================================
    
    public function checkGameStart() {
        $check = $this->checkIPBlacklist();
        if (!$check['allowed']) return $check;
        
        $check = $this->checkUserBanned();
        if (!$check['allowed']) return $check;
        
        $check = $this->checkRequestsPerMinute();
        if (!$check['allowed']) return $check;
        
        $check = $this->checkGameInterval();
        if (!$check['allowed']) return $check;
        
        $this->logAction('game_start');
        
        return ['allowed' => true];
    }
    
    public function checkGameEvent($sessionId) {
        $check = $this->checkRequestsPerMinute();
        if (!$check['allowed']) return $check;
        
        $check = $this->checkEventsPerSecond($sessionId);
        if (!$check['allowed']) return $check;
        
        $this->logAction('game_event');
        
        return ['allowed' => true];
    }
    
    public function checkAPIRequest() {
        $check = $this->checkIPBlacklist();
        if (!$check['allowed']) return $check;
        
        $check = $this->checkRequestsPerMinute();
        if (!$check['allowed']) return $check;
        
        $check = $this->checkRequestsPerHour();
        if (!$check['allowed']) return $check;
        
        $this->logAction('api_request');
        
        return ['allowed' => true];
    }
    
    // ============================================
    // FUNÇÕES ADMINISTRATIVAS
    // ============================================
    
    public static function banUser($pdo, $googleUid = null, $wallet = null, $reason = 'Manual ban') {
        if ($googleUid) {
            $stmt = $pdo->prepare("
                UPDATE users SET is_banned = 1, ban_reason = ?, updated_at = NOW()
                WHERE google_uid = ?
            ");
            $stmt->execute([$reason, $googleUid]);
        }
        return true;
    }
    
    public static function unbanUser($pdo, $googleUid = null, $wallet = null) {
        if ($googleUid) {
            $stmt = $pdo->prepare("
                UPDATE users SET is_banned = 0, ban_reason = NULL, updated_at = NOW()
                WHERE google_uid = ?
            ");
            $stmt->execute([$googleUid]);
        }
        return true;
    }
    
    public static function blacklistIP($pdo, $ip, $reason = null, $hours = null) {
        $expiresAt = $hours ? date('Y-m-d H:i:s', strtotime("+{$hours} hours")) : null;
        
        $stmt = $pdo->prepare("
            INSERT INTO ip_blacklist (ip_address, reason, expires_at)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE reason = ?, expires_at = ?
        ");
        $stmt->execute([$ip, $reason, $expiresAt, $reason, $expiresAt]);
        return true;
    }
    
    public static function unblacklistIP($pdo, $ip) {
        $stmt = $pdo->prepare("DELETE FROM ip_blacklist WHERE ip_address = ?");
        $stmt->execute([$ip]);
        return $stmt->rowCount() > 0;
    }
    
    public static function getStats($pdo) {
        $stats = [];
        
        $result = $pdo->query("
            SELECT COUNT(*) as count FROM rate_limits
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['requests_last_hour'] = $result['count'];
        
        $result = $pdo->query("
            SELECT COUNT(DISTINCT ip_address) as count FROM rate_limits
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['unique_ips_last_hour'] = $result['count'];
        
        $result = $pdo->query("
            SELECT COUNT(*) as count FROM users WHERE is_banned = 1
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['banned_users'] = $result['count'];
        
        $result = $pdo->query("
            SELECT COUNT(*) as count FROM ip_blacklist 
            WHERE expires_at IS NULL OR expires_at > NOW()
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['blacklisted_ips'] = $result['count'];
        
        return $stats;
    }
}

// ============================================
// HELPER FUNCTION
// ============================================

function checkRateLimit($pdo, $wallet = null, $googleUid = null, $type = 'api') {
    $limiter = new RateLimiter($pdo, $wallet, $googleUid);
    
    switch ($type) {
        case 'game_start':
            return $limiter->checkGameStart();
        case 'game_event':
            return $limiter->checkGameEvent(0);
        default:
            return $limiter->checkAPIRequest();
    }
}
