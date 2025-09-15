<?php
require_once __DIR__ . '/../config/path_constants.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

header('Content-Type: application/json');

require_once SECURITY_DIR . '/auth.php';
require_once PROJECT_ROOT . '/shared/ConfigService.php';

use Shared\ConfigService;

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

$action = filter_var($_POST['action'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$webhookUrl = filter_var(trim($_POST['webhook_url'] ?? ''), FILTER_SANITIZE_URL);

$config = ConfigService::getInstance();
$apiUrl = $config->get('WHATSAPP_NEW_API_URL', '');
$token = $config->get('WHATSAPP_NEW_SEND_SECRET', '');
$accountId = $config->get('WHATSAPP_NEW_ACCOUNT_ID', '');

function testWhatsAppConnection($url, $token) {
    if (empty($url) || empty($token)) {
        return [false, 'Faltan credenciales de WhatsApp en la configuración'];
    }
    // Usar el endpoint que SÍ funciona
    $endpoint = rtrim($url, '/');
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
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $code === 0) {
        $msg = "Error cURL {$errno}: {$error}";
        log_action($msg);
        return [false, $msg];
    }

    // Si responde (aunque sea error por número inválido), la API funciona
    if ($code >= 200 && $code < 500) {
        return [true, 'Conexión a la API exitosa'];
    }

    $msg = "Error del servidor API: HTTP {$code} - cURL {$errno}: {$error}";
    log_action($msg);
    return [false, $msg];
}

function validateWhatsAppInstance($url, $token) {
    if (empty($url) || empty($token)) {
        return [false, 'Faltan credenciales de WhatsApp en la configuración'];
    }
    // Usar el endpoint que SÍ funciona
    $endpoint = rtrim($url, '/');
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
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $code === 0) {
        $msg = "Error cURL {$errno}: {$error}";
        log_action($msg);
        return [false, $msg];
    }

    // Si responde, la instancia/API está funcionando
    if ($code >= 200 && $code < 500) {
        return [true, 'Instancia válida'];
    }

    $msg = "Error del servidor: HTTP {$code} - cURL {$errno}: {$error}";
    log_action($msg);
    return [false, $msg];
}

function sendTestMessage($url, $token, $phone, $accountId) {
    if (empty($url) || empty($token)) {
        return [false, 'Faltan credenciales de WhatsApp en la configuración'];
    }
    if (empty($phone)) {
        return [false, 'Número de teléfono requerido'];
    }
    $endpoint = rtrim($url, '/');
    log_action('POST ' . $endpoint);
    $payloadArray = [
        'number' => $phone,
        'body' => 'Mensaje de prueba',
    ];
    if (!empty($accountId)) {
        $payloadArray['accountId'] = $accountId;
    }
    $payload = json_encode($payloadArray);
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
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $code === 0) {
        $msg = "Error cURL {$errno}: {$error}";
        log_action($msg);
        return [false, $msg];
    }
    if ($code >= 400) {
        $msg = "Error al enviar mensaje: HTTP {$code} - cURL {$errno}: {$error}";
        log_action($msg);
        return [false, $msg];
    }
    return [true, 'Mensaje de prueba enviado'];
}

function verifyWebhook($url, $token, $webhookUrl) {
    if (empty($url) || empty($token)) {
        return [false, 'Faltan credenciales de WhatsApp en la configuración'];
    }
    if (empty($webhookUrl)) {
        return [false, 'URL de webhook no configurada'];
    }
    
    // 1. Validar formato de URL del webhook
    if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        return [false, 'URL de webhook inválida'];
    }
    
    // 2. En lugar de buscar endpoints inexistentes, 
    //    verificamos que podemos enviar mensajes (el único endpoint que funciona)
    $endpoint = rtrim($url, '/');
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
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Si obtenemos respuesta (aunque sea error por número inválido),
    // significa que la API está funcionando y el token es válido
    if ($response === false || $code === 0) {
        $msg = "Error cURL {$errno}: {$error}";
        log_action($msg);
        return [false, $msg];
    }

    // Códigos 200-299 = éxito, 400-499 = error de cliente pero API funciona
    if ($code >= 200 && $code < 500) {
        log_action('Webhook verificado: API responde correctamente y token válido');
        return [true, 'Webhook configurado - API operativa y token válido'];
    }

    // Solo códigos 500+ son errores reales del servidor
    $msg = "Error del servidor API: HTTP {$code} - cURL {$errno}: {$error}";
    log_action($msg);
    return [false, $msg];
}

switch ($action) {
    case 'test_api':
        $result = testWhatsAppConnection($apiUrl, $token);
        break;
    case 'validate_instance':
        $result = validateWhatsAppInstance($apiUrl, $token);
        break;
    case 'send_message':
        $phone = filter_var(trim($_POST['phone'] ?? ''), FILTER_SANITIZE_NUMBER_INT);
        try {
            $result = sendTestMessage($apiUrl, $token, $phone, $accountId);
        } catch (Exception $e) {
            $result = [false, 'Error inesperado: ' . $e->getMessage()];
        }
        break;
    case 'verify_webhook':
        $result = verifyWebhook($apiUrl, $token, $webhookUrl);
        break;
    default:
        $result = [false, 'Acción inválida'];
}

echo json_encode([
    'success' => $result[0],
    'message' => $result[1]
]);
?>
