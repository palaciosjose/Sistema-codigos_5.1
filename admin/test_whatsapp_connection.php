<?php
require_once __DIR__ . '/../config/path_constants.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

require_once SECURITY_DIR . '/auth.php';

if (!is_admin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

function log_action($message) {
    $logFile = __DIR__ . '/whatsapp_management.log';
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $logFile);
}

if (!defined('DEFAULT_WHATSAPP_STATUS_ENDPOINT')) {
    define('DEFAULT_WHATSAPP_STATUS_ENDPOINT', '/getInstanceInfo');
}

$action = filter_var($_POST['action'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$apiUrl = filter_var(trim($_POST['api_url'] ?? ''), FILTER_SANITIZE_URL);
$token = filter_var(trim($_POST['token'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$instance = filter_var(trim($_POST['instance'] ?? ''), FILTER_SANITIZE_NUMBER_INT);
$webhookUrl = filter_var(trim($_POST['webhook_url'] ?? ''), FILTER_SANITIZE_URL);
$webhookSecret = filter_var(trim($_POST['webhook_secret'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$statusEndpoint = filter_var(trim($_POST['status_endpoint'] ?? DEFAULT_WHATSAPP_STATUS_ENDPOINT), FILTER_SANITIZE_FULL_SPECIAL_CHARS);

function testWhatsAppConnection($url, $token, $instance, $statusEndpoint) {
    $endpoint = rtrim($url, '/') . '/' . ltrim($statusEndpoint, '/');
    log_action('POST ' . $endpoint);
    $payload = json_encode(['instance' => $instance]);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $code >= 400) {
        $msg = 'Error de conexión a la API: ' . ($error ?: 'HTTP ' . $code);
        log_action($msg);
        return [false, $msg];
    }
    return [true, 'Conexión a la API exitosa'];
}

function validateWhatsAppInstance($url, $token, $instance, $statusEndpoint) {
    $endpoint = rtrim($url, '/') . '/' . ltrim($statusEndpoint, '/');
    log_action('POST ' . $endpoint);
    $payload = json_encode(['instance' => $instance]);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $code >= 400) {
        $msg = 'Error al validar instancia: ' . ($error ?: 'HTTP ' . $code);
        log_action($msg);
        return [false, $msg];
    }
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['instance'])) {
        $msg = 'Respuesta inválida de la API';
        log_action($msg);
        return [false, $msg];
    }
    return [true, 'Instancia válida'];
}

function sendTestMessage($url, $token, $instance, $phone) {
    if (empty($phone)) {
        return [false, 'Número de teléfono requerido'];
    }
    $endpoint = rtrim($url, '/') . '/sendMessage';
    log_action('POST ' . $endpoint);
    $payload = json_encode([
        'instance' => $instance,
        'to' => $phone,
        'message' => 'Mensaje de prueba'
    ]);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $code >= 400) {
        $msg = 'Error al enviar mensaje: ' . ($error ?: 'HTTP ' . $code);
        log_action($msg);
        return [false, $msg];
    }
    return [true, 'Mensaje de prueba enviado'];
}

function verifyWebhook($url, $token, $instance, $webhookUrl, $secret) {
    if (empty($webhookUrl)) {
        return [false, 'URL de webhook no configurada'];
    }
    $endpoint = rtrim($url, '/') . '/testWebhook';
    log_action('POST ' . $endpoint);
    $payload = json_encode([
        'url' => $webhookUrl,
        'secret' => $secret,
        'instance' => $instance
    ]);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $code >= 400) {
        $msg = 'Error al verificar webhook: ' . ($error ?: 'HTTP ' . $code);
        log_action($msg);
        return [false, $msg];
    }
    return [true, 'Webhook verificado correctamente'];
}

switch ($action) {
    case 'test_api':
        $result = testWhatsAppConnection($apiUrl, $token, $instance, $statusEndpoint);
        break;
    case 'validate_instance':
        $result = validateWhatsAppInstance($apiUrl, $token, $instance, $statusEndpoint);
        break;
    case 'send_message':
        $phone = filter_var(trim($_POST['phone'] ?? ''), FILTER_SANITIZE_NUMBER_INT);
        $result = sendTestMessage($apiUrl, $token, $instance, $phone);
        break;
    case 'verify_webhook':
        $result = verifyWebhook($apiUrl, $token, $instance, $webhookUrl, $webhookSecret);
        break;
    default:
        $result = [false, 'Acción inválida'];
}

echo json_encode([
    'success' => $result[0],
    'message' => $result[1]
]);
?>
