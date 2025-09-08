<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Shared\ConfigService;
use WhatsappBot\Utils\WhatsappAPI;

class WhatsappBotSetup
{
    public static function configureWebhook()
    {
        $config = ConfigService::getInstance();

        $apiUrl = $config->get('WHATSAPP_NEW_API_URL', '');
        $sendSecret = $config->get('WHATSAPP_NEW_SEND_SECRET', '');
        $accountId = $config->get('WHATSAPP_NEW_ACCOUNT_ID', '');

        putenv('WHATSAPP_NEW_API_URL=' . $apiUrl);
        putenv('WHATSAPP_NEW_SEND_SECRET=' . $sendSecret);
        putenv('WHATSAPP_NEW_ACCOUNT_ID=' . $accountId);

        $webhookUrl = $config->get('WHATSAPP_WEBHOOK_URL', '');

        return WhatsappAPI::setWebhook($webhookUrl);
    }
}
