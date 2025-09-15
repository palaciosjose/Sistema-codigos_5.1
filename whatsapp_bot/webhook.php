<?php
/**
 * WEBHOOK WHATSAPP - VERSIÓN DEFINITIVA
 * Basado en webhook_debug que funciona + funcionalidad completa
 */

// Activar reporte de errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Ocultar en producción
ini_set('log_errors', 1);

// Headers básicos
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Función de log de emergencia (por si falla el LogService)
function emergencyLog($message, $data = []) {
    $logFile = __DIR__ . '/logs/webhook_emergency.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message";
    if (!empty($data)) {
        $logEntry .= " | " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    $logEntry .= "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

try {
    emergencyLog("=== WEBHOOK INICIADO ===");
    
    // Verificar método POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        emergencyLog("ERROR: Método no POST", ['method' => $_SERVER['REQUEST_METHOD']]);
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    emergencyLog("Método POST OK");
    
    // Obtener datos del payload
    $type = $_POST['type'] ?? $_REQUEST['type'] ?? '';
    $data = $_POST['data'] ?? $_REQUEST['data'] ?? '';
    
    emergencyLog("Payload recibido", [
        'type' => $type,
        'data_type' => gettype($data),
        'data_length' => is_string($data) ? strlen($data) : 0
    ]);
    
    // Verificar tipo WhatsApp
    if ($type !== 'whatsapp') {
        emergencyLog("Tipo no WhatsApp", ['type' => $type]);
        echo json_encode(['status' => 'ignored', 'reason' => 'not_whatsapp']);
        exit;
    }
    
    // Procesar data
    $decodedData = [];
    if (is_string($data)) {
        $decodedData = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            emergencyLog("ERROR JSON", ['error' => json_last_error_msg()]);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }
    } else {
        $decodedData = $data;
    }
    
    // Extraer mensaje y teléfono
    $messageText = trim($decodedData['message'] ?? '');
    $senderNumber = $decodedData['phone'] ?? $decodedData['wid'] ?? '';
    $messageId = $decodedData['id'] ?? 'unknown';
    
    // Limpiar número
    $senderNumber = preg_replace('/[^+0-9]/', '', $senderNumber);
    
    emergencyLog("Datos procesados", [
        'message' => $messageText,
        'sender' => $senderNumber,
        'id' => $messageId
    ]);
    
    // Validar datos mínimos
    if (empty($senderNumber) || empty($messageText)) {
        emergencyLog("Datos insuficientes");
        echo json_encode(['status' => 'ignored', 'reason' => 'insufficient_data']);
        exit;
    }
    
    // Intentar cargar el sistema completo
    $systemLoaded = false;
    $botResponse = '';
    
    try {
        // Intentar cargar vendor/autoload
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            emergencyLog("Autoload cargado");
            
            // Intentar usar LogService
            if (class_exists('WhatsappBot\\Services\\LogService')) {
                $log = new WhatsappBot\Services\LogService();
                $log->info('Sistema completo cargado');
                emergencyLog("LogService cargado");
            }
            
            // Intentar procesar comando con CommandHandler
            if (strpos($messageText, '/') === 0 && class_exists('WhatsappBot\\Handlers\\CommandHandler')) {
                emergencyLog("Procesando comando con CommandHandler", ['command' => $messageText]);
                
                WhatsappBot\Handlers\CommandHandler::handle([
                    'chat_id' => $senderNumber,
                    'whatsapp_id' => $senderNumber,
                    'text' => $messageText
                ]);
                
                $systemLoaded = true;
                emergencyLog("Comando procesado por CommandHandler");
                
            } else {
                emergencyLog("No es comando o CommandHandler no disponible");
            }
            
        } else {
            emergencyLog("Autoload no encontrado");
        }
        
    } catch (Throwable $e) {
        emergencyLog("Error cargando sistema completo", [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
    
    // Si el sistema completo falló, usar respuestas básicas
    if (!$systemLoaded) {
        emergencyLog("Usando sistema básico de respuestas");
        
        // Cargar mensajes básicos
        $messagesFile = __DIR__ . '/templates/messages.php';
        $messages = [];
        
        if (file_exists($messagesFile)) {
            $messages = include $messagesFile;
            emergencyLog("Mensajes cargados desde archivo");
        } else {
            $messages = [
                'welcome' => 'Hola! Soy el bot de WhatsApp. Envía /ayuda para ver los comandos disponibles.',
                'help' => 'Comandos disponibles: /start, /ayuda, /test',
                'unknown_command' => 'Comando no reconocido. Envía /ayuda para ver comandos disponibles.'
            ];
            emergencyLog("Usando mensajes por defecto");
        }
        
        // Procesar mensaje
        if (strpos($messageText, '/start') === 0) {
            $botResponse = $messages['welcome'] ?? 'Bienvenido al bot de WhatsApp.';
        } elseif (strpos($messageText, '/ayuda') === 0 || strpos($messageText, '/help') === 0) {
            $botResponse = $messages['help'] ?? 'Comandos disponibles: /start, /ayuda, /test';
        } elseif (strpos($messageText, '/test') === 0) {
            $botResponse = "🧪 Test del Bot\n✅ Webhook funcionando\n✅ Sistema básico activo\n🆔 Mensaje ID: $messageId\n⏰ " . date('Y-m-d H:i:s');
        } elseif (strpos($messageText, '/') === 0) {
            $botResponse = $messages['unknown_command'] ?? 'Comando no reconocido. Envía /ayuda para ver comandos disponibles.';
        } else {
            $botResponse = $messages['welcome'] ?? 'Hola! Soy el bot de WhatsApp. Envía /ayuda para ver los comandos disponibles.';
        }
        
        emergencyLog("Respuesta generada", ['response_length' => strlen($botResponse)]);
        
        // Enviar respuesta usando sistema básico
        if (!empty($botResponse)) {
            try {
                // Intentar usar WhatsappAPI si está disponible
                if (class_exists('WhatsappBot\\Utils\\WhatsappAPI')) {
                    WhatsappBot\Utils\WhatsappAPI::sendMessage($senderNumber, $botResponse);
                    emergencyLog("Mensaje enviado con WhatsappAPI");
                } else {
                    // Fallback: envío directo con cURL
                    $config = null;
                    if (class_exists('Shared\\ConfigService')) {
                        $config = Shared\ConfigService::getInstance();
                    }
                    
                    if ($config) {
                        $apiUrl = $config->get('WHATSAPP_NEW_API_URL', 'https://wamundo.com/api');
                        $sendSecret = $config->get('WHATSAPP_NEW_SEND_SECRET', '');
                        $accountId = $config->get('WHATSAPP_NEW_ACCOUNT_ID', '');
                        
                        if (!empty($sendSecret) && !empty($accountId)) {
                            $sendUrl = rtrim($apiUrl, '/') . '/send/whatsapp';
                            $postData = [
                                'secret' => $sendSecret,
                                'account' => $accountId,
                                'recipient' => $senderNumber,
                                'type' => 'text',
                                'message' => $botResponse,
                                'priority' => 1
                            ];
                            
                            $ch = curl_init();
                            curl_setopt_array($ch, [
                                CURLOPT_URL => $sendUrl,
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => $postData,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 30,
                                CURLOPT_SSL_VERIFYPEER => false
                            ]);
                            
                            $response = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($httpCode === 200) {
                                emergencyLog("Mensaje enviado con cURL directo");
                            } else {
                                emergencyLog("Error enviando con cURL", ['http_code' => $httpCode, 'response' => $response]);
                            }
                        } else {
                            emergencyLog("Configuración de envío incompleta");
                        }
                    } else {
                        emergencyLog("ConfigService no disponible");
                    }
                }
                
            } catch (Throwable $e) {
                emergencyLog("Error enviando respuesta", [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }
    }
    
    // Log de actividad
    $activityLog = __DIR__ . '/logs/webhook_activity.log';
    $activityDir = dirname($activityLog);
    if (!is_dir($activityDir)) {
        @mkdir($activityDir, 0755, true);
    }
    $logEntry = date('Y-m-d H:i:s') . " - Procesado - ID: $messageId - De: $senderNumber - Msg: $messageText\n";
    @file_put_contents($activityLog, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Respuesta exitosa
    $response = [
        'status' => 'success',
        'processed' => true,
        'message_id' => $messageId,
        'sender' => $senderNumber,
        'system_loaded' => $systemLoaded,
        'timestamp' => time()
    ];
    
    echo json_encode($response);
    emergencyLog("Webhook completado exitosamente", $response);
    
} catch (Throwable $e) {
    emergencyLog("ERROR CRÍTICO", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => substr($e->getTraceAsString(), 0, 1000)
    ]);
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'timestamp' => time()
    ]);
}

emergencyLog("=== WEBHOOK FINALIZADO ===");
?>
