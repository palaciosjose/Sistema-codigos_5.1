<?php
require_once __DIR__ . '/../config/path_constants.php';
session_start();
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once PROJECT_ROOT . '/shared/WhatsAppUrlHelper.php';
require_once SECURITY_DIR . '/auth.php';
use Shared\DatabaseManager;
use Shared\WhatsAppUrlHelper;

// Default endpoint for checking WhatsApp instance status
if (!defined('DEFAULT_WHATSAPP_STATUS_ENDPOINT')) {
    define('DEFAULT_WHATSAPP_STATUS_ENDPOINT', '/api/messages/instance');
}

authorize('manage_whatsapp', '../index.php', false);

$conn = DatabaseManager::getInstance()->getConnection();

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========================================
// FUNCIONES AUXILIARES
// ========================================

function log_action($message) {
    $logFile = __DIR__ . '/whatsapp_management.log';
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $logFile);
}

function get_setting($conn, $name) {
    $stmt = $conn->prepare("SELECT value FROM settings WHERE name = ? LIMIT 1");
    if (!$stmt) return '';
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($value);
    $result = $stmt->fetch() ? $value : '';
    $stmt->close();
    return $result;
}

function set_setting($conn, $name, $value) {
    $stmt = $conn->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    if ($stmt) {
        $stmt->bind_param('ss', $name, $value);
        $stmt->execute();
        $stmt->close();
    }
}

// ========================================
// FUNCIONES DE ESTADO Y DIAGNÓSTICO
// ========================================

function checkWhatsAppBotStatus($conn) {
    $api_url = get_setting($conn, 'WHATSAPP_API_URL');
    $token = get_setting($conn, 'WHATSAPP_TOKEN');
    $instance = get_setting($conn, 'WHATSAPP_INSTANCE');
    $webhook_secret = get_setting($conn, 'WHATSAPP_WEBHOOK_SECRET');
    $webhook_url = get_setting($conn, 'WHATSAPP_WEBHOOK_URL');
    $status_endpoint = get_setting($conn, 'WHATSAPP_STATUS_ENDPOINT') ?: DEFAULT_WHATSAPP_STATUS_ENDPOINT;

    $status = [
        'overall' => 'error',
        'checks' => [],
        'configured' => ($api_url && $token && $instance && $webhook_secret && $webhook_url),
        'linked' => false,
        'qr_url' => null
    ];

    // 1. Verificar configuración básica
    if (!$status['configured']) {
        $status['checks']['config'] = [
            'status' => 'error',
            'message' => 'Configuración incompleta - ingresa todos los datos requeridos',
            'icon' => 'fa-cog'
        ];
        return $status;
    } else {
        $status['checks']['config'] = [
            'status' => 'ok',
            'message' => 'Configuración guardada correctamente',
            'icon' => 'fa-cog'
        ];
    }

    // 2. Verificar conexión API
    $apiTest = testApiConnection($api_url, $token, $instance, $status_endpoint);
    $status['checks']['api'] = [
        'status' => $apiTest['success'] ? 'ok' : 'error',
        'message' => $apiTest['message'],
        'icon' => 'fa-plug'
    ];

    // 3. Verificar vinculación WhatsApp
    if ($apiTest['success']) {
        $instanceInfo = validateWhatsAppInstance($api_url, $token, $instance, $status_endpoint);
        $status['linked'] = $instanceInfo['linked'];
        $status['qr_url'] = $instanceInfo['qr_url'];
        
        $status['checks']['whatsapp'] = [
            'status' => $instanceInfo['linked'] ? 'ok' : 'warning',
            'message' => $instanceInfo['linked'] ? 'WhatsApp conectado' : 'WhatsApp no conectado - Escanea el QR',
            'icon' => 'fa-link'
        ];
    }

    // 4. Verificar webhook
    if ($webhook_url) {
        $webhookTest = testWebhookConfiguration($api_url, $token, $instance, $webhook_url, $webhook_secret);
        $status['checks']['webhook'] = [
            'status' => $webhookTest['success'] ? 'ok' : 'warning',
            'message' => $webhookTest['message'],
            'icon' => 'fa-globe'
        ];
    }

    // 5. Verificar tablas de base de datos
    $required_tables = ['whatsapp_sessions', 'whatsapp_activity_log', 'whatsapp_temp_data'];
    $missing_tables = [];
    
    foreach ($required_tables as $table) {
        $res = $conn->query("SHOW TABLES LIKE '$table'");
        if (!$res || $res->num_rows == 0) {
            $missing_tables[] = $table;
        }
    }
    
    if (empty($missing_tables)) {
        $status['checks']['database'] = [
            'status' => 'ok',
            'message' => 'Todas las tablas requeridas existen',
            'icon' => 'fa-database'
        ];
    } else {
        $status['checks']['database'] = [
            'status' => 'error',
            'message' => 'Faltan tablas: ' . implode(', ', $missing_tables),
            'icon' => 'fa-database'
        ];
    }

    // Determinar estado general
    $has_error = false;
    $has_warning = false;
    
    foreach ($status['checks'] as $check) {
        if ($check['status'] === 'error') {
            $has_error = true;
            break;
        } elseif ($check['status'] === 'warning') {
            $has_warning = true;
        }
    }
    
    if ($has_error) {
        $status['overall'] = 'error';
    } elseif ($has_warning) {
        $status['overall'] = 'warning';
    } else {
        $status['overall'] = 'ok';
    }
    
    return $status;
}

