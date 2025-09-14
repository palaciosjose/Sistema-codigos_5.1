<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config/whatsapp_config.php';

use WhatsappBot\Handlers\CommandHandler;
use WhatsappBot\Services\LogService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$log = new LogService();
$log->info('=== WEBHOOK INICIADO ===');
$log->info('Petición entrante', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'post' => $_POST
]);
try {
    $log->info('Verificando método POST');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $log->error('Método no POST', ['method' => $_SERVER['REQUEST_METHOD']]);
        http_response_code(405);
        $response = ['error' => 'Method not allowed'];
        echo json_encode($response);
        $log->info('Respuesta enviada', $response);
        exit;
    }

    $log->info('Verificando secret');
    
    $expectedSecret = \WhatsappBot\Config\WHATSAPP_NEW_WEBHOOK_SECRET;
    $receivedSecret = $_POST["secret"] ?? '';
    
    if ($receivedSecret !== $expectedSecret) {
        $log->error('Secret inválido', ['received' => substr($receivedSecret, 0, 10) . '...']);
        http_response_code(401);
        $response = ['error' => 'Unauthorized'];
        echo json_encode($response);
        $log->info('Respuesta enviada', $response);
        exit;
    }

    $log->info('Procesando payload');
    
    $payloadType = $_POST["type"] ?? '';
    $payloadData = $_POST["data"] ?? [];
    
    if ($payloadType !== 'whatsapp') {
        $log->error('Tipo no WhatsApp', ['type' => $payloadType]);
        $response = ['error' => 'Invalid type'];
        echo json_encode($response);
        $log->info('Respuesta enviada', $response);
        exit;
    }

    if (is_string($payloadData)) {
        $payloadData = json_decode($payloadData, true);
    }

    $messageText = trim($payloadData['message'] ?? '');
    $senderNumber = $payloadData['phone'] ?? '';
    $senderNumber = preg_replace('/\D+/', '', $senderNumber);

    $log->info('Mensaje procesado', [
        'sender' => $senderNumber,
        'message' => $messageText,
        'message_length' => strlen($messageText)
    ]);

    $messages = include __DIR__ . '/templates/messages.php';
    $botResponse = '';
    $handled = false;

    if (strpos($messageText, '/') === 0) {
        CommandHandler::handle([
            'chat_id' => $senderNumber,
            'whatsapp_id' => (int)$senderNumber,
            'text' => $messageText
        ]);
        $handled = true;
    } else {
        $botResponse = $messages['welcome'] ?? '';
        $log->info('Mensaje de texto normal');
    }

    if (!$handled && !empty($senderNumber) && !empty($botResponse)) {
        $log->info('Enviando respuesta', ['response_length' => strlen($botResponse)]);
        try {
            \WhatsappBot\Utils\WhatsappAPI::sendMessage($senderNumber, $botResponse);
            $sent = true;
        } catch (\Throwable $e) {
            $sent = false;
            $log->error('Error al enviar', ['error' => $e->getMessage()]);
        }
        $completeLog = __DIR__ . '/logs/webhook_complete.log';
        @file_put_contents($completeLog, "Mensaje de prueba enviado\n", FILE_APPEND | LOCK_EX);
        $log->info('Resultado envío', ['success' => $sent]);
    } elseif (!$handled) {
        $log->info('No se envió respuesta', ['sender_empty' => empty($senderNumber), 'response_empty' => empty($botResponse)]);
    }

    $log->info('Webhook completado exitosamente');
    $response = ['status' => 'success'];
    echo json_encode($response);
    $log->info('Respuesta enviada', $response);

} catch (Exception $e) {
    $log->error('ERROR CRÍTICO', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    http_response_code(500);
    $response = ['error' => 'Internal error'];
    echo json_encode($response);
    $log->info('Respuesta enviada', $response);
}

$log->info('=== WEBHOOK FINALIZADO ===');
?>
