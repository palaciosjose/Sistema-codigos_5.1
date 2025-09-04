<?php
require_once __DIR__ . '/../config/path_constants.php';
session_start();
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once SECURITY_DIR . '/auth.php';
use Shared\DatabaseManager;

authorize('manage_whatsapp', '../index.php', false);

$conn = DatabaseManager::getInstance()->getConnection();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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


function test_api_connection($url, $token, $instance) {
    $endpoint = rtrim($url, '/') . '/getInstanceInfo';
    $payload = json_encode(['instance' => $instance]);
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
    if ($response === false || $code >= 400) {
        $msg = 'Error de conexión a la API: ' . ($error ?: 'HTTP ' . $code);
        log_action($msg);
        return [false, $msg];
    }
    return [true, 'Conexión a la API exitosa'];
}

function register_webhook($url, $token, $instance, $webhook_url, $secret) {
    if (empty($webhook_url)) {
        return [false, 'URL de webhook no configurada'];
    }
    $endpoint = rtrim($url, '/') . '/setWebhook';
    $payload = json_encode([
        'url' => $webhook_url,
        'secret' => $secret,
        'instance' => $instance
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
    if ($response === false || $code >= 400) {
        $msg = 'Error al registrar webhook: ' . ($error ?: 'HTTP ' . $code);
        log_action($msg);
        return [false, $msg];
    }
    return [true, 'Webhook registrado correctamente'];
}

function validateWhatsAppInstance($url, $token, $instance) {
    $endpoint = rtrim($url, '/') . '/getInstanceInfo';
    $payload  = json_encode(['instance' => $instance]);
    $ch       = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $code >= 400) {
        $msg = 'Error al validar instancia: ' . ($error ?: 'HTTP ' . $code);
        log_action($msg);
        return [
            'success' => false,
            'message' => $msg,
            'linked'  => null,
            'qr_url'  => null
        ];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['instance'])) {
        $msg = 'Respuesta inválida de la API al validar instancia';
        log_action($msg);
        return [
            'success' => false,
            'message' => 'Respuesta inválida de la API',
            'linked'  => null,
            'qr_url'  => null
        ];
    }

    $info   = $data['instance'];
    $linked = null;

    if (isset($info['connected'])) {
        $linked = (bool)$info['connected'];
    } elseif (isset($info['isLinked'])) {
        $linked = (bool)$info['isLinked'];
    } elseif (isset($info['is_linked'])) {
        $linked = (bool)$info['is_linked'];
    } elseif (isset($info['state'])) {
        $linked = in_array(strtolower($info['state']), ['open', 'connected', 'authenticated']);
    }

    $qr_url = null;
    if ($linked === false) {
        $qr_url = $info['qr'] ?? $info['qrCode'] ?? $info['qr_url'] ?? $info['qrUrl'] ?? null;
    }

    return [
        'success' => (bool)$linked,
        'message' => $linked ? 'Instancia vinculada' : 'Instancia no vinculada',
        'linked'  => $linked,
        'qr_url'  => $qr_url
    ];
}

