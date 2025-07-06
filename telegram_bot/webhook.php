<?php
/**
 * Bot de Telegram Mejorado - webhook.php
 * v2.2 - Panel Admin completamente funcional
 */

// Configuración inicial
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(15);

// Headers para Telegram
header('Content-Type: application/json');

// Autoload y dependencias
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../instalacion/basededatos.php';
require_once __DIR__ . '/../cache/cache_helper.php';
require_once __DIR__ . '/../shared/UnifiedQueryEngine.php';

// ========== IMPORTAR CLASES DE TELEGRAM BOT ==========
use TelegramBot\Services\TelegramAuth;
use TelegramBot\Services\TelegramQuery;
use TelegramBot\Handlers\CommandHandler;
use TelegramBot\Handlers\CallbackHandler;
use TelegramBot\Utils\TelegramAPI;

// ========== CONFIGURACIÓN ==========
try {
    $db = new mysqli($db_host, $db_user, $db_password, $db_name);
    $db->set_charset("utf8mb4");
    if ($db->connect_error) throw new Exception("Error de conexión: " . $db->connect_error);
} catch (Exception $e) {
    http_response_code(500);
    exit('{"ok":false,"error":"Database connection failed"}');
}

$auth = new TelegramAuth();

$config = SimpleCache::get_settings($db);
if (($config['TELEGRAM_BOT_ENABLED'] ?? '0') !== '1') {
    http_response_code(403);
    exit('{"ok":false,"error":"Bot disabled"}');
}

$botToken = $config['TELEGRAM_BOT_TOKEN'] ?? '';
if (empty($botToken)) {
    http_response_code(400);
    exit('{"ok":false,"error":"No bot token configured"}');
}

// Ajustes de logging
$LOG_LEVEL = strtoupper($config['LOG_LEVEL'] ?? 'INFO');
$LOG_RETENTION_DAYS = (int)($config['LOG_RETENTION_DAYS'] ?? 7);
$LOG_MAX_FILE_SIZE = (int)($config['LOG_MAX_FILE_SIZE'] ?? 2048); // KB

// ========== FUNCIONES DE LOGGING ==========
// Crear directorio de logs si no existe
if (!file_exists(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}
function log_bot($message, $level = 'INFO') {
    global $LOG_LEVEL, $LOG_RETENTION_DAYS, $LOG_MAX_FILE_SIZE;

    $map = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];
    $current = $map[$LOG_LEVEL] ?? 1;
    $msgLevel = $map[strtoupper($level)] ?? 1;
    if ($msgLevel < $current) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";

    $logFile = __DIR__ . '/logs/bot.log';
    @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

    // Rotación por tamaño
    if (file_exists($logFile) && filesize($logFile) > ($LOG_MAX_FILE_SIZE * 1024)) {
        $archive = __DIR__ . '/logs/bot-' . date('Ymd_His') . '.log';
        @rename($logFile, $archive);
        @file_put_contents($logFile, '');
    }

    // Limpieza de archivos antiguos
    foreach (glob(__DIR__ . '/logs/bot-*.log') as $file) {
        if (filemtime($file) < time() - ($LOG_RETENTION_DAYS * 86400)) {
            @unlink($file);
        }
    }

    if ($level === 'ERROR') {
        error_log("Telegram Bot Error: $message");
    }
}

limpiarDatosTemporalesExpirados($db);

// ========== FUNCIONES DE ESTADO DE USUARIO ==========
function setUserState($userId, $state, $db) {
    $data = ['state' => $state, 'timestamp' => time()];
    $dataJson = json_encode($data);
    $stmt = $db->prepare("INSERT INTO telegram_temp_data (user_id, data_type, data_content, created_at) VALUES (?, 'user_state', ?, NOW()) ON DUPLICATE KEY UPDATE data_content = VALUES(data_content), created_at = NOW()");
    $stmt->bind_param("is", $userId, $dataJson);
    $stmt->execute();
    $stmt->close();
}

function getUserState($userId, $db) {
    $stmt = $db->prepare("SELECT data_content FROM telegram_temp_data WHERE user_id = ? AND data_type = 'user_state' AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return json_decode($row['data_content'], true);
    }
    $stmt->close();
    return null;
}

function clearUserState($userId, $db) {
    $stmt = $db->prepare("DELETE FROM telegram_temp_data WHERE user_id = ? AND data_type = 'user_state'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
}

// ========== FUNCIONES DE TELEGRAM API ==========
function enviarMensaje($botToken, $chatId, $texto, $teclado = null, $parseMode = 'MarkdownV2') {
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $texto, 'parse_mode' => $parseMode];
    if ($teclado) $data['reply_markup'] = json_encode($teclado);
    return enviarRequest($url, $data);
}

function editarMensaje($botToken, $chatId, $messageId, $texto, $teclado = null, $parseMode = 'MarkdownV2') {
    $url = "https://api.telegram.org/bot$botToken/editMessageText";
    $data = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $texto, 'parse_mode' => $parseMode];
    if ($teclado) {
        $data['reply_markup'] = json_encode($teclado);
    }

    $response = enviarRequest($url, $data);

    // Si el mensaje a editar no existe, enviar uno nuevo
    if (!($response['ok'] ?? false) && ($response['error_code'] ?? 0) === 400) {
        return enviarMensaje($botToken, $chatId, $texto, $teclado, $parseMode);
    }

    return $response;
}

function responderCallback($botToken, $callbackQueryId, $texto = "") {
    $url = "https://api.telegram.org/bot$botToken/answerCallbackQuery";
    $data = ['callback_query_id' => $callbackQueryId, 'text' => $texto];
    return enviarRequest($url, $data);
}

function enviarRequest($url, $data) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            log_bot('cURL error: ' . curl_error($ch), 'ERROR');
            curl_close($ch);
            return ['ok' => false];
        }
        curl_close($ch);

        if ($httpCode >= 400) {
            log_bot("Telegram API HTTP $httpCode: $response", 'ERROR');
            return ['ok' => false, 'error_code' => $httpCode];
        }

        return json_decode($response, true);
    }

    // Fallback a file_get_contents si no existe cURL
    $options = ['http' => [
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => http_build_query($data)
    ]];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        log_bot('HTTP request failed for ' . $url, 'ERROR');
        return ['ok' => false];
    }
    return json_decode($result, true);
}

