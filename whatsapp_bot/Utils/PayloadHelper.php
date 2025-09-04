<?php
namespace WhatsappBot\Utils;

class PayloadHelper
{
    public static function adaptWebhookPayload(array $data): ?array
    {
        $message = $data['message'] ?? ($data['messages'][0] ?? null);
        if (!$message) {
            return null;
        }

        $from = $message['from'] ?? ($message['contact']['number'] ?? ($message['chatId'] ?? null));
        $body = $message['body'] ?? ($message['text'] ?? null);
        if (is_array($body)) {
            $body = $body['text'] ?? ($body['content'] ?? null);
        }

        if (!$from || !$body) {
            return null;
        }

        $number = preg_replace('/\D+/', '', (string)$from);
        if ($number === '') {
            return null;
        }

        $chatId = $number . '@c.us';
        $whatsappId = (int)$number;

        return [
            'chat_id' => $chatId,
            'whatsapp_id' => $whatsappId,
            'text' => trim((string)$body)
        ];
    }
}