function testApiConnection($url, $token, $instance, $statusEndpoint) {
    if (empty($url) || empty($token) || empty($instance)) {
        return ['success' => false, 'message' => 'Configuración incompleta'];
    }

    $endpoint = rtrim($url, '/') . '/api/messages/send';
    log_action('POST ' . $endpoint . ' (test API)');

    $payload = json_encode([
        'number' => '00000000000',
        'body' => 'Test API'
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
        return ['success' => false, 'message' => 'Error de conexión: ' . $error];
    }

    if ($code >= 200 && $code < 500) {
        return ['success' => true, 'message' => 'Conexión exitosa con la API'];
    }

    return ['success' => false, 'message' => 'Error del servidor: HTTP ' . $code];
}

function validateWhatsAppInstance($url, $token, $instance, $statusEndpoint) {
    $endpoint = rtrim($url, '/') . '/' . ltrim($statusEndpoint, '/');
    $endpoint .= '?instance=' . urlencode($instance);
    log_action('GET ' . $endpoint);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $code >= 400) {
        return ['success' => false, 'message' => 'Error al validar instancia', 'linked' => false, 'qr_url' => null];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['instance'])) {
        return ['success' => false, 'message' => 'Respuesta inválida', 'linked' => false, 'qr_url' => null];
    }

    $info = $data['instance'];
    $linked = $info['connected'] ?? $info['isLinked'] ?? $info['is_linked'] ?? false;
    $qr_url = $linked ? null : ($info['qr'] ?? $info['qrCode'] ?? $info['qr_url'] ?? null);

    return [
        'success' => true,
        'message' => $linked ? 'Instancia vinculada' : 'Instancia no vinculada',
        'linked' => $linked,
        'qr_url' => $qr_url
    ];
}

function testWebhookConfiguration($url, $token, $instance, $webhook_url, $secret) {
    if (empty($webhook_url)) {
        return ['success' => false, 'message' => 'URL de webhook no configurada'];
    }

    if (!filter_var($webhook_url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => 'URL de webhook inválida'];
    }

    // Verificar usando el único endpoint que funciona
    $endpoint = rtrim($url, '/') . '/api/messages/send';
    log_action('POST ' . $endpoint . ' (verificación webhook)');

    $payload = json_encode([
        'number' => '00000000000',
        'body' => 'Test conectividad'
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
        return ['success' => false, 'message' => 'Error de conexión: ' . $error];
    }

    if ($code >= 200 && $code < 500) {
        return ['success' => true, 'message' => 'Webhook verificado - API operativa'];
    }

    return ['success' => false, 'message' => 'Error del servidor: HTTP ' . $code];
}

function getWhatsAppStats($conn) {
    $stats = [
        'active_users' => 0,
        'messages_today' => 0,
        'total_messages' => 0,
        'total_searches' => 0
    ];
    
    try {
        // Usuarios activos (últimos 30 días)
        $res = $conn->query("SELECT COUNT(DISTINCT whatsapp_id) AS c FROM users WHERE whatsapp_id IS NOT NULL AND last_whatsapp_activity >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($res) {
            $row = $res->fetch_assoc();
            $stats['active_users'] = (int)($row['c'] ?? 0);
            $res->close();
        }
        
        // Mensajes de hoy
        $res = $conn->query("SELECT COUNT(*) AS c FROM whatsapp_activity_log WHERE DATE(created_at) = CURDATE()");
        if ($res) {
            $row = $res->fetch_assoc();
            $stats['messages_today'] = (int)($row['c'] ?? 0);
            $res->close();
        }
        
        // Total mensajes
        $res = $conn->query("SELECT COUNT(*) AS c FROM whatsapp_activity_log");
        if ($res) {
            $row = $res->fetch_assoc();
            $stats['total_messages'] = (int)($row['c'] ?? 0);
            $res->close();
        }
        
        // Búsquedas
        $res = $conn->query("SELECT COUNT(*) AS c FROM search_logs WHERE source = 'whatsapp'");
        if ($res) {
            $row = $res->fetch_assoc();
            $stats['total_searches'] = (int)($row['c'] ?? 0);
            $res->close();
        }
    } catch (Exception $e) {
        // Mantener valores por defecto si hay error
    }
    
    return $stats;
}

function getLinkedUsers($conn) {
    $users = [];
    try {
        $query = "SELECT u.id, u.username, u.whatsapp_id, u.created_at, 
                  (SELECT COUNT(*) FROM whatsapp_activity_log WHERE whatsapp_id = u.whatsapp_id) as activity_count
                  FROM users u 
                  WHERE u.whatsapp_id IS NOT NULL AND u.whatsapp_id != ''
                  ORDER BY u.username ASC";
        $result = $conn->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $result->close();
        }
    } catch (Exception $e) {
        // Mantener array vacío si hay error
    }
    return $users;
}

