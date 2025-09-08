<?php
/**
 * PANEL ADMINISTRATIVO WHATSAPP - PROFESIONAL
 * Diseño coherente con el sistema, funcionalidad completa
 */

require_once '../config/path_constants.php';
require_once SECURITY_DIR . '/auth.php';

// Verificar autenticación
session_start();
if (!is_admin()) {
    header('Location: ../login.php');
    exit;
}

// Cargar dependencias de forma segura
$configLoaded = false;
$statsLoaded = false;

try {
    if (file_exists(PROJECT_ROOT . '/vendor/autoload.php')) {
        require_once PROJECT_ROOT . '/vendor/autoload.php';
        if (class_exists('Shared\\ConfigService')) {
            $config = Shared\ConfigService::getInstance();
            $configLoaded = true;
        }
        if (class_exists('Shared\\DatabaseManager')) {
            $db = Shared\DatabaseManager::getInstance()->getConnection();
            $statsLoaded = true;
        }
    }
} catch (Exception $e) {
    error_log("Error cargando dependencias: " . $e->getMessage());
}

// Variables de estado
$message = '';
$message_type = '';
$test_results = [];

// Función para obtener configuración segura
function getConfig($key, $default = '') {
    global $config, $configLoaded;
    if ($configLoaded && $config) {
        try {
            return $config->get($key, $default);
        } catch (Exception $e) {
            return $default;
        }
    }
    return $default;
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_config':
            $send_secret = trim($_POST['send_secret'] ?? '');
            $account_id = trim($_POST['account_id'] ?? '');
            $webhook_secret = trim($_POST['webhook_secret'] ?? '');
            $log_level = $_POST['log_level'] ?? 'info';
            
            if (empty($send_secret) || empty($account_id)) {
                $message = 'Send Secret y Account ID son campos obligatorios';
                $message_type = 'error';
            } else {
                try {
                    if ($configLoaded && $config) {
                        $config->set('WHATSAPP_NEW_SEND_SECRET', $send_secret);
                        $config->set('WHATSAPP_NEW_ACCOUNT_ID', $account_id);
                        $config->set('WHATSAPP_NEW_WEBHOOK_SECRET', $webhook_secret);
                        $config->set('WHATSAPP_NEW_LOG_LEVEL', $log_level);
                        
                        $message = 'Configuración guardada exitosamente';
                        $message_type = 'success';
                    } else {
                        throw new Exception('ConfigService no disponible');
                    }
                } catch (Exception $e) {
                    $message = 'Error al guardar configuración: ' . $e->getMessage();
                    $message_type = 'error';
                }
            }
            break;
            
        case 'test_system':
            $test_results = runSystemTests();
            break;
            
        case 'clear_logs':
            try {
                $log_file = PROJECT_ROOT . '/whatsapp_bot/logs/webhook_complete.log';
                if (file_exists($log_file)) {
                    file_put_contents($log_file, '');
                    $message = 'Logs limpiados correctamente';
                    $message_type = 'success';
                } else {
                    $message = 'Archivo de logs no encontrado';
                    $message_type = 'warning';
                }
            } catch (Exception $e) {
                $message = 'Error limpiando logs: ' . $e->getMessage();
                $message_type = 'error';
            }
            break;
    }
}

