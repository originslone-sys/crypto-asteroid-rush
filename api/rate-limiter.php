<?php
// ============================================
// CRYPTO ASTEROID RUSH - Rate Limiter
// Arquivo: api/rate-limiter.php
// 
// Inclua no início de cada API protegida:
// require_once "rate-limiter.php";
// ============================================

// Configurações de Rate Limit
define('RATE_LIMIT_GAME_INTERVAL', 180);     // 3 minutos entre jogos
define('RATE_LIMIT_REQUESTS_PER_MINUTE', 60); // 60 requests por minuto por IP
define('RATE_LIMIT_REQUESTS_PER_HOUR', 500);  // 500 requests por hora por IP
define('RATE_LIMIT_EVENTS_PER_SECOND', 5);    // 5 eventos de jogo por segundo

class RateLimiter {
    private $pdo;
    private $ip;
    private $wallet;
    
    public function __construct($pdo, $wallet = null) {
        $this->pdo = $pdo;
        $this->ip = $this->getClientIP();
        $this->wallet = $wallet ? strtolower(trim($wallet)) : null;
        
        // Criar tabela se não existir
        $this->ensureTableExists();
    }
    
    // Obter IP real do cliente
    private function getClientIP() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Proxy
            'HTTP_X_REAL_IP',            // Nginx
            'REMOTE_ADDR'                // Direto
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Se for lista de IPs, pegar o primeiro
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validar IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    // Criar tabela de rate limiting
    private function ensureTableExists() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                wallet_address VARCHAR(42) DEFAULT NULL,
                action_type VARCHAR(30) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip (ip_address),
                INDEX idx_wallet (wallet_address),
                INDEX idx_action (action_type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    
    // Registrar ação
    public function logAction($actionType) {
        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limits (ip_address, wallet_address, action_type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$this->ip, $this->wallet, $actionType]);
    }
    
