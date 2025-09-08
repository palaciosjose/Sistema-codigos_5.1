<?php
/**
 * GESTIÓN WHATSAPP - WAMUNDO.COM EXCLUSIVAMENTE
 * Reemplazo completo de whatsapp_management.php
 * Sistema de gestión exclusivo para wamundo.com (sin Whaticket)
 */

require_once '../config/path_constants.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';

use Shared\ConfigService;
use Shared\DatabaseManager;
use WhatsappBot\Services\LogService;

// Headers de seguridad
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

$logger = new LogService();
$config = ConfigService::getInstance();

// Variables de estado
$message = '';
$message_type = '';

// ========================================
// PROCESAR FORMULARIOS
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_wamundo_config') {
        $send_secret = trim($_POST['send_secret'] ?? '');
        $account_id = trim($_POST['account_id'] ?? '');
        $webhook_secret = trim($_POST['webhook_secret'] ?? '');
        
        // Validaciones básicas
        if (empty($send_secret) || empty($account_id)) {
            $message = 'Send Secret y Account ID son obligatorios';
            $message_type = 'error';
        } else {
            // Guardar configuración
            $config->set('WHATSAPP_NEW_SEND_SECRET', $send_secret);
            $config->set('WHATSAPP_NEW_ACCOUNT_ID', $account_id);
            $config->set('WHATSAPP_NEW_WEBHOOK_SECRET', $webhook_secret);
            
            $message = 'Configuración de Wamundo guardada correctamente';
            $message_type = 'success';
            
            $logger->info("Configuración Wamundo actualizada", [
                'account_id' => substr($account_id, 0, 10) . '...',
                'has_webhook_secret' => !empty($webhook_secret)
            ]);
        }
    }
    
    elseif ($action === 'test_wamundo_connection') {
        header('Content-Type: application/json');
        
        $send_secret = $config->get('WHATSAPP_NEW_SEND_SECRET', '');
        $account_id = $config->get('WHATSAPP_NEW_ACCOUNT_ID', '');
        
        $result = testWamundoConnection($send_secret, $account_id);
        echo json_encode($result);
        exit;
    }
    
    elseif ($action === 'clear_logs') {
        $log_file = PROJECT_ROOT . '/whatsapp_bot/logs/webhook_complete.log';
        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
            $message = 'Logs limpiados correctamente';
            $message_type = 'success';
        } else {
            $message = 'Archivo de logs no encontrado';
            $message_type = 'warning';
        }
    }
}

// ========================================
// FUNCIONES DE WAMUNDO
// ========================================

function testWamundoConnection($send_secret, $account_id) {
    if (empty($send_secret) || empty($account_id)) {
        return [
            'success' => false, 
            'message' => 'Credenciales incompletas. Configura Send Secret y Account ID.',
            'details' => 'Faltan credenciales de Wamundo'
        ];
    }
    
    $url = "https://wamundo.com/api/send/whatsapp";
    $data = [
        "secret" => $send_secret,
        "account" => $account_id,
        "recipient" => "000000000000", // Número de prueba
        "type" => "text",
        "message" => "Test de conectividad - " . date('H:i:s'),
        "priority" => 1
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'WamundoTest/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || !empty($curlError)) {
        return [
            'success' => false, 
            'message' => 'Error de conexión con Wamundo',
            'details' => $curlError ?: 'Error de red desconocido'
        ];
    }
    
    $responseData = json_decode($response, true);
    
    if ($httpCode === 200 && $responseData && $responseData['status'] === 200) {
        return [
            'success' => true, 
            'message' => 'Conexión exitosa con Wamundo',
            'details' => 'API respondió correctamente. Message ID: ' . ($responseData['data']['messageId'] ?? 'N/A')
        ];
    }
    
    // Analizar errores específicos
    $error_message = 'Error desconocido';
    if ($responseData && isset($responseData['message'])) {
        $error_message = $responseData['message'];
    } elseif ($httpCode !== 200) {
        $error_message = "HTTP Error $httpCode";
    }
    
    return [
        'success' => false, 
        'message' => 'Error en la API de Wamundo',
        'details' => $error_message . " (Código: $httpCode)"
    ];
}