// Función para ejecutar tests del sistema
function runSystemTests() {
    global $configLoaded, $config;
    
    $tests = [];
    
    // Test 1: Configuración básica
    $send_secret = getConfig('WHATSAPP_NEW_SEND_SECRET');
    $account_id = getConfig('WHATSAPP_NEW_ACCOUNT_ID');
    
    $tests['config'] = [
        'name' => 'Configuración Básica',
        'status' => (!empty($send_secret) && !empty($account_id)) ? 'success' : 'error',
        'message' => (!empty($send_secret) && !empty($account_id)) ? 
            'Send Secret y Account ID configurados' : 
            'Faltan Send Secret o Account ID',
        'details' => [
            'Send Secret' => !empty($send_secret) ? 'Configurado' : 'No configurado',
            'Account ID' => !empty($account_id) ? 'Configurado' : 'No configurado',
            'Webhook Secret' => !empty(getConfig('WHATSAPP_NEW_WEBHOOK_SECRET')) ? 'Configurado' : 'Opcional'
        ]
    ];
    
    // Test 2: Archivos del sistema
    $webhook_file = PROJECT_ROOT . '/whatsapp_bot/webhook_new.php';
    $logs_dir = PROJECT_ROOT . '/whatsapp_bot/logs';
    
    $tests['files'] = [
        'name' => 'Archivos del Sistema',
        'status' => (file_exists($webhook_file) && is_dir($logs_dir)) ? 'success' : 'error',
        'message' => 'Verificación de archivos críticos',
        'details' => [
            'webhook_new.php' => file_exists($webhook_file) ? 'Existe' : 'No encontrado',
            'Directorio logs' => is_dir($logs_dir) ? 'Existe' : 'No encontrado',
            'Logs escribibles' => (is_dir($logs_dir) && is_writable($logs_dir)) ? 'Sí' : 'No'
        ]
    ];
    
    // Test 3: Conectividad con Wamundo
    if (!empty($send_secret) && !empty($account_id)) {
        $api_test = testWamundoAPI($send_secret, $account_id);
        $tests['api'] = [
            'name' => 'Conectividad API Wamundo',
            'status' => $api_test['success'] ? 'success' : 'error',
            'message' => $api_test['message'],
            'details' => $api_test['details'] ?? []
        ];
    } else {
        $tests['api'] = [
            'name' => 'Conectividad API Wamundo',
            'status' => 'warning',
            'message' => 'No se puede probar - configuración incompleta',
            'details' => []
        ];
    }
    
    // Test 4: Base de datos
    try {
        global $statsLoaded, $db;
        if ($statsLoaded && $db) {
            $tables = ['whatsapp_sessions', 'whatsapp_activity_log', 'whatsapp_temp_data'];
            $missing = [];
            
            foreach ($tables as $table) {
                $result = $db->query("SHOW TABLES LIKE '$table'");
                if (!$result || $result->num_rows == 0) {
                    $missing[] = $table;
                }
            }
            
            $tests['database'] = [
                'name' => 'Base de Datos',
                'status' => empty($missing) ? 'success' : 'error',
                'message' => empty($missing) ? 'Todas las tablas existen' : 'Faltan tablas: ' . implode(', ', $missing),
                'details' => array_combine($tables, array_map(function($table) use ($db) {
                    $result = $db->query("SHOW TABLES LIKE '$table'");
                    return ($result && $result->num_rows > 0) ? 'Existe' : 'No existe';
                }, $tables))
            ];
        } else {
            throw new Exception('DatabaseManager no disponible');
        }
    } catch (Exception $e) {
        $tests['database'] = [
            'name' => 'Base de Datos',
            'status' => 'error',
            'message' => 'Error de conexión: ' . $e->getMessage(),
            'details' => []
        ];
    }
    
    return $tests;
}

// Función para test de API
function testWamundoAPI($send_secret, $account_id) {
    $url = "https://wamundo.com/api/send/whatsapp";
    $data = [
        "secret" => $send_secret,
        "account" => $account_id,
        "recipient" => "000000000000",
        "type" => "text",
        "message" => "Test API - " . date('H:i:s'),
        "priority" => 1
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || !empty($curlError)) {
        return [
            'success' => false,
            'message' => 'Error de conexión con Wamundo',
            'details' => ['Error' => $curlError ?: 'Timeout']
        ];
    }
    
    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        if ($responseData && $responseData['status'] === 200) {
            return [
                'success' => true,
                'message' => 'Conexión exitosa con API Wamundo',
                'details' => [
                    'Status' => 'OK',
                    'Response' => 'API respondió correctamente',
                    'HTTP Code' => $httpCode
                ]
            ];
        }
    }
    
    return [
        'success' => false,
        'message' => "Error HTTP $httpCode",
        'details' => ['HTTP Code' => $httpCode, 'Response' => substr($response, 0, 100)]
    ];
}

