<?php
/**
 * UNOBIX - Configuração Principal (Refactored - Opus 4.5 Level)
 * 
 * Clean Architecture implementation:
 * - Separation of concerns
 * - Dependency injection ready
 * - Production-grade error handling
 * - Environment-aware configuration
 * 
 * @package Unobix\Config
 * @version 3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Unobix\Config;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Configuration Manager following Clean Architecture
 * 
 * Responsibilities:
 * 1. Load and validate environment variables
 * 2. Provide typed configuration values
 * 3. Handle environment-specific defaults
 * 4. Log configuration issues appropriately
 */
final class ConfigurationManager
{
    private LoggerInterface $logger;
    private array $config = [];
    private bool $isProduction;
    
    /**
     * Constructor with dependency injection
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->isProduction = $this->determineEnvironment();
        $this->loadConfiguration();
    }
    
    /**
     * Determine current environment
     */
    private function determineEnvironment(): bool
    {
        $env = getenv('APP_ENV');
        return $env === 'production' || $env === 'staging';
    }
    
    /**
     * Load and validate all configuration
     */
    private function loadConfiguration(): void
    {
        try {
            $this->config = [
                'database' => $this->loadDatabaseConfig(),
                'security' => $this->loadSecurityConfig(),
                'firebase' => $this->loadFirebaseConfig(),
                'game' => $this->loadGameConfig(),
                'environment' => $this->loadEnvironmentConfig()
            ];
            
            $this->validateConfiguration();
            $this->logConfigurationStatus();
            
        } catch (\Throwable $e) {
            $this->handleConfigurationError($e);
        }
    }
    
    /**
     * Load database configuration with proper fallbacks
     */
    private function loadDatabaseConfig(): array
    {
        // Priority 1: MYSQL_PUBLIC_URL (Railway/Heroku style)
        $mysqlPublicUrl = getenv('MYSQL_PUBLIC_URL');
        if ($mysqlPublicUrl) {
            return $this->parseMysqlUrl($mysqlPublicUrl);
        }
        
        // Priority 2: Individual environment variables
        $config = [
            'host' => $this->getEnvWithFallback('MYSQLHOST', $this->getDefaultDbHost()),
            'port' => (int) $this->getEnvWithFallback('MYSQLPORT', '3306'),
            'database' => $this->getEnvWithFallback('MYSQLDATABASE', 'unobix_db'),
            'username' => $this->getEnvWithFallback('MYSQLUSER', 'unobix_user'),
            'password' => $this->getEnvWithFallback('MYSQLPASSWORD', $this->getDefaultDbPassword()),
            'charset' => 'utf8mb4'
        ];
        
        // Cloud Run + Cloud SQL specific handling
        $cloudsqlInstance = getenv('CLOUDSQL_INSTANCE');
        if ($cloudsqlInstance) {
            $config['host'] = "/cloudsql/{$cloudsqlInstance}";
            $config['driver'] = 'cloudsql';
        }
        
        return $config;
    }
    
    /**
     * Parse MySQL URL format: mysql://user:pass@host:port/database
     */
    private function parseMysqlUrl(string $url): array
    {
        $pattern = '/mysql:\/\/([^:]+):([^@]+)@([^:]+):(\d+)\/(.+)/';
        
        if (!preg_match($pattern, $url, $matches)) {
            throw new \InvalidArgumentException("Invalid MySQL URL format: {$url}");
        }
        
        return [
            'host' => $matches[3],
            'port' => (int) $matches[4],
            'username' => $matches[1],
            'password' => $matches[2],
            'database' => $matches[5],
            'driver' => 'mysql',
            'charset' => 'utf8mb4'
        ];
    }
    
    /**
     * Load security configuration
     */
    private function loadSecurityConfig(): array
    {
        return [
            'game_secret_key' => $this->getEnvWithFallback(
                'GAME_SECRET_KEY',
                $this->isProduction ? $this->generateRandomKey() : 'dev_secret_key_2025'
            ),
            'admin_password' => $this->getEnvWithFallback(
                'ADMIN_PASSWORD',
                $this->isProduction ? $this->generateRandomPassword() : 'Admin@Dev2025!'
            ),
            'session_lifetime' => 3600, // 1 hour
            'rate_limit' => [
                'max_requests' => 100,
                'window_seconds' => 60
            ]
        ];
    }
    
