<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Shared\ConfigService;

class TelegramBotSetup {
    public static function configureWebhook() {
        $config = ConfigService::getInstance();

        $token = $config->get('TELEGRAM_BOT_TOKEN', '');
        $url = "https://api.telegram.org/bot" . $token . "/setWebhook";
        $data = [
            'url' => $config->get('TELEGRAM_WEBHOOK_URL', ''),
            'secret_token' => $config->get('TELEGRAM_WEBHOOK_SECRET', ''),
            'allowed_updates' => json_encode(['message', 'callback_query'])
        ];

        return self::makeRequest($url, $data);
    }

    private static function makeRequest($url, $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result, true);
    }
}