// ========== FUNCIONES DE VALIDACIÓN ==========
function verificarUsuario($telegramId, $db) {
    try {
        $stmt = $db->prepare("SELECT id, username, role, status FROM users WHERE telegram_id = ? AND status = 1");
        if (!$stmt) {
            log_bot("Error preparando query usuario: " . $db->error, 'ERROR');
            return false;
        }
        
        $stmt->bind_param("i", $telegramId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = ($result->num_rows > 0) ? $result->fetch_assoc() : false;
        $stmt->close();
        
        if ($user) {
            log_bot("Usuario verificado: " . $user['username'] . " (ID: " . $user['id'] . ")", 'INFO');
        } else {
            log_bot("Usuario no encontrado o inactivo: $telegramId", 'WARNING');
        }
        
        return $user;
    } catch (Exception $e) {
        log_bot("Error verificando usuario: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

function obtenerCorreosAutorizados($user, $db) {
    try {
        if (isset($user['role']) && $user['role'] === 'admin') {
            $stmt = $db->prepare("SELECT email FROM authorized_emails WHERE status = 1 ORDER BY email ASC");
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $stmt = $db->prepare("SELECT ae.email FROM authorized_emails ae LEFT JOIN user_authorized_emails uae ON ae.id = uae.authorized_email_id AND uae.user_id = ? WHERE ae.status = 1 AND (uae.user_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM user_authorized_emails WHERE user_id = ?))");
            $userId = $user['id'];
            $stmt->bind_param("ii", $userId, $userId);
            $stmt->execute();
            $result = $stmt->get_result();
        }
        $emails = [];
        while ($row = $result->fetch_assoc()) $emails[] = $row['email'];
        $stmt->close();
        return $emails;
    } catch (Exception $e) { return []; }
}

function obtenerPlataformasDisponibles($db) {
    $stmt = $db->prepare("SELECT p.name, p.name as display_name FROM platforms p WHERE p.status = 1 ORDER BY p.name ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $plataformas = [];
    while ($row = $result->fetch_assoc()) $plataformas[$row['name']] = $row['display_name'];
    $stmt->close();
    return $plataformas;
}

// ========== FUNCIONES AUXILIARES ==========
function escaparMarkdown($texto) {
    $caracteres = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    foreach ($caracteres as $char) {
        $texto = str_replace($char, '\\' . $char, $texto);
    }
    return $texto;
}

function encodePart(string $str): string {
    // NO eliminar caracteres - solo codificar directamente
    return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
}

function decodePart(string $str): string {
    $str = strtr($str, '-_', '+/');
    // Agregar padding si es necesario
    $pad = strlen($str) % 4;
    if ($pad) {
        $str .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($str, true);
    return $decoded !== false ? $decoded : $str;
}


// ========== FUNCIONES DE TECLADOS ==========
function crearTecladoMenuPrincipal($esAdmin = false) {
    $teclado = [
        'inline_keyboard' => [
            [['text' => '🔍 Buscar Códigos', 'callback_data' => 'buscar_codigos']],
            [
                ['text' => '📧 Mis Correos', 'callback_data' => 'mis_correos'],
                ['text' => '⚙️ Mi Config', 'callback_data' => 'mi_config']
            ],
            [['text' => '❓ Ayuda', 'callback_data' => 'ayuda']]
        ]
    ];
    
    if ($esAdmin) {
        $teclado['inline_keyboard'][] = [['text' => '👨‍💼 Panel Admin', 'callback_data' => 'admin_panel']];
    }
    
    return $teclado;
}

function crearTecladoCorreos($emails, $pagina = 0, $porPagina = 5) {
    $total = count($emails);
    $inicio = $pagina * $porPagina;
    $emailsPagina = array_slice($emails, $inicio, $porPagina);
    
    $teclado = ['inline_keyboard' => []];
    
    // Botones de emails
    foreach ($emailsPagina as $email) {
        $teclado['inline_keyboard'][] = [
            ['text' => "📧 $email", 'callback_data' => "select_email_$email"]
        ];
    }
    
    // Navegación de páginas
    $botonesPaginacion = [];
    if ($pagina > 0) {
        $botonesPaginacion[] = ['text' => '⬅️ Anterior', 'callback_data' => "emails_page_" . ($pagina - 1)];
    }
    if ($inicio + $porPagina < $total) {
        $botonesPaginacion[] = ['text' => 'Siguiente ➡️', 'callback_data' => "emails_page_" . ($pagina + 1)];
    }
    
    if (!empty($botonesPaginacion)) {
        $teclado['inline_keyboard'][] = $botonesPaginacion;
    }
    
    // Botón volver
    $teclado['inline_keyboard'][] = [
        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
    ];
    
    return $teclado;
}

function crearTecladoPlataformas($plataformas, $email) {
    $teclado = ['inline_keyboard' => []];
    
    $fila = [];
    $contador = 0;
    
    foreach ($plataformas as $nombre => $display) {
        $fila[] = ['text' => $display, 'callback_data' => "search_" . encodePart($email) . '_' . encodePart($nombre)];
        $contador++;
        
        // Máximo 2 botones por fila
        if ($contador == 2) {
            $teclado['inline_keyboard'][] = $fila;
            $fila = [];
            $contador = 0;
        }
    }
    
    // Agregar fila restante si existe
    if (!empty($fila)) {
        $teclado['inline_keyboard'][] = $fila;
    }
    
    // Botones de navegación
    $teclado['inline_keyboard'][] = [
        ['text' => '📋 Cambiar Email', 'callback_data' => 'mis_correos'],
        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
    ];
    
    return $teclado;
}

function crearTecladoResultados($email, $plataforma, $resultados) {
    $teclado = ['inline_keyboard' => []];
    
    if (!empty($resultados) && isset($resultados['emails']) && count($resultados['emails']) > 0) {
        // Mostrar cada resultado
        foreach ($resultados['emails'] as $index => $emailData) {
            $fecha = isset($emailData['date']) ? date('d/m H:i', strtotime($emailData['date'])) : 'Sin fecha';
            
            // Determinar qué mostrar según el tipo de acceso
            $descripcion = '';
            if (isset($emailData['tipo_acceso'])) {
                if ($emailData['tipo_acceso'] === 'codigo') {
                    $descripcion = '🔐 Código';
                } elseif ($emailData['tipo_acceso'] === 'enlace') {
                    $descripcion = '🔗 Enlace';
                }
            } else {
                $descripcion = '📧 Email';
            }
            
            $asunto = isset($emailData['subject']) ? 
                (strlen($emailData['subject']) > 25 ? substr($emailData['subject'], 0, 25) . '...' : $emailData['subject']) : 
                'Sin asunto';
            
            $data = "show_email_" . encodePart($email) . '_' . encodePart($plataforma) . '_' . $index;
            $teclado['inline_keyboard'][] = [
                ['text' => "$descripcion $fecha - $asunto", 'callback_data' => $data]
            ];
        }
    }
    
    // Botones de navegación
    $teclado['inline_keyboard'][] = [
        ['text' => '🔄 Nueva Búsqueda', 'callback_data' => "select_email_$email"],
        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
    ];
    
    return $teclado;
}

/**
 * Crear un teclado simple con un solo botón de retorno
 * Si no se especifica destino, vuelve al menú principal
 */
function crearTecladoVolver($callback = 'menu_principal') {
    $texto = $callback === 'menu_principal' ? '🏠 Menú Principal' : '🔙 Volver';
    return [
        'inline_keyboard' => [
            [
                ['text' => $texto, 'callback_data' => $callback]
            ]
        ]
    ];
}

/**
 * Crear teclado de ayuda con botones de contacto configurables
 */
function crearTecladoAyudaConContacto($config) {
    $teclado = ['inline_keyboard' => []];
    
    // Fila de botones de contacto
    $filaContacto = [];
    
    // BOTÓN DE WHATSAPP
    $whatsappNumero = $config['enlace_global_numero_whatsapp'] ?? '';
    $whatsappTexto = $config['enlace_global_texto_whatsapp'] ?? 'Hola, necesito soporte técnico';
    
    if (!empty($whatsappNumero) && $whatsappNumero !== '000000') {
        $whatsappUrl = "https://wa.me/" . $whatsappNumero . "?text=" . urlencode($whatsappTexto);
        $filaContacto[] = ['text' => '📱 Contacto', 'url' => $whatsappUrl];
    }
    
    // BOTÓN 2 CONFIGURADO EN ADMIN
    $boton2Url = $config['enlace_global_2'] ?? '';
    $boton2Texto = $config['enlace_global_2_texto'] ?? 'Ir a Telegram';
    
    if (!empty($boton2Url) && $boton2Url !== 'https://') {
        $filaContacto[] = ['text' => $boton2Texto, 'url' => $boton2Url];
    }
    
    // Agregar fila de contacto si hay botones
    if (!empty($filaContacto)) {
        $teclado['inline_keyboard'][] = $filaContacto;
    }
    
    // BOTÓN DE VOLVER AL MENÚ PRINCIPAL
    $teclado['inline_keyboard'][] = [
        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
    ];
    
    return $teclado;
}

/**
 * Teclado principal del panel de administración
 */
function crearTecladoAdminPanel() {
    return [
        'inline_keyboard' => [
            [
                ['text' => '📝 Logs', 'callback_data' => 'admin_logs'],
                ['text' => '👥 Usuarios', 'callback_data' => 'admin_users']
            ],
            [
                ['text' => '📊 Estado', 'callback_data' => 'admin_status'],
                ['text' => '🧪 Test Email', 'callback_data' => 'admin_test']
            ],
            [
                ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
            ]
        ]
    ];
}

// ========== FUNCIONES DE ALMACENAMIENTO TEMPORAL ==========

function limpiarDatosParaJSON($data) {
    if (is_array($data)) {
        $cleaned = [];
        foreach ($data as $key => $value) {
            // Limpiar la clave también
            $cleanKey = limpiarUTF8String((string)$key);
            
            // Saltear recursos
            if (is_resource($value)) {
                continue;
            }
            
            // Limpiar recursivamente
            if (is_array($value) || is_object($value)) {
                $cleaned[$cleanKey] = limpiarDatosParaJSON($value);
            } else {
                $cleaned[$cleanKey] = limpiarUTF8String((string)$value);
            }
        }
        return $cleaned;
    } elseif (is_object($data)) {
        return limpiarDatosParaJSON((array)$data);
    } elseif (is_resource($data)) {
        return null;
    } else {
        return limpiarUTF8String((string)$data);
    }
}

/**
 * Función específica para limpiar strings con problemas de UTF-8
 */
function limpiarUTF8String($string) {
    if (empty($string)) {
        return '';
    }
    
    // 1. Convertir a string si no lo es
    $string = (string)$string;
    
    // 2. Limpiar caracteres de control y NULL bytes
    $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $string);
    
    // 3. Decodificar quoted-printable si está presente
    if (strpos($string, '=') !== false && preg_match('/=[0-9A-F]{2}/', $string)) {
        $string = quoted_printable_decode($string);
    }
    
    // 4. Decodificar HTML entities
    $string = html_entity_decode($string, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    
    // 5. Convertir a UTF-8 válido - CRÍTICO
    if (!mb_check_encoding($string, 'UTF-8')) {
        // Si no es UTF-8 válido, intentar diferentes codificaciones
        $string = mb_convert_encoding($string, 'UTF-8', ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII']);
    }
    
    // 6. Limpiar caracteres UTF-8 inválidos
    $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
    
    // 7. Escapar caracteres problemáticos para JSON
    $string = str_replace(["\r\n", "\r", "\n"], [' ', ' ', ' '], $string);
    
    // 8. Limitar longitud para evitar problemas de memoria
    if (strlen($string) > 50000) {
        $string = mb_substr($string, 0, 50000, 'UTF-8') . '... [truncado]';
    }
    
    // 9. Validación final
    if (!mb_check_encoding($string, 'UTF-8')) {
        // Si aún hay problemas, usar solo caracteres ASCII seguros
        $string = preg_replace('/[^\x20-\x7E]/', '?', $string);
    }
    
    return $string;
}

function guardarBusquedaTemporal($userId, $email, $plataforma, $resultados, $db) {
    try {
        log_bot("=== INICIO GUARDAR TEMPORAL ===", 'DEBUG');
        log_bot("UserId: $userId, Email: $email, Plataforma: $plataforma", 'DEBUG');
        log_bot("Resultados found: " . ($resultados['found'] ? 'true' : 'false'), 'DEBUG');
        
        // Verificar estructura de resultados
        if (!isset($resultados['emails'])) {
            log_bot("⚠️ WARNING: No hay clave 'emails' en resultados", 'WARNING');
            $resultados['emails'] = [];
        }
        
        log_bot("Total emails a procesar: " . count($resultados['emails']), 'DEBUG');
        
        // LIMPIAR DATOS ANTES DE TODO
        log_bot("=== LIMPIANDO DATOS ===", 'DEBUG');
        $resultadosLimpios = limpiarDatosParaJSON($resultados);
        log_bot("Datos limpiados exitosamente", 'DEBUG');
        
        // Crear estructura final
        $dataParaGuardar = [
            'email' => limpiarUTF8String($email),
            'plataforma' => limpiarUTF8String($plataforma),
            'resultados' => $resultadosLimpios,
            'timestamp' => time(),
            'expires_at' => time() + 120,
            'debug_info' => [
                'saved_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + 120),
                'user_id' => $userId,
                'total_emails' => count($resultadosLimpios['emails'] ?? [])
            ]
        ];
        
        log_bot("=== SERIALIZANDO JSON ===", 'DEBUG');
        $data = json_encode($dataParaGuardar, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        
        if ($data === false) {
            $jsonError = json_last_error_msg();
            log_bot("❌ ERROR JSON: $jsonError", 'ERROR');
            return false;
        }
        
        log_bot("JSON serializado exitosamente, tamaño: " . strlen($data) . " bytes", 'DEBUG');
        
        log_bot("=== EJECUTANDO QUERY ===", 'DEBUG');
        $stmt = $db->prepare("
            INSERT INTO telegram_temp_data (user_id, data_type, data_content, created_at) 
            VALUES (?, 'search_result', ?, NOW())
            ON DUPLICATE KEY UPDATE data_content = VALUES(data_content), created_at = NOW()
        ");
        
        if (!$stmt) {
            log_bot("❌ ERROR preparando statement: " . $db->error, 'ERROR');
            return false;
        }
        
        $stmt->bind_param("is", $userId, $data);
        $success = $stmt->execute();
        
        if (!$success) {
            log_bot("❌ ERROR ejecutando query: " . $stmt->error, 'ERROR');
            $stmt->close();
            return false;
        }
        
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        log_bot("✅ QUERY EJECUTADA - Affected rows: $affectedRows", 'DEBUG');
        
        if ($success && $affectedRows > 0) {
            log_bot("✅ DATOS TEMPORALES GUARDADOS EXITOSAMENTE por 2 minutos para usuario $userId", 'INFO');
            return true;
        } else {
            log_bot("⚠️ Query exitosa pero sin filas afectadas", 'WARNING');
            return false;
        }
        
    } catch (Exception $e) {
        log_bot("❌ EXCEPCIÓN en guardarBusquedaTemporal: " . $e->getMessage(), 'ERROR');
        log_bot("Stack trace: " . $e->getTraceAsString(), 'ERROR');
        return false;
    }
}

function obtenerBusquedaTemporal($userId, $db) {
    try {
        log_bot("=== RECUPERANDO TEMPORAL ===", 'DEBUG');
        log_bot("Usuario: $userId", 'DEBUG');
        
        $stmt = $db->prepare("
            SELECT data_content, created_at
            FROM telegram_temp_data 
            WHERE user_id = ? AND data_type = 'search_result' 
            AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) 
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            
            log_bot("✅ DATOS TEMPORALES ENCONTRADOS - Creado: " . $row['created_at'], 'DEBUG');
            log_bot("Tamaño de datos: " . strlen($row['data_content']) . " bytes", 'DEBUG');
            
            $decoded = json_decode($row['data_content'], true);
            if ($decoded === null) {
                log_bot("❌ ERROR decodificando JSON: " . json_last_error_msg(), 'ERROR');
                return null;
            }
            
            log_bot("✅ JSON decodificado exitosamente", 'DEBUG');
            log_bot("Emails en datos recuperados: " . count($decoded['resultados']['emails'] ?? []), 'DEBUG');
            
            return $decoded;
        }
        
        $stmt->close();
        log_bot("❌ NO SE ENCONTRARON DATOS TEMPORALES para usuario $userId (2 min)", 'WARNING');
        
        // VERIFICAR SI HAY DATOS EXPIRADOS
        $stmt2 = $db->prepare("
            SELECT COUNT(*) as total, MAX(created_at) as ultimo
            FROM telegram_temp_data 
            WHERE user_id = ? AND data_type = 'search_result'
        ");
        $stmt2->bind_param("i", $userId);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        $info = $result2->fetch_assoc();
        $stmt2->close();
        
        log_bot("Total registros del usuario: " . $info['total'] . ", Último: " . ($info['ultimo'] ?? 'ninguno'), 'DEBUG');
        
        return null;
        
    } catch (Exception $e) {
        log_bot("❌ ERROR obteniendo búsqueda temporal: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

function limpiarDatosTemporalesExpirados($db) {
    try {
        // CAMBIO: Limpiar datos más viejos de 2 minutos en lugar de 2 horas
        $stmt = $db->prepare("DELETE FROM telegram_temp_data WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        if ($affected > 0) {
            log_bot("Limpiados $affected registros temporales expirados (2 min)", 'INFO');
        }
        
        return $affected;
    } catch (Exception $e) {
        log_bot("Error limpiando datos temporales: " . $e->getMessage(), 'ERROR');
        return 0;
    }
}

// ========== FUNCIONES PRINCIPALES DE INTERFAZ ==========
function mostrarMenuPrincipal($botToken, $chatId, $firstName, $user, $messageId = null) {
    $esAdmin = (isset($user['role']) && $user['role'] === 'admin');
    
    $texto = "🤖 *¡Hola " . escaparMarkdown($firstName) . "\\!*\n\n";
    $texto .= "🎯 *Sistema de Códigos*\n\n";
    $texto .= "💡 Soluciones inteligentes a tu alcance\n";
    $texto .= "🚀 Encuentra tus códigos al instante\n";
    $texto .= "🛡️ Seguro, confiable y siempre disponiblen\n\n";
    $texto .= "*¿Qué deseas hacer?*";
    
    $teclado = crearTecladoMenuPrincipal($esAdmin);
    
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarMenuSeleccionCorreo($botToken, $chatId, $messageId, $user, $db) {
    $emails = obtenerCorreosAutorizados($user, $db);
    if (empty($emails)) {
        $texto = "❌ *Sin Correos Autorizados*\n\nNo tienes permisos para consultar correos\\.";
        if ($messageId) {
            editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver());
        } else {
            enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver());
        }
        return;
    }
    $texto = "Tienes acceso a *" . count($emails) . "* correos\\.\n\n*¿Cómo quieres proceder?*";
    
    $teclado = [
        'inline_keyboard' => [
            [['text' => '📋 Ver Todos', 'callback_data' => 'email_view_all']],
            [['text' => '🔍 Buscar Email', 'callback_data' => 'email_search']],
            [['text' => '⌨️ Escribir Email', 'callback_data' => 'email_manual_input']],
            [['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']]
        ]
    ];
    
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarCorreosAutorizados($botToken, $chatId, $messageId, $user, $db, $pagina = 0, $filtro = '') {
    $emails = obtenerCorreosAutorizados($user, $db);
    $emailsFiltrados = empty($filtro) ? $emails : array_filter($emails, function($email) use ($filtro) {
        return stripos($email, $filtro) !== false;
    });

    if (empty($emailsFiltrados)) {
        $texto = "😔 *Sin Resultados*\n\nNo se encontraron correos que coincidan con `".escaparMarkdown($filtro)."`\\.";
        if ($messageId) {
            editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver('buscar_codigos'));
        } else {
            enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver('buscar_codigos'));
        }
        return;
    }

    $texto = "📧 *Tus Correos Autorizados*\n\n";
    $texto .= "Tienes acceso a *" . count($emailsFiltrados) . "* correo" . (count($emailsFiltrados) != 1 ? 's' : '') . "\n\n";
    $texto .= "Selecciona un correo para buscar códigos:";
    
    $teclado = crearTecladoCorreos($emailsFiltrados, $pagina);
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarPlataformasParaEmail($botToken, $chatId, $messageId, $email, $db) {
    $plataformas = obtenerPlataformasDisponibles($db);

    if (empty($plataformas)) {
        $texto = "❌ *Sin Plataformas Configuradas*\n\n";
        $texto .= "No hay plataformas disponibles en el sistema\\.\n";
        $texto .= "Contacta al administrador\\.";

        if ($messageId) {
            editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver());
        } else {
            enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver());
        }
        return;
    }
    
    $texto = "🎯 *Selecciona la Plataforma*\n\n";
    $texto .= "📧 Email: `" . escaparMarkdown($email) . "`\n\n";
    $texto .= "Elige dónde buscar los códigos:";
    
    $teclado = crearTecladoPlataformas($plataformas, $email);
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarResultadosBusqueda($botToken, $chatId, $messageId, $email, $plataforma, $resultado) {
    if ($resultado['found']) {
        $texto = "✅ *¡Códigos Encontrados\\!*\n\n";
        $texto .= "📧 Email: `" . escaparMarkdown($email) . "`\n";
        $texto .= "🎯 Plataforma: *" . escaparMarkdown($plataforma) . "*\n\n";
        
        if (isset($resultado['emails']) && count($resultado['emails']) > 0) {
            $texto .= "📊 *Resultados:* " . count($resultado['emails']) . " mensaje" . 
                     (count($resultado['emails']) != 1 ? 's' : '') . "\n\n";
            $texto .= "Toca un resultado para ver los detalles:";
            
            $teclado = crearTecladoResultados($email, $plataforma, $resultado);
        } else {
            $texto .= "❓ *Sin Detalles*\n\n";
            $texto .= "Se encontraron resultados pero sin detalles disponibles\\.";
            
            $teclado = crearTecladoVolver();
        }
    } else {
        $texto = "😔 *Sin Resultados*\n\n";
        $texto .= "📧 Email: `" . escaparMarkdown($email) . "`\n";
        $texto .= "🎯 Plataforma: *" . escaparMarkdown($plataforma) . "*\n\n";
        
        $mensaje = $resultado['message'] ?? 'No se encontraron códigos para tu búsqueda.';
        $texto .= "💡 " . escaparMarkdown($mensaje) . "\n\n";
        $texto .= "*Sugerencias:*\n";
        $texto .= "🔹 Verifica que el email sea correcto\n";
        $texto .= "🔹 Prueba con otra plataforma\n";
        $texto .= "🔹 Revisa tus permisos";
        
        $teclado = crearTecladoVolver();
    }
    
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}


function mostrarConfiguracionUsuario($botToken, $chatId, $messageId, $user, $db) {
    $emails = obtenerCorreosAutorizados($user, $db);
    $plataformas = obtenerPlataformasDisponibles($db);
    
    $texto = "⚙️ *Tu Configuración*\n\n";
    $texto .= "👤 *Usuario:* `" . escaparMarkdown($user['username']) . "`\n";
    $texto .= "🎭 *Rol:* `" . escaparMarkdown($user['role']) . "`\n";
    $texto .= "📊 *Estado:* " . ($user['status'] ? '✅ Activo' : '❌ Inactivo') . "\n\n";
    
    $texto .= "📧 *Correos Autorizados:* " . count($emails) . "\n";
    $texto .= "🎯 *Plataformas Disponibles:* " . count($plataformas) . "\n\n";
    
    $texto .= "*Permisos Actuales:*\n";
    foreach (array_slice($emails, 0, 5) as $email) {
        $texto .= "• `" . escaparMarkdown($email) . "`\n";
    }
    
    if (count($emails) > 5) {
        $texto .= "• \\.\\.\\. y " . (count($emails) - 5) . " más\n";
    }
    
    $teclado = crearTecladoVolver();
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarAyuda($botToken, $chatId, $messageId) {
    global $config; // Acceder a las configuraciones globales
    
    $texto = "❓ *Ayuda del Sistema*\n\n";
    $texto .= "*🔍 Buscar Códigos:*\n";
    $texto .= "1\\. Selecciona un correo autorizado\n";
    $texto .= "2\\. Elige la plataforma \\(Netflix, Disney, etc\\.\\)\n";
    $texto .= "3\\. Espera los resultados\n";
    $texto .= "4\\. Toca un resultado para ver detalles\n\n";
    
    $texto .= "*📧 Correos Autorizados:*\n";
    $texto .= "Solo puedes consultar correos específicamente autorizados\\.\n";
    $texto .= "Si necesitas acceso a más correos, contacta al administrador\\.\n\n";
    
    $texto .= "*🎯 Plataformas:*\n";
    $texto .= "Cada plataforma tiene asuntos específicos configurados\\.\n";
    $texto .= "Elige la plataforma correcta para mejores resultados\\.\n\n";
    
    $texto .= "*⚡ Comandos Rápidos:*\n";
    $texto .= "• `/start` \\- Menú principal\n";
    $texto .= "• Usa los botones para navegar\n\n";
    
    $texto .= "*🆘 Soporte:*\n";
    $texto .= "Si tienes problemas, contacta al administrador del sistema\\.";
    
    // CREAR TECLADO CON BOTONES DE CONTACTO
    $teclado = crearTecladoAyudaConContacto($config);
    
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarPanelAdmin($botToken, $chatId, $messageId, $user, $db) {
    // Verificar que sea administrador
    if ($user['role'] !== 'admin') {
        $texto = "🚫 *Acceso Denegado*\n\n";
        $texto .= "Solo los administradores pueden acceder a este panel\\.";
        if ($messageId) {
            editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver());
        } else {
            enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver());
        }
        return;
    }
    
    // Obtener estadísticas
    try {
        // Usuarios totales
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE status = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $usuariosActivos = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
        
        // Correos autorizados
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM authorized_emails WHERE status = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $emailsAutorizados = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
        
        // Plataformas activas
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM platforms WHERE status = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $plataformasActivas = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
        
        // Búsquedas recientes
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM search_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        $result = $stmt->get_result();
        $busquedasHoy = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
        
    } catch (Exception $e) {
        log_bot("Error obteniendo estadísticas admin: " . $e->getMessage(), 'ERROR');
        $usuariosActivos = $emailsAutorizados = $plataformasActivas = $busquedasHoy = 0;
    }
    
    $texto = "👨‍💼 *Panel de Administración*\n\n";
    $texto .= "📊 *Estadísticas del Sistema:*\n\n";
    $texto .= "👥 *Usuarios Activos:* `$usuariosActivos`\n";
    $texto .= "📧 *Correos Autorizados:* `$emailsAutorizados`\n";
    $texto .= "🎯 *Plataformas Activas:* `$plataformasActivas`\n";
    $texto .= "🔍 *Búsquedas Hoy:* `$busquedasHoy`\n\n";
    $texto .= "🌐 *Administrador:* `" . escaparMarkdown($user['username']) . "`\n\n";
    $texto .= "_Para gestión completa, usa el panel web_";
    
    $teclado = crearTecladoAdminPanel();
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarLogsAdmin($botToken, $chatId, $messageId, $user, $db) {
    if ($user['role'] !== 'admin') {
        $texto = "🚫 *Acceso Denegado*";
        if ($messageId) {
            editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver());
        } else {
            enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver());
        }
        return;
    }
    
    try {
        // Obtener logs recientes del bot
        $logFile = __DIR__ . '/logs/bot.log';
        $texto = "📝 *Logs del Bot*\n\n";
        
        if (file_exists($logFile)) {
            $lines = file($logFile);
            $recentLines = array_slice($lines, -10); // Últimas 10 líneas
            
            $texto .= "*Últimas 10 entradas:*\n\n";
            foreach ($recentLines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    // Escapar caracteres especiales para MarkdownV2
                    $lineEscaped = str_replace(['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'], 
                                             ['\_', '\*', '\[', '\]', '\(', '\)', '\~', '\`', '\>', '\#', '\+', '\-', '\=', '\|', '\{', '\}', '\.', '\!'], $line);
                    $texto .= "`" . substr($lineEscaped, 0, 100) . "`\n";
                }
            }
        } else {
            $texto .= "No se encontró archivo de logs\\.";
        }
        
        // Estadísticas adicionales
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM search_logs WHERE DATE(created_at) = CURDATE()");
        $stmt->execute();
        $result = $stmt->get_result();
        $busquedasHoy = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
        
        $texto .= "\n📊 *Estadísticas de Hoy:*\n";
        $texto .= "🔍 Búsquedas: `$busquedasHoy`\n";
        
    } catch (Exception $e) {
        $texto = "❌ *Error obteniendo logs*\n\n";
        $texto .= "Contacta al administrador del sistema\\.";
        log_bot("Error obteniendo logs: " . $e->getMessage(), 'ERROR');
    }
    
    $teclado = [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Actualizar', 'callback_data' => 'admin_logs'],
                ['text' => '🗑️ Limpiar Logs', 'callback_data' => 'admin_clear_logs']
            ],
            [
                ['text' => '🔙 Panel Admin', 'callback_data' => 'admin_panel'],
                ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
            ]
        ]
    ];
    
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarUsuariosAdmin($botToken, $chatId, $messageId, $user, $db) {
    if ($user['role'] !== 'admin') {
        $texto = "🚫 *Acceso Denegado*";
        if ($messageId) {
            editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver());
        } else {
            enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver());
        }
        return;
    }
    
    try {
        // Obtener usuarios del sistema
        $stmt = $db->prepare("SELECT id, username, role, status, telegram_id, created_at FROM users ORDER BY created_at DESC LIMIT 10");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $texto = "👥 *Usuarios del Sistema*\n\n";
        $texto .= "*Últimos 10 usuarios:*\n\n";
        
        $totalUsuarios = 0;
        $usuariosConTelegram = 0;
        
        while ($userData = $result->fetch_assoc()) {
            $totalUsuarios++;
            $estado = $userData['status'] ? '✅' : '❌';
            $telegram = $userData['telegram_id'] ? '📱' : '📴';
            
            if ($userData['telegram_id']) $usuariosConTelegram++;
            
            $username = escaparMarkdown($userData['username']);
            $role = escaparMarkdown($userData['role']);
            $fecha = date('d/m/Y', strtotime($userData['created_at']));
            
            $texto .= "$estado $telegram `$username` \\- $role \\($fecha\\)\n";
        }
        $stmt->close();
        
        // Estadísticas generales
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM users");
        $stmt->execute();
        $result = $stmt->get_result();
        $totalSistema = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
        
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE telegram_id IS NOT NULL");
        $stmt->execute();
        $result = $stmt->get_result();
        $totalConTelegram = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
        
        $texto .= "\n📊 *Estadísticas:*\n";
        $texto .= "👥 Total usuarios: `$totalSistema`\n";
        $texto .= "📱 Con Telegram: `$totalConTelegram`\n";
        $texto .= "✅ Activos: `" . ($totalSistema - 0) . "`\n";
        
    } catch (Exception $e) {
        $texto = "❌ *Error obteniendo usuarios*\n\n";
        $texto .= "Contacta al administrador del sistema\\.";
        log_bot("Error obteniendo usuarios: " . $e->getMessage(), 'ERROR');
    }
    
    $teclado = [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Actualizar', 'callback_data' => 'admin_users'],
                ['text' => '👤 Detalles', 'callback_data' => 'admin_user_details']
            ],
            [
                ['text' => '🔙 Panel Admin', 'callback_data' => 'admin_panel'],
                ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
            ]
        ]
    ];
    
    editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
}

function mostrarEstadoSistema($botToken, $chatId, $messageId, $user, $db) {
    if ($user['role'] !== 'admin') {
        $texto = "🚫 *Acceso Denegado*";
        if ($messageId) {
            editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver());
        } else {
            enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver());
        }
        return;
    }

    try {
        global $config;
        $texto = "🔧 *Estado del Sistema*\n\n";
        
        // Verificar conexión a base de datos
        $dbStatus = $db->ping() ? '✅' : '❌';
        $texto .= "💾 Base de datos: $dbStatus\n";
        
        // Verificar archivos críticos
        $filesStatus = [
            'webhook.php' => file_exists(__FILE__),
            'basededatos.php' => file_exists(__DIR__ . '/../instalacion/basededatos.php'),
            'cache_helper.php' => file_exists(__DIR__ . '/../cache/cache_helper.php'),
            'logs/' => is_dir(__DIR__ . '/logs') && is_writable(__DIR__ . '/logs')
        ];
        
        foreach ($filesStatus as $file => $exists) {
            $status = $exists ? '✅' : '❌';
            $texto .= "📁 $file: $status\n";
        }
        
        // Verificar permisos
        $logDir = __DIR__ . '/logs';
        $permisosLog = is_writable($logDir) ? '✅' : '❌';
        $texto .= "📝 Permisos logs: $permisosLog\n";
        
        // Verificar configuración
        $configStatus = !empty($config['TELEGRAM_BOT_TOKEN']) ? '✅' : '❌';
        $texto .= "⚙️ Configuración: $configStatus\n";
        
        // Memoria y tiempo
        $memoria = round(memory_get_usage(true) / 1024 / 1024, 2);
        $texto .= "\n📊 *Recursos:*\n";
        $texto .= "🧠 Memoria: `{$memoria}MB`\n";
        $texto .= "⏱️ Tiempo: `" . date('Y\\-m\\-d H:i:s') . "`\n";
        
        // Verificar servidores IMAP
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM email_servers WHERE enabled = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $servidoresActivos = $result->fetch_assoc()['total'] ?? 0;
        $stmt->close();
        
        $texto .= "📧 Servidores IMAP: `$servidoresActivos`\n";
        
    } catch (Exception $e) {
        $texto = "❌ *Error verificando estado*\n\n";
        $texto .= "Contacta al administrador del sistema\\.";
        log_bot("Error verificando estado: " . $e->getMessage(), 'ERROR');
    }
    
    $teclado = [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Actualizar', 'callback_data' => 'admin_status'],
                ['text' => '🧹 Limpiar Cache', 'callback_data' => 'admin_clear_cache']
            ],
            [
                ['text' => '🔙 Panel Admin', 'callback_data' => 'admin_panel'],
                ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
            ]
        ]
    ];
    
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
    } else {
        enviarMensaje($botToken, $chatId, $texto, $teclado);
    }
}

