<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Shared\ConfigService;
use WhatsappBot\Utils\WhatsappAPI;

class WhatsappBotSetup
{
    public static function configureWebhook()
    {
        $config = ConfigService::getInstance();

        $webhookUrl = $config->get('WHATSAPP_WEBHOOK_URL', '');

        return WhatsappAPI::setWebhook($webhookUrl);
    }
}
