<?php
namespace Shared;

require_once __DIR__ . '/DatabaseManager.php';

/**
 * Simple audit logger to track critical actions
 */
class AuditLogger
{
    /**
     * Register an action in audit_log table
     */
    public static function log(?int $userId, string $action, ?string $ip = null): void
    {
        try {
            $conn = DatabaseManager::getInstance()->getConnection();
            $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'CLI');
            $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, ip, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param('iss', $userId, $action, $ip);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('AuditLogger error: ' . $e->getMessage());
        }
    }

    /**
     * Purge audit logs older than given days
     */
    public static function purge(int $days): void
    {
        try {
            $conn = DatabaseManager::getInstance()->getConnection();
            $stmt = $conn->prepare("DELETE FROM audit_log WHERE created_at < (NOW() - INTERVAL ? DAY)");
            $stmt->bind_param('i', $days);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('AuditLogger purge error: ' . $e->getMessage());
        }
    }
}
