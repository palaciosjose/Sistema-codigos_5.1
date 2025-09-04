<?php
namespace WhatsappBot\Services;

use WhatsappBot\Utils\WhatsappAPI;

class NotificationService
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    public function notifyAdmins(string $message): void
    {
        $stmt = $this->db->prepare('SELECT whatsapp_id FROM users WHERE (role = "admin" OR role = "superadmin") AND whatsapp_id IS NOT NULL');
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            WhatsappAPI::sendMessage((string)$row['whatsapp_id'], "\xF0\x9F\x94\x94 *Notificación Admin*\n\n" . $message);
        }
        $stmt->close();
    }

    public function scheduleNotification(int $userId, string $message, \DateTime $when): void
    {
        $stmt = $this->db->prepare('INSERT INTO scheduled_notifications (user_id, message, send_at) VALUES (?, ?, ?)');
        $sendAt = $when->format('Y-m-d H:i:s');
        $stmt->bind_param('iss', $userId, $message, $sendAt);
        $stmt->execute();
        $stmt->close();
    }

    public function sendBulkNotification(array $userIds, string $message): void
    {
        if (empty($userIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $stmt = $this->db->prepare("SELECT whatsapp_id FROM users WHERE id IN ($placeholders) AND whatsapp_id IS NOT NULL");
        $stmt->bind_param($types, ...$userIds);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            WhatsappAPI::sendMessage((string)$row['whatsapp_id'], $message);
        }
        $stmt->close();
    }
}
