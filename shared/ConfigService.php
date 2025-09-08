<?php
namespace Shared;

require_once __DIR__ . '/../config/path_constants.php';
require_once __DIR__ . '/../config/env_helper.php';
require_once __DIR__ . '/Crypto.php';

use Shared\DatabaseManager;

/**
 * Centralized configuration service with layered precedence and in-memory cache.
 *
 * Precedence: Environment > config file > database settings > defaults
 */
class ConfigService
{
    private static ?ConfigService $instance = null;

    /** @var array<string,mixed> */
    private array $cache = [];

    /** @var array<string,string> */
    private array $fileConfig = [];

    /** @var array<string,string> */
    private array $dbSettings = [];

    private bool $dbLoaded = false;

    private string $cacheFile;

    /** @var array<int,string> */
    private const ENCRYPTED_KEYS = [
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_WEBHOOK_SECRET',
    ];

    private function __construct()
    {
        $this->cacheFile = CACHE_DIR . '/data/settings.json';
        $this->loadFileConfig();
    }

    public static function getInstance(): ConfigService
    {
        if (self::$instance === null) {
            self::$instance = new ConfigService();
        }
        return self::$instance;
    }

    /**
     * Retrieve a configuration value following precedence rules.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $this->getFromEnv($key);

        if ($value === null && isset($this->fileConfig[$key])) {
            $value = $this->fileConfig[$key];
        }

        if ($value === null) {
            $value = $this->getFromDatabase($key);
        }

        if ($value === null) {
            $value = $default;
        }

        if (is_string($value) && in_array($key, self::ENCRYPTED_KEYS, true)) {
            $value = Crypto::decrypt($value);
        }

        $this->cache[$key] = $value;
        return $value;
    }

    private function getFromEnv(string $key): ?string
    {
        $val = $_ENV[$key] ?? getenv($key);
        return $val !== false ? $val : null;
    }

    private function loadFileConfig(): void
    {
        $configFile = CONFIG_DIR . '/db_credentials.php';
        $legacyFile = INSTALL_DIR . '/basededatos.php';

        $db_host = $db_user = $db_password = $db_name = null;

        if (file_exists($configFile)) {
            include $configFile;
        } elseif (file_exists($legacyFile)) {
            include $legacyFile;
        }

        if ($db_host !== null) {
            $this->fileConfig['DB_HOST'] = $db_host;
        }
        if ($db_user !== null) {
            $this->fileConfig['DB_USER'] = $db_user;
        }
        if ($db_password !== null) {
            $this->fileConfig['DB_PASSWORD'] = $db_password;
        }
        if ($db_name !== null) {
            $this->fileConfig['DB_NAME'] = $db_name;
        }
    }

    private function getFromDatabase(string $key): ?string
    {
        if (!$this->dbLoaded) {
            $this->loadDbSettings();
        }
        return $this->dbSettings[$key] ?? null;
    }

    private function loadDbSettings(): void
    {
        $this->dbLoaded = true;

        // Try disk cache first
        if (file_exists($this->cacheFile)) {
            try {
                $content = file_get_contents($this->cacheFile);
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $this->dbSettings = $data;
                    return;
                }
            } catch (\Throwable $e) {
                error_log('ConfigService cache load error: ' . $e->getMessage());
            }
        }

        // Fallback to database
        try {
            $db = DatabaseManager::getInstance()->getConnection();
            $stmt = $db->prepare('SELECT name, value FROM settings');
            if ($stmt && $stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $this->dbSettings[$row['name']] = $row['value'];
                }
            }
            if ($stmt) {
                $stmt->close();
            }

            $this->writeCacheFile();
        } catch (\Throwable $e) {
            // Settings may not be available during installation
            error_log('ConfigService DB load error: ' . $e->getMessage());
        }
    }

    private function writeCacheFile(): void
    {
        try {
            $dir = dirname($this->cacheFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->cacheFile, json_encode($this->dbSettings));
        } catch (\Throwable $e) {
            error_log('ConfigService cache write error: ' . $e->getMessage());
        }
    }

    /**
     * Persist a configuration value to the database and update caches.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function set(string $key, $value): void
    {
        $storeValue = $value;
        if (is_string($value) && in_array($key, self::ENCRYPTED_KEYS, true)) {
            $storeValue = Crypto::encrypt($value);
        }

        try {
            $db = DatabaseManager::getInstance()->getConnection();
            $stmt = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
            if ($stmt) {
                $stmt->bind_param('ss', $key, $storeValue);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            error_log('ConfigService set error: ' . $e->getMessage());
        }

        $this->dbSettings[$key] = $storeValue;
        $this->cache[$key] = $value;
        $this->dbLoaded = true;
        $this->writeCacheFile();
    }

    /**
     * Return all configuration settings from database cache.
     *
     * @return array<string,string>
     */
    public function getAll(): array
    {
        if (!$this->dbLoaded) {
            $this->loadDbSettings();
        }
        $all = $this->dbSettings;
        foreach (self::ENCRYPTED_KEYS as $ekey) {
            if (isset($all[$ekey]) && is_string($all[$ekey])) {
                $all[$ekey] = Crypto::decrypt($all[$ekey]);
            }
        }
        return $all;
    }

    /**
     * Invalidate both memory and disk caches.
     */
    public function reload(): void
    {
        $this->cache = [];
        $this->dbSettings = [];
        $this->dbLoaded = false;
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }
}
