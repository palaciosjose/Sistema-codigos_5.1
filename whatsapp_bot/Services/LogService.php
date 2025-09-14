<?php
namespace WhatsappBot\Services;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

class LogService
{
    private $logger;

    public function __construct(int $maxFiles = 30)
    {
        // Usar la constante configurada en whatsapp_config.php
        $logFile = \WhatsappBot\Config\WHATSAPP_LOG_PATH;
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $handler = new RotatingFileHandler($logFile, $maxFiles, Logger::DEBUG);
        $handler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
        $this->logger = new Logger(\WhatsappBot\Config\WHATSAPP_LOG_CHANNEL);
        $this->logger->pushHandler($handler);
        $this->cleanupOldLogs($logDir, $maxFiles);
    }

    private function cleanupOldLogs(string $dir, int $maxDays): void
    {
        $pattern = $dir . '/whatsapp-*.log';
        foreach (glob($pattern) as $file) {
            if (filemtime($file) < strtotime("-{$maxDays} days")) {
                @unlink($file);
            }
        }
        
        // También limpiar logs con el patrón anterior si existen
        $oldPattern = $dir . '/whatsapp_bot-*.log';
        foreach (glob($oldPattern) as $file) {
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
        $sensitive = ['username', 'password', 'token', 'secret', 'key', 'authorization'];
        
        foreach ($context as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Verificar si la clave contiene palabras sensibles
            foreach ($sensitive as $sensitiveWord) {
                if (strpos($lowerKey, $sensitiveWord) !== false) {
                    $context[$key] = $this->mask((string)$value);
                    break;
                }
            }
            
            // Recursivamente sanitizar arrays anidados
            if (is_array($value)) {
                $context[$key] = $this->sanitize($value);
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
        if ($len <= 6) {
            return substr($value, 0, 1) . str_repeat('*', $len - 2) . substr($value, -1);
        }
        // Para valores largos, mostrar más caracteres
        return substr($value, 0, 3) . str_repeat('*', $len - 6) . substr($value, -3);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $this->sanitize($context));
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $this->sanitize($context));
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $this->sanitize($context));
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $this->sanitize($context));
    }

    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $this->sanitize($context));
    }

    /**
     * Log específico para comandos ejecutados
     */
    public function logCommand(string $whatsappId, string $command, array $context = []): void
    {
        $this->info('Command executed', [
            'whatsapp_id' => $whatsappId,
            'command' => $command,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Log específico para errores (alias de error() para compatibilidad)
     */
    public function logError(string $message, array $context = []): void
    {
        $this->error($message, $context);
    }

    /**
     * Log específico para peticiones HTTP recibidas
     */
    public function logRequest(string $method, array $data = []): void
    {
        $this->info('HTTP Request received', [
            'method' => $method,
            'data' => $data,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Log específico para respuestas HTTP enviadas
     */
    public function logResponse(int $httpCode, array $response = []): void
    {
        $this->info('HTTP Response sent', [
            'http_code' => $httpCode,
            'response' => $response,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Log específico para mensajes enviados via WhatsApp
     */
    public function logMessageSent(string $recipient, string $message, bool $success = true): void
    {
        $this->info('WhatsApp message sent', [
            'recipient' => $recipient,
            'message_length' => strlen($message),
            'success' => $success,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Log específico para errores de API
     */
    public function logApiError(string $endpoint, int $httpCode, string $error): void
    {
        $this->error('API Error', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'error' => $error,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Log específico para inicio y fin de webhook
     */
    public function logWebhookEvent(string $event, array $data = []): void
    {
        $this->info("Webhook {$event}", [
            'event' => $event,
            'data' => $data,
            'memory_usage' => memory_get_usage(true),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Obtiene información sobre el estado actual de logs
     */
    public function getLogInfo(): array
    {
        $logFile = \WhatsappBot\Config\WHATSAPP_LOG_PATH;
        $logDir = dirname($logFile);
        
        $info = [
            'log_file' => $logFile,
            'log_dir' => $logDir,
            'log_exists' => file_exists($logFile),
            'log_writable' => is_writable($logDir),
            'log_size' => file_exists($logFile) ? filesize($logFile) : 0,
            'log_modified' => file_exists($logFile) ? date('Y-m-d H:i:s', filemtime($logFile)) : null
        ];
        
        // Contar archivos de log
        $logFiles = glob($logDir . '/whatsapp-*.log');
        $oldLogFiles = glob($logDir . '/whatsapp_bot-*.log');
        $info['total_log_files'] = count($logFiles) + count($oldLogFiles);
        
        return $info;
    }
}
