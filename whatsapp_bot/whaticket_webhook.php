<?php
require_once __DIR__ . '/../config/path_constants.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';

use Shared\ConfigService;
use WhatsappBot\Services\WhatsappAuth;
use WhatsappBot\Services\WhatsappQuery;
use WhatsappBot\Services\LogService;

header('Content-Type: application/json');

$config = ConfigService::getInstance();
$secret = $config->get('WHATSAPP_WEBHOOK_SECRET', '');
$headerToken = $_SERVER['HTTP_X_WHATSAPP_WEBHOOK_TOKEN'] ?? '';
if (empty($secret) || empty($headerToken) || !hash_equals($secret, $headerToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$logger = new LogService();
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $logger->error('Invalid JSON payload');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$payload = adaptWhaticketPayload($data);
if (!$payload) {
    $logger->error('Unable to adapt payload', ['data' => $data]);
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported payload']);
    exit;
}

$whatsappId = $payload['whatsapp_id'];
$chatId = $payload['chat_id'];
$text = $payload['text'];

$auth = new WhatsappAuth();
$query = new WhatsappQuery($auth);

if (preg_match('/^login\s+(\S+)\s+(\S+)/i', $text, $m)) {
    $user = $auth->loginWithCredentials((int)$whatsappId, $m[1], $m[2]);
    $response = $user ? ['success' => true, 'message' => 'Sesión iniciada'] : ['error' => 'Credenciales inválidas'];
} elseif (preg_match('/^buscar\s+(\S+)\s+(\S+)/i', $text, $m)) {
    $response = $query->processSearchRequest((int)$whatsappId, (int)$chatId, $m[1], $m[2]);
} elseif (preg_match('/^codigo\s+(\d+)/i', $text, $m)) {
    $response = $query->getCodeById((int)$whatsappId, (int)$m[1]);
} else {
    $response = ['error' => 'Comando no reconocido'];
}

$logger->info('Message processed', ['payload' => $payload, 'response' => $response]);

echo json_encode($response);

function adaptWhaticketPayload(array $data): ?array
{
    $message = $data['message'] ?? ($data['messages'][0] ?? $data);
    $text = $message['body'] ?? $message['text'] ?? null;
    $from = $message['from'] ?? $message['sender'] ?? $message['chatId'] ?? null;
    if (!$text || !$from) {
        return null;
    }
    $whatsappId = (int)preg_replace('/\D/', '', $from);
    return [
        'chat_id' => $from,
        'whatsapp_id' => $whatsappId,
        'text' => trim($text)
    ];
}
