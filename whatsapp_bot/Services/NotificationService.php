<?php
namespace WhatsappBot\Services;

class NotificationService
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    public function notifyAdmins(string $message): void
    {
        // Integración con Telegram eliminada; método dejado como marcador de posición.
    }

    public function scheduleNotification(int $userId, string $message, \DateTime $when): void
    {
        // Programar notificaciones
    }

    public function sendBulkNotification(array $userIds, string $message): void
    {
        // Envío masivo de notificaciones
    }
}
