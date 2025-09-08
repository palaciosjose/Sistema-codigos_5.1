<?php
/**
 * WEBHOOK DEFINITIVO - Sistema WhatsApp completo para wamundo.com
 * Integra funcionalidad completa del negocio: autenticación, búsquedas IMAP, permisos
 */

// Cargar configuración y servicios principales
require_once __DIR__ . '/../config/path_constants.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';

use Shared\ConfigService;
use Shared\DatabaseManager;
use WhatsappBot\Services\WhatsappAuth;
use WhatsappBot\Services\WhatsappQuery;
use WhatsappBot\Services\LogService;

// Headers de respuesta
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Inicializar servicios principales
$logger = new LogService();
$logger->info("=== WEBHOOK PRODUCTION INICIADO ===");

/**
 * Función de envío de mensajes via wamundo.com
 */
function sendWhatsAppResponse($recipient, $message) {
    global $logger;
    
    $config = ConfigService::getInstance();
    $apiSecret = $config->get('WHATSAPP_NEW_SEND_SECRET', '');
    $accountId = $config->get('WHATSAPP_NEW_ACCOUNT_ID', '');
    
    if (empty($apiSecret) || empty($accountId)) {
        $logger->error("API credentials not configured for sending");
        return ['success' => false, 'error' => 'API not configured'];
    }
    
    $url = "https://wamundo.com/api/send/whatsapp";
    
    $data = [
        "secret" => $apiSecret,
        "account" => $accountId,
        "recipient" => $recipient,
        "type" => "text",
        "message" => $message,
        "priority" => 1
    ];
    
    $logger->debug("Sending WhatsApp message", [
        'recipient' => $recipient,
        'message_length' => strlen($message)
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || !empty($curlError)) {
        $logger->error("cURL error sending message", ['error' => $curlError]);
        return ['success' => false, 'error' => 'Connection error'];
    }
    
    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        if ($responseData && $responseData['status'] === 200) {
            $logger->info("Message sent successfully", [
                'recipient' => $recipient,
                'message_id' => $responseData['data']['messageId'] ?? 'N/A'
            ]);
            return ['success' => true, 'data' => $responseData];
        }
    }
    
    $logger->error("Failed to send message", [
        'http_code' => $httpCode,
        'response' => $response
    ]);
    return ['success' => false, 'error' => "HTTP $httpCode"];
}

