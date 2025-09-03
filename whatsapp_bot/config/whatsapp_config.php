<?php
namespace WhatsappBot\Config;

/**
 * Obtiene una variable de entorno con valor por defecto.
 */
function env(string $key, ?string $default = null): ?string
{
    $val = getenv($key);
    return ($val === false || $val === '') ? $default : $val;
}

// Configuración de la API de WhatsApp
$url = env('WHATSAPP_API_URL', 'https://api.example.com');
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    $url = 'https://api.example.com';
}
const WHATSAPP_API_URL = $url;

$token = env('WHATSAPP_API_TOKEN', 'your-api-token');
if ($token === null || $token === '') {
    $token = 'your-api-token';
}
const WHATSAPP_API_TOKEN = $token;

$instance = env('WHATSAPP_INSTANCE_ID', '');
$instance = $instance ? trim($instance) : '';
const WHATSAPP_INSTANCE_ID = $instance;

$webhookUrl = env('WHATSAPP_WEBHOOK_URL', 'https://yourdomain.com/whatsapp/webhook');
if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
    $webhookUrl = 'https://yourdomain.com/whatsapp/webhook';
}
const WHATSAPP_WEBHOOK_URL = $webhookUrl;

$webhookSecret = env('WHATSAPP_WEBHOOK_SECRET', '');
$webhookSecret = $webhookSecret ? trim($webhookSecret) : '';
const WHATSAPP_WEBHOOK_SECRET = $webhookSecret;

// Configuración de logs
const WHATSAPP_LOG_CHANNEL = 'whatsapp';
const WHATSAPP_LOG_PATH = __DIR__ . '/../logs/whatsapp.log';
const WHATSAPP_LOG_LEVEL = env('WHATSAPP_LOG_LEVEL', 'info');

