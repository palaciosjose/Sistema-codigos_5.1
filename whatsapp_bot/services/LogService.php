<?php
namespace WhatsappBot\Services;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

class LogService
{
    private Logger $logger;

    public function __construct(int $maxFiles = 7)
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $handler = new RotatingFileHandler($logDir . '/bot.log', $maxFiles, Logger::DEBUG);
        $handler->setFilenameFormat('{date}-{filename}', 'Ymd');
        $this->logger = new Logger('whatsapp_bot');
        $this->logger->pushHandler($handler);
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
