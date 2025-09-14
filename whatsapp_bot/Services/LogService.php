<?php
namespace WhatsappBot\Services;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

class LogService
{
    private Logger $logger;

    public function __construct(int $maxFiles = 30)
    {
        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 2);

        $envPath = getenv('WHATSAPP_LOG_PATH') ?: ($_ENV['WHATSAPP_LOG_PATH'] ?? null);
        $logFile = $envPath ?: $root . '/logs/whatsapp_bot.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $levelName = getenv('WHATSAPP_NEW_LOG_LEVEL') ?: ($_ENV['WHATSAPP_NEW_LOG_LEVEL'] ?? 'info');
        try {
            $level = Logger::toMonologLevel($levelName);
        } catch (\Throwable $e) {
            $level = Logger::INFO;
        }

        $handler = new RotatingFileHandler($logFile, $maxFiles, $level, true, 0644);
        $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
        $this->logger = new Logger('whatsapp_bot');
        $this->logger->pushHandler($handler);
        $this->cleanupOldLogs($logDir, pathinfo($logFile, PATHINFO_FILENAME), $maxFiles);
    }

    private function cleanupOldLogs(string $dir, string $baseName, int $maxDays): void
    {
        foreach (glob($dir . '/' . $baseName . '-*.log') as $file) {
            if (filemtime($file) < strtotime("-{$maxDays} days")) {
                @unlink($file);
            }
        }
    }

    /**
     * Masks sensitive values in the logging context.
     */
    private function sanitize(array $context): array
    {
        $sensitive = ['username', 'password', 'token'];
        foreach ($sensitive as $key) {
            if (isset($context[$key])) {
                $context[$key] = $this->mask((string)$context[$key]);
            }
        }
        return $context;
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 1) . str_repeat('*', $len - 2) . substr($value, -1);
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $this->sanitize($context));
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $this->sanitize($context));
    }

    public function logCommand(int $whatsappId, string $command, array $context = []): void
    {
        $this->info('Command executed', [
            'whatsapp_id' => $whatsappId,
            'command' => $command,
            'context' => $context
        ]);
    }

    public function logError(string $message, array $context = []): void
    {
        $this->error($message, $context);
    }
}