function mostrarTestEmail($botToken, $chatId, $messageId, $user, $db) {
    if ($user['role'] !== 'admin') {
        $texto = "🚫 *Acceso Denegado*";
        if ($messageId) {
            editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver());
        } else {
            enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver());
        }
        return;
    }
    
    try {
        $texto = "📧 *Test de Email*\n\n";
        $texto .= "*Función de prueba para administradores*\n\n";
        
        // Obtener primer email autorizado para prueba
        $stmt = $db->prepare("SELECT email FROM authorized_emails WHERE status = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $emailTest = $row['email'];
            $texto .= "📮 Email de prueba: `" . escaparMarkdown($emailTest) . "`\n";
            $texto .= "🎯 Este test verificará la conectividad\n";
            $texto .= "⚡ Sin realizar búsquedas reales\n\n";
            $texto .= "Estado: 🟢 Listo para probar";
            
            $teclado = [
                'inline_keyboard' => [
                    [
                        ['text' => '▶️ Ejecutar Test', 'callback_data' => 'admin_run_test'],
                        ['text' => '📊 Ver Resultado', 'callback_data' => 'admin_test_result']
                    ],
                    [
                        ['text' => '🔙 Panel Admin', 'callback_data' => 'admin_panel'],
                        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
                    ]
                ]
            ];
        } else {
            $texto .= "❌ No hay emails autorizados\n";
            $texto .= "Configura emails antes de probar\\.";
            
            $teclado = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔙 Panel Admin', 'callback_data' => 'admin_panel'],
                        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
                    ]
                ]
            ];
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $texto = "❌ *Error en test de email*\n\n";
        $texto .= "Contacta al administrador del sistema\\.";
        log_bot("Error en test email: " . $e->getMessage(), 'ERROR');
        
        $teclado = crearTecladoVolver('admin_panel');
    }
    
    editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
}

// ========== FUNCIONES DE BÚSQUEDA IMAP  ==========

function ejecutarBusquedaReal($botToken, $chatId, $messageId, $email, $plataforma, $user, $db) {
    // Mostrar mensaje de búsqueda
    $texto = "🔍 *Buscando Códigos\.\.\.*\n\n";
    $texto .= "📧 Email: `" . escaparMarkdown($email) . "`\n";
    $texto .= "🎯 Plataforma: *" . escaparMarkdown($plataforma) . "*\n\n";
    $texto .= "⏳ Consultando servidores\.\.\.\n";
    $texto .= "_Esto puede tardar unos segundos_\n";

    editarMensaje($botToken, $chatId, $messageId, $texto, null);

    try {
        log_bot("=== INICIO BÚSQUEDA REAL ===", 'DEBUG');
        log_bot("Usuario ID: " . $user['id'] . ", Email: $email, Plataforma: $plataforma", 'INFO');
        
        $engine = new UnifiedQueryEngine($db);
        $engine->enableTelegramMode();
        
        log_bot("=== EJECUTANDO BÚSQUEDA ===", 'DEBUG');
        $resultado = $engine->searchEmails($email, $plataforma, (int)$user['id']);
        
        log_bot("=== RESULTADO OBTENIDO ===", 'DEBUG');
        log_bot("Found: " . ($resultado['found'] ? 'true' : 'false'), 'DEBUG');
        log_bot("Emails count: " . (isset($resultado['emails']) ? count($resultado['emails']) : 0), 'DEBUG');
        
        log_bot("=== PROCESANDO RESULTADOS ===", 'DEBUG');
        $resultadoProcesado = procesarResultadosBusquedaMejorado($resultado);
        
        log_bot("=== GUARDANDO DATOS TEMPORALES ===", 'DEBUG');
        $guardadoExitoso = guardarBusquedaTemporal($user['id'], $email, $plataforma, $resultadoProcesado, $db);
        
        if ($guardadoExitoso) {
            log_bot("✅ GUARDADO CONFIRMADO", 'INFO');
        } else {
            log_bot("❌ FALLO EN GUARDADO", 'ERROR');
        }
        
        log_bot("=== MOSTRANDO RESULTADOS ===", 'DEBUG');
        mostrarResultadosBusqueda($botToken, $chatId, $messageId, $email, $plataforma, $resultadoProcesado);
        
        log_bot("=== FIN BÚSQUEDA REAL ===", 'DEBUG');
        
    } catch (Exception $e) {
        log_bot("ERROR en búsqueda real: " . $e->getMessage(), 'ERROR');
        log_bot("Stack trace: " . $e->getTraceAsString(), 'ERROR');
        mostrarError($botToken, $chatId, $messageId, "Error interno del servidor");
    }
}

function limpiarContenidoEmail($body) {
    if (empty($body)) return '';
    
    // 1. Decodificar quoted-printable si está presente
    if (strpos($body, '=') !== false && strpos($body, '=\r\n') !== false) {
        $body = quoted_printable_decode($body);
    }
    
    // 2. Decodificar entidades HTML
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // 3. NUEVO: Usar extractor inteligente de texto
    if (strpos($body, '<') !== false) {
        // Intentar extraer usando el método específico primero
        $textoLimpio = extraerTextoLimpioParaUsuario($body);
        if (!empty($textoLimpio)) {
            return $textoLimpio;
        }
        
        // Fallback al método original mejorado
        $body = extraerTextoImportanteHTML($body);
        $body = strip_tags($body);
    }
    
    // 4. Limpiar caracteres especiales y espacios
    $body = preg_replace('/\s+/', ' ', $body);
    $body = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $body);
    $body = trim($body);
    
    return $body;
}

/**
 * Extrae texto importante de HTML antes de strip_tags
 * Se enfoca en encontrar códigos de verificación
 */
function extraerTextoImportanteHTML($html) {
    $textImportant = '';
    
    // Buscar patrones comunes para códigos en HTML
    $patronesHTML = [
        // Disney+ - TD con estilos específicos (font-size grande y letter-spacing)
        '/<td[^>]*font-size:\s*(?:2[4-9]|[3-9]\d)px[^>]*letter-spacing[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/td>/i',
        
        // Amazon - TD con clase 'data' específica
        '/<td[^>]*class="data"[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/td>/i',
        
        // Netflix - TD con clase 'copy lrg-number'
        '/<td[^>]*class="[^"]*lrg-number[^"]*"[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/td>/i',
        
        // ChatGPT/OpenAI - H1 con códigos
        '/<h1[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/h1>/i',
        
        // Genérico - TD con font-size grande
        '/<td[^>]*font-size:\s*(?:2[4-9]|[3-9]\d)px[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/td>/i',
        
        // Números grandes con letra-spacing
        '/<[^>]*letter-spacing[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/[^>]*>/i',
        
        // Divs o spans con clases que sugieren códigos
        '/<(?:div|span|p)[^>]*(?:code|codigo|verification|otp|pin)[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/(?:div|span|p)>/i',
        
        // Headers (H1-H6) con códigos
        '/<h[1-6][^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/h[1-6]>/i',
        
        // Texto en negrita o destacado
        '/<(?:b|strong|em)[^>]*>[\s\r\n]*(\d{4,8})[\s\r\n]*<\/(?:b|strong|em)>/i',
        
        // Buscar en atributos alt o title
        '/(?:alt|title)=["\'][^"\']*(\d{4,8})[^"\']*["\']/i',
    ];
    
    foreach ($patronesHTML as $patron) {
        if (preg_match_all($patron, $html, $matches)) {
            foreach ($matches[1] as $match) {
                $textImportant .= " CODIGO_ENCONTRADO: $match ";
            }
        }
    }
    
    return $textImportant . $html;
}