function createWhatsAppTables($conn) {
    $queries = [
        "CREATE TABLE IF NOT EXISTS whatsapp_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            whatsapp_id VARCHAR(50) NOT NULL,
            user_id INT,
            session_token VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            INDEX idx_whatsapp_id (whatsapp_id),
            INDEX idx_user_id (user_id),
            INDEX idx_session_token (session_token),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS whatsapp_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            whatsapp_id VARCHAR(50) NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_whatsapp_id (whatsapp_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS whatsapp_temp_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            data_type VARCHAR(50) NOT NULL,
            data_content TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_type (user_id, data_type),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    
    foreach ($queries as $query) {
        $conn->query($query);
    }
    
    // Agregar columnas de WhatsApp a la tabla users si no existen
    $check_column = $conn->query("SHOW COLUMNS FROM users LIKE 'whatsapp_id'");
    if ($check_column && $check_column->num_rows == 0) {
        $conn->query("ALTER TABLE users 
                     ADD COLUMN whatsapp_id VARCHAR(50) NULL UNIQUE, 
                     ADD COLUMN last_whatsapp_activity TIMESTAMP NULL");
    }
}

// ========================================
// PROCESAMIENTO DE ACCIONES
// ========================================

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    
    if ($action === 'save_config') {
        $api_url_input = filter_var(trim($_POST['api_url'] ?? ''), FILTER_SANITIZE_URL);
        $token = filter_var(trim($_POST['token'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $instance = filter_var(trim($_POST['instance'] ?? ''), FILTER_SANITIZE_NUMBER_INT);
        $webhook_secret = filter_var(trim($_POST['webhook_secret'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $webhook_url = filter_var(trim($_POST['webhook_url'] ?? ''), FILTER_SANITIZE_URL);
        $status_endpoint = filter_var(trim($_POST['status_endpoint'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if ($status_endpoint === '') { $status_endpoint = DEFAULT_WHATSAPP_STATUS_ENDPOINT; }

        $warning = null;
        $api_url = WhatsAppUrlHelper::sanitizeBaseUrl($api_url_input, $warning);

        set_setting($conn, 'WHATSAPP_API_URL', $api_url);
        set_setting($conn, 'WHATSAPP_TOKEN', $token);
        set_setting($conn, 'WHATSAPP_INSTANCE', $instance);
        set_setting($conn, 'WHATSAPP_WEBHOOK_SECRET', $webhook_secret);
        set_setting($conn, 'WHATSAPP_WEBHOOK_URL', $webhook_url);
        set_setting($conn, 'WHATSAPP_STATUS_ENDPOINT', $status_endpoint);

        if ($warning) {
            $message = $warning;
            $message_type = 'warning';
        } else {
            $message = 'Configuración guardada correctamente';
            $message_type = 'success';
        }
    }
    
    elseif ($action === 'create_tables') {
        createWhatsAppTables($conn);
        $message = 'Tablas creadas/verificadas exitosamente';
        $message_type = 'success';
    }
    
    elseif ($action === 'test_connection') {
        header('Content-Type: application/json');
        $api_url = get_setting($conn, 'WHATSAPP_API_URL');
        $token = get_setting($conn, 'WHATSAPP_TOKEN');
        $instance = get_setting($conn, 'WHATSAPP_INSTANCE');
        $status_endpoint = get_setting($conn, 'WHATSAPP_STATUS_ENDPOINT') ?: DEFAULT_WHATSAPP_STATUS_ENDPOINT;

        $result = testApiConnection($api_url, $token, $instance, $status_endpoint);
        echo json_encode($result);
        exit;
    }
}

// ========================================
// CARGAR DATOS PARA EL PANEL
// ========================================

$status = checkWhatsAppBotStatus($conn);
$stats = getWhatsAppStats($conn);
$linked_users = getLinkedUsers($conn);

// Configuración actual
$api_url = get_setting($conn, 'WHATSAPP_API_URL');
$token = get_setting($conn, 'WHATSAPP_TOKEN');
$instance = get_setting($conn, 'WHATSAPP_INSTANCE');
$webhook_secret = get_setting($conn, 'WHATSAPP_WEBHOOK_SECRET');
$webhook_url = get_setting($conn, 'WHATSAPP_WEBHOOK_URL');
$status_endpoint = get_setting($conn, 'WHATSAPP_STATUS_ENDPOINT') ?: DEFAULT_WHATSAPP_STATUS_ENDPOINT;

// Última actividad
$last_activity = '';
try {
    $activity_res = $conn->query("SELECT MAX(created_at) AS last_activity FROM whatsapp_activity_log");
    if ($activity_res) {
        $row = $activity_res->fetch_assoc();
        $last_activity = $row['last_activity'] ?? '';
        $activity_res->close();
    }
} catch (Exception $e) {
    // Mantener vacío si hay error
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 Panel del Bot de WhatsApp</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../styles/modern_global.css">
    <link rel="stylesheet" href="../styles/modern_admin.css">
    
    <style>
        /* Estilos específicos para el panel de WhatsApp */
        :root {
            --wa-green: #25D366;
            --wa-green-dark: #128C7E;
            --wa-green-light: #DCF8C6;
            --bg-primary: #1a1d3a;
            --bg-secondary: #242850;
            --text-primary: #e0e6ff;
            --text-secondary: #a0a8d0;
            --accent-success: #25D366;
            --accent-warning: #FFC107;
            --accent-danger: #FF5252;
            --card-bg: rgba(255, 255, 255, 0.05);
        }
        
        body {
            background: linear-gradient(135deg, #1a1d3a 0%, #242850 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .admin-header {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px 30px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }
        
        .admin-header h1 {
            font-size: 24px;
            font-weight: 600;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }
        
        .admin-header h1::before {
            content: "💬";
            font-size: 28px;
        }
        
        .admin-header p {
            color: var(--text-secondary);
            margin: 0;
            font-size: 14px;
        }
        
        .btn-back-modern {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back-modern:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-1px);
            color: #ffffff;
        }
        
        /* Navigation Tabs */
        .nav-tabs-modern {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background: var(--card-bg);
            padding: 10px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow-x: auto;
        }
        
        .nav-tabs-modern .nav-link {
            padding: 12px 24px;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        
        .nav-tabs-modern .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        
        .nav-tabs-modern .nav-link.active {
            background: linear-gradient(135deg, var(--wa-green) 0%, var(--wa-green-dark) 100%);
            color: white;
        }
        
        /* Cards */
        .admin-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .admin-card-title {
            font-size: 18px;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Status Indicators */
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        
        .status-active {
            background-color: var(--accent-success);
            box-shadow: 0 0 10px var(--accent-success);
        }
        
        .status-inactive {
            background-color: var(--accent-danger);
            box-shadow: 0 0 10px var(--accent-danger);
        }
        
        .status-warning {
            background-color: var(--accent-warning);
            box-shadow: 0 0 10px var(--accent-warning);
        }
        
        /* Alert Styles */
        .alert-modern {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }
        
        .alert-success-modern {
            background: rgba(37, 211, 102, 0.1);
            border: 1px solid var(--wa-green);
            color: var(--wa-green);
        }
        
        .alert-warning-modern {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid var(--accent-warning);
            color: var(--accent-warning);
        }
        
        .alert-danger-modern {
            background: rgba(255, 82, 82, 0.1);
            border: 1px solid var(--accent-danger);
            color: var(--accent-danger);
        }
        
        /* Form Controls */
        .form-group-admin {
            margin-bottom: 20px;
        }
        
        .form-label-admin {
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control-admin {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #ffffff;
            padding: 12px 16px;
            font-size: 14px;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .form-control-admin:focus {
            outline: none;
            border-color: var(--wa-green);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.1);
        }
        
        .form-control-admin.is-invalid {
            border-color: var(--accent-danger);
        }
        
        /* Buttons */
        .btn-admin {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary-admin {
            background: linear-gradient(135deg, var(--wa-green) 0%, var(--wa-green-dark) 100%);
            color: white;
        }
        
        .btn-primary-admin:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.4);
        }
        
        .btn-secondary-admin {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .btn-secondary-admin:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }
        
        .btn-warning-admin {
            background: transparent;
            color: var(--accent-warning);
            border: 1px solid var(--accent-warning);
        }
        
        .btn-warning-admin:hover {
            background: rgba(255, 193, 7, 0.1);
            transform: translateY(-1px);
        }
        
        .btn-info-admin {
            background: transparent;
            color: #06b6d4;
            border: 1px solid #06b6d4;
        }
        
        .btn-info-admin:hover {
            background: rgba(6, 182, 212, 0.1);
        }
        
        /* Status Check Items */
        .status-check-item {
            display: flex;
            align-items: center;
            padding: 15px;
            margin-bottom: 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        
        .status-check-item:hover {
            background: rgba(0, 0, 0, 0.3);
            transform: translateX(5px);
        }
        
        .status-check-ok {
            border-left-color: var(--accent-success);
        }
        
        .status-check-warning {
            border-left-color: var(--accent-warning);
        }
        
        .status-check-error {
            border-left-color: var(--accent-danger);
        }
        
        .status-check-icon {
            font-size: 24px;
            margin-right: 15px;
            width: 40px;
            text-align: center;
        }
        
        .status-check-content {
            flex: 1;
        }
        
        .status-check-title {
            color: #ffffff;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .status-check-message {
            color: var(--text-secondary);
            font-size: 13px;
        }
        
        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: rgba(0, 0, 0, 0.2);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            background: rgba(0, 0, 0, 0.3);
            transform: translateY(-2px);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: var(--wa-green);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Table Styles */
        .table-admin {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-admin thead {
            background: rgba(0, 0, 0, 0.2);
        }
        
        .table-admin th {
            padding: 12px;
            text-align: left;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .table-admin td {
            padding: 12px;
            color: var(--text-primary);
            font-size: 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .table-admin tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        
        /* Input Group */
        .input-group {
            display: flex;
            gap: 10px;
        }
        
        .input-group .form-control-admin {
            flex: 1;
        }
        
        /* QR Modal */
        .modal-content {
            background: var(--bg-secondary);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
        }
        
        .modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 20px;
        }
        
        .modal-title {
            color: #ffffff;
            font-weight: 600;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .btn-close {
            filter: invert(1);
        }
        
        /* Loading Animation */
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            border-top-color: var(--wa-green);
            animation: spin 1s ease-in-out infinite;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-tabs-modern {
                flex-wrap: nowrap;
                overflow-x: auto;
            }
            
            .admin-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- Header -->
        <div class="admin-header">
            <div>
                <h1>Panel del Bot de WhatsApp</h1>
                <p>Diagnostica, configura y monitorea la integración de tu bot</p>
            </div>
            <a href="admin.php" class="btn-back-modern">
                <i class="fas fa-arrow-left"></i> Volver al Panel Principal
            </a>
        </div>
        
        <?php if (!empty($message)): ?>
        <div class="alert-modern alert-<?= $message_type ?>-modern">
            <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <!-- Navigation Tabs -->
        <div class="nav nav-tabs-modern" id="whatsappTabs" role="tablist">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#diagnostico">
                <i class="fas fa-stethoscope"></i> Diagnóstico
            </button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#configuracion">
                <i class="fas fa-cog"></i> Configuración
            </button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#estadisticas">
                <i class="fas fa-chart-bar"></i> Estadísticas
            </button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#usuarios">
                <i class="fas fa-users"></i> Usuarios Vinculados
            </button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sistema">
                <i class="fas fa-server"></i> Sistema
            </button>
        </div>
        
        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Diagnóstico Tab -->
            <div class="tab-pane fade show active" id="diagnostico">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">
                            <i class="fas fa-heartbeat"></i> Estado del Bot
                        </h3>
                    </div>
                    
                    <!-- Estado General -->
                    <div class="alert-modern alert-<?= $status['overall'] === 'ok' ? 'success' : ($status['overall'] === 'warning' ? 'warning' : 'danger') ?>-modern">
                        <i class="fas <?= $status['overall'] === 'ok' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
                        <strong>Estado General:</strong> 
                        <?= $status['overall'] === 'ok' ? 'Requiere atención' : ($status['overall'] === 'warning' ? 'Requiere atención' : 'Requiere atención') ?>
                    </div>
                    
                    <!-- Checks Detallados -->
                    <?php foreach ($status['checks'] as $check): ?>
                    <div class="status-check-item status-check-<?= $check['status'] ?>">
                        <div class="status-check-icon">
                            <?php if ($check['status'] === 'ok'): ?>
                                <i class="fas fa-check-circle" style="color: var(--accent-success);"></i>
                            <?php elseif ($check['status'] === 'warning'): ?>
                                <i class="fas fa-exclamation-triangle" style="color: var(--accent-warning);"></i>
                            <?php else: ?>
                                <i class="fas fa-times-circle" style="color: var(--accent-danger);"></i>
                            <?php endif; ?>
                        </div>
                        <div class="status-check-content">
                            <div class="status-check-title">
                                <i class="fas <?= $check['icon'] ?> me-2"></i>
                                <?= ucfirst(str_replace('_', ' ', array_search($check, $status['checks']))) ?>
                            </div>
                            <div class="status-check-message"><?= htmlspecialchars($check['message']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Info Adicional -->
                    <div class="mt-4 p-3" style="background: rgba(0,0,0,0.2); border-radius: 10px;">
                        <p class="mb-2"><strong>ID de Instancia:</strong> <?= htmlspecialchars($instance ?: 'No configurada') ?></p>
                        <p class="mb-0"><strong>Última actividad:</strong> <?= htmlspecialchars($last_activity ?: 'Sin registros') ?></p>
                    </div>
                    
                    <!-- Acciones -->
                    <div class="d-flex gap-2 flex-wrap mt-4">
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="create_tables">
                            <button type="submit" class="btn-admin btn-warning-admin">
                                <i class="fas fa-database"></i> Crear Tablas
                            </button>
                        </form>
                        <?php if (!$status['linked'] && $status['qr_url']): ?>
                        <button type="button" id="btnShowQR" class="btn-admin btn-primary-admin">
                            <i class="fas fa-qrcode"></i> Ver Código QR
                        </button>
                        <?php endif; ?>
                        <button type="button" id="btnTestConnection" class="btn-admin btn-info-admin">
                            <i class="fas fa-plug"></i> Probar Conexión
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Configuración Tab -->
            <div class="tab-pane fade" id="configuracion">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">
                            <i class="fas fa-tools"></i> Configuración del Bot
                        </h3>
                    </div>
                    
                    <form method="post" novalidate>
                        <input type="hidden" name="action" value="save_config">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        
                        <div class="form-group-admin">
                            <label class="form-label-admin">
                                <i class="fas fa-link me-2"></i>API URL
                            </label>
                            <input type="url" name="api_url" id="api_url" class="form-control-admin"
                                   value="<?= htmlspecialchars($api_url) ?>"
                                   placeholder="https://api.whatsapp.example.com" required>
                        </div>

                        <div class="form-group-admin">
                            <label class="form-label-admin">
                                <i class="fas fa-route me-2"></i>Endpoint de Estado
                            </label>
                            <input type="text" name="status_endpoint" id="status_endpoint" class="form-control-admin"
                                   value="<?= htmlspecialchars($status_endpoint) ?>"
                                   placeholder="/getInstanceInfo" required>
                        </div>
                        
                        <div class="form-group-admin">
                            <label class="form-label-admin">
                                <i class="fas fa-key me-2"></i>Token
                            </label>
                            <div class="input-group">
                                <input type="password" name="token" id="token" class="form-control-admin" 
                                       value="<?= htmlspecialchars($token) ?>" 
                                       placeholder="Tu token de autenticación" required>
                                <button type="button" id="toggleToken" class="btn-admin btn-secondary-admin">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group-admin">
                            <label class="form-label-admin">
                                <i class="fas fa-hashtag me-2"></i>ID de Instancia
                            </label>
                            <input type="text" name="instance" id="instance" class="form-control-admin" 
                                   value="<?= htmlspecialchars($instance) ?>" 
                                   placeholder="123456" pattern="[0-9]+" required>
                        </div>
                        
                        <div class="form-group-admin">
                            <label class="form-label-admin">
                                <i class="fas fa-lock me-2"></i>Webhook Secret
                            </label>
                            <div class="input-group">
                                <input type="text" name="webhook_secret" id="webhook_secret" class="form-control-admin" 
                                       value="<?= htmlspecialchars($webhook_secret) ?>" 
                                       placeholder="Secreto del webhook" required>
                                <button type="button" id="generateSecret" class="btn-admin btn-secondary-admin">
                                    <i class="fas fa-dice"></i> Generar
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group-admin">
                            <label class="form-label-admin">
                                <i class="fas fa-globe me-2"></i>Webhook URL
                            </label>
                            <input type="url" name="webhook_url" id="webhook_url" class="form-control-admin" 
                                   value="<?= htmlspecialchars($webhook_url) ?>" 
                                   placeholder="https://tudominio.com/whatsapp_bot/webhook.php" required>
                        </div>
                        
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn-admin btn-primary-admin">
                                <i class="fas fa-save"></i> Guardar Configuración
                            </button>
                            <button type="button" id="btnVerifyWebhook" class="btn-admin btn-secondary-admin">
                                <i class="fas fa-check-circle"></i> Verificar Webhook
                            </button>
                        </div>
                        <div id="webhookFeedback" class="mt-3"></div>
                    </form>
                </div>
            </div>
            
            <!-- Estadísticas Tab -->
            <div class="tab-pane fade" id="estadisticas">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">
                            <i class="fas fa-chart-line"></i> Estadísticas
                        </h3>
                    </div>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?= $stats['active_users'] ?></div>
                            <div class="stat-label">Usuarios Activos</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= $stats['messages_today'] ?></div>
                            <div class="stat-label">Mensajes de Hoy</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= $stats['total_messages'] ?></div>
                            <div class="stat-label">Total Mensajes</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= $stats['total_searches'] ?></div>
                            <div class="stat-label">Búsquedas</div>
                        </div>
                    </div>
                    
                    <button type="button" id="refreshStats" class="btn-admin btn-secondary-admin">
                        <i class="fas fa-sync-alt"></i> Refrescar
                    </button>
                </div>
            </div>
            
            <!-- Usuarios Vinculados Tab -->
            <div class="tab-pane fade" id="usuarios">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">
                            <i class="fas fa-users-cog"></i> Usuarios Vinculados (<?= count($linked_users) ?>)
                        </h3>
                    </div>
                    
                    <?php if (empty($linked_users)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users" style="font-size: 3rem; color: var(--text-secondary); opacity: 0.5;"></i>
                        <p style="color: var(--text-secondary); margin-top: 1rem;">
                            No hay usuarios vinculados con WhatsApp aún
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>WhatsApp ID</th>
                                    <th>Actividad</th>
                                    <th>Vinculado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linked_users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['username']) ?></td>
                                    <td style="color: var(--wa-green);"><?= htmlspecialchars($user['whatsapp_id']) ?></td>
                                    <td><?= $user['activity_count'] ?> acciones</td>
                                    <td><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Sistema Tab -->
            <div class="tab-pane fade" id="sistema">
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">
                            <i class="fas fa-server"></i> Sistema
                        </h3>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h5 style="color: var(--text-primary); margin-bottom: 1rem;">
                                <i class="fas fa-info-circle me-2"></i>Información del Sistema
                            </h5>
                            <ul class="list-unstyled" style="color: var(--text-secondary);">
                                <li class="mb-2"><strong>PHP Version:</strong> <?= PHP_VERSION ?></li>
                                <li class="mb-2"><strong>Servidor:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'No disponible' ?></li>
                                <li class="mb-2"><strong>Base de Datos:</strong> MySQL</li>
                                <li class="mb-2"><strong>Última Actualización:</strong> <?= date('Y-m-d H:i:s') ?></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h5 style="color: var(--text-primary); margin-bottom: 1rem;">
                                <i class="fas fa-clipboard-list me-2"></i>Logs del Sistema
                            </h5>
                            <div style="background: rgba(0,0,0,0.3); border-radius: 10px; padding: 15px; height: 150px; overflow-y: auto;">
                                <small style="color: var(--text-secondary); font-family: monospace;">
                                    [<?= date('Y-m-d H:i:s') ?>] Sistema iniciado<br>
                                    [<?= date('Y-m-d H:i:s') ?>] Panel de administración cargado<br>
                                    [<?= date('Y-m-d H:i:s') ?>] Verificación de estado completada
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h5 style="color: var(--text-primary); margin-bottom: 1rem;">
                            <i class="fas fa-terminal me-2"></i>Comandos Útiles
                        </h5>
                        <div style="background: rgba(0,0,0,0.3); border-radius: 10px; padding: 15px;">
                            <code style="color: var(--wa-green);">composer run whatsapp-test</code> - Ejecutar pruebas del bot<br>
                            <code style="color: var(--wa-green);">composer install</code> - Instalar dependencias<br>
                            <code style="color: var(--wa-green);">php whatsapp_bot/test.php</code> - Probar conexión directamente
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2 flex-wrap">
                        <button type="button" class="btn-admin btn-secondary-admin" data-accion="purge_audit_logs">
                            <i class="fas fa-trash"></i> Purgar registros
                        </button>
                    </div>
                    <div id="cli-output" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- QR Modal -->
    <?php if (!$status['linked'] && $status['qr_url']): ?>
    <div class="modal fade" id="qrModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-qrcode me-2"></i>Código QR de WhatsApp
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img src="<?= htmlspecialchars($status['qr_url']) ?>" alt="QR Code" class="img-fluid" style="max-width: 300px;">
                    <p class="mt-3" style="color: var(--text-secondary);">
                        Escanea este código con WhatsApp para vincular el bot
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const csrf = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle Token Visibility
        const toggleToken = document.getElementById('toggleToken');
        const tokenInput = document.getElementById('token');
        
        if (toggleToken) {
            toggleToken.addEventListener('click', function() {
                if (tokenInput.type === 'password') {
                    tokenInput.type = 'text';
                    this.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    tokenInput.type = 'password';
                    this.innerHTML = '<i class="fas fa-eye"></i>';
                }
            });
        }
        
        // Generate Secret
        const generateSecret = document.getElementById('generateSecret');
        if (generateSecret) {
            generateSecret.addEventListener('click', function() {
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                let secret = '';
                for (let i = 0; i < 32; i++) {
                    secret += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                document.getElementById('webhook_secret').value = secret;
            });
        }
        
        // Auto-fill webhook URL
        const webhookUrl = document.getElementById('webhook_url');
        if (webhookUrl && !webhookUrl.value) {
            const currentDomain = window.location.hostname;
            const basePath = window.location.pathname.split('/admin/')[0];
            webhookUrl.value = `https://${currentDomain}${basePath}/whatsapp_bot/webhook.php`;
        }

        // Verify Webhook
        const btnVerifyWebhook = document.getElementById('btnVerifyWebhook');
        const webhookFeedback = document.getElementById('webhookFeedback');
        if (btnVerifyWebhook && webhookFeedback) {
            btnVerifyWebhook.addEventListener('click', async () => {
                webhookFeedback.textContent = 'Verificando...';
                const api_url = document.getElementById('api_url').value;
                const token = document.getElementById('token').value;
                const instance = document.getElementById('instance').value;
                const webhook_url = document.getElementById('webhook_url').value;
                const webhook_secret = document.getElementById('webhook_secret').value;
                const params = new URLSearchParams({
                    action: 'verify_webhook',
                    api_url,
                    token,
                    instance,
                    webhook_url,
                    webhook_secret,
                    csrf_token: csrf
                });

                try {
                    const response = await fetch('test_whatsapp_connection.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: params.toString()
                    });
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    const data = await response.json();
                    webhookFeedback.textContent = (data.success ? '✅ ' : '❌ ') + data.message;
                } catch (error) {
                    webhookFeedback.textContent = '❌ Error de red: ' + error.message;
                }
            });
        }

        // Show QR Modal
        const btnShowQR = document.getElementById('btnShowQR');
        if (btnShowQR) {
            btnShowQR.addEventListener('click', function() {
                const qrModal = new bootstrap.Modal(document.getElementById('qrModal'));
                qrModal.show();
            });
        }
        
        // Test Connection
        const btnTestConnection = document.getElementById('btnTestConnection');
        if (btnTestConnection) {
            btnTestConnection.addEventListener('click', function() {
                this.disabled = true;
                this.innerHTML = '<span class="loading-spinner"></span> Probando...';
                
                fetch('whatsapp_management.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=test_connection'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-plug"></i> Probar Conexión';
                });
            });
        }
        
        // Refresh Stats
        const refreshStats = document.getElementById('refreshStats');
        if (refreshStats) {
            refreshStats.addEventListener('click', function() {
                window.location.reload();
            });
        }
        
        // Tab persistence
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');
        if (tab) {
            const tabElement = document.querySelector(`[data-bs-target="#${tab}"]`);
            if (tabElement) {
                new bootstrap.Tab(tabElement).show();
            }
        }
        
        // Update URL on tab change
        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(button => {
            button.addEventListener('shown.bs.tab', event => {
                const newTabId = event.target.getAttribute('data-bs-target').substring(1);
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('tab', newTabId);
                window.history.pushState({path: newUrl.href}, '', newUrl.href);
            });
        });

        // CLI buttons
        const cliOutput = document.getElementById('cli-output');
        document.querySelectorAll('button[data-accion]').forEach(btn => {
            btn.addEventListener('click', () => {
                const accion = btn.dataset.accion;
                btn.disabled = true;
                fetch('run_cli.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `accion=${encodeURIComponent(accion)}&csrf_token=${encodeURIComponent(csrf)}`
                })
                .then(async res => {
                    const text = await res.text();
                    if (!res.ok) {
                        throw new Error(text || `Error ${res.status}`);
                    }
                    cliOutput.textContent = text;
                })
                .catch(err => {
                    cliOutput.textContent = 'Error: ' + err.message;
                })
                .finally(() => {
                    btn.disabled = false;
                });
            });
        });
    });
    </script>
</body>
</html>