// Obtener estadísticas
function getWhatsAppStats() {
    global $statsLoaded, $db;
    
    $stats = [
        'total_sessions' => 0,
        'active_sessions' => 0,
        'total_messages' => 0,
        'messages_today' => 0,
        'last_activity' => null
    ];
    
    if (!$statsLoaded || !$db) {
        return $stats;
    }
    
    try {
        // Total sesiones
        $result = $db->query("SELECT COUNT(*) as count FROM whatsapp_sessions");
        if ($result) {
            $stats['total_sessions'] = $result->fetch_assoc()['count'];
        }
        
        // Sesiones activas (últimas 24h)
        $result = $db->query("SELECT COUNT(*) as count FROM whatsapp_sessions WHERE last_activity > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        if ($result) {
            $stats['active_sessions'] = $result->fetch_assoc()['count'];
        }
        
        // Total mensajes
        $result = $db->query("SELECT COUNT(*) as count FROM whatsapp_activity_log");
        if ($result) {
            $stats['total_messages'] = $result->fetch_assoc()['count'];
        }
        
        // Mensajes hoy
        $result = $db->query("SELECT COUNT(*) as count FROM whatsapp_activity_log WHERE DATE(created_at) = CURDATE()");
        if ($result) {
            $stats['messages_today'] = $result->fetch_assoc()['count'];
        }
        
        // Última actividad
        $result = $db->query("SELECT MAX(created_at) as last_activity FROM whatsapp_activity_log");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['last_activity'] = $row['last_activity'];
        }
        
    } catch (Exception $e) {
        error_log("Error obteniendo estadísticas: " . $e->getMessage());
    }
    
    return $stats;
}

// Obtener logs recientes
function getRecentLogs($lines = 20) {
    $log_file = PROJECT_ROOT . '/whatsapp_bot/logs/webhook_complete.log';
    
    if (!file_exists($log_file)) {
        return "No hay logs disponibles";
    }
    
    $content = file_get_contents($log_file);
    $log_lines = explode("\n", $content);
    $recent_lines = array_slice($log_lines, -$lines);
    
    return implode("\n", array_filter($recent_lines));
}

// Cargar datos
$current_config = [
    'send_secret' => getConfig('WHATSAPP_NEW_SEND_SECRET'),
    'account_id' => getConfig('WHATSAPP_NEW_ACCOUNT_ID'),
    'webhook_secret' => getConfig('WHATSAPP_NEW_WEBHOOK_SECRET'),
    'log_level' => getConfig('WHATSAPP_NEW_LOG_LEVEL', 'info'),
    'webhook_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/whatsapp_bot/webhook_new.php'
];