    // Limpar registros antigos (mais de 1 hora)
    public function cleanup() {
        $this->pdo->exec("
            DELETE FROM rate_limits 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
    }
    
    // ============================================
    // VERIFICAÇÕES DE RATE LIMIT
    // ============================================
    
    // Verificar limite de requests por minuto (por IP)
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
    
    // Verificar limite de requests por hora (por IP)
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
    
    // Verificar intervalo entre jogos (por wallet)
    public function checkGameInterval() {
        if (!$this->wallet) {
            return ['allowed' => true];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT created_at FROM rate_limits
            WHERE wallet_address = ?
            AND action_type = 'game_start'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$this->wallet]);
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
    
    // Verificar se wallet está banida
    public function checkWalletBanned() {
        if (!$this->wallet) {
            return ['allowed' => true];
        }
        
        $stmt = $this->pdo->prepare("
            SELECT is_banned, ban_reason FROM players
            WHERE wallet_address = ?
        ");
        $stmt->execute([$this->wallet]);
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
    
    // Verificar se wallet está flagged (muitas atividades suspeitas)
    public function checkWalletFlagged() {
        if (!$this->wallet) {
            return ['allowed' => true, 'flagged' => false];
        }
        
        // Verificar coluna is_flagged
        try {
            $stmt = $this->pdo->prepare("
                SELECT is_flagged FROM players
                WHERE wallet_address = ?
            ");
            $stmt->execute([$this->wallet]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && isset($result['is_flagged']) && $result['is_flagged']) {
                return [
                    'allowed' => true, // Ainda pode jogar
                    'flagged' => true,
                    'warning' => 'Conta em observação'
                ];
            }
        } catch (Exception $e) {
            // Coluna não existe, ignorar
        }
        
        return ['allowed' => true, 'flagged' => false];
    }
    
    // Verificar se IP está na blacklist
    public function checkIPBlacklist() {
        // Criar tabela de blacklist se não existir
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
    
    // Verificar limite de eventos por segundo (durante jogo)
    public function checkEventsPerSecond($sessionId) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM rate_limits
            WHERE wallet_address = ?
            AND action_type = 'game_event'
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND)
        ");
        $stmt->execute([$this->wallet]);
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
    // VERIFICAÇÃO COMPLETA
    // ============================================
    
    // Executar todas as verificações para início de jogo
    public function checkGameStart() {
        // 1. Verificar IP blacklist
        $check = $this->checkIPBlacklist();
        if (!$check['allowed']) return $check;
        
        // 2. Verificar wallet banida
        $check = $this->checkWalletBanned();
        if (!$check['allowed']) return $check;
        
        // 3. Verificar requests por minuto
        $check = $this->checkRequestsPerMinute();
        if (!$check['allowed']) return $check;
        
        // 4. Verificar intervalo entre jogos
        $check = $this->checkGameInterval();
        if (!$check['allowed']) return $check;
        
        // 5. Verificar wallet flagged (apenas warning)
        $flagCheck = $this->checkWalletFlagged();
        
        // Registrar ação
        $this->logAction('game_start');
        
        return [
            'allowed' => true,
            'flagged' => $flagCheck['flagged'] ?? false
        ];
    }
    
    // Executar verificações para eventos de jogo
    public function checkGameEvent($sessionId) {
        // 1. Verificar requests por minuto
        $check = $this->checkRequestsPerMinute();
        if (!$check['allowed']) return $check;
        
        // 2. Verificar eventos por segundo
        $check = $this->checkEventsPerSecond($sessionId);
        if (!$check['allowed']) return $check;
        
        // Registrar ação
        $this->logAction('game_event');
        
        return ['allowed' => true];
    }
    
    // Executar verificações para API geral
    public function checkAPIRequest() {
        // 1. Verificar IP blacklist
        $check = $this->checkIPBlacklist();
        if (!$check['allowed']) return $check;
        
        // 2. Verificar requests por minuto
        $check = $this->checkRequestsPerMinute();
        if (!$check['allowed']) return $check;
        
        // 3. Verificar requests por hora
        $check = $this->checkRequestsPerHour();
        if (!$check['allowed']) return $check;
        
        // Registrar ação
        $this->logAction('api_request');
        
        return ['allowed' => true];
    }
    
    // ============================================
    // FUNÇÕES ADMINISTRATIVAS
    // ============================================
    
    // Banir wallet
    public static function banWallet($pdo, $wallet, $reason = 'Manual ban') {
        $wallet = strtolower(trim($wallet));
        
        $stmt = $pdo->prepare("
            UPDATE players 
            SET is_banned = 1, ban_reason = ?
            WHERE wallet_address = ?
        ");
        $stmt->execute([$reason, $wallet]);
        
        return $stmt->rowCount() > 0;
    }
    
    // Desbanir wallet
    public static function unbanWallet($pdo, $wallet) {
        $wallet = strtolower(trim($wallet));
        
        $stmt = $pdo->prepare("
            UPDATE players 
            SET is_banned = 0, ban_reason = NULL
            WHERE wallet_address = ?
        ");
        $stmt->execute([$wallet]);
        
        return $stmt->rowCount() > 0;
    }
    
    // Adicionar IP à blacklist
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
    
    // Remover IP da blacklist
    public static function unblacklistIP($pdo, $ip) {
        $stmt = $pdo->prepare("DELETE FROM ip_blacklist WHERE ip_address = ?");
        $stmt->execute([$ip]);
        
        return $stmt->rowCount() > 0;
    }
    
    // Obter estatísticas de rate limit
    public static function getStats($pdo) {
        $stats = [];
        
        // Requests na última hora
        $result = $pdo->query("
            SELECT COUNT(*) as count FROM rate_limits
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['requests_last_hour'] = $result['count'];
        
        // IPs únicos na última hora
        $result = $pdo->query("
            SELECT COUNT(DISTINCT ip_address) as count FROM rate_limits
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['unique_ips_last_hour'] = $result['count'];
        
        // Wallets banidas
        $result = $pdo->query("
            SELECT COUNT(*) as count FROM players WHERE is_banned = 1
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['banned_wallets'] = $result['count'];
        
        // IPs na blacklist
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

// Função para usar rapidamente nas APIs
function checkRateLimit($pdo, $wallet = null, $type = 'api') {
    $limiter = new RateLimiter($pdo, $wallet);
    
    switch ($type) {
        case 'game_start':
            return $limiter->checkGameStart();
        case 'game_event':
            return $limiter->checkGameEvent(0);
        default:
            return $limiter->checkAPIRequest();
    }
}
?>