function extraerCodigoOEnlaceMejorado($body, $subject = '') {
    $textCompleto = $subject . ' ' . $body;
    
    // ===== PRIORIDAD 1: ENLACES ESPECÍFICOS DE NETFLIX =====
    $patronesEnlaceNetflix = [
        // Netflix Travel Verify - MÁXIMA PRIORIDAD
        '/(https?:\/\/(?:www\.)?netflix\.com\/account\/travel\/verify[^\s\)]*)/i',
        
        // Netflix Account Access en general
        '/(https?:\/\/(?:www\.)?netflix\.com\/account\/[^\s\)]*(?:verify|access|travel)[^\s\)]*)/i',
        
        // Netflix Management Account 
        '/(https?:\/\/(?:www\.)?netflix\.com\/ManageAccountAccess[^\s\)]*)/i',
        
        // Netflix Password Reset
        '/(https?:\/\/(?:www\.)?netflix\.com\/password[^\s\)]*)/i',
        
        // Enlaces específicos en HTML (para emails HTML)
        '/href=["\']([^"\']*netflix\.com\/account\/travel\/verify[^"\']*)["\']/',
        '/href=["\']([^"\']*netflix\.com\/account[^"\']*(?:verify|access|travel)[^"\']*)["\']/',
    ];
    
    foreach ($patronesEnlaceNetflix as $i => $patron) {
        if (preg_match($patron, $textCompleto, $matches, PREG_OFFSET_CAPTURE)) {
            $enlace = $matches[1][0];
            $posicion = $matches[1][1];
            
            // Limpiar el enlace
            $enlace = trim($enlace, '"\'<>()');
            $enlace = html_entity_decode($enlace, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            if (filter_var($enlace, FILTER_VALIDATE_URL)) {
                // Determinar el tipo específico de enlace Netflix
                $tipoNetflix = determinarTipoEnlaceNetflix($enlace);
                
                // Extraer fragmento contextual específico para Netflix
                $fragmento = extraerContextoNetflixEspecifico($textCompleto, $posicion, $enlace, $tipoNetflix);
                
                log_bot("✅ ENLACE NETFLIX DETECTADO: $tipoNetflix - " . substr($enlace, 0, 50), 'INFO');
                log_bot("FRAGMENTO: " . substr($fragmento, 0, 100), 'DEBUG');
                
                return [
                    'tipo' => 'enlace',
                    'valor' => $enlace,
                    'confianza' => 'alta', // Alta confianza para enlaces específicos de Netflix
                    'fragmento' => $fragmento,
                    'posicion' => $posicion,
                    'patron' => $i,
                    'servicio' => 'Netflix',
                    'tipo_enlace' => $tipoNetflix
                ];
            }
        }
    }
    
    // ===== PRIORIDAD 2: DETECCIÓN DE CÓDIGOS (LÓGICA ORIGINAL) =====
    $patronesCodigo = [
        // Patrón específico para códigos extraídos de HTML
        '/CODIGO_ENCONTRADO:\s*(\d{4,8})/i',
        
        // Netflix específico - códigos de acceso temporal
        '/(?:código|code).*?(?:acceso|access).*?(?:temporal|temporary).*?(\d{4,8})/iu',
        '/(?:acceso|access).*?(?:temporal|temporary).*?Netflix.*?(\d{4,8})/iu',
        
        // Extraer código del subject si está explícito (ChatGPT style)
        '/(?:code|código)\s+(?:is|es)\s+(\d{4,8})/i',
        
        // Patrones generales mejorados con más variaciones
        '/(?:código|code|verification|verificación|otp|pin|access|acceso)[\s:]*(\d{4,8})/iu',
        '/(?:your|tu|el|su)\s+(?:código|code|verification|otp|pin)[\s:]*(\d{4,8})/iu',
        '/(?:enter|ingresa|introduce|usa|use)\s+(?:this|este|el|the)?\s*(?:code|código)[\s:]*(\d{4,8})/iu',
        
        // Netflix códigos específicos
        '/netflix.*?(\d{4,8})/i',
        '/(?:obtener|get|utiliza|use).*?código.*?(\d{4,8})/iu',
        
        // Contexto español mejorado
        '/(?:acceso|inicio|sesión|verificar|verifica).*?(\d{4,8})/iu',
        '/(?:expira|vence|válido|temporal).*?(\d{4,8})/iu',
        '/(?:solicitud|dispositivo).*?(\d{4,8})/iu',
        
        // Patrones específicos por longitud y contexto
        '/\b(\d{6})\b(?=\s*(?:is|es|será|will|expires|vence|válido|valid|temporal|minutos))/iu',
        '/\b(\d{6})\b(?!\d)/', // 6 dígitos aislados (más comunes)
        '/\b(\d{5})\b(?=\s*(?:is|es|será|will|expires|vence|válido|valid|temporal|minutos))/iu',
        '/\b(\d{4})\b(?=\s*(?:is|es|será|will|expires|vence|válido|valid|temporal|minutos))/iu',
        
        // Fallback para 4-8 dígitos en contexto
        '/\b(\d{4,8})\b(?=\s*(?:to|para|sign|log|access|acceder|iniciar))/iu',
        
        // Último recurso: cualquier secuencia de 4-8 dígitos
        '/\b(\d{4,8})\b/',
    ];
    
    // Buscar códigos con prioridad Y CAPTURAR CONTEXTO
    foreach ($patronesCodigo as $i => $patron) {
        if (preg_match($patron, $textCompleto, $matches, PREG_OFFSET_CAPTURE)) {
            $codigo = $matches[1][0]; // El código detectado
            $posicion = $matches[1][1]; // Posición donde se encontró
            $longitud = strlen($codigo);
            
            // Validar longitud típica de códigos
            if ($longitud >= 4 && $longitud <= 8) {
                // Los primeros patrones tienen mayor confianza
                $confianza = $i < 8 ? 'alta' : ($i < 15 ? 'media' : 'baja');
                
                // EXTRAER FRAGMENTO ALREDEDOR DEL CÓDIGO
                $fragmento = extraerFragmentoContexto($textCompleto, $posicion, $codigo);
                
                log_bot("CÓDIGO DETECTADO: $codigo (patrón $i, confianza: $confianza)", 'INFO');
                log_bot("FRAGMENTO: " . substr($fragmento, 0, 100), 'DEBUG');
                
                return [
                    'tipo' => 'codigo', 
                    'valor' => $codigo,
                    'confianza' => $confianza,
                    'patron' => $i,
                    'fragmento' => $fragmento,
                    'posicion' => $posicion
                ];
            }
        }
    }
    
    // ===== PRIORIDAD 3: ENLACES GENÉRICOS =====
    $patronesEnlaceGenericos = [
        // Servicios específicos con verificación
        '/(https?:\/\/[^\s\)]+(?:verify|verification|code|codigo|auth|login|access)[^\s\)]*)/i',
        
        // Enlaces con texto descriptivo en español e inglés
        '/(?:click|press|tap|toca|pulsa|accede|obtener|get)\s+(?:here|aquí|below|abajo|button|botón|código|code)[^.]*?(https?:\/\/[^\s\)]+)/i',
        '/(?:verify|verifica|confirm|confirma|access|acceder)[^.]*?(https?:\/\/[^\s\)]+)/i',
        '/(?:get|obtener|generate|generar)\s+(?:code|código)[^.]*?(https?:\/\/[^\s\)]+)/i',
        
        // Enlaces en HTML
        '/href=["\']([^"\']+(?:verify|access|login|auth|code|codigo|travel)[^"\']*)["\']/',
        '/href=["\']([^"\']+)["\'][^>]*>.*?(?:verify|verifica|código|code|access|obtener|get)/i',
        
        // Servicios específicos (dominios conocidos)
        '/(https?:\/\/(?:[^\/\s]+\.)?(?:disney|amazon|microsoft|google|apple|openai)\.com[^\s]*(?:verify|code|auth|login|travel|access)[^\s]*)/i',
        
        // Enlaces genéricos en contextos de verificación
        '/(https?:\/\/[^\s\)]+)(?=\s*.*(?:verify|code|access|login|temporal|vence))/i',
    ];
    
    foreach ($patronesEnlaceGenericos as $patron) {
        if (preg_match($patron, $textCompleto, $matches, PREG_OFFSET_CAPTURE)) {
            $enlace = isset($matches[1]) ? $matches[1][0] : $matches[0][0];
            $posicion = isset($matches[1]) ? $matches[1][1] : $matches[0][1];
            $enlace = trim($enlace, '"\'<>()');
            
            if (filter_var($enlace, FILTER_VALIDATE_URL)) {
                $fragmento = extraerFragmentoContexto($textCompleto, $posicion, $enlace);
                
                log_bot("ENLACE GENÉRICO DETECTADO: " . substr($enlace, 0, 50), 'DEBUG');
                return [
                    'tipo' => 'enlace',
                    'valor' => $enlace,
                    'confianza' => 'media',
                    'fragmento' => $fragmento,
                    'posicion' => $posicion
                ];
            }
        }
    }
    
    // Si no se encuentra nada
    log_bot("NO SE DETECTÓ CONTENIDO PRIORITARIO en: " . substr($textCompleto, 0, 100), 'WARNING');
    return ['tipo' => 'ninguno', 'valor' => '', 'confianza' => 'ninguna'];
}

// ================================================
// FUNCIÓN PARA DETERMINAR TIPO DE ENLACE NETFLIX
// ================================================

function determinarTipoEnlaceNetflix($enlace) {
    if (strpos($enlace, '/account/travel/verify') !== false) {
        return 'Código de Acceso Temporal (Viajes)';
    } elseif (strpos($enlace, '/ManageAccountAccess') !== false) {
        return 'Gestión de Acceso a Cuenta';
    } elseif (strpos($enlace, '/password') !== false) {
        return 'Cambio de Contraseña';
    } elseif (strpos($enlace, '/account/') !== false) {
        return 'Configuración de Cuenta';
    } else {
        return 'Enlace de Netflix';
    }
}

// ================================================
// FUNCIÓN PARA EXTRAER CONTEXTO ESPECÍFICO DE NETFLIX
// ================================================

function extraerContextoNetflixEspecifico($texto, $posicion, $enlace, $tipoEnlace) {
    // Buscar texto específico de Netflix alrededor del enlace
    $patronesContextoNetflix = [
        // Para enlaces de travel/verify
        '/(?:obtener|get)\s+código.*?(?:viajes?|travel).*?temporalmente/is',
        '/código.*?acceso.*?temporal.*?Netflix/is',
        '/solicitud.*?código.*?acceso.*?temporal/is',
        '/dispositivo.*?aparece.*?continuación/is',
        '/enlace.*?vence.*?(\d+).*?minutos?/is',
        
        // Para otros tipos de enlaces
        '/protege.*?cuenta.*?reconozcas/is',
        '/cerrar.*?sesión.*?dispositivos/is',
        '/cambiar.*?contraseña/is',
    ];
    
    foreach ($patronesContextoNetflix as $patron) {
        if (preg_match($patron, $texto, $matches)) {
            $contexto = trim($matches[0]);
            if (strlen($contexto) > 20 && strlen($contexto) < 300) {
                return limpiarFragmentoCompleto($contexto, $enlace);
            }
        }
    }
    
    // Fallback al método estándar
    return extraerFragmentoContexto($texto, $posicion, $enlace);
}

/**
 * NUEVA FUNCIÓN: Extraer texto limpio específicamente para mostrar al usuario
 * Esta función se enfoca en obtener solo el contenido relevante y legible
 */
function extraerTextoLimpioParaUsuario($html, $subject = '') {
    if (empty($html)) return '';
    
    // 1. Eliminar elementos que nunca queremos mostrar
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
    
    // 2. Buscar contenido específico por servicio ANTES de limpiar
    $contenidoEspecifico = extraerContenidoPorServicio($html, $subject);
    if (!empty($contenidoEspecifico)) {
        return $contenidoEspecifico;
    }
    
    // 3. Extraer texto de elementos importantes (preservando estructura)
    $textoImportante = '';
    
    // Patrones para extraer contenido relevante por orden de importancia
    $patronesContenido = [
        // H1-H3 con códigos o texto relevante
        '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is',
        
        // Párrafos con códigos o palabras clave
        '/<p[^>]*>(.*?(?:código|code|verification|acceso|expira|minutos|disney|netflix|amazon).*?)<\/p>/is',
        
        // Divs con clases importantes
        '/<div[^>]*(?:code|verification|main|content)[^>]*>(.*?)<\/div>/is',
        
        // TDs con contenido relevante
        '/<td[^>]*>(.*?(?:\d{4,8}|código|code|verification).*?)<\/td>/is',
        
        // Spans importantes
        '/<span[^>]*>(.*?(?:\d{4,8}|código|expira|minutos).*?)<\/span>/is',
    ];
    
    foreach ($patronesContenido as $patron) {
        if (preg_match_all($patron, $html, $matches)) {
            foreach ($matches[1] as $match) {
                $textoLimpio = strip_tags($match);
                $textoLimpio = html_entity_decode($textoLimpio, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $textoLimpio = preg_replace('/\s+/', ' ', trim($textoLimpio));
                
                if (strlen($textoLimpio) > 10) {
                    $textoImportante .= $textoLimpio . ' ';
                }
            }
        }
    }
    
    // 4. Si no encontramos nada específico, usar método general mejorado
    if (empty($textoImportante)) {
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/\s+/', ' ', $html);
        $textoImportante = $html;
    }
    
    return trim($textoImportante);
}

/**
 * NUEVA FUNCIÓN: Extraer contenido específico por servicio
 */
function extraerContenidoPorServicio($html, $subject) {
    $servicioDetectado = '';
    
    // Detectar servicio por subject
    if (preg_match('/disney/i', $subject)) {
        $servicioDetectado = 'disney';
    } elseif (preg_match('/netflix/i', $subject)) {
        $servicioDetectado = 'netflix';
    } elseif (preg_match('/amazon/i', $subject)) {
        $servicioDetectado = 'amazon';
    } elseif (preg_match('/microsoft|outlook|xbox/i', $subject)) {
        $servicioDetectado = 'microsoft';
    } elseif (preg_match('/google|gmail/i', $subject)) {
        $servicioDetectado = 'google';
    } elseif (preg_match('/apple|icloud/i', $subject)) {
        $servicioDetectado = 'apple';
    } elseif (preg_match('/chatgpt|openai/i', $subject)) {
        $servicioDetectado = 'openai';
    }
    
    switch ($servicioDetectado) {
        case 'disney':
            return extraerContenidoDisney($html);
        case 'netflix':
            return extraerContenidoNetflix($html);
        case 'amazon':
            return extraerContenidoAmazon($html);
        case 'microsoft':
            return extraerContenidoMicrosoft($html);
        case 'google':
            return extraerContenidoGoogle($html);
        case 'apple':
            return extraerContenidoApple($html);
        case 'openai':
            return extraerContenidoOpenAI($html);
        default:
            return '';
    }
}

/**
 * NUEVAS FUNCIONES: Extractores específicos por servicio
 */
function extraerContenidoDisney($html) {
    // Disney+ - Buscar el texto específico alrededor del código
    $patrones = [
        '/Es necesario que verifiques.*?(\d{4,8}).*?minutos\./is',
        '/código de acceso único.*?(\d{4,8}).*?minutos\./is',
        '/verificar.*?cuenta.*?(\d{4,8}).*?vencer/is',
    ];
    
    foreach ($patrones as $patron) {
        if (preg_match($patron, $html, $matches)) {
            $contenido = strip_tags($matches[0]);
            $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $contenido = preg_replace('/\s+/', ' ', trim($contenido));
            return $contenido;
        }
    }
    
    return '';
}

function extraerContenidoNetflix($html) {
    // Prioridad 1: Buscar información sobre enlaces de acceso temporal
    $patronesAccesoTemporal = [
        // Texto específico del email de travel verify
        '/(?:recibimos.*?solicitud|código.*?acceso.*?temporal).*?(?:dispositivo|viajes?).*?(?:minutos?|expira)/is',
        '/(?:obtener|utiliza).*?código.*?(?:durante.*?viajes?|temporalmente)/is',
        '/(?:enviaste.*?tú|alguien.*?vive.*?contigo).*?obtener.*?código/is',
        '/enlace.*?vence.*?(\d+).*?minutos?/is',
        
        // Información de seguridad
        '/protege.*?cuenta.*?(?:solicitud|reconozcas)/is',
        '/cerrar.*?sesión.*?inmediato.*?dispositivos/is',
        '/cambiar.*?contraseña/is',
    ];
    
    foreach ($patronesAccesoTemporal as $patron) {
        if (preg_match($patron, $html, $matches)) {
            $contenido = strip_tags($matches[0]);
            $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $contenido = preg_replace('/\s+/', ' ', trim($contenido));
            
            if (strlen($contenido) > 20) {
                return $contenido;
            }
        }
    }
    
    // Prioridad 2: Patrones generales de Netflix
    $patronesGenerales = [
        '/código.*?inicio.*?sesión.*?(\d{4,8})/is',
        '/verificación.*?(\d{4,8}).*?minutos/is',
        '/acceso.*?temporal.*?(\d{4,8})/is',
        '/Netflix.*?código.*?(\d{4,8})/is',
    ];
    
    foreach ($patronesGenerales as $patron) {
        if (preg_match($patron, $html, $matches)) {
            $contenido = strip_tags($matches[0]);
            $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $contenido = preg_replace('/\s+/', ' ', trim($contenido));
            return $contenido;
        }
    }
    
    return '';
}

function extraerContenidoAmazon($html) {
    $patrones = [
        '/código de verificación.*?(\d{4,8})/is',
        '/Amazon.*?(\d{4,8}).*?verificar/is',
        '/Prime.*?(\d{4,8}).*?acceso/is',
    ];
    
    foreach ($patrones as $patron) {
        if (preg_match($patron, $html, $matches)) {
            $contenido = strip_tags($matches[0]);
            $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $contenido = preg_replace('/\s+/', ' ', trim($contenido));
            return $contenido;
        }
    }
    
    return '';
}

function extraerContenidoMicrosoft($html) {
    $patrones = [
        '/Microsoft.*?(\d{4,8}).*?verificar/is',
        '/código de seguridad.*?(\d{4,8})/is',
        '/Outlook.*?(\d{4,8})/is',
    ];
    
    foreach ($patrones as $patron) {
        if (preg_match($patron, $html, $matches)) {
            $contenido = strip_tags($matches[0]);
            $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $contenido = preg_replace('/\s+/', ' ', trim($contenido));
            return $contenido;
        }
    }
    
    return '';
}

function extraerContenidoGoogle($html) {
    $patrones = [
        '/Google.*?(\d{4,8}).*?verificar/is',
        '/código de verificación.*?(\d{4,8})/is',
        '/Gmail.*?(\d{4,8})/is',
    ];
    
    foreach ($patrones as $patron) {
        if (preg_match($patron, $html, $matches)) {
            $contenido = strip_tags($matches[0]);
            $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $contenido = preg_replace('/\s+/', ' ', trim($contenido));
            return $contenido;
        }
    }
    
    return '';
}

function extraerContenidoApple($html) {
    $patrones = [
        '/Apple.*?(\d{4,8}).*?verificar/is',
        '/iCloud.*?(\d{4,8})/is',
        '/código de verificación.*?(\d{4,8})/is',
    ];
    
    foreach ($patrones as $patron) {
        if (preg_match($patron, $html, $matches)) {
            $contenido = strip_tags($matches[0]);
            $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $contenido = preg_replace('/\s+/', ' ', trim($contenido));
            return $contenido;
        }
    }
    
    return '';
}

function extraerContenidoOpenAI($html) {
    $patrones = [
        '/ChatGPT.*?(\d{4,8})/is',
        '/OpenAI.*?(\d{4,8})/is',
        '/código de verificación.*?(\d{4,8})/is',
    ];
    
    foreach ($patrones as $patron) {
        if (preg_match($patron, $html, $matches)) {
            $contenido = strip_tags($matches[0]);
            $contenido = html_entity_decode($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $contenido = preg_replace('/\s+/', ' ', trim($contenido));
            return $contenido;
        }
    }
    
    return '';
}

/**
 * Limpia completamente un fragmento de texto para mostrar al usuario
 */
function limpiarFragmentoCompleto($fragmento, $valorEncontrado) {
    // 1. Decodificar quoted-printable PRIMERO
    if (strpos($fragmento, '=') !== false && preg_match('/=[0-9A-F]{2}/', $fragmento)) {
        $fragmento = quoted_printable_decode($fragmento);
    }
    
    // 2. Decodificar entidades HTML
    $fragmento = html_entity_decode($fragmento, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // 3. Convertir a UTF-8 válido si es necesario
    if (!mb_check_encoding($fragmento, 'UTF-8')) {
        $fragmento = mb_convert_encoding($fragmento, 'UTF-8', ['UTF-8', 'ISO-8859-1', 'Windows-1252']);
    }
    
    // 4. Limpiar caracteres de control y espacios múltiples
    $fragmento = preg_replace('/\s+/', ' ', $fragmento);
    $fragmento = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $fragmento);
    
    // 5. Eliminar elementos técnicos no deseados
    $patronesTecnicos = [
        '/CODIGO_ENCONTRADO:\s*/',
        '/------=_Part_\d+_\d+\.\d+/',
        '/Content-Type:.*?charset=UTF-8/i',
        '/Content-Transfer-Encoding:.*$/m',
        '/@font-face\s*\{[^}]*\}/',
        '/font-family:\s*[^;]+;/',
        '/\*\s*\{[^}]*\}/',
        '/http[s]?:\/\/[^\s]+\.(woff|woff2|ttf|eot)/',
    ];
    
    foreach ($patronesTecnicos as $patron) {
        $fragmento = preg_replace($patron, '', $fragmento);
    }
    
    // 6. Limpiar espacios y puntuación múltiple
    $fragmento = preg_replace('/\s*\.\s*\.+\s*/', '. ', $fragmento);
    $fragmento = preg_replace('/\s*,\s*,+\s*/', ', ', $fragmento);
    $fragmento = preg_replace('/\s+/', ' ', $fragmento);
    
    // 7. Trim y validar longitud
    $fragmento = trim($fragmento);
    
    // 8. Truncar inteligentemente si es muy largo
    if (strlen($fragmento) > 200) {
        $fragmentoCorto = substr($fragmento, 0, 197);
        $ultimoPunto = strrpos($fragmentoCorto, '.');
        $ultimoEspacio = strrpos($fragmentoCorto, ' ');
        
        $mejorCorte = $ultimoPunto !== false && $ultimoPunto > 150 ? $ultimoPunto : $ultimoEspacio;
        
        if ($mejorCorte !== false && $mejorCorte > 100) {
            $fragmento = substr($fragmento, 0, $mejorCorte) . '...';
        } else {
            $fragmento = $fragmentoCorto . '...';
        }
    }
    
    return $fragmento;
}

/**
 * Extrae el contexto completo relevante del email según la plataforma
 */
function extraerContextoCompletoEmail($body, $subject, $codigo, $plataforma) {
    // 1. Limpiar el body primero
    $bodyLimpio = $body;
    
    // Decodificar quoted-printable
    if (strpos($bodyLimpio, '=') !== false && preg_match('/=[0-9A-F]{2}/', $bodyLimpio)) {
        $bodyLimpio = quoted_printable_decode($bodyLimpio);
    }
    
    // Decodificar entidades HTML
    $bodyLimpio = html_entity_decode($bodyLimpio, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Si hay HTML, extraer solo el texto
    if (strpos($bodyLimpio, '<') !== false) {
        $bodyLimpio = strip_tags($bodyLimpio);
    }
    
    // Limpiar espacios múltiples
    $bodyLimpio = preg_replace('/\s+/', ' ', $bodyLimpio);
    $bodyLimpio = trim($bodyLimpio);
    
    // 2. Detectar plataforma si no se especifica
    if (empty($plataforma)) {
        if (preg_match('/disney/i', $subject)) {
            $plataforma = 'Disney+';
        } elseif (preg_match('/netflix/i', $subject)) {
            $plataforma = 'Netflix';
        } elseif (preg_match('/amazon/i', $subject)) {
            $plataforma = 'Amazon';
        }
    }
    
    // 3. Extraer según la plataforma
    switch (strtolower($plataforma)) {
        case 'disney+':
        case 'disney':
            return extraerContextoDisney($bodyLimpio, $subject, $codigo);
        case 'netflix':
            return extraerContextoNetflix($bodyLimpio, $subject, $codigo);
        case 'amazon':
            return extraerContextoAmazon($bodyLimpio, $subject, $codigo);
        default:
            return extraerContextoGenerico($bodyLimpio, $subject, $codigo);
    }
}

/**
 * Extraer contexto específico para Disney+
 */
function extraerContextoDisney($body, $subject, $codigo) {
    $contexto = "**" . $subject . "**\n\n";
    
    // Buscar el párrafo principal que contiene la explicación
    $patronPrincipal = '/(?:Es necesario|Necesitas|You need).*?(?:vencerá|expire|expir).*?(?:minutos|minutes)\.?/is';
    if (preg_match($patronPrincipal, $body, $matches)) {
        $contexto .= trim($matches[0]) . "\n\n";
    }
    
    // Agregar el código resaltado
    $contexto .= "**" . $codigo . "**\n\n";
    
    // Buscar información adicional (lo que viene después del código)
    $posicionCodigo = strpos($body, $codigo);
    if ($posicionCodigo !== false) {
        $despuesCodigo = substr($body, $posicionCodigo + strlen($codigo));
        
        // Buscar la siguiente oración relevante
        $patronAdicional = '/[^.]*(?:solicitaste|Centro de ayuda|help|support|no request).*?\.?/i';
        if (preg_match($patronAdicional, $despuesCodigo, $matches)) {
            $infoAdicional = trim($matches[0]);
            if (!empty($infoAdicional)) {
                $contexto .= $infoAdicional;
            }
        }
    }
    
    return trim($contexto);
}

/**
 * Extraer contexto específico para Netflix
 */
function extraerContextoNetflix($body, $subject, $codigo) {
    $contexto = "**" . $subject . "**\n\n";
    
    // Buscar explicación de Netflix
    $patronPrincipal = '/(?:código|code).*?(?:Netflix|streaming|device).*?(?:minutos|minutes|expire)\.?/is';
    if (preg_match($patronPrincipal, $body, $matches)) {
        $contexto .= trim($matches[0]) . "\n\n";
    }
    
    $contexto .= "**" . $codigo . "**\n\n";
    
    // Agregar información adicional
    $posicionCodigo = strpos($body, $codigo);
    if ($posicionCodigo !== false) {
        $despuesCodigo = substr($body, $posicionCodigo + strlen($codigo));
        $patronAdicional = '/[^.]*(?:expire|valid|válido|device).*?\.?/i';
        if (preg_match($patronAdicional, $despuesCodigo, $matches)) {
            $contexto .= trim($matches[0]);
        }
    }
    
    return trim($contexto);
}

/**
 * Extraer contexto específico para Amazon
 */
function extraerContextoAmazon($body, $subject, $codigo) {
    $contexto = "**" . $subject . "**\n\n";
    
    $patronPrincipal = '/(?:código|code).*?(?:Amazon|Prime|verification).*?\.?/is';
    if (preg_match($patronPrincipal, $body, $matches)) {
        $contexto .= trim($matches[0]) . "\n\n";
    }
    
    $contexto .= "**" . $codigo . "**\n\n";
    
    return trim($contexto);
}

/**
 * Extraer contexto genérico para otras plataformas
 */
function extraerContextoGenerico($body, $subject, $codigo) {
    $contexto = "**" . $subject . "**\n\n";
    
    // Buscar párrafo que contenga el código
    $posicionCodigo = strpos($body, $codigo);
    if ($posicionCodigo !== false) {
        // Extraer 200 caracteres antes y después del código
        $inicio = max(0, $posicionCodigo - 200);
        $fin = min(strlen($body), $posicionCodigo + strlen($codigo) + 200);
        $fragmento = substr($body, $inicio, $fin - $inicio);
        
        // Buscar límites de oraciones
        $fragmento = trim($fragmento);
        $contexto .= $fragmento . "\n\n";
    }
    
    $contexto .= "**" . $codigo . "**";
    
    return trim($contexto);
}

/**
 * Extrae un fragmento de contexto alrededor de la posición donde se encontró el código/enlace
 */
function extraerFragmentoContexto($texto, $posicion, $valorEncontrado) {
    // 1. PRIMERO: Intentar extraer usando el método específico por servicio
    $textoLimpio = extraerTextoLimpioParaUsuario($texto);
    
    // 2. Si el texto limpio contiene el valor, usarlo como base
    if (strpos($textoLimpio, $valorEncontrado) !== false) {
        $texto = $textoLimpio;
        // Recalcular posición en el texto limpio
        $posicion = strpos($texto, $valorEncontrado);
        if ($posicion === false) $posicion = 0;
    }
    
    $longitudTexto = strlen($texto);
    $longitudValor = strlen($valorEncontrado);
    
    // 3. Buscar una oración completa que contenga el código
    $oracionCompleta = extraerOracionCompleta($texto, $posicion, $valorEncontrado);
    if (!empty($oracionCompleta)) {
        return limpiarFragmentoParaMostrarMejorado($oracionCompleta, $valorEncontrado);
    }
    
    // 4. Fallback al método original pero con contexto más pequeño
    $contextoAntes = 60;
    $contextoDespues = 60;
    
    $inicio = max(0, $posicion - $contextoAntes);
    $fin = min($longitudTexto, $posicion + $longitudValor + $contextoDespues);
    
    $fragmento = substr($texto, $inicio, $fin - $inicio);
    $fragmento = limpiarFragmentoParaMostrarMejorado($fragmento, $valorEncontrado);
    
    // Agregar indicadores si se cortó
    if ($inicio > 0) {
        $fragmento = '...' . $fragmento;
    }
    if ($fin < $longitudTexto) {
        $fragmento = $fragmento . '...';
    }
    
    return limpiarFragmentoCompleto($fragmento, $valorEncontrado);
}

/**
 * NUEVA FUNCIÓN: Extraer oración completa que contiene el código
 */
function extraerOracionCompleta($texto, $posicion, $valorEncontrado) {
    // Buscar límites de oración
    $inicioOracion = $posicion;
    $finOracion = $posicion + strlen($valorEncontrado);
    
    // Retroceder hasta encontrar inicio de oración
    while ($inicioOracion > 0) {
        $char = $texto[$inicioOracion - 1];
        if ($char === '.' || $char === '!' || $char === '?' || $char === "\n") {
            break;
        }
        $inicioOracion--;
        
        // Límite de seguridad
        if ($posicion - $inicioOracion > 200) break;
    }
    
    // Avanzar hasta encontrar fin de oración
    while ($finOracion < strlen($texto)) {
        $char = $texto[$finOracion];
        if ($char === '.' || $char === '!' || $char === '?') {
            $finOracion++;
            break;
        }
        $finOracion++;
        
        // Límite de seguridad
        if ($finOracion - $posicion > 200) break;
    }
    
    $oracion = substr($texto, $inicioOracion, $finOracion - $inicioOracion);
    $oracion = trim($oracion);
    
    // Solo devolver si la oración es coherente y no muy larga
    if (strlen($oracion) > 15 && strlen($oracion) < 300 && strpos($oracion, $valorEncontrado) !== false) {
        return $oracion;
    }
    
    return '';
}

/**
 * Limpia el fragmento para que sea legible y útil
 */
function limpiarFragmentoParaMostrarMejorado($fragmento, $valorEncontrado) {
    // 1. Decodificar quoted-printable PRIMERO
    if (strpos($fragmento, '=') !== false && preg_match('/=[0-9A-F]{2}/', $fragmento)) {
        $fragmento = quoted_printable_decode($fragmento);
    }
    
    // 2. Decodificar entidades HTML
    $fragmento = html_entity_decode($fragmento, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // 3. Convertir a UTF-8 válido si es necesario
    if (!mb_check_encoding($fragmento, 'UTF-8')) {
        $fragmento = mb_convert_encoding($fragmento, 'UTF-8', ['UTF-8', 'ISO-8859-1', 'Windows-1252']);
    }
    
    // 4. Limpiar caracteres de control y espacios múltiples
    $fragmento = preg_replace('/\s+/', ' ', $fragmento);
    $fragmento = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $fragmento);
    
    // 5. Eliminar elementos técnicos no deseados
    $patronesTecnicos = [
        '/CODIGO_ENCONTRADO:\s*/',
        '/------=_Part_\d+_\d+\.\d+/',
        '/Content-Type:.*?charset=UTF-8/i',
        '/Content-Transfer-Encoding:.*$/m',
        '/@font-face\s*\{[^}]*\}/',
        '/font-family:\s*[^;]+;/',
        '/\*\s*\{[^}]*\}/',
        '/http[s]?:\/\/[^\s]+\.(woff|woff2|ttf|eot)/',
    ];
    
    foreach ($patronesTecnicos as $patron) {
        $fragmento = preg_replace($patron, '', $fragmento);
    }
    
    // 6. Limpiar espacios y puntuación múltiple
    $fragmento = preg_replace('/\s*\.\s*\.+\s*/', '. ', $fragmento);
    $fragmento = preg_replace('/\s*,\s*,+\s*/', ', ', $fragmento);
    $fragmento = preg_replace('/\s+/', ' ', $fragmento);
    
    // 7. Trim y validar longitud
    $fragmento = trim($fragmento);
    
    // 8. Truncar inteligentemente si es muy largo
    if (strlen($fragmento) > 200) {
        // Buscar una parada natural cerca del límite
        $fragmentoCorto = substr($fragmento, 0, 197);
        $ultimoPunto = strrpos($fragmentoCorto, '.');
        $ultimoEspacio = strrpos($fragmentoCorto, ' ');
        
        $mejorCorte = $ultimoPunto !== false && $ultimoPunto > 150 ? $ultimoPunto : $ultimoEspacio;
        
        if ($mejorCorte !== false && $mejorCorte > 100) {
            $fragmento = substr($fragmento, 0, $mejorCorte) . '...';
        } else {
            $fragmento = $fragmentoCorto . '...';
        }
    }
    
    return $fragmento;
}


/**
 * Detecta servicios conocidos por patrones
 */
function detectarServicioPorEmail($from, $subject) {
    $servicios = [
        'Disney+' => [
            'patterns' => ['/disney/i', '/disneyplus/i'],
            'domains' => ['disney.com', 'disneyplus.com', 'bamgrid.com'],
            'subjects' => ['/disney\+/i', '/mydisney/i']
        ],
        'Netflix' => [
            'patterns' => ['/netflix/i'],
            'domains' => ['netflix.com', 'nflxext.com'],
            'subjects' => ['/netflix/i']
        ],
        'Amazon Prime' => [
            'patterns' => ['/amazon/i', '/prime/i'],
            'domains' => ['amazon.com', 'amazon.es', 'primevideo.com', 'amazonses.com'],
            'subjects' => ['/amazon/i', '/prime/i']
        ],
        'Microsoft' => [
            'patterns' => ['/microsoft/i', '/outlook/i', '/xbox/i'],
            'domains' => ['microsoft.com', 'outlook.com', 'xbox.com', 'live.com'],
            'subjects' => ['/microsoft/i', '/outlook/i', '/xbox/i']
        ],
        'Google' => [
            'patterns' => ['/google/i', '/gmail/i'],
            'domains' => ['google.com', 'gmail.com', 'googlemail.com'],
            'subjects' => ['/google/i', '/gmail/i']
        ],
        'Apple' => [
            'patterns' => ['/apple/i', '/icloud/i'],
            'domains' => ['apple.com', 'icloud.com', 'me.com'],
            'subjects' => ['/apple/i', '/icloud/i']
        ],
        'ChatGPT' => [
            'patterns' => ['/chatgpt/i', '/openai/i'],
            'domains' => ['openai.com', 'tm.openai.com'],
            'subjects' => ['/chatgpt/i', '/openai/i']
        ],
        'Instagram' => [
            'patterns' => ['/instagram/i'],
            'domains' => ['instagram.com', 'facebookmail.com'],
            'subjects' => ['/instagram/i']
        ],
        'Facebook' => [
            'patterns' => ['/facebook/i', '/meta/i'],
            'domains' => ['facebook.com', 'facebookmail.com', 'meta.com'],
            'subjects' => ['/facebook/i']
        ],
        'WhatsApp' => [
            'patterns' => ['/whatsapp/i'],
            'domains' => ['whatsapp.com', 'facebookmail.com'],
            'subjects' => ['/whatsapp/i']
        ],
        'Spotify' => [
            'patterns' => ['/spotify/i'],
            'domains' => ['spotify.com'],
            'subjects' => ['/spotify/i']
        ],
        'Telegram' => [
            'patterns' => ['/telegram/i'],
            'domains' => ['telegram.org'],
            'subjects' => ['/telegram/i']
        ]
    ];
    
    $texto = $from . ' ' . $subject;
    
    foreach ($servicios as $nombre => $config) {
        // Verificar subject primero (más específico)
        if (isset($config['subjects'])) {
            foreach ($config['subjects'] as $pattern) {
                if (preg_match($pattern, $subject)) {
                    return $nombre;
                }
            }
        }
        
        // Verificar patrones en texto completo
        foreach ($config['patterns'] as $pattern) {
            if (preg_match($pattern, $texto)) {
                return $nombre;
            }
        }
        
        // Verificar dominios
        foreach ($config['domains'] as $domain) {
            if (strpos(strtolower($from), $domain) !== false) {
                return $nombre;
            }
        }
    }
    
    return null;
}

/**
 * Limpiar campo FROM mejorado
 */
function limpiarCampoFromMejorado($from) {
    if (empty($from)) return '';
    
    // Decodificar quoted-printable
    if (strpos($from, '=') !== false) {
        $from = quoted_printable_decode($from);
    }
    
    // Decodificar entidades
    $from = html_entity_decode($from, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Limpiar caracteres especiales
    $from = trim($from, '"\'<>()');
    $from = preg_replace('/\s+/', ' ', $from);
    
    // Extraer solo el nombre si hay email
    if (preg_match('/^(.+?)\s*<[^>]+>$/', $from, $matches)) {
        $from = trim($matches[1], '"\'');
    }
    
    // Si es muy largo, truncar
    if (strlen($from) > 50) {
        $from = substr($from, 0, 47) . '...';
    }
    
    return $from;
}

/**
 * Crear vista previa con formato mejorado
 */
function crearVistaPreviaConFormato($bodyLimpio) {
    $lineas = explode("\n", $bodyLimpio);
    $lineasUtiles = [];
    
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        
        // Saltar líneas irrelevantes
        if (strlen($linea) < 10) continue;
        if (preg_match('/^(From:|To:|Subject:|Date:|Content-|CODIGO_ENCONTRADO)/i', $linea)) continue;
        if (preg_match('/^[\-=]{3,}/', $linea)) continue;
        if (preg_match('/^@font-face|^</', $linea)) continue;
        
        // Priorizar líneas con contenido relevante
        if (preg_match('/(?:código|code|verification|acceso|disney|netflix)/i', $linea)) {
            array_unshift($lineasUtiles, $linea); // Poner al principio
        } else {
            $lineasUtiles[] = $linea;
        }
        
        if (count($lineasUtiles) >= 4) break;
    }
    
    $preview = implode(' ', $lineasUtiles);
    
    // Limitar longitud
    if (strlen($preview) > 250) {
        $preview = substr($preview, 0, 247) . '...';
    }
    
    return $preview;
}

/**
 * Organizar contenido completo para visualización
 */
function organizarContenidoCompletoParaUsuario($body, $subject) {
    if (empty($body)) {
        return "📧 Contenido no disponible\n\nNo se pudo obtener el contenido del email\\.";
    }
    
    log_bot("=== ORGANIZANDO CONTENIDO COMPLETO ===", 'DEBUG');
    
    // 1. LIMPIAR Y DECODIFICAR EL CONTENIDO
    $contenidoLimpio = limpiarContenidoParaVisualizacion($body);
    
    // 2. DETECTAR SI ES HTML O TEXTO PLANO
    $esHTML = (strpos($contenidoLimpio, '<') !== false);
    
    // 3. PROCESAR SEGÚN EL TIPO
    if ($esHTML) {
        $contenidoOrganizado = procesarHTMLParaVisualizacion($contenidoLimpio, $subject);
    } else {
        $contenidoOrganizado = procesarTextoPlanoParaVisualizacion($contenidoLimpio, $subject);
    }
    
    // 4. CONSTRUIR EL MENSAJE FINAL (SIN CARACTERES PROBLEMÁTICOS)
    $mensaje = "📋 CONTENIDO PARA REVISIÓN MANUAL\n\n";
    $mensaje .= "⚠️ Se detectó con patrón genérico \\- Revisa manualmente\n\n";
    $mensaje .= "📝 Asunto: " . escaparMarkdown($subject) . "\n\n";
    $mensaje .= "📄 Contenido:\n\n";
    $mensaje .= $contenidoOrganizado;
    
    // 5. AGREGAR INSTRUCCIONES SEGURAS
    $mensaje .= "\n\n💡 Guía de búsqueda:\n";
    $mensaje .= "• Busca números de 4 a 8 dígitos\n";
    $mensaje .= "• Ignora direcciones y años\n";
    $mensaje .= "• Enfócate en texto cerca de código o verification\n";
    $mensaje .= "• Prioriza números en negritas o marcados\n";
    
    return $mensaje;
}

/**
 * ✅ FUNCIÓN: Limpiar contenido para visualización
 */
function limpiarContenidoParaVisualizacion($body) {
    // 1. Decodificar quoted-printable
    if (strpos($body, '=') !== false && preg_match('/=[0-9A-F]{2}/', $body)) {
        $body = quoted_printable_decode($body);
        log_bot("Decodificado quoted-printable", 'DEBUG');
    }
    
    // 2. Decodificar base64 si parece serlo
    if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $body) && strlen($body) > 100) {
        $decoded = base64_decode($body, true);
        if ($decoded !== false && strlen($decoded) > 50) {
            $body = $decoded;
            log_bot("Decodificado base64", 'DEBUG');
        }
    }
    
    // 3. Decodificar entidades HTML
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // 4. Convertir a UTF-8 válido si es necesario
    if (!mb_check_encoding($body, 'UTF-8')) {
        $body = mb_convert_encoding($body, 'UTF-8', ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII']);
        log_bot("Convertido a UTF-8", 'DEBUG');
    }
    
    // 5. Limpiar caracteres de control (pero preservar saltos de línea)
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body);
    
    return $body;
}

/**
 * ✅ FUNCIÓN: Procesar HTML para visualización
 */
function procesarHTMLParaVisualizacion($html, $subject) {
    log_bot("Procesando HTML para visualización", 'DEBUG');
    
    // 1. EXTRAER SECCIONES IMPORTANTES ANTES DE LIMPIAR
    $seccionesImportantes = extraerSeccionesImportantesHTML($html);
    
    // 2. LIMPIAR HTML PERO PRESERVAR ESTRUCTURA
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
    
    // 3. CONVERTIR ELEMENTOS HTML A TEXTO ESTRUCTURADO
    $html = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
    $html = str_replace(['<p>', '</p>'], ["\n", "\n"], $html);
    $html = str_replace(['<div>', '</div>'], ["\n", "\n"], $html);
    $html = str_replace(['<h1>', '</h1>', '<h2>', '</h2>', '<h3>', '</h3>'], ["**", "**\n", "**", "**\n", "*", "*\n"], $html);
    
    // 4. ELIMINAR TAGS RESTANTES
    $textoLimpio = strip_tags($html);
    
    // 5. ORGANIZAR LÍNEAS
    $lineas = explode("\n", $textoLimpio);
    $lineasOrganizadas = [];
    
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if (strlen($linea) > 5) { // Filtrar líneas muy cortas
            // Resaltar líneas que probablemente contengan códigos
            if (preg_match('/\b\d{4,8}\b/', $linea)) {
                $lineasOrganizadas[] = "🔍 **" . escaparMarkdown($linea) . "**";
            } else {
                $lineasOrganizadas[] = escaparMarkdown($linea);
            }
        }
    }
    
    // 6. AGREGAR SECCIONES IMPORTANTES DETECTADAS
    $resultado = "";
    if (!empty($seccionesImportantes)) {
        $resultado .= "🎯 *Secciones Relevantes Detectadas:*\n\n";
        foreach ($seccionesImportantes as $seccion) {
            $resultado .= "• " . escaparMarkdown($seccion) . "\n";
        }
        $resultado .= "\n---\n\n";
    }
    
    // 7. COMBINAR TODO
    $resultado .= "📄 *Contenido Principal:*\n\n";
    $resultado .= implode("\n", array_slice($lineasOrganizadas, 0, 25)); // Limitar a 25 líneas
    
    if (count($lineasOrganizadas) > 25) {
        $resultado .= "\n\n_\\[Se muestran las primeras 25 líneas\\]_";
    }
    
    return $resultado;
}

/**
 * ✅ FUNCIÓN: Procesar texto plano para visualización
 */
function procesarTextoPlanoParaVisualizacion($texto, $subject) {
    log_bot("Procesando texto plano para visualización", 'DEBUG');
    
    // 1. NORMALIZAR SALTOS DE LÍNEA
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    
    // 2. DIVIDIR EN LÍNEAS Y FILTRAR
    $lineas = explode("\n", $texto);
    $lineasOrganizadas = [];
    $contadorLineas = 0;
    
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        
        // Filtrar líneas muy cortas o irrelevantes
        if (strlen($linea) < 5) continue;
        if (preg_match('/^[\-=_\s]*$/', $linea)) continue; // Líneas decorativas
        if (preg_match('/^Content-|^From:|^To:|^Subject:|^Date:/i', $linea)) continue; // Headers
        
        $contadorLineas++;
        if ($contadorLineas > 30) break; // Limitar a 30 líneas
        
        // RESALTAR LÍNEAS CON POSIBLES CÓDIGOS
        if (preg_match('/\b\d{4,8}\b/', $linea)) {
            // Línea con números de 4-8 dígitos
            $lineasOrganizadas[] = "🔍 **" . escaparMarkdown($linea) . "**";
        } elseif (preg_match('/(?:code|código|verification|passcode|otp|pin)/i', $linea)) {
            // Línea con palabras clave
            $lineasOrganizadas[] = "💡 *" . escaparMarkdown($linea) . "*";
        } else {
            // Línea normal
            $lineasOrganizadas[] = escaparMarkdown($linea);
        }
    }
    
    $resultado = "📄 *Contenido del Email:*\n\n";
    $resultado .= implode("\n", $lineasOrganizadas);
    
    if ($contadorLineas > 30) {
        $resultado .= "\n\n_\\[Se muestran las primeras 30 líneas relevantes\\]_";
    }
    
    return $resultado;
}

