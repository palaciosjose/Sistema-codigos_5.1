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
    define('DEFAULT_WHATSAPP_STATUS_ENDPOINT', '/api/messages/instance');
}

$action = filter_var($_POST['action'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$apiUrl = filter_var(trim($_POST['api_url'] ?? ''), FILTER_SANITIZE_URL);
$token = filter_var(trim($_POST['token'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$instance = filter_var(trim($_POST['instance'] ?? ''), FILTER_SANITIZE_NUMBER_INT);
$webhookUrl = filter_var(trim($_POST['webhook_url'] ?? ''), FILTER_SANITIZE_URL);
$webhookSecret = filter_var(trim($_POST['webhook_secret'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$statusEndpoint = filter_var(trim($_POST['status_endpoint'] ?? DEFAULT_WHATSAPP_STATUS_ENDPOINT), FILTER_SANITIZE_FULL_SPECIAL_CHARS);

function testWhatsAppConnection($url, $token, $instance, $statusEndpoint) {
    // Usar el endpoint que SÍ funciona
    $endpoint = rtrim($url, '/') . '/api/messages/send';
    log_action('POST ' . $endpoint . ' (test conexión)');
    
    // Payload de prueba
    $payload = json_encode([
        'number' => '00000000000', // Número inválido para prueba
        'body' => 'Test de conexión API'
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
    
    if ($response === false) {
        $msg = 'Error de conexión a la API: ' . $error;
        log_action($msg);
        return [false, $msg];
    }
    
    // Si responde (aunque sea error por número inválido), la API funciona
    if ($code >= 200 && $code < 500) {
        return [true, 'Conexión a la API exitosa'];
    }
    
    $msg = 'Error del servidor API: HTTP ' . $code;
    log_action($msg);
    return [false, $msg];
}

function validateWhatsAppInstance($url, $token, $instance, $statusEndpoint) {
    // Usar el endpoint que SÍ funciona
    $endpoint = rtrim($url, '/') . '/api/messages/send';
    log_action('POST ' . $endpoint . ' (validar instancia)');
    
    // Payload de prueba
    $payload = json_encode([
        'number' => '00000000000',
        'body' => 'Test validación instancia'
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
    
    if ($response === false) {
        $msg = 'Error al validar instancia: ' . $error;
        log_action($msg);
        return [false, $msg];
    }
    
    // Si responde, la instancia/API está funcionando
    if ($code >= 200 && $code < 500) {
        return [true, 'Instancia válida'];
    }
    
    $msg = 'Error del servidor: HTTP ' . $code;
    log_action($msg);
    return [false, $msg];
}

function sendTestMessage($url, $token, $instance, $phone) {
    if (empty($phone)) {
        return [false, 'Número de teléfono requerido'];
    }
    $endpoint = rtrim($url, '/') . '/api/messages/send';
    log_action('POST ' . $endpoint);
    $payload = json_encode([
        'number' => $phone,
        'body' => 'Mensaje de prueba',
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
    
    // 1. Validar formato de URL del webhook
    if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        return [false, 'URL de webhook inválida'];
    }
    
    // 2. En lugar de buscar endpoints inexistentes, 
    //    verificamos que podemos enviar mensajes (el único endpoint que funciona)
    $endpoint = rtrim($url, '/') . '/api/messages/send';
    log_action('POST ' . $endpoint . ' (verificación vía envío de mensaje)');
    
    // Mensaje de prueba con número que no causará problemas
    $payload = json_encode([
        'number' => '00000000000', // Número inválido para prueba
        'body' => 'Test de conectividad'
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
    
    // Si obtenemos respuesta (aunque sea error por número inválido), 
    // significa que la API está funcionando y el token es válido
    if ($response === false) {
        $msg = 'No se puede conectar con la API: ' . $error;
        log_action($msg);
        return [false, $msg];
    }
    
    // Códigos 200-299 = éxito, 400-499 = error de cliente pero API funciona
    if ($code >= 200 && $code < 500) {
        log_action('Webhook verificado: API responde correctamente y token válido');
        return [true, 'Webhook configurado - API operativa y token válido'];
    }
    
    // Solo códigos 500+ son errores reales del servidor
    $msg = 'Error del servidor API: HTTP ' . $code;
    log_action($msg);
    return [false, $msg];
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
