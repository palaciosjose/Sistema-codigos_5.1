<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Shared\WhatsAppUrlHelper;

$base = $argv[1] ?? '';
$statusEndpoint = $argv[2] ?? '/api/messages/instance';

$warning = null;
$sanitized = WhatsAppUrlHelper::sanitizeBaseUrl($base, $warning);
$final = rtrim($sanitized, '/') . '/' . ltrim($statusEndpoint, '/');

$result = [
    'final_url' => $final,
    'status' => $warning ? 'warning' : 'ok',
    'message' => $warning ?: 'URL base válida'
];

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