try {
    // Verificar método POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    // Verificar configuración de base de datos
    try {
        $db = DatabaseManager::getInstance()->getConnection();
    } catch (Exception $e) {
        $logger->error("Database connection failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database unavailable']);
        exit;
    }

    // Leer y validar webhook data
    $request = $_POST;
    $logger->debug("Webhook request received", ['request' => $request]);

    // Verificar webhook secret
    $config = ConfigService::getInstance();
    $webhookSecret = $config->get('WHATSAPP_NEW_WEBHOOK_SECRET', '');
    $receivedSecret = $request["secret"] ?? '';
    
    if (!empty($webhookSecret) && !hash_equals($webhookSecret, $receivedSecret)) {
        $logger->error("Invalid webhook secret");
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Validar estructura del payload
    $payloadType = $request["type"] ?? '';
    $payloadData = $request["data"] ?? [];

    if ($payloadType !== 'whatsapp' || empty($payloadData)) {
        $logger->error("Invalid payload structure", [
            'type' => $payloadType,
            'has_data' => !empty($payloadData)
        ]);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    }

    // Extraer datos del mensaje
    $messageId = $payloadData['id'] ?? 0;
    $messageText = trim($payloadData['message'] ?? '');
    $attachment = $payloadData['attachment'] ?? '';
    $timestamp = $payloadData['timestamp'] ?? time();
    
    // Determinar remitente (priorizar phone sobre wid)
    $senderPhone = $payloadData['phone'] ?? $payloadData['wid'] ?? '';
    $cleanSender = preg_replace('/[^+0-9]/', '', $senderPhone);

    $logger->info("Processing WhatsApp message", [
        'message_id' => $messageId,
        'sender' => $cleanSender,
        'message' => substr($messageText, 0, 50) . '...',
        'has_attachment' => !empty($attachment)
    ]);

    if (empty($messageText) || empty($cleanSender)) {
        $logger->error("Missing required fields", [
            'has_message' => !empty($messageText),
            'has_sender' => !empty($cleanSender)
        ]);
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Inicializar servicios de WhatsApp
    $auth = new WhatsappAuth();
    $auth->cleanupExpiredData();
    $query = new WhatsappQuery($auth);

    $botResponse = '';
    $shouldRespond = false;

    // ========== PROCESAR COMANDOS DEL NEGOCIO ==========

    // Comando /login usuario contraseña
    if (preg_match('/^\/login\s+(\S+)\s+(\S+)$/i', $messageText, $matches)) {
        $username = $matches[1];
        $password = $matches[2];
        
        $logger->info("Login attempt", [
            'sender' => $cleanSender,
            'username' => $username
        ]);
        
        try {
            $user = $auth->loginWithCredentials($cleanSender, $username, $password);
            
            if ($user) {
                $botResponse = "🔐 *Sesión iniciada correctamente*\n\n" .
                              "👤 Usuario: *" . $user['username'] . "*\n" .
                              "🆔 ID: " . $user['id'] . "\n" .
                              "📱 WhatsApp vinculado exitosamente\n\n" .
                              "✅ Ya puedes usar todos los comandos:\n" .
                              "• `/buscar email plataforma`\n" .
                              "• `/codigo id`\n" .
                              "• `/stats`\n\n" .
                              "_Sesión válida por " . (WhatsappAuth::SESSION_LIFETIME / 3600) . " horas_";
                $shouldRespond = true;
                
                $logger->info("Login successful", [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'sender' => $cleanSender
                ]);
            } else {
                $botResponse = "❌ *Credenciales incorrectas*\n\n" .
                              "Usuario o contraseña inválidos.\n\n" .
                              "📝 Formato correcto:\n" .
                              "`/login tu_usuario tu_contraseña`\n\n" .
                              "💡 Contacta al administrador si tienes problemas.";
                $shouldRespond = true;
                
                $logger->warning("Login failed", [
                    'username' => $username,
                    'sender' => $cleanSender
                ]);
            }
        } catch (Exception $e) {
            $logger->error("Login error: " . $e->getMessage());
            $botResponse = "🔧 *Error del sistema*\n\n" .
                          "Hubo un problema con la autenticación.\n" .
                          "Inténtalo más tarde o contacta al administrador.";
            $shouldRespond = true;
        }
    }
    
    // Comando /buscar email plataforma
    elseif (preg_match('/^\/buscar\s+(\S+@\S+\.\S+)\s+(\S+)$/i', $messageText, $matches)) {
        $email = strtolower($matches[1]);
        $platform = $matches[2];
        
        $logger->info("Search request", [
            'sender' => $cleanSender,
            'email' => $email,
            'platform' => $platform
        ]);
        
        try {
            // Primero verificar autenticación
            $user = $auth->authenticateUser($cleanSender);
            if (!$user) {
                $botResponse = "🔒 *Acceso denegado*\n\n" .
                              "Debes iniciar sesión primero.\n\n" .
                              "Usa: `/login tu_usuario tu_contraseña`";
                $shouldRespond = true;
            } else {
                // Mensaje de procesamiento
                $processingMsg = "🔍 *Buscando códigos...*\n\n" .
                               "📧 Email: `" . $email . "`\n" .
                               "🎯 Plataforma: *" . $platform . "*\n\n" .
                               "⏳ Consultando servidores IMAP...\n" .
                               "_Esto puede tardar unos segundos_";
                
                sendWhatsAppResponse($cleanSender, $processingMsg);
                
                // Ejecutar búsqueda real
                $searchResult = $query->processSearchRequest(
                    (int)$cleanSender, // Usar teléfono como ID temporal
                    (int)$cleanSender,
                    $email,
                    $platform
                );
                
                if (isset($searchResult['found']) && $searchResult['found']) {
                    $codes = $searchResult['emails'] ?? [];
                    $botResponse = "✅ *Códigos encontrados*\n\n" .
                                  "📧 Email: `" . $email . "`\n" .
                                  "🎯 Plataforma: *" . $platform . "*\n" .
                                  "📊 Resultados: " . count($codes) . "\n\n";
                    
                    foreach (array_slice($codes, 0, 5) as $i => $code) {
                        $botResponse .= "🔐 **Código " . ($i + 1) . ":**\n";
                        $botResponse .= "`" . $code['content'] . "`\n";
                        if (isset($code['id'])) {
                            $botResponse .= "🆔 ID: " . $code['id'] . "\n";
                        }
                        $botResponse .= "📅 " . $code['date'] . "\n\n";
                    }
                    
                    if (count($codes) > 5) {
                        $botResponse .= "... y " . (count($codes) - 5) . " códigos más\n\n";
                    }
                    
                    $botResponse .= "_Usa `/codigo id` para obtener un código específico_";
                } else {
                    $error = $searchResult['error'] ?? 'No se encontraron códigos';
                    $botResponse = "😔 *Sin resultados*\n\n" .
                                  "📧 Email: `" . $email . "`\n" .
                                  "🎯 Plataforma: *" . $platform . "*\n\n" .
                                  "❌ " . $error . "\n\n" .
                                  "💡 Verifica:\n" .
                                  "• Email escrito correctamente\n" .
                                  "• Plataforma válida\n" .
                                  "• Permisos de acceso";
                }
                
                $shouldRespond = true;
            }
        } catch (Exception $e) {
            $logger->error("Search error: " . $e->getMessage());
            $botResponse = "🔧 *Error en la búsqueda*\n\n" .
                          "Hubo un problema consultando los servidores.\n" .
                          "Inténtalo más tarde o contacta al administrador.";
            $shouldRespond = true;
        }
    }
    
    // Comando /codigo id
    elseif (preg_match('/^\/codigo\s+(\d+)$/i', $messageText, $matches)) {
        $codeId = (int)$matches[1];
        
        $logger->info("Code request", [
            'sender' => $cleanSender,
            'code_id' => $codeId
        ]);
        
        try {
            $user = $auth->authenticateUser($cleanSender);
            if (!$user) {
                $botResponse = "🔒 *Acceso denegado*\n\n" .
                              "Debes iniciar sesión primero.\n\n" .
                              "Usa: `/login tu_usuario tu_contraseña`";
            } else {
                $codeResult = $query->getCodeById($cleanSender, $codeId);
                
                if (isset($codeResult['found']) && $codeResult['found']) {
                    $code = $codeResult['content'];
                    $botResponse = "✅ *Código encontrado*\n\n" .
                                  "🆔 ID: " . $codeId . "\n" .
                                  "🔐 Código: `" . $code['content'] . "`\n" .
                                  "📅 Fecha: " . $code['date'] . "\n" .
                                  "📧 Email: " . $code['email'] . "\n" .
                                  "🎯 Plataforma: " . $code['platform'];
                } else {
                    $error = $codeResult['error'] ?? 'Código no encontrado';
                    $botResponse = "❌ *Código no disponible*\n\n" .
                                  "🆔 ID solicitado: " . $codeId . "\n\n" .
                                  $error;
                }
            }
            
            $shouldRespond = true;
        } catch (Exception $e) {
            $logger->error("Code retrieval error: " . $e->getMessage());
            $botResponse = "🔧 *Error obteniendo código*\n\n" .
                          "Hubo un problema accediendo al código.\n" .
                          "Verifica el ID e inténtalo más tarde.";
            $shouldRespond = true;
        }
    }
    
    // Comando /stats
    elseif (preg_match('/^\/stats$/i', $messageText)) {
        try {
            $user = $auth->authenticateUser($cleanSender);
            if (!$user) {
                $botResponse = "🔒 *Acceso denegado*\n\n" .
                              "Debes iniciar sesión primero.";
            } else {
                $stats = $query->getStats();
                $botResponse = "📊 *Estadísticas del Sistema*\n\n" .
                              "👥 Usuarios activos: " . $stats['active_users'] . "\n" .
                              "🔍 Búsquedas hoy: " . $stats['searches_today'] . "\n" .
                              "📈 Total búsquedas: " . $stats['total_searches'] . "\n" .
                              "🔗 Plataforma: wamundo.com\n" .
                              "⚡ Estado: Completamente operativo\n\n" .
                              "🆔 Tu ID: " . $user['id'] . "\n" .
                              "👤 Usuario: " . $user['username'];
            }
            
            $shouldRespond = true;
        } catch (Exception $e) {
            $logger->error("Stats error: " . $e->getMessage());
            $botResponse = "Error obteniendo estadísticas.";
            $shouldRespond = true;
        }
    }
    
    // Comando /ayuda
    elseif (preg_match('/^\/ayuda$/i', $messageText)) {
        $user = $auth->authenticateUser($cleanSender);
        
        $botResponse = "🤖 *Bot de Códigos - Guía Completa*\n\n";
        
        if (!$user) {
            $botResponse .= "🔒 **Primero debes autenticarte:**\n" .
                           "`/login tu_usuario tu_contraseña`\n\n";
        }
        
        $botResponse .= "📋 **Comandos disponibles:**\n" .
                       "• `/login usuario contraseña` - Iniciar sesión\n" .
                       "• `/buscar email plataforma` - Buscar códigos\n" .
                       "• `/codigo id` - Obtener código específico\n" .
                       "• `/stats` - Ver estadísticas\n" .
                       "• `/ayuda` - Esta ayuda\n\n" .
                       "📝 **Ejemplos de uso:**\n" .
                       "• `/buscar juan@gmail.com Netflix`\n" .
                       "• `/codigo 12345`\n\n" .
                       "🔗 Plataforma: wamundo.com\n" .
                       "_Sistema completamente funcional_";
        
        $shouldRespond = true;
    }
    
    // Comando no reconocido
    elseif (preg_match('/^\//', $messageText)) {
        $botResponse = "❓ *Comando no reconocido*\n\n" .
                      "Comando: `" . substr($messageText, 0, 20) . "`\n\n" .
                      "Usa `/ayuda` para ver todos los comandos disponibles.";
        $shouldRespond = true;
    }

    // ========== ENVIAR RESPUESTA ==========
    
    $responseSuccess = false;
    if ($shouldRespond && !empty($botResponse) && !empty($cleanSender)) {
        $sendResult = sendWhatsAppResponse($cleanSender, $botResponse);
        $responseSuccess = $sendResult['success'];
        
        if (!$responseSuccess) {
            $logger->error("Failed to send response", [
                'error' => $sendResult['error'] ?? 'Unknown error',
                'recipient' => $cleanSender
            ]);
        }
    }

    // Respuesta al webhook
    $logger->info("Webhook processed", [
        'message_id' => $messageId,
        'command' => substr($messageText, 0, 20),
        'response_sent' => $responseSuccess,
        'should_respond' => $shouldRespond
    ]);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Webhook processed successfully',
        'message_id' => $messageId,
        'response_sent' => $responseSuccess,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $logger->error("Critical webhook error: " . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

$logger->info("=== WEBHOOK PRODUCTION FINALIZADO ===");
?>