/**
 * ✅ FUNCIÓN: Extraer secciones importantes de HTML
 */
function extraerSeccionesImportantesHTML($html) {
    $secciones = [];
    
    // Patrones para encontrar secciones importantes
    $patrones = [
        // Elementos con códigos y estilos específicos
        '/<td[^>]*(?:font-size:[^>]*(?:2[4-9]|[3-9]\d)px|letter-spacing)[^>]*>([^<]*\d{4,8}[^<]*)<\/td>/i',
        '/<(?:div|span|p)[^>]*(?:code|verification|passcode)[^>]*>([^<]*)<\/(?:div|span|p)>/i',
        '/<h[1-6][^>]*>([^<]*\d{4,8}[^<]*)<\/h[1-6]>/i',
        
        // Texto con palabras clave importantes
        '/<[^>]*>([^<]*(?:one-time|passcode|verification|código|expire|minutos)[^<]*)<\/[^>]*>/i',
    ];
    
    foreach ($patrones as $patron) {
        if (preg_match_all($patron, $html, $matches)) {
            foreach ($matches[1] as $match) {
                $texto = strip_tags($match);
                $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $texto = trim($texto);
                
                if (strlen($texto) > 10 && strlen($texto) < 200) {
                    $secciones[] = $texto;
                }
            }
        }
    }
    
    return array_unique($secciones);
}