$stats = getWhatsAppStats();
$recent_logs = getRecentLogs();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel WhatsApp - Sistema de Códigos</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../styles/modern_global.css">
    <link rel="stylesheet" href="../styles/modern_admin.css">
    <link rel="stylesheet" href="../styles/admin.css">
    
    <style>
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-success { background-color: #32FFB5; box-shadow: 0 0 8px rgba(50, 255, 181, 0.6); }
        .status-error { background-color: #ff4d4d; box-shadow: 0 0 8px rgba(255, 77, 77, 0.6); }
        .status-warning { background-color: #ffa500; box-shadow: 0 0 8px rgba(255, 165, 0, 0.6); }
        
        .test-result {
            border-left: 4px solid;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0 8px 8px 0;
            background: rgba(0,0,0,0.2);
        }
        .test-success { border-color: #32FFB5; }
        .test-error { border-color: #ff4d4d; }
        .test-warning { border-color: #ffa500; }
        
        .logs-container {
            background: #1a1a1a;
            color: #00ff00;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            padding: 1rem;
            border-radius: 8px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--glow-border);
        }
        
        .stat-card {
            background: rgba(0,0,0,0.3);
            border: 1px solid var(--glow-border);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(50, 255, 181, 0.1);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--accent-green);
            margin-bottom: 0.5rem;
        }
        
        .webhook-url-display {
            background: rgba(0,0,0,0.3);
            padding: 0.75rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.9rem;
            border: 1px solid var(--glow-border);
            word-break: break-all;
        }
    </style>
</head>
<body class="admin-page">

<div class="floating-particles">
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
    <div class="particle"></div>
</div>

<div class="admin-container">
    <div class="admin-header">
        <h1 class="admin-title">
            <i class="fab fa-whatsapp me-3"></i>
            Panel Administrativo WhatsApp
        </h1>
        <p class="mb-0 opacity-75">Gestión completa del bot de WhatsApp con Wamundo.com</p>
    </div>

    <div class="p-4">
        <a href="admin.php" class="btn-back-modern">
            <i class="fas fa-arrow-left"></i>
            Volver al Panel Principal
        </a>
        <span class="badge bg-info ms-3">
            <i class="fas fa-cloud me-1"></i>
            Wamundo.com
        </span>
    </div>

    <?php if ($message): ?>
        <div class="mx-4">
            <div class="alert-admin alert-<?= $message_type ?>-admin">
                <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : 'info-circle') ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs nav-tabs-modern" id="whatsappTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard" type="button" role="tab">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="config-tab" data-bs-toggle="tab" data-bs-target="#config" type="button" role="tab">
                <i class="fas fa-cog me-2"></i>Configuración
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tests-tab" data-bs-toggle="tab" data-bs-target="#tests" type="button" role="tab">
                <i class="fas fa-vial me-2"></i>Tests del Sistema
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs" type="button" role="tab">
                <i class="fas fa-terminal me-2"></i>Logs
            </button>
        </li>
    </ul>

    <div class="tab-content" id="whatsappTabContent">
        <!-- Dashboard -->
        <div class="tab-pane fade show active" id="dashboard" role="tabpanel">
            <!-- Estado General -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <i class="fas fa-info-circle me-2"></i>
                        Estado General del Sistema
                    </h3>
                    <div class="badge bg-<?= (!empty($current_config['send_secret']) && !empty($current_config['account_id'])) ? 'success' : 'warning' ?> fs-6">
                        <?= (!empty($current_config['send_secret']) && !empty($current_config['account_id'])) ? 'Configurado' : 'Requiere Configuración' ?>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="<?= !empty($current_config['send_secret']) ? 'text-success' : 'text-danger' ?>">
                                <i class="fas fa-paper-plane fa-3x mb-2"></i>
                                <div class="small">Envío de Mensajes</div>
                                <div class="status-indicator <?= !empty($current_config['send_secret']) ? 'status-success' : 'status-error' ?>"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="<?= !empty($current_config['webhook_secret']) ? 'text-success' : 'text-warning' ?>">
                                <i class="fas fa-shield-alt fa-3x mb-2"></i>
                                <div class="small">Webhook Security</div>
                                <div class="status-indicator <?= !empty($current_config['webhook_secret']) ? 'status-success' : 'status-warning' ?>"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="<?= file_exists(PROJECT_ROOT . '/whatsapp_bot/webhook_new.php') ? 'text-success' : 'text-danger' ?>">
                                <i class="fas fa-file-code fa-3x mb-2"></i>
                                <div class="small">Archivo Webhook</div>
                                <div class="status-indicator <?= file_exists(PROJECT_ROOT . '/whatsapp_bot/webhook_new.php') ? 'status-success' : 'status-error' ?>"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <div class="<?= is_dir(PROJECT_ROOT . '/whatsapp_bot/logs') ? 'text-success' : 'text-danger' ?>">
                                <i class="fas fa-folder fa-3x mb-2"></i>
                                <div class="small">Sistema de Logs</div>
                                <div class="status-indicator <?= is_dir(PROJECT_ROOT . '/whatsapp_bot/logs') ? 'status-success' : 'status-error' ?>"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estadísticas -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <i class="fas fa-chart-bar me-2"></i>
                        Estadísticas de Uso
                    </h3>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?= $stats['total_sessions'] ?></div>
                            <div class="text-muted">Sesiones Totales</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?= $stats['active_sessions'] ?></div>
                            <div class="text-muted">Activas (24h)</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?= $stats['total_messages'] ?></div>
                            <div class="text-muted">Mensajes Total</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-number"><?= $stats['messages_today'] ?></div>
                            <div class="text-muted">Mensajes Hoy</div>
                        </div>
                    </div>
                </div>
                
                <?php if ($stats['last_activity']): ?>
                <div class="text-center mt-3">
                    <small class="text-muted">
                        <i class="fas fa-clock me-1"></i>
                        Última actividad: <?= date('d/m/Y H:i:s', strtotime($stats['last_activity'])) ?>
                    </small>
                </div>
                <?php endif; ?>
            </div>

            <!-- URL del Webhook -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <i class="fas fa-link me-2"></i>
                        Información de Webhook
                    </h3>
                    <button type="button" class="btn btn-sm btn-outline-light" onclick="copyWebhookUrl()">
                        <i class="fas fa-copy me-1"></i>Copiar URL
                    </button>
                </div>
                
                <div class="webhook-url-display" id="webhookUrl">
                    <?= htmlspecialchars($current_config['webhook_url']) ?>
                </div>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Configura esta URL en tu panel de Wamundo.com para recibir webhooks
                    </small>
                </div>
            </div>
        </div>

        <!-- Configuración -->
        <div class="tab-pane fade" id="config" role="tabpanel">
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_config">
                
                <div class="admin-card">
                    <div class="admin-card-header">
                        <h3 class="admin-card-title">
                            <i class="fas fa-cog me-2"></i>
                            Configuración de Wamundo
                        </h3>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group-admin">
                                <label class="form-label-admin">
                                    <i class="fas fa-key me-2"></i>
                                    Send Secret <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control-admin" name="send_secret" 
                                       value="<?= htmlspecialchars($current_config['send_secret']) ?>"
                                       placeholder="Obtener de wamundo.com"
                                       required>
                                <div class="form-text-admin">
                                    Secret para enviar mensajes desde la API de Wamundo
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group-admin">
                                <label class="form-label-admin">
                                    <i class="fas fa-id-badge me-2"></i>
                                    Account ID <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control-admin" name="account_id" 
                                       value="<?= htmlspecialchars($current_config['account_id']) ?>"
                                       placeholder="ID de tu cuenta en Wamundo"
                                       required>
                                <div class="form-text-admin">
                                    Identificador único de tu cuenta en Wamundo
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group-admin">
                                <label class="form-label-admin">
                                    <i class="fas fa-shield-alt me-2"></i>
                                    Webhook Secret
                                </label>
                                <input type="text" class="form-control-admin" name="webhook_secret" 
                                       value="<?= htmlspecialchars($current_config['webhook_secret']) ?>"
                                       placeholder="Opcional - para validar webhooks">
                                <div class="form-text-admin">
                                    Recomendado para mayor seguridad en las comunicaciones
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group-admin">
                                <label class="form-label-admin">
                                    <i class="fas fa-list me-2"></i>
                                    Nivel de Log
                                </label>
                                <select class="form-control-admin" name="log_level">
                                    <option value="debug" <?= $current_config['log_level'] === 'debug' ? 'selected' : '' ?>>Debug (Muy detallado)</option>
                                    <option value="info" <?= $current_config['log_level'] === 'info' ? 'selected' : '' ?>>Info (Recomendado)</option>
                                    <option value="warning" <?= $current_config['log_level'] === 'warning' ? 'selected' : '' ?>>Warning (Solo advertencias)</option>
                                    <option value="error" <?= $current_config['log_level'] === 'error' ? 'selected' : '' ?>>Error (Solo errores)</option>
                                </select>
                                <div class="form-text-admin">
                                    Controla la cantidad de información registrada en los logs
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end gap-3">
                        <button type="submit" class="btn-admin btn-success-admin">
                            <i class="fas fa-save me-2"></i>
                            Guardar Configuración
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tests del Sistema -->
        <div class="tab-pane fade" id="tests" role="tabpanel">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <i class="fas fa-vial me-2"></i>
                        Diagnósticos del Sistema
                    </h3>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="test_system">
                        <button type="submit" class="btn-admin btn-primary-admin">
                            <i class="fas fa-play me-1"></i>
                            Ejecutar Tests
                        </button>
                    </form>
                </div>
                
                <?php if (!empty($test_results)): ?>
                    <?php foreach ($test_results as $test): ?>
                        <div class="test-result test-<?= $test['status'] ?>">
                            <h5>
                                <span class="status-indicator status-<?= $test['status'] ?>"></span>
                                <?= htmlspecialchars($test['name']) ?>
                            </h5>
                            <p class="mb-2"><?= htmlspecialchars($test['message']) ?></p>
                            
                            <?php if (!empty($test['details'])): ?>
                                <div class="mt-2">
                                    <small><strong>Detalles:</strong></small>
                                    <ul class="small mb-0">
                                        <?php foreach ($test['details'] as $key => $value): ?>
                                            <li><?= htmlspecialchars($key) ?>: <?= htmlspecialchars($value) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-flask fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Ejecuta los tests para verificar el estado del sistema</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Logs -->
        <div class="tab-pane fade" id="logs" role="tabpanel">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h3 class="admin-card-title">
                        <i class="fas fa-terminal me-2"></i>
                        Logs del Sistema
                    </h3>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="btn-admin btn-danger-admin" 
                                onclick="return confirm('¿Estás seguro de limpiar todos los logs?')">
                            <i class="fas fa-trash me-1"></i>
                            Limpiar Logs
                        </button>
                    </form>
                </div>
                
                <div class="logs-container">
                    <pre class="mb-0"><?= htmlspecialchars($recent_logs) ?></pre>
                </div>
                
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Se muestran las últimas 20 líneas del log. Los logs se actualizan automáticamente.
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyWebhookUrl() {
    const urlElement = document.getElementById('webhookUrl');
    const url = urlElement.textContent;
    
    navigator.clipboard.writeText(url).then(function() {
        // Feedback visual
        const originalText = urlElement.innerHTML;
        urlElement.innerHTML = '<i class="fas fa-check text-success"></i> URL copiada al portapapeles';
        
        setTimeout(function() {
            urlElement.innerHTML = url;
        }, 2000);
    }).catch(function(err) {
        console.error('Error al copiar: ', err);
        alert('Error al copiar la URL');
    });
}

// Auto-refresh de logs cada 30 segundos
if (document.querySelector('#logs.active')) {
    setInterval(function() {
        if (document.querySelector('#logs.active')) {
            location.reload();
        }
    }, 30000);
}

// Mostrar/ocultar detalles en tests
document.addEventListener('DOMContentLoaded', function() {
    const testResults = document.querySelectorAll('.test-result');
    testResults.forEach(function(result) {
        result.style.cursor = 'pointer';
        result.addEventListener('click', function() {
            const details = this.querySelector('ul');
            if (details) {
                details.style.display = details.style.display === 'none' ? 'block' : 'none';
            }
        });
    });
});
</script>

</body>
</html>
