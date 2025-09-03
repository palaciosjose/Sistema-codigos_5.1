<?php
require_once __DIR__ . '/../config/path_constants.php';
/**
 * Test de conexión IMAP - Versión Simple
 * Solo lo esencial para que funcione tu botón de prueba
 */

session_start();

// Solo peticiones POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Método no permitido']));
}

// Incluir archivos necesarios
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once SECURITY_DIR . '/auth.php';
use Shared\DatabaseManager;

try {
    // Verificar que sea admin usando la función del sistema
    if (!is_admin()) {
        http_response_code(401);
        die(json_encode(['success' => false, 'error' => 'No autorizado']));
    }
    $server = filter_var(trim($_POST['imap_server'] ?? ''), FILTER_SANITIZE_STRING);
    $port = filter_var($_POST['imap_port'] ?? 993, FILTER_VALIDATE_INT);
    $username = filter_var(trim($_POST['imap_user'] ?? ''), FILTER_SANITIZE_STRING);
    $password = trim($_POST['imap_password'] ?? '');
    $server_id = filter_var($_POST['server_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($port === false) {
        $port = 993;
    }
    
    // Validaciones básicas
    if (empty($server) || empty($username)) {
        throw new Exception('Servidor y usuario son obligatorios');
    }
    
    // Si la contraseña viene como **********, obtener la real de la BD
    if ($password === '**********' || empty($password)) {
        if ($server_id > 0) {
            $conn = DatabaseManager::getInstance()->getConnection();
            
            $stmt = $conn->prepare("SELECT imap_password FROM email_servers WHERE id = ?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $password = $row['imap_password'];
            }
            
            $conn->close();
        }
        
        if (empty($password)) {
            throw new Exception('No se encontró contraseña');
        }
    }
    
    // Probar conexión IMAP
    $details = [];
    
    // Intentar con SSL primero
    ini_set('default_socket_timeout', 10);
    $mailbox_ssl = "{" . $server . ":" . $port . "/imap/ssl/novalidate-cert}";
    $details[] = "Probando conexión SSL en puerto $port...";
    
    $inbox = @imap_open($mailbox_ssl, $username, $password);
    
    if ($inbox) {
        $details[] = "✅ Conexión SSL exitosa";
        $check = @imap_check($inbox);
        $server_info = [
            'server' => $server,
            'port' => $port,
            'user' => $username,
            'messages' => $check ? $check->Nmsgs : 0
        ];
        @imap_close($inbox);
        
        echo json_encode([
            'success' => true,
            'details' => $details,
            'server_info' => $server_info
        ]);
        exit();
    }
    
    // Si SSL falla, probar sin SSL
    $details[] = "SSL falló, probando sin SSL...";
    $mailbox_plain = "{" . $server . ":" . $port . "/imap/notls}";
    
    $inbox = @imap_open($mailbox_plain, $username, $password);
    
    if ($inbox) {
        $details[] = "✅ Conexión sin SSL exitosa";
        $details[] = "⚠️ Recomendación: usar puerto 993 con SSL";
        
        $check = @imap_check($inbox);
        $server_info = [
            'server' => $server,
            'port' => $port,
            'user' => $username,
            'messages' => $check ? $check->Nmsgs : 0
        ];
        @imap_close($inbox);
        
        echo json_encode([
            'success' => true,
            'details' => $details,
            'server_info' => $server_info
        ]);
        exit();
    }
    
    // Ambos fallaron
    $error = imap_last_error() ?: 'Conexión rechazada';
    $details[] = "❌ Error: " . $error;
    
    echo json_encode([
        'success' => false,
        'error' => $error,
        'details' => $details
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'details' => ['❌ ' . $e->getMessage()]
    ]);
}
?>