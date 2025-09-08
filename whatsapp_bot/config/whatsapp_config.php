<?php
namespace WhatsappBot\Config;

require_once __DIR__ . '/../../config/env_helper.php';

/**
 * Helper to obtain an environment variable with default value.
 */
function env(string $key, ?string $default = null): ?string
{
    $val = getenv($key);
    return ($val === false || $val === '') ? $default : $val;
}

// WamBot API configuration
$apiUrl = env('WHATSAPP_NEW_API_URL', 'https://wamundo.com/api');
if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
    $apiUrl = 'https://wamundo.com/api';
}
define(__NAMESPACE__ . '\\WHATSAPP_NEW_API_URL', $apiUrl);

$sendSecret = env('WHATSAPP_NEW_SEND_SECRET', '');
$sendSecret = $sendSecret ? trim($sendSecret) : '';
define(__NAMESPACE__ . '\\WHATSAPP_NEW_SEND_SECRET', $sendSecret);

$accountId = env('WHATSAPP_NEW_ACCOUNT_ID', '');
$accountId = $accountId ? trim($accountId) : '';
define(__NAMESPACE__ . '\\WHATSAPP_NEW_ACCOUNT_ID', $accountId);

$webhookSecret = env('WHATSAPP_NEW_WEBHOOK_SECRET', '');
$webhookSecret = $webhookSecret ? trim($webhookSecret) : '';
define(__NAMESPACE__ . '\\WHATSAPP_NEW_WEBHOOK_SECRET', $webhookSecret);

// Logging configuration

define(__NAMESPACE__ . '\\WHATSAPP_NEW_LOG_LEVEL', env('WHATSAPP_NEW_LOG_LEVEL', 'info'));
define(__NAMESPACE__ . '\\WHATSAPP_NEW_API_TIMEOUT', (int) env('WHATSAPP_NEW_API_TIMEOUT', '30'));
define(__NAMESPACE__ . '\\WHATSAPP_ACTIVE_WEBHOOK', env('WHATSAPP_ACTIVE_WEBHOOK', 'wamundo'));
define(__NAMESPACE__ . '\\WHATSAPP_LOG_CHANNEL', 'whatsapp');
define(__NAMESPACE__ . '\\WHATSAPP_LOG_PATH', __DIR__ . '/../logs/whatsapp.log');
