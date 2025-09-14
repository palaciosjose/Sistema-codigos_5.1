<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config/whatsapp_config.php';

use WhatsappBot\Handlers\CommandHandler;
use WhatsappBot\Services\WhatsappQuery;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Log básico de debug
$debugLog = __DIR__ . '/../logs/webhook_debug_' . date('Y-m-d') . '.log';
if (!is_dir(dirname($debugLog))) {
    mkdir(dirname($debugLog), 0755, true);
}

function logDebug($message, $data = []) {
    global $debugLog;
    $entry = date('Y-m-d H:i:s') . " - $message";
    if ($data) {
        $entry .= " | " . json_encode($data);
    }
    $entry .= "\n";
    @file_put_contents($debugLog, $entry, FILE_APPEND | LOCK_EX);
}

logDebug("=== WEBHOOK INICIADO ===");
try {
    logDebug("Verificando método POST");
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logDebug("Método no POST", ['method' => $_SERVER['REQUEST_METHOD']]);
        http_response_code(405);
        exit;
    }

    logDebug("Verificando secret");
    
    $expectedSecret = \WhatsappBot\Config\WHATSAPP_NEW_WEBHOOK_SECRET;
    $receivedSecret = $_POST["secret"] ?? '';
    
    if ($receivedSecret !== $expectedSecret) {
        logDebug("Secret inválido", ['received' => substr($receivedSecret, 0, 10) . '...']);
        http_response_code(401);
        exit;
    }

    logDebug("Procesando payload");
    
    $payloadType = $_POST["type"] ?? '';
    $payloadData = $_POST["data"] ?? [];
    
    if ($payloadType !== 'whatsapp') {
        logDebug("Tipo no WhatsApp", ['type' => $payloadType]);
        exit;
    }

    if (is_string($payloadData)) {
        $payloadData = json_decode($payloadData, true);
    }

    $messageText = trim($payloadData['message'] ?? '');
    $senderNumber = $payloadData['phone'] ?? '';
    $senderNumber = preg_replace('/\D+/', '', $senderNumber);

    logDebug("Mensaje procesado", [
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
        logDebug("Mensaje de texto normal");
    }

    if (!$handled && !empty($senderNumber) && !empty($botResponse)) {
        logDebug("Enviando respuesta", ['response_length' => strlen($botResponse)]);
        try {
            \WhatsappBot\Utils\WhatsappAPI::sendMessage($senderNumber, $botResponse);
            $sent = true;
        } catch (\Throwable $e) {
            $sent = false;
            logDebug("Error al enviar", ['error' => $e->getMessage()]);
        }

        $completeLog = __DIR__ . '/logs/webhook_complete.log';
        @file_put_contents($completeLog, "Mensaje de prueba enviado\n", FILE_APPEND | LOCK_EX);
        logDebug("Resultado envío", ['success' => $sent]);
    } elseif (!$handled) {
        logDebug("No se envió respuesta", ['sender_empty' => empty($senderNumber), 'response_empty' => empty($botResponse)]);
    }

    logDebug("Webhook completado exitosamente");
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    logDebug("ERROR CRÍTICO", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}

logDebug("=== WEBHOOK FINALIZADO ===");
?>
