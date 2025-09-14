<?php
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

function sendWhatsAppMessage($recipient, $message) {
    logDebug("Enviando mensaje", ['recipient' => $recipient, 'message_length' => strlen($message)]);
    
    $url = "https://wamundo.com/api/send/whatsapp";
    
    $data = [
        "secret" => "b28b28d472e40899f323c7c2abc471eeed838541",
        "account" => "175788333672b32a1f754ba1c09b3695e0cb6cde7f68c72bc817985",
        "recipient" => $recipient,
        "type" => "text",
        "message" => $message,
        "priority" => 1
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logDebug("Respuesta API", ['http_code' => $httpCode, 'response' => substr($response, 0, 100)]);
    
    return $httpCode === 200;
}

try {
    logDebug("Verificando método POST");
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        logDebug("Método no POST", ['method' => $_SERVER['REQUEST_METHOD']]);
        http_response_code(405);
        exit;
    }

    logDebug("Verificando secret");
    
    $expectedSecret = '912a2b17d46ee5bd023d5e1b903dc28913a07e52';
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
    $senderNumber = preg_replace('/[^+0-9]/', '', $senderNumber);

    logDebug("Mensaje procesado", [
        'sender' => $senderNumber,
        'message' => $messageText,
        'message_length' => strlen($messageText)
    ]);

    $botResponse = '';
    
    if (strpos($messageText, '/start') === 0) {
        $botResponse = "Hola! Bienvenido al bot.\n\nComandos:\n/test - Probar bot\n/ayuda - Ver comandos\n/info - Info del sistema";
        logDebug("Comando /start ejecutado");
        
    } elseif (strpos($messageText, '/test') === 0) {
        $botResponse = "Bot funcionando correctamente!\n\nAPI Wamundo: Activa\nWebhook: Funcionando\nLogs: Habilitados\n\nSistema operativo.";
        logDebug("Comando /test ejecutado");
        
    } elseif (strpos($messageText, '/info') === 0) {
        $botResponse = "Info del Sistema:\n\nTu numero: " . $senderNumber . "\nFecha: " . date('Y-m-d H:i:s') . "\nWebhook: Activo\nAPI: Wamundo\nEstado: Operativo";
        logDebug("Comando /info ejecutado");
        
    } elseif (strpos($messageText, '/ayuda') === 0) {
        $botResponse = "Comandos disponibles:\n\n/start - Iniciar\n/test - Probar bot\n/info - Informacion\n/ayuda - Esta ayuda\n\nSistema funcionando correctamente.";
        logDebug("Comando /ayuda ejecutado");
        
    } elseif (strpos($messageText, '/') === 0) {
        $botResponse = "Comando no reconocido: " . substr($messageText, 0, 20) . "\n\nUsa /ayuda para ver comandos disponibles.";
        logDebug("Comando desconocido", ['command' => $messageText]);
        
    } else {
        $botResponse = "Hola! Soy el bot. Usa /start para comenzar o /ayuda para ver comandos.";
        logDebug("Mensaje de texto normal");
    }

    if (!empty($senderNumber) && !empty($botResponse)) {
        logDebug("Enviando respuesta", ['response_length' => strlen($botResponse)]);
        $sent = sendWhatsAppMessage($senderNumber, $botResponse);
        logDebug("Resultado envío", ['success' => $sent]);
    } else {
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