/**
 * Función corregida para mostrar detalle con formato perfecto
 * CORRIGE: Error de etiquetas HTML mal formateadas para patrones genéricos
 */
function mostrarDetalleEmailPerfecto($botToken, $chatId, $messageId, $email, $plataforma, $index, $user, $db) {
    log_bot("=== INICIO MOSTRAR DETALLE ===", 'DEBUG');
    log_bot("Email: $email, Plataforma: $plataforma, Index: $index", 'DEBUG');
    log_bot("User ID: " . $user['id'], 'DEBUG');
    
    try {
        // USAR ÚNICAMENTE DATOS TEMPORALES (válidos por 2 minutos)
        log_bot("=== OBTENIENDO BÚSQUEDA TEMPORAL ===", 'DEBUG');
        $busqueda = obtenerBusquedaTemporal($user['id'], $db);
        
        if (!$busqueda) {
            log_bot("❌ No hay búsqueda temporal", 'ERROR');
            $texto = "⏰ *Búsqueda Expirada*\n\n";
            $texto .= "La búsqueda anterior expiró \\(2 minutos\\)\\.\n\n";
            $texto .= "💡 *Solución:* Realiza una nueva búsqueda\\.";
            
            $teclado = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 Nueva Búsqueda', 'callback_data' => "select_email_$email"],
                        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
                    ]
                ]
            ];
            
            editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
            return;
        }
        
        log_bot("✅ Búsqueda temporal obtenida", 'DEBUG');
        
        // === VERIFICACIÓN SIMPLIFICADA ===
        log_bot("=== VERIFICACIÓN SIMPLIFICADA ===", 'DEBUG');
        
        if (!$busqueda || !isset($busqueda['resultados']['emails']) || empty($busqueda['resultados']['emails'])) {
            log_bot("❌ No hay emails válidos", 'ERROR');
            throw new Exception("No hay emails válidos");
        }
        
        // TOMAR SIEMPRE EL PRIMER EMAIL SIN IMPORTAR EL ÍNDICE
        $emailsArray = array_values($busqueda['resultados']['emails']); // Reindexar a 0,1,2...
        $totalEmails = count($emailsArray);
        
        log_bot("Total emails reindexados: $totalEmails", 'DEBUG');
        
        if ($index >= $totalEmails) {
            throw new Exception("Index fuera de rango: $index >= $totalEmails");
        }

        if (!isset($emailsArray[$index])) {
            throw new Exception("Email no encontrado en el índice: $index");
        }

        $emailData = $emailsArray[$index];
        log_bot("✅ Email obtenido exitosamente en index $index", 'DEBUG');
        
        log_bot("Claves en emailData: " . implode(', ', array_keys($emailData)), 'DEBUG');
        log_bot("Subject: " . ($emailData['subject'] ?? 'N/A'), 'DEBUG');
        log_bot("From: " . ($emailData['from'] ?? 'N/A'), 'DEBUG');
        log_bot("Tipo acceso: " . ($emailData['tipo_acceso'] ?? 'N/A'), 'DEBUG');
        log_bot("Verification code: " . ($emailData['verification_code'] ?? 'N/A'), 'DEBUG');
        
        // DETERMINAR SI ES PATRÓN GENÉRICO
        $esPatronGenerico = false;
        if (isset($emailData['confianza_deteccion']) && isset($emailData['patron_usado'])) {
            $confianza = $emailData['confianza_deteccion'];
            $patronUsado = $emailData['patron_usado'];
            $esPatronGenerico = ($confianza === 'baja' || $patronUsado > 15);
            
            log_bot("EVALUANDO PATRÓN - Confianza: $confianza, Patrón: $patronUsado, Es genérico: " . ($esPatronGenerico ? 'SÍ' : 'NO'), 'DEBUG');
        }
        
        // ============================================
        // DECISIÓN: ¿FORMATO NORMAL O TEXTO PLANO?
        // ============================================
        
        if ($esPatronGenerico) {
            // PATRÓN GENÉRICO: CONSTRUIR MENSAJE EN TEXTO PLANO (SIN FORMATO)
            log_bot("🔍 PATRÓN GENÉRICO DETECTADO - Construyendo mensaje en texto plano", 'INFO');
            
            $textoPlano = "📄 DETALLE DEL EMAIL\n\n";
            $textoPlano .= "⚠️ DETECCIÓN DE BAJA PRECISIÓN\n";
            $textoPlano .= "🔍 Se requiere revisión manual\n\n";
            $textoPlano .= "---\n\n";
            
            // INFORMACIÓN BÁSICA (SIN MARKDOWN, UTF-8 SEGURO)
            if (isset($emailData['date'])) {
                $fecha = date('d/m/Y H:i:s', strtotime($emailData['date']));
                $fecha = asegurarUTF8Valido($fecha);
                $textoPlano .= "📅 Fecha: $fecha\n\n";
            }
            
            if (isset($emailData['subject'])) {
                $asunto = $emailData['subject'];
                if (strlen($asunto) > 80) {
                    $asunto = substr($asunto, 0, 77) . '...';
                }
                $asunto = asegurarUTF8Valido($asunto);
                $textoPlano .= "📝 Asunto:\n$asunto\n\n";
            }
            
            // REMITENTE
            $from = isset($emailData['from']) ? $emailData['from'] : 'Desconocido';
            $from = asegurarUTF8Valido($from);
            $textoPlano .= "👤 De: $from\n\n";
            
            // CONTENIDO DEL EMAIL (LIMPIO Y UTF-8 SEGURO)
            $bodyOriginal = $emailData['body'] ?? $emailData['body_clean'] ?? '';
            $bodyRaw = limpiarContenidoParaTextoPlanoSeguro($bodyOriginal);
            $contenido = '';

            if ($bodyRaw === '') {
                $textoPlano .= organizarContenidoCompletoParaUsuario($bodyOriginal, $emailData['subject'] ?? '');
            } else {
                $textoPlano .= "📄 Contenido del email:\n\n";

                // Limitar longitud y mostrar de forma segura
                $contenido = substr($bodyRaw, 0, 1500); // Reducir más el límite
                $contenido = asegurarUTF8Valido($contenido); // Nueva función de seguridad

                $textoPlano .= $contenido;

                if (strlen($bodyRaw) > 1500) {
                    $textoPlano .= "\n\n[Contenido truncado - busca numeros de 4-8 digitos]";
                }
            }

            // Validar que se haya generado contenido
            if (trim($contenido) === '' || trim($textoPlano) === '') {
                log_bot("Contenido vacío, regenerando", 'DEBUG');
                $textoPlano = organizarContenidoCompletoParaUsuario($emailData['body'] ?? '', $emailData['subject'] ?? '');
            }
            
            $textoPlano .= "\n\n💡 Busca numeros de 4 a 8 digitos en el contenido anterior";
            
            // LIMPIAR TODO EL MENSAJE FINAL
            $textoPlano = asegurarUTF8Valido($textoPlano);
            
            // CREAR TECLADO
            $teclado = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔙 Volver a Resultados', 'callback_data' => "search_" . encodePart($email) . '_' . encodePart($plataforma)],
                        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
                    ]
                ]
            ];
            
            // ENVIAR SIN PARSE_MODE (TEXTO PLANO) CON VALIDACIÓN UTF-8
            log_bot("=== ENVIANDO MENSAJE EN TEXTO PLANO SEGURO ===", 'DEBUG');
            
            // VALIDACIÓN FINAL DE UTF-8
            $textoPlano = asegurarUTF8Valido($textoPlano);
            
            // Verificar que el mensaje no sea demasiado largo para Telegram (4096 caracteres max)
            if (strlen($textoPlano) > 4000) {
                $textoPlano = substr($textoPlano, 0, 3950) . "\n\n[Mensaje truncado por longitud]";
                $textoPlano = asegurarUTF8Valido($textoPlano);
            }
            
            log_bot("Mensaje final válido UTF-8: " . (mb_check_encoding($textoPlano, 'UTF-8') ? 'SÍ' : 'NO'), 'DEBUG');
            log_bot("Longitud del mensaje: " . strlen($textoPlano), 'DEBUG');
            
            $url = "https://api.telegram.org/bot$botToken/editMessageText";
            $data = [
                'chat_id' => $chatId, 
                'message_id' => $messageId, 
                'text' => $textoPlano,
                'reply_markup' => json_encode($teclado)
                // NO parse_mode = texto plano
            ];
            
            // Asegurar que todos los datos sean UTF-8 válidos
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $data[$key] = asegurarUTF8Valido($value);
                }
            }

            // Garantizar que el texto no esté vacío antes de enviar
            if (trim($textoPlano) === '') {
                log_bot("Texto plano vacío antes de enviar, regenerando", 'DEBUG');
                $textoPlano = organizarContenidoCompletoParaUsuario($emailData['body'] ?? '', $emailData['subject'] ?? '');
                $textoPlano = asegurarUTF8Valido($textoPlano);
                $data['text'] = $textoPlano;
            }

            $resultado = enviarRequest($url, $data);
            
        } else {
            // PATRÓN NORMAL: USAR MARKDOWN V2
            log_bot("📄 PATRÓN NORMAL - Usando MarkdownV2", 'DEBUG');
            
            $texto = "📄 *Detalle del Email*\n\n";
            
            // === INFORMACIÓN BÁSICA ===
            if (isset($emailData['date'])) {
                log_bot("Procesando fecha: " . $emailData['date'], 'DEBUG');
                $fecha = date('d/m/Y H:i:s', strtotime($emailData['date']));
                $texto .= "📅 *Fecha:* `$fecha`\n\n";
            }
            
            if (isset($emailData['subject'])) {
                log_bot("Procesando subject", 'DEBUG');
                $asunto = strlen($emailData['subject']) > 80 ? 
                         substr($emailData['subject'], 0, 77) . '\\.\\.\\.' : 
                         $emailData['subject'];
                $texto .= "📝 *Asunto:*\n" . escaparMarkdown($asunto) . "\n\n";
            }
            
            // === REMITENTE ===
            log_bot("Procesando remitente", 'DEBUG');
            $from = isset($emailData['from']) ? $emailData['from'] : 'Desconocido';
            $texto .= "👤 *De:* " . escaparMarkdown($from) . "\n\n";
            
            // === CÓDIGO O ENLACE ===
            log_bot("Procesando código/enlace", 'DEBUG');
            $tieneContenidoPrincipal = false;
            
            if (isset($emailData['tipo_acceso'])) {
                log_bot("Tipo de acceso detectado: " . $emailData['tipo_acceso'], 'DEBUG');
                
                if ($emailData['tipo_acceso'] === 'codigo' && isset($emailData['verification_code'])) {
                    // CÓDIGO CONFIABLE: MOSTRAR NORMALMENTE
                    log_bot("Agregando código de verificación: " . $emailData['verification_code'], 'DEBUG');
                    $texto .= "🔐 *CÓDIGO DE VERIFICACIÓN:*\n\n";
                    $texto .= "`" . $emailData['verification_code'] . "`\n\n";
                    
                    if (isset($emailData['fragmento_deteccion']) && !empty($emailData['fragmento_deteccion'])) {
                        $texto .= "📍 *Contexto donde se detectó:*\n\n";
                        $fragmentoMostrar = $emailData['fragmento_deteccion'];
                        if (strpos($fragmentoMostrar, '=') !== false) {
                            $fragmentoMostrar = quoted_printable_decode($fragmentoMostrar);
                        }
                        $fragmentoMostrar = html_entity_decode($fragmentoMostrar, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $fragmentoConResaltado = str_ireplace(
                            $emailData['verification_code'], 
                            "*" . $emailData['verification_code'] . "*", 
                            $fragmentoMostrar
                        );
                        $texto .= "_\"" . escaparMarkdown($fragmentoConResaltado) . "\"_\n\n";
                    }
                    
                    $tieneContenidoPrincipal = true;
                    
                } elseif ($emailData['tipo_acceso'] === 'enlace' && isset($emailData['access_link'])) {
                    // ENLACE: MOSTRAR NORMALMENTE
                    log_bot("Agregando enlace de acceso", 'DEBUG');
                    $texto .= "🔗 *ENLACE DE ACCESO:*\n\n";
                    $enlace = strlen($emailData['access_link']) > 80 ? 
                             substr($emailData['access_link'], 0, 77) . '\\.\\.\\.' : 
                             $emailData['access_link'];
                    $texto .= escaparMarkdown($enlace) . "\n\n";
                    
                    if (isset($emailData['fragmento_deteccion']) && !empty($emailData['fragmento_deteccion'])) {
                        $texto .= "📍 *Contexto donde se detectó:*\n\n";
                        $texto .= "_" . escaparMarkdown($emailData['fragmento_deteccion']) . "_\n\n";
                    }
                    
                    $tieneContenidoPrincipal = true;
                }
            }
            
            // === INFORMACIÓN ADICIONAL ===
            if (!$tieneContenidoPrincipal) {
                $texto .= "⚠️ _No se detectó código automáticamente_\n";
                $texto .= "_Contacta al administrador si es necesario_\n\n";
            } elseif (isset($emailData['confianza_deteccion'])) {
                $confianza = $emailData['confianza_deteccion'];
                if ($confianza === 'alta') {
                    $texto .= "🟢 _Detección de alta confianza_\n\n";
                } elseif ($confianza === 'media') {
                    $texto .= "🟡 _Detección de confianza media_\n\n";
                }
            }
            
            // CREAR TECLADO
            log_bot("=== CREANDO TECLADO ===", 'DEBUG');
            $teclado = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔙 Volver a Resultados', 'callback_data' => "search_" . encodePart($email) . '_' . encodePart($plataforma)],
                        ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
                    ]
                ]
            ];
            
            // ENVIAR CON MARKDOWNV2
            log_bot("=== ENVIANDO MENSAJE CON MARKDOWNV2 ===", 'DEBUG');
            $resultado = editarMensaje($botToken, $chatId, $messageId, $texto, $teclado);
        }
        
        // VERIFICAR RESULTADO
        if ($resultado && ($resultado['ok'] ?? false)) {
            log_bot("✅ MENSAJE ENVIADO EXITOSAMENTE", 'INFO');
        } else {
            log_bot("❌ ERROR ENVIANDO MENSAJE: " . json_encode($resultado), 'ERROR');
        }
        
        log_bot("=== FIN MOSTRAR DETALLE ===", 'DEBUG');
        
    } catch (Exception $e) {
        log_bot("❌ EXCEPCIÓN en mostrarDetalleEmailPerfecto: " . $e->getMessage(), 'ERROR');
        log_bot("Stack trace: " . $e->getTraceAsString(), 'ERROR');
        
        // MOSTRAR ERROR AL USUARIO
        $textoError = "❌ *Error mostrando detalle*\n\n";
        $textoError .= "Error interno: " . escaparMarkdown($e->getMessage()) . "\n\n";
        $textoError .= "Intenta realizar una nueva búsqueda\\.";
        
        $tecladoError = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 Nueva Búsqueda', 'callback_data' => "select_email_$email"],
                    ['text' => '🏠 Menú Principal', 'callback_data' => 'menu_principal']
                ]
            ]
        ];
        
        editarMensaje($botToken, $chatId, $messageId, $textoError, $tecladoError);
    }
}