function getWamundoStatus() {
    $config = ConfigService::getInstance();
    
    $send_secret = $config->get('WHATSAPP_NEW_SEND_SECRET', '');
    $account_id = $config->get('WHATSAPP_NEW_ACCOUNT_ID', '');
    $webhook_secret = $config->get('WHATSAPP_NEW_WEBHOOK_SECRET', '');
    
    $status = [
        'send_configured' => !empty($send_secret) && !empty($account_id),
        'webhook_configured' => !empty($webhook_secret),
        'webhook_file_exists' => file_exists(PROJECT_ROOT . '/whatsapp_bot/webhook_new.php'),
        'logs_exist' => file_exists(PROJECT_ROOT . '/whatsapp_bot/logs/webhook_complete.log')
    ];
    
    // Estado general
    if ($status['send_configured'] && $status['webhook_configured'] && $status['webhook_file_exists']) {
        $status['overall'] = 'ready';
        $status['overall_message'] = 'Sistema completamente configurado';
    } elseif ($status['send_configured']) {
        $status['overall'] = 'partial';
        $status['overall_message'] = 'Configuración parcial - revisa webhook';
    } else {
        $status['overall'] = 'not_configured';
        $status['overall_message'] = 'Requiere configuración inicial';
    }
    
    return $status;
}

function getWamundoStats() {
    try {
        $db = DatabaseManager::getInstance()->getConnection();
        
        // Estadísticas básicas
        $stats = [
            'total_sessions' => 0,
            'active_sessions' => 0,
            'total_activity' => 0,
            'activity_today' => 0
        ];
        
        // Sesiones de WhatsApp
        $result = $db->query("SELECT COUNT(*) as total FROM whatsapp_sessions");
        if ($result) {
            $stats['total_sessions'] = $result->fetch_assoc()['total'];
        }
        
        // Sesiones activas (últimas 24 horas)
        $result = $db->query("SELECT COUNT(*) as active FROM whatsapp_sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        if ($result) {
            $stats['active_sessions'] = $result->fetch_assoc()['active'];
        }
        
        // Actividad total
        $result = $db->query("SELECT COUNT(*) as total FROM whatsapp_activity_log");
        if ($result) {
            $stats['total_activity'] = $result->fetch_assoc()['total'];
        }
        
        // Actividad hoy
        $result = $db->query("SELECT COUNT(*) as today FROM whatsapp_activity_log WHERE DATE(created_at) = CURDATE()");
        if ($result) {
            $stats['activity_today'] = $result->fetch_assoc()['today'];
        }
        
        return $stats;
        
    } catch (Exception $e) {
        return [
            'total_sessions' => 0,
            'active_sessions' => 0,
            'total_activity' => 0,
            'activity_today' => 0,
            'error' => $e->getMessage()
        ];
    }
}

// ========================================
// CARGAR DATOS PARA LA VISTA
// ========================================

$wamundo_config = [
    'send_secret' => $config->get('WHATSAPP_NEW_SEND_SECRET', ''),
    'account_id' => $config->get('WHATSAPP_NEW_ACCOUNT_ID', ''),
    'webhook_secret' => $config->get('WHATSAPP_NEW_WEBHOOK_SECRET', ''),
    'webhook_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/whatsapp_bot/webhook_new.php'
];

$wamundo_status = getWamundoStatus();
$wamundo_stats = getWamundoStats();

