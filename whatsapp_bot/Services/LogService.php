<?php
namespace WhatsappBot\Services;

/**
 * LogService simplificado para WhatsApp Bot
 * No depende de Monolog - funciona con archivos simples
 */
class LogService
{
    private string $logFile;
    private string $logLevel;
    
    public function __construct()
    {
        $this->logLevel = 'info';
        $this->logFile = __DIR__ . '/../logs/whatsapp_simple.log';
        
        // Crear directorio si no existe
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Log de información general
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }
    
    /**
     * Log de errores
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }
    
    /**
     * Log de debugging
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }
    
    /**
     * Log de advertencias
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }
    
    /**
     * Log crítico
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }
    
    /**
     * Escribir al log
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $logEntry = "[$timestamp] [$level] $message$contextStr\n";
        
        @file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Sanitizar datos sensibles (simplificado)
     */
    private function sanitize(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && (
                stripos($key, 'secret') !== false ||
                stripos($key, 'password') !== false ||
                stripos($key, 'token') !== false
            )) {
                $sanitized[$key] = '***' . substr($value, -4);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
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
            'data' => $this->sanitize($data),
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
        $info = [
            'log_file' => $this->logFile,
            'log_dir' => dirname($this->logFile),
            'log_exists' => file_exists($this->logFile),
            'log_writable' => is_writable(dirname($this->logFile)),
            'log_size' => file_exists($this->logFile) ? filesize($this->logFile) : 0,
            'log_modified' => file_exists($this->logFile) ? date('Y-m-d H:i:s', filemtime($this->logFile)) : null
        ];
        
        return $info;
    }
}