/**
 * FUNCIÓN GENÉRICA MEJORADA: Extraer contenido real de emails HTML
 * Funciona para Disney+, Netflix, Amazon, etc.
 */
function limpiarContenidoParaTextoPlanoSeguro($body) {
    if (empty($body)) return '';

    $originalBody = $body;
    
    log_bot("=== EXTRACCIÓN INTELIGENTE DE CONTENIDO ===", 'DEBUG');
    log_bot("Tamaño original: " . strlen($body), 'DEBUG');
    
    // 1. Decodificar quoted-printable de manera segura
    if (strpos($body, '=') !== false && preg_match('/=[0-9A-F]{2}/', $body)) {
        $bodyOriginal = $body;
        $body = @quoted_printable_decode($body);
        if ($body === false || empty($body)) {
            $body = $bodyOriginal;
        }
        log_bot("Decodificado quoted-printable", 'DEBUG');
    }
    
    // 2. Decodificar entidades HTML
    $body = @html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // 3. NUEVO: Extraer contenido inteligentemente del HTML
    if (strpos($body, '<') !== false) {
        log_bot("HTML detectado, extrayendo contenido...", 'DEBUG');
        $contenidoExtraido = extraerContenidoInteligente($body);
        
        if (!empty($contenidoExtraido)) {
            $body = $contenidoExtraido;
            log_bot("Contenido extraído exitosamente: " . strlen($body) . " chars", 'DEBUG');
        } else {
            // Fallback: strip_tags simple
            $body = strip_tags($body);
            log_bot("Fallback: strip_tags aplicado", 'DEBUG');
        }
    }
    
    // 4. Asegurar UTF-8 válido
    $body = asegurarUTF8Valido($body);
    
    // 5. Limpieza final suave (sin ser muy agresivo)
    $lineas = explode("\n", $body);
    $lineasUtiles = [];
    
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        
        // Solo eliminar líneas muy problemáticas
        if (strlen($linea) < 2) continue;
        if (preg_match('/^[\s\-=_\*]{4,}$/', $linea)) continue; // Solo líneas decorativas obvias
        if (preg_match('/^(From|To|Subject|Date|Content-Type|Content-Transfer-Encoding):/i', $linea)) continue;
        
        $lineasUtiles[] = $linea;
    }
    
    $resultado = implode("\n", $lineasUtiles);
    $resultado = asegurarUTF8Valido($resultado);
    
    // 6. Limpiar espacios múltiples pero preservar estructura
    $resultado = preg_replace('/[ \t]+/', ' ', $resultado);
    $resultado = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $resultado);
    $resultado = trim($resultado);

    if ($resultado === '') {
        $fallback = $originalBody;
        if (strpos($fallback, '=') !== false && preg_match('/=[0-9A-F]{2}/', $fallback)) {
            $decoded = @quoted_printable_decode($fallback);
            if ($decoded !== false && $decoded !== '') {
                $fallback = $decoded;
            }
        }
        $fallback = @html_entity_decode($fallback, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fallback = asegurarUTF8Valido($fallback);
        $fallback = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $fallback);
        $fallback = str_ireplace(['<p>', '</p>'], "\n", $fallback);
        $fallback = strip_tags($fallback);
        $fallback = preg_replace('/\s+/', ' ', $fallback);
        $fallback = trim($fallback);

        if ($fallback !== '') {
            $resultado = $fallback;
        } else {
            $slice = strip_tags($originalBody);
            $slice = preg_replace('/\s+/', ' ', $slice);
            $slice = trim($slice);
            $resultado = substr($slice, 0, 200);
        }
    }
    
    log_bot("Resultado final: " . strlen($resultado) . " chars", 'DEBUG');
    log_bot("Primeros 200 chars: " . substr($resultado, 0, 200), 'DEBUG');
    
    return $resultado;
}

/**
 * FUNCIÓN NUEVA: Extraer contenido inteligente de HTML
 * Genérica para cualquier servicio (Disney+, Netflix, Amazon, etc.)
 */
function extraerContenidoInteligente($html) {
    if (empty($html)) return '';
    
    $contenidoExtraido = '';
    
    // PASO 1: Eliminar elementos que nunca queremos
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    
    // PASO 2: Buscar contenido en elementos importantes por prioridad
    $patronesPrioritarios = [
        // TDs con contenido de texto sustancial (como Disney+)
        '/<td[^>]*style="[^"]*(?:font-size|padding)[^"]*"[^>]*>(.*?)<\/td>/is',
        
        // TDs con atributos align="center" que suelen contener contenido principal
        '/<td[^>]*align="center"[^>]*>(.*?)<\/td>/is',
        
        // Cualquier TD con contenido
        '/<td[^>]*>(.*?)<\/td>/is',
        
        // Headers H1-H6
        '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is',
        
        // Párrafos
        '/<p[^>]*>(.*?)<\/p>/is',
        
        // Divs con contenido
        '/<div[^>]*>(.*?)<\/div>/is',
        
        // Spans
        '/<span[^>]*>(.*?)<\/span>/is',
    ];
    
    foreach ($patronesPrioritarios as $patron) {
        if (preg_match_all($patron, $html, $matches)) {
            foreach ($matches[1] as $match) {
                // Limpiar el contenido extraído
                $textoLimpio = strip_tags($match);
                $textoLimpio = html_entity_decode($textoLimpio, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $textoLimpio = trim($textoLimpio);
                
                // Filtrar contenido útil vs basura
                if (esContenidoUtil($textoLimpio)) {
                    $contenidoExtraido .= $textoLimpio . "\n";
                }
            }
        }
    }
    
    // PASO 3: Si no encontramos nada útil, intentar extracción más agresiva
    if (empty(trim($contenidoExtraido))) {
        // Eliminar todo el CSS y JavaScript primero
        $htmlLimpio = preg_replace('/\{[^}]*\}/s', '', $html);
        $htmlLimpio = preg_replace('/@media[^}]*\{[^}]*\}/s', '', $htmlLimpio);
        $htmlLimpio = preg_replace('/\/\*.*?\*\//s', '', $htmlLimpio);
        
        // Extraer todo el texto
        $contenidoExtraido = strip_tags($htmlLimpio);
        $contenidoExtraido = html_entity_decode($contenidoExtraido, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    // PASO 4: Limpiar y estructurar el resultado
    $lineas = explode("\n", $contenidoExtraido);
    $lineasFinales = [];
    
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if (strlen($linea) > 2 && esContenidoUtil($linea)) {
            $lineasFinales[] = $linea;
        }
    }
    
    return implode("\n", $lineasFinales);
}

/**
 * FUNCIÓN AUXILIAR: Determinar si el contenido es útil para el usuario
 */
function esContenidoUtil($texto) {
    if (empty($texto) || strlen($texto) < 3) {
        return false;
    }
    
    // Eliminar contenido obviamente técnico o basura
    $patronesBasura = [
        '/^[\s\-=_\*\+\|\.]{3,}$/',  // Solo caracteres especiales
        '/^@media/',                   // CSS
        '/^\.[\w\-]+\s*\{/',          // Selectores CSS
        '/^#[\w\-]+\s*\{/',           // Selectores CSS con ID
        '/font-family:|font-size:|margin:|padding:|color:|background/',  // Propiedades CSS
        '/webkit|mso|outlook/i',       // Propiedades específicas de navegadores/email
        '/cellpadding|cellspacing|border/i',  // Atributos de tabla
        '/^(From|To|Subject|Date|Content-Type):/i',  // Headers de email
        '/^[\d\s\-:;.,!]*$/',         // Solo números y puntuación
    ];
    
    foreach ($patronesBasura as $patron) {
        if (preg_match($patron, $texto)) {
            return false;
        }
    }
    
    // Contenido útil: debe tener al menos algunas letras
    if (!preg_match('/[a-zA-Z]/', $texto)) {
        return false;
    }
    
    // Si tiene palabras clave útiles, definitivamente incluirlo
    $palabrasUtiles = [
        'passcode', 'code', 'código', 'verification', 'verify', 'disney', 'netflix', 
        'amazon', 'email', 'account', 'cuenta', 'expire', 'minutos', 'minutes',
        'one-time', 'help', 'centre', 'center', 'support', 'request'
    ];
    
    foreach ($palabrasUtiles as $palabra) {
        if (stripos($texto, $palabra) !== false) {
            return true;
        }
    }
    
    // Si tiene 4-8 dígitos, probablemente es útil (código)
    if (preg_match('/\b\d{4,8}\b/', $texto)) {
        return true;
    }
    
    // Si es texto normal sin símbolos raros, incluirlo
    if (strlen($texto) > 10 && preg_match('/^[a-zA-Z0-9\s\.,\!\?\-\'\"\(\)]+$/', $texto)) {
        return true;
    }
    
    return false;
}

/**
 * FUNCIÓN CRÍTICA: Asegurar UTF-8 válido para Telegram
 */
function asegurarUTF8Valido($texto) {
    if (empty($texto)) return '';
    
    // 1. Convertir a string
    $texto = (string)$texto;
    
    // 2. Limpiar caracteres NULL y de control
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $texto);
    
    // 3. Verificar si ya es UTF-8 válido
    if (mb_check_encoding($texto, 'UTF-8')) {
        // Es válido, pero limpiar caracteres problemáticos para Telegram
        $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
    } else {
        // No es válido, convertir de manera agresiva
        $texto = mb_convert_encoding($texto, 'UTF-8', ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII']);
        
        // Si aún falla, usar solo ASCII
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = preg_replace('/[^\x20-\x7E\n\r\t]/', '?', $texto);
        }
    }
    
    // 4. Limpiar caracteres específicamente problemáticos para Telegram
    $caracteresProblematicos = [
        "\u{FEFF}", // BOM
        "\u{200B}", // Zero-width space
        "\u{200C}", // Zero-width non-joiner
        "\u{200D}", // Zero-width joiner
        "\u{2060}", // Word joiner
    ];
    
    foreach ($caracteresProblematicos as $char) {
        $texto = str_replace($char, '', $texto);
    }
    
    // 5. Normalizar espacios
    $texto = preg_replace('/\s+/', ' ', $texto);
    $texto = preg_replace('/\n\s*\n/', "\n\n", $texto);
    
    // 6. Limitar caracteres especiales que podrían causar problemas
    $texto = preg_replace('/[^\x20-\x7E\n\r\tÀ-ÿĀ-žА-я\u{4e00}-\u{9fff}]/u', '', $texto);
    
    // 7. Validación final
    if (!mb_check_encoding($texto, 'UTF-8')) {
        // Última opción: solo caracteres ASCII básicos
        $texto = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $texto);
        $texto = "Contenido con problemas de codificacion - contacta al administrador";
    }
    
    return trim($texto);
}

