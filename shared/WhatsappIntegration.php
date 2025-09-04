<?php
namespace Shared;

class WhatsappIntegration
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    public function markLogAsWhatsApp(int $logId, string $chatId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE search_logs SET whatsapp_chat_id = ?, source = 'whatsapp' WHERE id = ?"
            );
            $stmt->bind_param('si', $chatId, $logId);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        } catch (\Exception $e) {
            error_log("Error marking log as WhatsApp: " . $e->getMessage());
            return false;
        }
    }

    public function logActivity(int $whatsappId, string $action, array $details = []): bool
    {
        try {
            $detailsJson = json_encode($details);
            $stmt = $this->db->prepare(
                "INSERT INTO whatsapp_activity_log (whatsapp_id, action, details, created_at) VALUES (?, ?, ?, NOW())"
            );
            $stmt->bind_param('iss', $whatsappId, $action, $detailsJson);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        } catch (\Exception $e) {
            error_log("WhatsApp activity: User $whatsappId - Action: $action - Details: " . json_encode($details));
            return true;
        }
    }

    public function getCodeById(int $codeId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM codes WHERE id = ?"
            );
            $stmt->bind_param('i', $codeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }

            $stmt = $this->db->prepare(
                "SELECT id, email, platform, result_details, created_at, status FROM search_logs WHERE id = ? AND status = 'found'"
            );
            $stmt->bind_param('i', $codeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            if ($row && $row['result_details']) {
                $details = json_decode($row['result_details'], true);
                if ($details && isset($details['content'])) {
                    return [
                        'id' => $row['id'],
                        'code' => $details['content'],
                        'platform' => $row['platform'],
                        'email' => $row['email'],
                        'created_at' => $row['created_at'],
                        'details' => $details
                    ];
                }
            }
            return null;
        } catch (\Exception $e) {
            error_log("Error getting code by ID: " . $e->getMessage());
            return null;
        }
    }
}