function testWebhookConfiguration($url, $token, $instance, $webhook_url, $secret) {
    if (empty($webhook_url)) {
        return [false, 'URL de webhook no configurada'];
    }
    $endpoint = rtrim($url, '/') . '/testWebhook';
    $payload = json_encode([
        'url' => $webhook_url,
        'secret' => $secret,
        'instance' => $instance
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
    if ($response === false || $code >= 400) {
        $msg = 'Error al probar webhook: ' . ($error ?: 'HTTP ' . $code);
        log_action($msg);
        return [false, $msg];
    }
    return [true, 'Webhook verificado correctamente'];
}

function checkWhatsAppBotStatus($conn) {
    $api_url = get_setting($conn, 'WHATSAPP_API_URL');
    $token = get_setting($conn, 'WHATSAPP_TOKEN');
    $instance = get_setting($conn, 'WHATSAPP_INSTANCE');
    $webhook_secret = get_setting($conn, 'WHATSAPP_WEBHOOK_SECRET');
    $webhook_url = get_setting($conn, 'WHATSAPP_WEBHOOK_URL');

    $status = [
        'configured' => ($api_url && $token && $instance && $webhook_secret && $webhook_url),
        'api'        => [false, 'Configuración incompleta'],
        'webhook'    => [false, 'Configuración incompleta'],
        'linked'     => null,
        'qr_url'     => null,
        'tables'     => []
    ];

    if ($status['configured']) {
        $instanceInfo    = validateWhatsAppInstance($api_url, $token, $instance);
        $status['api']   = [$instanceInfo['success'], $instanceInfo['message']];
        $status['linked'] = $instanceInfo['linked'];
        $status['qr_url'] = $instanceInfo['qr_url'];
        $status['webhook'] = testWebhookConfiguration($api_url, $token, $instance, $webhook_url, $webhook_secret);
    }

    $required = ['whatsapp_temp_data', 'whatsapp_activity_log', 'whatsapp_sessions'];
    foreach ($required as $table) {
        $res = $conn->query("SHOW TABLES LIKE '$table'");
        $exists = $res && $res->num_rows > 0;
        if ($res) {
            $res->close();
        }
        $status['tables'][$table] = $exists;
    }
    return $status;
}

function getWhatsAppStats($conn) {
    $stats = [
        'active_users'    => 0,
        'messages_today'  => 0,
        'total_messages'  => 0,
        'total_searches'  => 0,
    ];

    // Usuarios con actividad reciente (últimos 30 días)
    $res = $conn->query("SELECT COUNT(DISTINCT whatsapp_id) AS c FROM users WHERE whatsapp_id IS NOT NULL AND status=1 AND last_whatsapp_activity >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    if ($res) {
        $row = $res->fetch_assoc();
        $stats['active_users'] = (int)($row['c'] ?? 0);
        $res->close();
    }

    // Mensajes registrados hoy
    $res = $conn->query("SELECT COUNT(*) AS c FROM whatsapp_activity_log WHERE DATE(created_at)=CURDATE()");
    if ($res) {
        $row = $res->fetch_assoc();
        $stats['messages_today'] = (int)($row['c'] ?? 0);
        $res->close();
    }

    // Total de mensajes registrados
    $res = $conn->query('SELECT COUNT(*) AS c FROM whatsapp_activity_log');
    if ($res) {
        $row = $res->fetch_assoc();
        $stats['total_messages'] = (int)($row['c'] ?? 0);
        $res->close();
    }

    // Total de búsquedas realizadas vía WhatsApp
    $res = $conn->query("SELECT COUNT(*) AS c FROM search_logs WHERE source='whatsapp'");
    if ($res) {
        $row = $res->fetch_assoc();
        $stats['total_searches'] = (int)($row['c'] ?? 0);
        $res->close();
    }

    return $stats;
}

if (($_GET['action'] ?? '') === 'stats') {
    header('Content-Type: application/json');
    echo json_encode(getWhatsAppStats($conn));
    exit;
}

$message = '';
$error_message = '';
$api_result = null;
$webhook_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Token CSRF inválido';
    } else {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $api_url = filter_var(trim($_POST['api_url'] ?? ''), FILTER_SANITIZE_URL);
        $token = filter_var(trim($_POST['token'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $instance = filter_var(trim($_POST['instance'] ?? ''), FILTER_SANITIZE_NUMBER_INT);
        $webhook_secret = filter_var(trim($_POST['webhook_secret'] ?? ''), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $webhook_url = filter_var(trim($_POST['webhook_url'] ?? ''), FILTER_SANITIZE_URL);

        $errors = [];
        if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'API URL inválida';
        }
        if ($token === '') {
            $errors[] = 'El token es obligatorio';
        }
        if ($instance === '') {
            $errors[] = 'La instancia es obligatoria';
        }
        if ($webhook_secret === '') {
            $errors[] = 'El secreto del webhook es obligatorio';
        }
        if (!filter_var($webhook_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Webhook URL inválida';
        }

        if (empty($errors)) {
            set_setting($conn, 'WHATSAPP_API_URL', $api_url);
            set_setting($conn, 'WHATSAPP_TOKEN', $token);
            set_setting($conn, 'WHATSAPP_INSTANCE', $instance);
            set_setting($conn, 'WHATSAPP_WEBHOOK_SECRET', $webhook_secret);
            set_setting($conn, 'WHATSAPP_WEBHOOK_URL', $webhook_url);
            log_action('Configuración actualizada');
            $message = 'Configuración guardada correctamente. Tras guardar, ejecuta <code>composer run whatsapp-test</code> para confirmar la integración.';
            $api_result = test_api_connection($api_url, $token, $instance);
            $webhook_result = register_webhook($api_url, $token, $instance, $webhook_url, $webhook_secret);
        } else {
            $error_message = implode('<br>', $errors);
            log_action('Error al guardar configuración: ' . implode('; ', $errors));
        }
    }
}

$api_url = get_setting($conn, 'WHATSAPP_API_URL');
$token = get_setting($conn, 'WHATSAPP_TOKEN');
$instance = get_setting($conn, 'WHATSAPP_INSTANCE');
$webhook_secret = get_setting($conn, 'WHATSAPP_WEBHOOK_SECRET');
$webhook_url = get_setting($conn, 'WHATSAPP_WEBHOOK_URL');
$webhook_status = $webhook_url ? 'Configurado' : 'No configurado';

$bot_status = checkWhatsAppBotStatus($conn);
$last_activity = '';
$activity_res = $conn->query("SELECT MAX(created_at) AS last_activity FROM whatsapp_activity_log");
if ($activity_res) {
    $row = $activity_res->fetch_assoc();
    $last_activity = $row['last_activity'] ?? '';
    $activity_res->close();
}

$authorized_users = [];
$auth_res = $conn->query("SELECT u.username, ae.email FROM user_authorized_emails uae JOIN users u ON uae.user_id = u.id JOIN authorized_emails ae ON uae.authorized_email_id = ae.id ORDER BY u.username");
if ($auth_res) {
    while ($row = $auth_res->fetch_assoc()) {
        $authorized_users[] = $row;
    }
    $auth_res->close();
}

function get_recent_logs($file, $lines = 20) {
    if (!file_exists($file)) return [];
    return array_slice(file($file), -$lines);
}

$error_log = get_recent_logs(PROJECT_ROOT . '/whatsapp_bot/logs/error.log');
$bot_log = get_recent_logs(PROJECT_ROOT . '/whatsapp_bot/logs/bot.log');
$whatsapp_stats = getWhatsAppStats($conn);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión Bot WhatsApp</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../styles/modern_global.css">
    <link rel="stylesheet" href="../styles/modern_admin.css">
</head>
<body class="admin-page">
<div class="admin-container">
    <div class="admin-header">
        <h1>Gestión Bot WhatsApp</h1>
    </div>
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger"><?php echo $error_message; ?></div>
    <?php endif; ?>
    <?php if (!empty($message)): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if ($api_result): ?>
        <div class="alert alert-<?php echo $api_result[0] ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($api_result[1]); ?></div>
    <?php endif; ?>
    <?php if ($webhook_result): ?>
        <div class="alert alert-<?php echo $webhook_result[0] ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($webhook_result[1]); ?></div>
    <?php endif; ?>
    <div class="admin-card">
        <h2>Estado del Bot y API</h2>
        <div class="d-flex align-items-center mb-2">
            <span class="me-2">Bot:</span>
            <span class="status-indicator <?php echo $bot_status['webhook'][0] ? 'status-active' : 'status-inactive'; ?>"></span>
            <span><?php echo htmlspecialchars($bot_status['webhook'][1]); ?></span>
        </div>
        <div class="d-flex align-items-center mb-2">
            <span class="me-2">API:</span>
            <span class="status-indicator <?php echo $bot_status['api'][0] ? 'status-active' : 'status-inactive'; ?>"></span>
            <span><?php echo htmlspecialchars($bot_status['api'][1]); ?></span>
        </div>
        <div class="d-flex align-items-center mb-2">
            <span class="me-2">Vinculación:</span>
            <span class="status-indicator <?php echo $bot_status['linked'] ? 'status-active' : 'status-inactive'; ?>"></span>
            <span><?php echo $bot_status['linked'] ? 'Conectado' : 'No conectado'; ?></span>
            <?php if (!$bot_status['linked'] && !empty($bot_status['qr_url'])): ?>
                <button type="button" id="btnGetQr" class="btn-admin btn-primary-admin btn-sm ms-2">Obtener QR</button>
            <?php endif; ?>
        </div>
        <p>ID de Instancia: <?php echo htmlspecialchars($instance); ?></p>
        <p>Última actividad: <?php echo htmlspecialchars($last_activity ?: 'Sin registros'); ?></p>
    </div>

    <div class="admin-card" id="statsCard">
        <h2>Estadísticas</h2>
        <ul class="list-unstyled mb-3">
            <li>Usuarios activos: <span id="statActiveUsers"><?php echo (int)$whatsapp_stats['active_users']; ?></span></li>
            <li>Mensajes de hoy: <span id="statMessagesToday"><?php echo (int)$whatsapp_stats['messages_today']; ?></span></li>
            <li>Total mensajes: <span id="statTotalMessages"><?php echo (int)$whatsapp_stats['total_messages']; ?></span></li>
            <li>Búsquedas: <span id="statSearches"><?php echo (int)$whatsapp_stats['total_searches']; ?></span></li>
        </ul>
        <button type="button" id="refreshStats" class="btn-admin btn-secondary-admin btn-sm">Refrescar</button>
    </div>

    <div class="admin-card">
    <form method="post" class="mb-4" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="form-group-admin">
            <label class="form-label-admin">API URL</label>
            <input type="text" name="api_url" id="api_url" class="form-control-admin" value="<?php echo htmlspecialchars($api_url); ?>" required>
        </div>
        <div class="form-group-admin">
            <label class="form-label-admin">Token</label>
            <div class="input-group">
                <input type="password" name="token" id="token" class="form-control-admin" value="<?php echo htmlspecialchars($token); ?>" required>
                <button type="button" id="toggleToken" class="btn-admin btn-primary-admin btn-sm">Mostrar</button>
            </div>
        </div>
        <div class="form-group-admin">
            <label class="form-label-admin">ID de Instancia</label>
            <input type="text" name="instance" id="instance" class="form-control-admin" value="<?php echo htmlspecialchars($instance); ?>" inputmode="numeric" required>
        </div>
        <div class="form-group-admin">
            <label class="form-label-admin">Webhook Secret</label>
            <div class="input-group">
                <input type="text" name="webhook_secret" id="webhook_secret" class="form-control-admin" value="<?php echo htmlspecialchars($webhook_secret); ?>" required>
                <button type="button" id="generateSecret" class="btn-admin btn-primary-admin btn-sm">Generar</button>
            </div>
        </div>
        <div class="form-group-admin">
            <label class="form-label-admin">Webhook URL</label>
            <input type="text" name="webhook_url" id="webhook_url" class="form-control-admin" value="<?php echo htmlspecialchars($webhook_url); ?>" required>
        </div>
        <button type="submit" class="btn-admin btn-primary-admin">Guardar</button>
        <p class="mt-3">Tras guardar los valores ejecuta <code>composer run whatsapp-test</code> para confirmar la integración.</p>
    </form>
    </div>

    <div class="admin-card">
        <h2>Pruebas de Conexión</h2>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" id="btnTestApi" class="btn-admin btn-primary-admin">Probar Conexión API</button>
            <button type="button" id="btnValidateInstance" class="btn-admin btn-secondary-admin">Validar Instancia</button>
            <button type="button" id="btnSendTest" class="btn-admin btn-info-admin">Enviar Mensaje de Prueba</button>
            <button type="button" id="btnVerifyWebhook" class="btn-admin btn-warning-admin">Verificar Webhook</button>
        </div>
        <div id="testResults" class="mt-2"></div>
    </div>

    <div class="admin-card">
        <h2>Estado del Webhook</h2>
        <p><?php echo htmlspecialchars($webhook_status); ?><?php if ($webhook_url) echo ': ' . htmlspecialchars($webhook_url); ?></p>
    </div>

    <div class="admin-card">
        <h2>Usuarios Autorizados</h2>
        <?php if (empty($authorized_users)): ?>
            <p>No hay usuarios autorizados.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($authorized_users as $u): ?>
                    <li><?php echo htmlspecialchars($u['username'] . ' - ' . $u['email']); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="admin-card">
        <h2>Logs Recientes</h2>
        <?php if (!$error_log && !$bot_log): ?>
            <p>No hay logs disponibles.</p>
        <?php else: ?>
            <?php if ($error_log): ?>
                <h3>Error Log</h3>
                <pre class="bg-dark text-light p-2 border"><?php echo htmlspecialchars(implode('', $error_log)); ?></pre>
            <?php endif; ?>
            <?php if ($bot_log): ?>
                <h3>Bot Log</h3>
                <pre class="bg-dark text-light p-2 border"><?php echo htmlspecialchars(implode('', $bot_log)); ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <a href="admin.php" class="btn-admin btn-info-admin">Volver</a>
</div>

<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Código QR</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center">
                <img id="qrImage" src="" alt="QR" class="img-fluid">
                <div class="mt-3">
                    <a id="qrDownload" href="#" download="whatsapp_qr.png" class="btn btn-secondary">Descargar</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const apiUrlInput = document.getElementById('api_url');
    const tokenInput = document.getElementById('token');
    const instanceInput = document.getElementById('instance');
    const webhookSecretInput = document.getElementById('webhook_secret');
    const webhookUrlInput = document.getElementById('webhook_url');
    const toggleTokenBtn = document.getElementById('toggleToken');
    const generateSecretBtn = document.getElementById('generateSecret');
    const form = document.querySelector('form');
    const submitBtn = form.querySelector('button[type="submit"]');
    const testResults = document.getElementById('testResults');
    const btnTestApi = document.getElementById('btnTestApi');
    const btnValidateInstance = document.getElementById('btnValidateInstance');
    const btnSendTest = document.getElementById('btnSendTest');
    const btnVerifyWebhook = document.getElementById('btnVerifyWebhook');
    const refreshStatsBtn = document.getElementById('refreshStats');
    const statActiveUsers = document.getElementById('statActiveUsers');
    const statMessagesToday = document.getElementById('statMessagesToday');
    const statTotalMessages = document.getElementById('statTotalMessages');
    const statSearches = document.getElementById('statSearches');
    const btnGetQr = document.getElementById('btnGetQr');
    const qrImage = document.getElementById('qrImage');
    const qrDownload = document.getElementById('qrDownload');
    const qrData = <?php echo json_encode($bot_status['qr_url']); ?>;

    if (btnGetQr && qrData) {
        const qrModal = new bootstrap.Modal(document.getElementById('qrModal'));
        btnGetQr.addEventListener('click', () => {
            qrImage.src = qrData;
            qrDownload.href = qrData;
            qrModal.show();
        });
    }

    function isValidUrl(value) {
        try { new URL(value); return true; } catch { return false; }
    }

    function validateInput(input, condition) {
        if (condition) {
            input.classList.remove('is-invalid');
            return true;
        } else {
            input.classList.add('is-invalid');
            return false;
        }
    }

    function validateForm() {
        const urlValid = validateInput(apiUrlInput, isValidUrl(apiUrlInput.value));
        const tokenValid = validateInput(tokenInput, tokenInput.value.trim() !== '');
        const instanceValid = validateInput(instanceInput, /^\d+$/.test(instanceInput.value));
        const secretValid = validateInput(webhookSecretInput, webhookSecretInput.value.trim() !== '');
        const webhookUrlValid = validateInput(webhookUrlInput, isValidUrl(webhookUrlInput.value));
        const valid = urlValid && tokenValid && instanceValid && secretValid && webhookUrlValid;
        submitBtn.disabled = !valid;
        return valid;
    }

    toggleTokenBtn.addEventListener('click', () => {
        if (tokenInput.type === 'password') {
            tokenInput.type = 'text';
            toggleTokenBtn.textContent = 'Ocultar';
        } else {
            tokenInput.type = 'password';
            toggleTokenBtn.textContent = 'Mostrar';
        }
    });

    function generateSecret(len = 32) {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < len; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }

    generateSecretBtn.addEventListener('click', () => {
        webhookSecretInput.value = generateSecret();
        validateForm();
    });

    if (!webhookUrlInput.value) {
        const currentDomain = window.location.hostname;
        const basePath = window.location.pathname.split('/admin/')[0];
        const finalPath = (basePath === '/' ? '' : basePath);
        webhookUrlInput.value = `https://${currentDomain}${finalPath}/whatsapp_bot/webhook.php`;
    }

    [apiUrlInput, tokenInput, instanceInput, webhookSecretInput, webhookUrlInput].forEach(input => {
        input.addEventListener('input', validateForm);
    });

    form.addEventListener('submit', function(e) {
        if (!validateForm()) {
            e.preventDefault();
        }
    });

    function showTestResult(success, message) {
        const cls = success ? 'alert-success-admin' : 'alert-danger-admin';
        testResults.innerHTML = `<div class="alert-admin ${cls}">${message}</div>`;
    }

    function runTest(action, extra = {}) {
        const params = new URLSearchParams({
            action,
            api_url: apiUrlInput.value,
            token: tokenInput.value,
            instance: instanceInput.value,
            webhook_url: webhookUrlInput.value,
            webhook_secret: webhookSecretInput.value,
            ...extra
        });
        fetch('test_whatsapp_connection.php', {
            method: 'POST',
            body: params
        })
        .then(r => r.json())
        .then(data => {
            showTestResult(data.success, data.message || data.error || 'Respuesta inválida');
        })
        .catch(err => {
            showTestResult(false, 'Error de red: ' + err.message);
        });
    }

    btnTestApi.addEventListener('click', () => runTest('test_api'));
    btnValidateInstance.addEventListener('click', () => runTest('validate_instance'));
    btnSendTest.addEventListener('click', () => {
        const phone = prompt('Número de WhatsApp (incluye código de país):');
        if (phone) runTest('send_message', { phone });
    });
    btnVerifyWebhook.addEventListener('click', () => runTest('verify_webhook'));

    function loadStats() {
        fetch('whatsapp_management.php?action=stats')
            .then(r => r.json())
            .then(data => {
                statActiveUsers.textContent = data.active_users ?? 0;
                statMessagesToday.textContent = data.messages_today ?? 0;
                statTotalMessages.textContent = data.total_messages ?? 0;
                statSearches.textContent = data.total_searches ?? 0;
            });
    }

    refreshStatsBtn.addEventListener('click', loadStats);

    validateForm();
});
</script>
<style>
.alert-success-admin {
    background: rgba(50, 255, 181, 0.1);
    border: 1px solid var(--accent-green);
    color: var(--accent-green);
}
.alert-danger-admin {
    background: rgba(255, 77, 77, 0.1);
    border: 1px solid var(--danger-red);
    color: var(--danger-red);
}
.alert-info-admin {
    background: rgba(6, 182, 212, 0.1);
    border: 1px solid #06b6d4;
    color: #06b6d4;
}
.alert-warning-admin {
    background: rgba(255, 193, 7, 0.1);
    border: 1px solid #ffc107;
    color: #ffc107;
}
</style>
</body>
</html>
