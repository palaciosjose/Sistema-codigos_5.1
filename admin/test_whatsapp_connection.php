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

$action = $_POST['action'] ?? '';
$apiUrl = trim($_POST['api_url'] ?? '');
$token = trim($_POST['token'] ?? '');
$instance = trim($_POST['instance'] ?? '');
$webhookUrl = trim($_POST['webhook_url'] ?? '');
$webhookSecret = trim($_POST['webhook_secret'] ?? '');

function testWhatsAppConnection($url, $token, $instance) {
    $endpoint = rtrim($url, '/') . '/getInstanceInfo';
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
        return [false, 'Error de conexión a la API: ' . ($error ?: 'HTTP ' . $code)];
    }
    return [true, 'Conexión a la API exitosa'];
}

function validateWhatsAppInstance($url, $token, $instance) {
    $endpoint = rtrim($url, '/') . '/getInstanceInfo';
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
        return [false, 'Error al validar instancia: ' . ($error ?: 'HTTP ' . $code)];
    }
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['instance'])) {
        return [false, 'Respuesta inválida de la API'];
    }
    return [true, 'Instancia válida'];
}

function sendTestMessage($url, $token, $instance, $phone) {
    if (empty($phone)) {
        return [false, 'Número de teléfono requerido'];
    }
    $endpoint = rtrim($url, '/') . '/sendMessage';
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
        return [false, 'Error al enviar mensaje: ' . ($error ?: 'HTTP ' . $code)];
    }
    return [true, 'Mensaje de prueba enviado'];
}

function verifyWebhook($url, $token, $instance, $webhookUrl, $secret) {
    if (empty($webhookUrl)) {
        return [false, 'URL de webhook no configurada'];
    }
    $endpoint = rtrim($url, '/') . '/testWebhook';
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
        return [false, 'Error al verificar webhook: ' . ($error ?: 'HTTP ' . $code)];
    }
    return [true, 'Webhook verificado correctamente'];
}

switch ($action) {
    case 'test_api':
        $result = testWhatsAppConnection($apiUrl, $token, $instance);
        break;
    case 'validate_instance':
        $result = validateWhatsAppInstance($apiUrl, $token, $instance);
        break;
    case 'send_message':
        $phone = trim($_POST['phone'] ?? '');
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
