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

// WhatsApp API configuration
$rawUrl = getenv('WHATSAPP_API_URL');
if ($rawUrl === false || $rawUrl === '') {
    error_log('WHATSAPP_API_URL not found in environment, using default');
}
$url = env('WHATSAPP_API_URL', 'https://api.example.com');
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    $url = 'https://api.example.com';
}
define(__NAMESPACE__ . '\\WHATSAPP_API_URL', $url);

$rawToken = getenv('WHATSAPP_TOKEN');
if ($rawToken === false || $rawToken === '') {
    error_log('WHATSAPP_TOKEN not found in environment, using default');
}
$token = env('WHATSAPP_TOKEN', 'your-api-token');
if ($token === null || $token === '') {
    $token = 'your-api-token';
}
define(__NAMESPACE__ . '\\WHATSAPP_TOKEN', $token);

$instance = env('WHATSAPP_INSTANCE_ID', '');
$instance = $instance ? trim($instance) : '';
define(__NAMESPACE__ . '\\WHATSAPP_INSTANCE_ID', $instance);

$webhookUrl = env('WHATSAPP_WEBHOOK_URL', 'https://yourdomain.com/whatsapp/webhook');
if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
    $webhookUrl = 'https://yourdomain.com/whatsapp/webhook';
}
define(__NAMESPACE__ . '\\WHATSAPP_WEBHOOK_URL', $webhookUrl);

$webhookSecret = env('WHATSAPP_WEBHOOK_SECRET', '');
$webhookSecret = $webhookSecret ? trim($webhookSecret) : '';
define(__NAMESPACE__ . '\\WHATSAPP_WEBHOOK_SECRET', $webhookSecret);

// Logging configuration

define(__NAMESPACE__ . '\\WHATSAPP_LOG_CHANNEL', 'whatsapp');
define(__NAMESPACE__ . '\\WHATSAPP_LOG_PATH', __DIR__ . '/../logs/whatsapp.log');
define(__NAMESPACE__ . '\\WHATSAPP_LOG_LEVEL', env('WHATSAPP_LOG_LEVEL', 'info'));
