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
        $logFile = $root . '/logs/whatsapp_bot.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $handler = new RotatingFileHandler($logFile, $maxFiles, Logger::DEBUG);
        $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
        $this->logger = new Logger('whatsapp_bot');
        $this->logger->pushHandler($handler);
        $this->cleanupOldLogs($logDir, $maxFiles);
    }

    private function cleanupOldLogs(string $dir, int $maxDays): void
    {
        foreach (glob($dir . '/whatsapp_bot-*.log') as $file) {
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