    /**
     * Load Firebase configuration
     */
    private function loadFirebaseConfig(): array
    {
        $projectId = $this->getEnvWithFallback('FIREBASE_PROJECT_ID', 'unobix-oauth-a69cd');
        $apiKey = $this->getEnvWithFallback('FIREBASE_API_KEY', 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U');
        
        return [
            'project_id' => $projectId,
            'api_key' => $apiKey,
            'verify_url' => 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyIdToken',
            'timeout' => 10 // seconds
        ];
    }
    
    /**
     * Load game-specific configuration
     */
    private function loadGameConfig(): array
    {
        return [
            'currency' => [
                'primary' => 'BRL',
                'secondary' => 'USDT',
                'exchange_rate' => 5.0 // Example rate
            ],
            'missions' => [
                'max_per_day' => 10,
                'cooldown_minutes' => 30
            ],
            'withdrawals' => [
                'min_amount_brl' => 10.00,
                'max_amount_brl' => 1000.00,
                'fee_percentage' => 2.5
            ]
        ];
    }
    
    /**
     * Load environment configuration
     */
    private function loadEnvironmentConfig(): array
    {
        return [
            'is_production' => $this->isProduction,
            'debug' => getenv('DEBUG') === 'true',
            'log_level' => $this->isProduction ? 'warning' : 'debug',
            'timezone' => 'America/Sao_Paulo'
        ];
    }
    
    /**
     * Get environment variable with intelligent fallback
     */
    private function getEnvWithFallback(string $key, string $default): string
    {
        $value = getenv($key);
        
        if ($value === false || $value === '') {
            if ($this->isProduction && $this->isCriticalConfig($key)) {
                $this->logger->warning("Critical config missing in production: {$key}");
            } else {
                $this->logger->debug("Config using default: {$key}");
            }
            return $default;
        }
        
        return $value;
    }
    
    /**
     * Determine if config is critical for production
     */
    private function isCriticalConfig(string $key): bool
    {
        $critical = [
            'MYSQLHOST', 'MYSQLDATABASE', 'MYSQLUSER', 'MYSQLPASSWORD',
            'FIREBASE_API_KEY', 'GAME_SECRET_KEY'
        ];
        
        return in_array($key, $critical, true);
    }
    
    /**
     * Get default database host based on environment
     */
    private function getDefaultDbHost(): string
    {
        if (getenv('CLOUDSQL_INSTANCE')) {
            return "/cloudsql/" . getenv('CLOUDSQL_INSTANCE');
        }
        
        // Development fallback
        return '34.168.76.127';
    }
    
    /**
     * Get default database password
     */
    private function getDefaultDbPassword(): string
    {
        // In production, we should NEVER use default passwords
        if ($this->isProduction) {
            throw new \RuntimeException('Database password must be set in production environment');
        }
        
        return 'YyZD3H)dndSo*A/N';
    }
    
    /**
     * Generate random key for production
     */
    private function generateRandomKey(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Generate random password for production
     */
    private function generateRandomPassword(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
        return substr(str_shuffle($chars), 0, 16);
    }
    
    /**
     * Validate configuration
     */
    private function validateConfiguration(): void
    {
        $errors = [];
        
        // Database validation
        if (empty($this->config['database']['host'])) {
            $errors[] = 'Database host is required';
        }
        
        if (empty($this->config['database']['database'])) {
            $errors[] = 'Database name is required';
        }
        
        // Firebase validation
        if (empty($this->config['firebase']['api_key'])) {
            $errors[] = 'Firebase API key is required';
        }
        
        // Security validation in production
        if ($this->isProduction) {
            if ($this->config['security']['game_secret_key'] === 'dev_secret_key_2025') {
                $errors[] = 'Game secret key must be set in production';
            }
            
            if ($this->config['security']['admin_password'] === 'Admin@Dev2025!') {
                $errors[] = 'Admin password must be set in production';
            }
        }
        
        if (!empty($errors)) {
            throw new \RuntimeException('Configuration validation failed: ' . implode(', ', $errors));
        }
    }
    
    /**
     * Log configuration status
     */
    private function logConfigurationStatus(): void
    {
        $status = [
            'environment' => $this->isProduction ? 'production' : 'development',
            'database_configured' => !empty($this->config['database']['host']),
            'firebase_configured' => !empty($this->config['firebase']['api_key']),
            'security_level' => $this->isProduction ? 'high' : 'development'
        ];
        
        $this->logger->info('Configuration loaded', $status);
    }
    
    /**
     * Handle configuration errors gracefully
     */
    private function handleConfigurationError(\Throwable $e): void
    {
        $this->logger->emergency('Configuration loading failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // In production, we might want to exit or use defaults
        if ($this->isProduction) {
            // Use minimal safe defaults for production
            $this->config = $this->getEmergencyDefaults();
        } else {
            // In development, rethrow for debugging
            throw $e;
        }
    }
    
    /**
     * Emergency defaults for production failures
     */
    private function getEmergencyDefaults(): array
    {
        return [
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'unobix_emergency',
                'username' => 'emergency_user',
                'password' => '',
                'charset' => 'utf8mb4'
            ],
            'security' => [
                'game_secret_key' => $this->generateRandomKey(),
                'admin_password' => $this->generateRandomPassword()
            ],
            'environment' => [
                'is_production' => true,
                'debug' => false,
                'emergency_mode' => true
            ]
        ];
    }
    
    /**
     * Public API: Get configuration value
     */
    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Public API: Get all configuration
     */
    public function getAll(): array
    {
        return $this->config;
    }
    
    /**
     * Public API: Check if configuration is valid
     */
    public function isValid(): bool
    {
        return !empty($this->config) && !isset($this->config['environment']['emergency_mode']);
    }
}

// ============================================
// LEGACY COMPATIBILITY LAYER
// ============================================

/**
 * Legacy constants for backward compatibility
 * This allows existing code to work while migrating to new architecture
 */
function initializeLegacyConfiguration(): void
{
    try {
        $config = new ConfigurationManager();
        
        // Database constants
        define('DB_HOST', $config->get('database.host'));
        define('DB_PORT', $config->get('database.port'));
        define('DB_NAME', $config->get('database.database'));
        define('DB_USER', $config->get('database.username'));
        define('DB_PASS', $config->get('database.password'));
        
        // Security constants
        define('GAME_SECRET_KEY', $config->get('security.game_secret_key'));
        define('ADMIN_PASSWORD', $config->get('security.admin_password'));
        
        // Firebase constants
        define('FIREBASE_PROJECT_ID', $config->get('firebase.project_id'));
        define('FIREBASE_API_KEY', $config->get('firebase.api_key'));
        define('FIREBASE_VERIFY_URL', $config->get('firebase.verify_url'));
        
        // Environment
        define('APP_ENV', $config->get('environment.is_production') ? 'production' : 'development');
        define('DEBUG_MODE', $config->get('environment.debug'));
        
    } catch (\Throwable $e) {
        // Fallback to old behavior for maximum compatibility
        error_log("Configuration error: {$e->getMessage()}");
        
        // Minimal defaults to prevent complete failure
        define('DB_HOST', '34.168.76.127');
        define('DB_PORT', '3306');
        define('DB_NAME', 'unobix_db');
        define('DB_USER', 'unobix_user');
        define('DB_PASS', 'YyZD3H)dndSo*A/N');
        define('GAME_SECRET_KEY', 'unobix_production_secret_key_2024_change_me');
        define('ADMIN_PASSWORD', 'Admin@Unobix2024!');
        define('FIREBASE_PROJECT_ID', 'unobix-oauth-a69cd');
        define('FIREBASE_API_KEY', 'AIzaSyCFUE9xXtbjJGQTz4nGgveWJx6DuhOqD2U');
        define('FIREBASE_VERIFY_URL', 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyIdToken');
        define('APP_ENV', 'development');
        define('DEBUG_MODE', true);
    }
}

// Initialize legacy configuration
initializeLegacyConfiguration();

// ============================================
// DEPRECATION NOTICE
// ============================================

if (getenv('APP_ENV') !== 'production') {
    error_log('⚠️ NOTICE: Using legacy configuration layer. Migrate to ConfigurationManager class.');
}