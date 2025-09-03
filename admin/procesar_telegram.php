<?php
require_once __DIR__ . '/../config/path_constants.php';
/**
 * Procesador de configuraci¨®n del bot de Telegram CORREGIDO
 * Ahora guarda en la tabla 'settings' (sistema principal)
 * Reemplaza el archivo admin/procesar_telegram.php existente
 */

session_start();
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once PROJECT_ROOT . '/shared/Crypto.php';
require_once PROJECT_ROOT . '/shared/AuditLogger.php';
require_once SECURITY_DIR . '/auth.php';
use Shared\DatabaseManager;
use Shared\Crypto;
use Shared\AuditLogger;

// Verificar autenticaci¨®n de administrador
authorize('manage_telegram', '../index.php', false);

try {
    $conn = DatabaseManager::getInstance()->getConnection();
} catch (\Throwable $e) {
    die("Error de conexión: " . $e->getMessage());
}

$action = filter_var($_POST['action'] ?? '', FILTER_SANITIZE_STRING);

try {
    switch ($action) {
        case 'save_config':
            $token = filter_var(trim($_POST['token'] ?? ''), FILTER_SANITIZE_STRING);
            $webhook = filter_var(trim($_POST['webhook'] ?? ''), FILTER_SANITIZE_URL);
            $webhook_secret = filter_var(trim($_POST['webhook_secret'] ?? ''), FILTER_SANITIZE_STRING);
            
            // Validaciones b¨¢sicas
            if (empty($token)) {
                throw new Exception('El token del bot es requerido');
            }
            
            if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token)) {
                throw new Exception('Formato de token inv¨¢lido');
            }
            
            if (!empty($webhook) && !filter_var($webhook, FILTER_VALIDATE_URL)) {
                throw new Exception('URL del webhook inv¨¢lida');
            }
            // Guardar en tabla 'settings' (cifrado)
            $encToken = Crypto::encrypt($token);
            $stmt = $conn->prepare("UPDATE settings SET value = ? WHERE name = 'TELEGRAM_BOT_TOKEN'");
            $stmt->bind_param("s", $encToken);
            $stmt->execute();
            $stmt->close();

            if (!empty($webhook)) {
                $stmt = $conn->prepare("INSERT INTO settings (name, value, description, category) VALUES ('TELEGRAM_WEBHOOK_URL', ?, 'URL del webhook de Telegram', 'telegram') ON DUPLICATE KEY UPDATE value = VALUES(value)");
                $stmt->bind_param("s", $webhook);
                $stmt->execute();
                $stmt->close();
            }

            if (!empty($webhook_secret)) {
                $encSecret = Crypto::encrypt($webhook_secret);
                $stmt = $conn->prepare("INSERT INTO settings (name, value, description, category) VALUES ('TELEGRAM_WEBHOOK_SECRET', ?, 'Secreto del webhook de Telegram', 'telegram') ON DUPLICATE KEY UPDATE value = VALUES(value)");
                $stmt->bind_param("s", $encSecret);
                $stmt->execute();
                $stmt->close();
            }
            
            // Registrar en logs
            $log_message = "Configuraci¨®n del bot actualizada - Token: " . substr($token, 0, 10) . "...";
            if (!empty($webhook)) {
                $log_message .= ", Webhook: $webhook";
            }
            
            // Log opcional en tabla si existe
            $stmt = $conn->prepare("INSERT INTO telegram_bot_logs (user_id, telegram_user_id, action_type, action_data, response_status) VALUES (?, 0, 'config_update', ?, 'success')");
            $log_data = json_encode(['token_updated' => true, 'webhook_updated' => !empty($webhook)]);
            $stmt->bind_param("is", $_SESSION['user_id'], $log_data);
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['success_message'] = 'Configuraci¨®n del bot guardada correctamente';
            AuditLogger::log($_SESSION['user_id'] ?? null, 'config_update_telegram');
            break;
            
        case 'test_webhook':
            $token = filter_var(trim($_POST['token'] ?? ''), FILTER_SANITIZE_STRING);
            $webhook = filter_var(trim($_POST['webhook'] ?? ''), FILTER_SANITIZE_URL);
            
            if (empty($token) || empty($webhook)) {
                throw new Exception('Token y webhook son requeridos para la prueba');
            }
            
            // Probar conexi¨®n con Telegram API
            $url = "https://api.telegram.org/bot$token/getMe";
            $response = file_get_contents($url);
            $result = json_decode($response, true);
            
            if (!$result['ok']) {
                throw new Exception('Token inv¨¢lido: ' . ($result['description'] ?? 'Error desconocido'));
            }
            
            // Registrar webhook
            $webhook_url = "https://api.telegram.org/bot$token/setWebhook";
            $webhook_data = http_build_query(['url' => $webhook]);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => $webhook_data
                ]
            ]);
            
            $webhook_response = file_get_contents($webhook_url, false, $context);
            $webhook_result = json_decode($webhook_response, true);
            
            if (!$webhook_result['ok']) {
                throw new Exception('Error registrando webhook: ' . ($webhook_result['description'] ?? 'Error desconocido'));
            }
            
            $_SESSION['success_message'] = 'Bot probado exitosamente. Webhook registrado.';
            break;
            
        case 'disable_bot':
            // Deshabilitar bot
            $stmt = $conn->prepare("UPDATE settings SET value = '0' WHERE name = 'TELEGRAM_BOT_ENABLED'");
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['success_message'] = 'Bot de Telegram deshabilitado';
            break;
            
        case 'enable_bot':
            // Habilitar bot
            $stmt = $conn->prepare("UPDATE settings SET value = '1' WHERE name = 'TELEGRAM_BOT_ENABLED'");
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['success_message'] = 'Bot de Telegram habilitado';
            break;
            
        default:
            throw new Exception('Acci¨®n no v¨¢lida');
    }
    
} catch (Exception $e) {
    $_SESSION['error_message'] = $e->getMessage();
}

$conn->close();

// Redireccionar de vuelta al panel
$redirect_url = $_POST['redirect'] ?? 'telegram_management.php';
header("Location: $redirect_url");
exit;
?>
