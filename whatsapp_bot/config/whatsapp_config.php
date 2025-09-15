<?php
namespace WhatsappBot\Config;

use Shared\ConfigService;

$config = ConfigService::getInstance();

// WamBot API configuration
$apiUrl = $config->get('WHATSAPP_NEW_API_URL', 'https://wamundo.com/api');
if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
    $apiUrl = 'https://wamundo.com/api';
}
define(__NAMESPACE__ . '\\WHATSAPP_NEW_API_URL', $apiUrl);

$sendSecret = $config->get('WHATSAPP_NEW_SEND_SECRET', '');
$sendSecret = $sendSecret ? trim($sendSecret) : '';
define(__NAMESPACE__ . '\\WHATSAPP_NEW_SEND_SECRET', $sendSecret);

$accountId = $config->get('WHATSAPP_NEW_ACCOUNT_ID', '');
$accountId = $accountId ? trim($accountId) : '';
define(__NAMESPACE__ . '\\WHATSAPP_NEW_ACCOUNT_ID', $accountId);

$webhookUrl = $config->get('WHATSAPP_WEBHOOK_URL', '');
$webhookUrl = $webhookUrl ? trim($webhookUrl) : '';
define(__NAMESPACE__ . '\\WHATSAPP_WEBHOOK_URL', $webhookUrl);

// Logging configuration

define(__NAMESPACE__ . '\\WHATSAPP_NEW_LOG_LEVEL', $config->get('WHATSAPP_NEW_LOG_LEVEL', 'info'));
define(__NAMESPACE__ . '\\WHATSAPP_NEW_API_TIMEOUT', (int) $config->get('WHATSAPP_NEW_API_TIMEOUT', '30'));
define(__NAMESPACE__ . '\\WHATSAPP_ACTIVE_WEBHOOK', $config->get('WHATSAPP_ACTIVE_WEBHOOK', 'wamundo'));
define(__NAMESPACE__ . '\\WHATSAPP_LOG_CHANNEL', 'whatsapp');
define(__NAMESPACE__ . '\\WHATSAPP_LOG_PATH', __DIR__ . '/../logs/whatsapp.log');