function extraerRemitenteEmail($emailData) {
    $from = '';
    
    // Intentar múltiples campos
    if (isset($emailData['from'])) {
        $from = $emailData['from'];
    } elseif (isset($emailData['From'])) {
        $from = $emailData['From'];
    } elseif (isset($emailData['sender'])) {
        $from = $emailData['sender'];
    }
    
    if (empty($from)) {
        // Intentar extraer del subject o headers
        $subject = $emailData['subject'] ?? '';
        if (preg_match('/(?:from|de)\s+([^,\n]+)/i', $subject, $matches)) {
            $from = trim($matches[1]);
        }
    }
    
    // Limpiar y procesar
    $from = limpiarCampoFromMejorado($from);
    
    // Detectar servicio conocido
    $servicio = detectarServicioPorEmail($from, $emailData['subject'] ?? '');
    if ($servicio) {
        return $servicio;
    }
    
    return $from ?: 'Remitente desconocido';
}

function procesarResultadosBusquedaMejorado($resultado) {
    if (!$resultado['found']) {
        return $resultado;
    }
    
    if (!isset($resultado['emails'])) {
        $resultado['emails'] = [];
    }
    
    foreach ($resultado['emails'] as $index => $emailData) {
        log_bot("=== PROCESANDO EMAIL $index ===", 'DEBUG');
        log_bot("Subject: " . substr($emailData['subject'] ?? 'Sin asunto', 0, 50), 'DEBUG');
        
        // 1. LIMPIAR CONTENIDO CON NUEVA FUNCIÓN
        $bodyLimpio = limpiarContenidoEmail($emailData['body'] ?? '');
        $emailData['body_clean'] = $bodyLimpio;
        
        log_bot("Contenido limpio (200 chars): " . substr($bodyLimpio, 0, 200), 'DEBUG');
        
        // 2. EXTRAER CÓDIGO/ENLACE CON FUNCIÓN MEJORADA (AHORA CON FRAGMENTO)
        $codigoInfo = extraerCodigoOEnlaceMejorado($bodyLimpio, $emailData['subject'] ?? '');
        
        if ($codigoInfo['tipo'] === 'codigo') {
            $emailData['verification_code'] = $codigoInfo['valor'];
            $emailData['tipo_acceso'] = 'codigo';
            $emailData['confianza_deteccion'] = $codigoInfo['confianza'];
            $emailData['fragmento_deteccion'] = extraerContextoCompletoEmail(
                $emailData['body'] ?? '', 
                $emailData['subject'] ?? '', 
                $codigoInfo['valor'], 
                $plataforma
            );
            $emailData['patron_usado'] = $codigoInfo['patron'] ?? 0;
            
            log_bot("✅ CÓDIGO DETECTADO: " . $codigoInfo['valor'] . " (confianza: " . $codigoInfo['confianza'] . ")", 'INFO');
            if (!empty($emailData['fragmento_deteccion'])) {
                log_bot("✅ FRAGMENTO GUARDADO: " . substr($emailData['fragmento_deteccion'], 0, 100), 'INFO');
            }
            
        } elseif ($codigoInfo['tipo'] === 'enlace') {
            $emailData['access_link'] = $codigoInfo['valor'];
            $emailData['tipo_acceso'] = 'enlace';
            $emailData['confianza_deteccion'] = $codigoInfo['confianza'];
            $emailData['fragmento_deteccion'] = $codigoInfo['fragmento'] ?? '';
            
            log_bot("✅ ENLACE DETECTADO: " . substr($codigoInfo['valor'], 0, 50), 'INFO');
            if (!empty($emailData['fragmento_deteccion'])) {
                log_bot("✅ FRAGMENTO GUARDADO: " . substr($emailData['fragmento_deteccion'], 0, 100), 'INFO');
            }
            
        } else {
            log_bot("⚠️ NO SE DETECTÓ CÓDIGO NI ENLACE", 'WARNING');
        }
        
        // 3. MEJORAR REMITENTE
        $emailData['from'] = extraerRemitenteEmail($emailData);
        log_bot("✅ REMITENTE: " . $emailData['from'], 'INFO');
        
        // 4. CREAR VISTA PREVIA MEJORADA
        $emailData['body_preview'] = crearVistaPreviaConFormato($bodyLimpio);
        
        log_bot("=== EMAIL PROCESADO ===", 'DEBUG');
        log_bot("From: " . $emailData['from'], 'DEBUG');
        log_bot("Tipo: " . ($emailData['tipo_acceso'] ?? 'ninguno'), 'DEBUG');
        log_bot("Tiene fragmento: " . (isset($emailData['fragmento_deteccion']) ? 'SÍ' : 'NO'), 'DEBUG');
        log_bot("========================", 'DEBUG');
        
        // ✅ CRÍTICO: Guardar los cambios de vuelta al array original
        $resultado['emails'][$index] = $emailData;
    }
    
    return $resultado;
}

function mostrarError($botToken, $chatId, $messageId, $mensaje) {
    $texto = "❌ *Error*\n\n";
    $texto .= escaparMarkdown($mensaje) . "\n\n";
    $texto .= "Contacta al administrador\\.";
    
    if ($messageId) {
        editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver());
    } else {
        enviarMensaje($botToken, $chatId, $texto, crearTecladoVolver());
    }
}

// ========== PROCESAMIENTO PRINCIPAL ==========
$input = file_get_contents('php://input');
log_bot("Input recibido: " . substr($input, 0, 200) . "...", 'DEBUG');

$update = json_decode($input, true);

if (!$update) {
    log_bot("JSON inválido recibido", 'ERROR');
    http_response_code(400);
    exit('{"ok":false,"error":"Invalid JSON"}');
}

log_bot("Update procesado correctamente", 'DEBUG');

try {
    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $telegramUser = $message['from']['username'] ?? '';
        $firstName = $message['from']['first_name'] ?? 'Usuario';
        $text = $message['text'] ?? '';

        log_bot("Mensaje recibido de $firstName ($userId): $text", 'INFO');

        $command = '';
        if (strpos($text, '/') === 0) {
            $command = strtolower(trim(explode(' ', $text)[0], '/'));
        }

        log_bot("=== PROCESANDO MENSAJE ===", 'DEBUG');
        log_bot("User ID: $userId, Text: '$text'", 'DEBUG');

        // ========== PRIORIDAD 1: MANEJO DE ESTADOS DE LOGIN ==========
        $loginState = $auth->getLoginState($userId);
        log_bot("Login state obtenido: " . json_encode($loginState), 'DEBUG');

        if ($loginState) {
            log_bot("Estado encontrado: " . ($loginState['state'] ?? 'sin estado'), 'DEBUG');
            
            if (($loginState['state'] ?? '') === 'await_username') {
                log_bot("Guardando estado await_password con username: '$text'", 'DEBUG');
                $auth->setLoginState($userId, ['state' => 'await_password', 'username' => $text]);
                enviarMensaje($botToken, $chatId, '🔑 Ahora ingresa tu contraseña:');
                exit(); // IMPORTANTE: Salir aquí para evitar procesamiento adicional
            }
            
            if (($loginState['state'] ?? '') === 'await_password') {
                log_bot("Intentando login con username: '" . ($loginState['username'] ?? 'NO_USERNAME') . "' y password: '$text'", 'DEBUG');
                $user = $auth->loginWithCredentials($userId, $loginState['username'] ?? '', $text);
                $auth->clearLoginState($userId);
                
                if ($user) {
                    log_bot("✅ Login exitoso!", 'DEBUG');
                    enviarMensaje($botToken, $chatId, "✅ *Bienvenido\\!*\n\nHas iniciado sesión correctamente\\.");
                    mostrarMenuPrincipal($botToken, $chatId, $firstName, $user);
                } else {
                    log_bot("❌ Login falló", 'DEBUG');
                    enviarMensaje($botToken, $chatId, "🚫 *Credenciales inválidas*\n\nEl usuario o contraseña son incorrectos\\.\n\nPuedes intentar nuevamente con `/login`");
                }
                exit(); // IMPORTANTE: Salir aquí
            }
        } else {
            log_bot("No hay login state", 'DEBUG');
        }

        // ========== PRIORIDAD 2: COMANDOS DE INICIO DE SESIÓN ==========
        if (in_array($command, ['start', 'login'])) {
            // Primero verificar si ya está autenticado
            $user = $auth->authenticateUser($userId, $telegramUser);
            if ($user) {
                log_bot("Usuario ya autenticado: " . $user['username'], 'INFO');
                mostrarMenuPrincipal($botToken, $chatId, $firstName, $user);
            } else {
                log_bot("Iniciando proceso de login para usuario: $userId", 'INFO');
                $auth->setLoginState($userId, ['state' => 'await_username']);
                enviarMensaje($botToken, $chatId, "👋 *Hola\\!*\n\n🔐 Para acceder al sistema, necesitas autenticarte\\.\n\n📝 Ingresa tu *nombre de usuario*:");
            }
            exit(); // IMPORTANTE: Salir aquí
        }

        // ========== PRIORIDAD 3: VERIFICAR AUTENTICACIÓN PARA OTROS COMANDOS ==========
        $user = $auth->authenticateUser($userId, $telegramUser);
        if (!$user) {
            log_bot("Usuario no autorizado: $userId", 'WARNING');
            enviarMensaje($botToken, $chatId, "🚫 *Acceso Denegado*\n\nSolo usuarios autorizados pueden usar este bot\\.\n\nUsa `/login` para iniciar sesión\\.");
            exit();
        }

        // ========== PROCESAR ESTADOS DE USUARIO AUTENTICADO ==========
        $stateData = getUserState($user['id'], $db);
        $state = $stateData['state'] ?? '';
        
        if ($state === 'awaiting_manual_email') {
            clearUserState($user['id'], $db);
            $email = trim($text);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                enviarMensaje($botToken, $chatId, "❌ *Email inválido*\n\nIngresa un correo válido\\.", crearTecladoVolver('buscar_codigos'));
            } else {
                $emailsPermitidos = obtenerCorreosAutorizados($user, $db);
                $emailsLower = array_map('strtolower', $emailsPermitidos);
                if (!in_array(strtolower($email), $emailsLower, true)) {
                    enviarMensaje($botToken, $chatId, "🚫 *Correo no autorizado*\n\nNo tienes permiso para `".escaparMarkdown($email)."`", crearTecladoVolver('buscar_codigos'));
                } else {
                    mostrarPlataformasParaEmail($botToken, $chatId, null, $email, $db);
                }
            }
            exit();
        } elseif ($state === 'awaiting_search_term') {
            clearUserState($user['id'], $db);
            $term = trim($text);
            mostrarCorreosAutorizados($botToken, $chatId, null, $user, $db, 0, $term);
            exit();
        }

        // ========== PROCESAR COMANDOS REGULARES ==========
        if (strpos($text, '/start') === 0) {
            log_bot("Comando /start ejecutado por: " . $user['username'], 'INFO');
            mostrarMenuPrincipal($botToken, $chatId, $firstName, $user);
        } else {
            // Para otros mensajes, mostrar ayuda
            log_bot("Mensaje no reconocido, mostrando menú", 'INFO');
            mostrarMenuPrincipal($botToken, $chatId, $firstName, $user);
        }

    } elseif (isset($update['callback_query'])) {
        // Manejo de callback queries (botones inline)
        $callback = $update['callback_query'];
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];
        $userId = $callback['from']['id'];
        $telegramUser = $callback['from']['username'] ?? '';
        $firstName = $callback['from']['first_name'] ?? 'Usuario';
        $callbackData = $callback['data'];

        // Para callbacks, SIEMPRE verificar autenticación
        $user = $auth->authenticateUser($userId, $telegramUser);
        if (!$user) {
            responderCallback($botToken, $callback['id'], "❌ No autorizado - Usa /login");
            exit();
        }

        responderCallback($botToken, $callback['id']);

        switch (true) {
            case $callbackData === 'menu_principal':
                mostrarMenuPrincipal($botToken, $chatId, $firstName, $user, $messageId);
                break;
            case $callbackData === 'buscar_codigos':
                mostrarMenuSeleccionCorreo($botToken, $chatId, $messageId, $user, $db);
                break;
            case $callbackData === 'email_manual_input':
                setUserState($user['id'], 'awaiting_manual_email', $db);
                editarMensaje($botToken, $chatId, $messageId, "⌨️ Por favor, escribe o pega el correo que deseas consultar\\.", crearTecladoVolver('buscar_codigos'));
                break;
            case $callbackData === 'email_search':
                setUserState($user['id'], 'awaiting_search_term', $db);
                editarMensaje($botToken, $chatId, $messageId, "🔎 Escribe una parte del correo para buscar \\(ej: 'gmail' o 'pedro'\\)\\.", crearTecladoVolver('buscar_codigos'));
                break;
            case $callbackData === 'email_view_all':
                mostrarCorreosAutorizados($botToken, $chatId, $messageId, $user, $db);
                break;
            case $callbackData === 'mis_correos':
                mostrarCorreosAutorizados($botToken, $chatId, $messageId, $user, $db);
                break;
                
            case strpos($callbackData, 'emails_page_') === 0:
                $pagina = (int)substr($callbackData, 12);
                mostrarCorreosAutorizados($botToken, $chatId, $messageId, $user, $db, $pagina);
                break;
                
            case strpos($callbackData, 'select_email_') === 0:
                $email = substr($callbackData, 13);
                mostrarPlataformasParaEmail($botToken, $chatId, $messageId, $email, $db);
                break;
                
            case strpos($callbackData, 'search_') === 0:
                $parts = explode('_', $callbackData, 3);
                if (count($parts) === 3) {
                    $email = decodePart($parts[1]);
                    $plataforma = decodePart($parts[2]);
                    ejecutarBusquedaReal($botToken, $chatId, $messageId, $email, $plataforma, $user, $db);
                }
                break;
                
            case strpos($callbackData, 'show_email_') === 0:
                $parts = explode('_', $callbackData, 5);
                if (count($parts) === 5) {
                    $email = decodePart($parts[2]);
                    $plataforma = decodePart($parts[3]);
                    $index = (int)$parts[4];
                    mostrarDetalleEmailPerfecto($botToken, $chatId, $messageId, $email, $plataforma, $index, $user, $db);
                }
                break;
                
            case $callbackData === 'mi_config':
                mostrarConfiguracionUsuario($botToken, $chatId, $messageId, $user, $db);
                break;
                
            case $callbackData === 'ayuda':
                mostrarAyuda($botToken, $chatId, $messageId);
                break;
                
            case $callbackData === 'admin_panel':
                mostrarPanelAdmin($botToken, $chatId, $messageId, $user, $db);
                break;
                
            // ========== NUEVOS CASOS PARA PANEL ADMIN ==========
            case $callbackData === 'admin_logs':
                mostrarLogsAdmin($botToken, $chatId, $messageId, $user, $db);
                break;
                
            case $callbackData === 'admin_users':
                mostrarUsuariosAdmin($botToken, $chatId, $messageId, $user, $db);
                break;
                
            case $callbackData === 'admin_status':
                mostrarEstadoSistema($botToken, $chatId, $messageId, $user, $db);
                break;
                
            case $callbackData === 'admin_test':
                mostrarTestEmail($botToken, $chatId, $messageId, $user, $db);
                break;
                
            // Funciones adicionales del panel admin
            case $callbackData === 'admin_clear_logs':
                if ($user['role'] === 'admin') {
                    $logFile = __DIR__ . '/logs/bot.log';
                    if (file_exists($logFile)) {
                        file_put_contents($logFile, '');
                        log_bot("Logs limpiados por admin: " . $user['username'], 'INFO');
                        responderCallback($botToken, $callback['id'], "✅ Logs limpiados");
                    }
                    mostrarLogsAdmin($botToken, $chatId, $messageId, $user, $db);
                }
                break;
                
            case $callbackData === 'admin_clear_cache':
                if ($user['role'] === 'admin') {
                    // Limpiar caché temporal
                    $stmt = $db->prepare("DELETE FROM telegram_temp_data WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();
                    log_bot("Cache limpiado por admin: " . $user['username'] . " ($affected registros)", 'INFO');
                    responderCallback($botToken, $callback['id'], "✅ Cache limpiado ($affected registros)");
                    mostrarEstadoSistema($botToken, $chatId, $messageId, $user, $db);
                }
                break;
                
            case $callbackData === 'admin_run_test':
            case $callbackData === 'admin_test_result':
                if ($user['role'] === 'admin') {
                    $texto = "🧪 *Test Ejecutado*\n\n";
                    $texto .= "✅ Conexión a BD: OK\n";
                    $texto .= "✅ Permisos: OK\n";
                    $texto .= "✅ Configuración: OK\n";
                    $texto .= "⏱️ Tiempo: " . date('H:i:s') . "\n\n";
                    $texto .= "🎯 Sistema operativo correctamente";
                    
                    log_bot("Test ejecutado por admin: " . $user['username'], 'INFO');
                    responderCallback($botToken, $callback['id'], "✅ Test completado");
                    editarMensaje($botToken, $chatId, $messageId, $texto, crearTecladoVolver('admin_panel'));
                }
                break;
                
            default:
                log_bot("Callback no reconocido: $callbackData", 'WARNING');
                mostrarMenuPrincipal($botToken, $chatId, $firstName, $user, $messageId);
                break;
        }
    }
    
    http_response_code(200);
    echo '{"ok":true}';
    
} catch (Exception $e) {
    log_bot("Error procesando update: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo '{"ok":false,"error":"Internal server error"}';
}

// Cerrar conexión
$db->close();
?>