// Logs recientes
$recent_logs = '';
$log_file = PROJECT_ROOT . '/whatsapp_bot/logs/webhook_complete.log';
if (file_exists($log_file)) {
    $log_content = file_get_contents($log_file);
    $log_lines = explode("\n", $log_content);
    $recent_logs = implode("\n", array_slice($log_lines, -20)); // Últimas 20 líneas
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión WhatsApp - Wamundo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-ready { color: #28a745; }
        .status-partial { color: #ffc107; }
        .status-not-configured { color: #dc3545; }
        .config-card { border-left: 4px solid #007bff; }
        .logs-container { background: #1e1e1e; color: #00ff00; font-family: monospace; }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="fas fa-whatsapp text-success me-2"></i>Gestión WhatsApp - Wamundo</h1>
                <div class="badge bg-info fs-6">
                    <i class="fas fa-cloud me-1"></i>wamundo.com
                </div>
            </div>
            
            <?php if ($message): ?>
            <div class="alert alert-<?= $message_type === 'error' ? 'danger' : $message_type ?> alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <!-- Estado del Sistema -->
        <div class="col-md-4">
            <div class="card config-card">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle me-2"></i>Estado del Sistema</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Estado General:</span>
                            <span class="badge bg-<?= $wamundo_status['overall'] === 'ready' ? 'success' : ($wamundo_status['overall'] === 'partial' ? 'warning' : 'danger') ?> fs-6">
                                <?= ucfirst($wamundo_status['overall']) ?>
                            </span>
                        </div>
                        <small class="text-muted"><?= $wamundo_status['overall_message'] ?></small>
                    </div>
                    
                    <hr>
                    
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="<?= $wamundo_status['send_configured'] ? 'text-success' : 'text-danger' ?>">
                                <i class="fas fa-paper-plane fa-2x"></i>
                                <div class="mt-1 small">Envío</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="<?= $wamundo_status['webhook_configured'] ? 'text-success' : 'text-warning' ?>">
                                <i class="fas fa-link fa-2x"></i>
                                <div class="mt-1 small">Webhook</div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-grid">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="testConnection()">
                            <i class="fas fa-vial me-1"></i>Test de Conexión
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configuración -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-cog me-2"></i>Configuración Wamundo</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_wamundo_config">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Send Secret <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="send_secret" 
                                           value="<?= htmlspecialchars($wamundo_config['send_secret']) ?>"
                                           placeholder="Obtener de wamundo.com">
                                    <div class="form-text">Secret para enviar mensajes</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Account ID <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="account_id" 
                                           value="<?= htmlspecialchars($wamundo_config['account_id']) ?>"
                                           placeholder="ID de cuenta de wamundo.com">
                                    <div class="form-text">Identificador de tu cuenta</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Webhook Secret</label>
                            <input type="text" class="form-control" name="webhook_secret" 
                                   value="<?= htmlspecialchars($wamundo_config['webhook_secret']) ?>"
                                   placeholder="Opcional - para validar webhooks entrantes">
                            <div class="form-text">Recomendado para mayor seguridad</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">URL del Webhook (Solo lectura)</label>
                            <div class="input-group">
                                <input type="text" class="form-control" readonly 
                                       value="<?= htmlspecialchars($wamundo_config['webhook_url']) ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="copyWebhookUrl()">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                            <div class="form-text">Usar esta URL en tu panel de Wamundo</div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Guardar Configuración
                            </button>
                            <a href="../admin/admin.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Volver al Admin
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Estadísticas y Logs -->
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h6><i class="fas fa-chart-bar me-2"></i>Estadísticas</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="border-end">
                                <div class="h4 text-primary"><?= $wamundo_stats['total_sessions'] ?></div>
                                <div class="small text-muted">Sesiones Total</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="h4 text-success"><?= $wamundo_stats['active_sessions'] ?></div>
                            <div class="small text-muted">Activas (24h)</div>
                        </div>
                    </div>
                    <hr>
                    <div class="row text-center">
                        <div class="col-6">
                            <div class="border-end">
                                <div class="h4 text-info"><?= $wamundo_stats['total_activity'] ?></div>
                                <div class="small text-muted">Actividad Total</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="h4 text-warning"><?= $wamundo_stats['activity_today'] ?></div>
                            <div class="small text-muted">Hoy</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6><i class="fas fa-terminal me-2"></i>Logs Recientes</h6>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="btn btn-outline-danger btn-sm" 
                                onclick="return confirm('¿Limpiar todos los logs?')">
                            <i class="fas fa-trash me-1"></i>Limpiar
                        </button>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="logs-container p-3" style="height: 300px; overflow-y: auto;">
                        <pre class="mb-0"><?= htmlspecialchars($recent_logs ?: 'No hay logs disponibles') ?></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function testConnection() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Probando...';
    btn.disabled = true;
    
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=test_wamundo_connection'
    })
    .then(response => response.json())
    .then(data => {
        const alertClass = data.success ? 'alert-success' : 'alert-danger';
        const icon = data.success ? 'check-circle' : 'exclamation-triangle';
        
        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show mt-3">
                <i class="fas fa-${icon} me-2"></i>
                <strong>${data.message}</strong><br>
                <small>${data.details || ''}</small>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        document.querySelector('.container-fluid').insertAdjacentHTML('afterbegin', alertHtml);
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al probar la conexión');
    })
    .finally(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}

function copyWebhookUrl() {
    const input = document.querySelector('input[readonly]');
    input.select();
    document.execCommand('copy');
    
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i>';
    setTimeout(() => {
        btn.innerHTML = originalHtml;
    }, 1500);
}
</script>

</body>
</html>
