<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Shared\ConfigService;
use WhatsappBot\Utils\WhatsappAPI;

class WhatsappBotSetup
{
    public static function configureWebhook()
    {
        $config = ConfigService::getInstance();

        $apiUrl = $config->get('WHATSAPP_API_URL', '');
        $token = $config->get('WHATSAPP_TOKEN', '');
        $instanceId = $config->get('WHATSAPP_INSTANCE_ID', '');

        putenv('WHATSAPP_API_URL=' . $apiUrl);
        putenv('WHATSAPP_TOKEN=' . $token);
        putenv('WHATSAPP_INSTANCE_ID=' . $instanceId);

        $webhookUrl = $config->get('WHATSAPP_WEBHOOK_URL', '');

        return WhatsappAPI::setWebhook($webhookUrl);
    }
}
